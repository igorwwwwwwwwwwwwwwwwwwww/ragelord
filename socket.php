<?php

namespace ragelord;

const LISTEN_PORT = 6667;
const LISTEN_BACKLOG  = 512;

const SELECT_TIMEOUT_SEC  = 10;
const SELECT_TIMEOUT_USEC = 0;

// TODO: format depending on ipv4 vs ipv6
function client_socket_name($sock) {
    socket_getpeername($sock, $addr, $port);
    return "[$addr]:$port";
}

function schedule($clients, $read, $write) {
    // printf("schedule: read=%d write=%d\n", count($read), count($write));

    $deleted = [];

    foreach ($read as $sock) {
        $name = client_socket_name($sock);
        $client = $clients[$name];
        if ($client->state === ClientState::WAIT_READ) {
            $client->serve_read();
        }

        if ($client->state === ClientState::CLOSED || $client->state === ClientState::ERROR) {
            $deleted[] = $client;
        }
    }

    foreach ($write as $sock) {
        $name = client_socket_name($sock);
        $client = $clients[$name];
        if ($client->state === ClientState::WAIT_WRITE) {
            $client->serve_write();
        }

        if ($client->state === ClientState::CLOSED || $client->state === ClientState::ERROR) {
            $deleted[] = $client;
        }
    }

    return $deleted;
}

function create_server_socket6($addr = '::1', $port = LISTEN_PORT) {
    $retries = 0;
    while (true) {
        try {
            // TODO: support both ipv4 and ipv6
            $server_sock = socket_create(AF_INET6, SOCK_STREAM, SOL_TCP);
            socket_set_nonblock($server_sock);
            socket_bind($server_sock, $addr, $port);
            socket_listen($server_sock, LISTEN_BACKLOG);
            return $server_sock;
        } catch (\ErrorException $e) {
            if (!str_contains($e->getMessage(), 'Address already in use')) {
                throw $e;
            }
            if ($retries > 60) {
                echo "retries exceeded\n";
                throw $e;
            }
            if ($retries === 0) {
                echo "waiting for port to become available...\n";
            }
            $retries++;
            sleep(1);
        }
    }
}

function server($server_sock, $sigbuf, $server) {
    $clients = [];

    while (true) {
        // echo "loop\n";
        foreach ($sigbuf->consume() as $signo) {
            printf("received signal: %s\n", signo_name($signo));
            switch ($signo) {
                case SIGINT:
                    // TODO: notify all clients before shutting down
                    return;
                case SIGTERM:
                    return;
            }
        }

        $client_socks_read = array_map(
            fn ($client) => $client->sock,
            array_filter(array_values($clients), fn ($client) => $client->state === ClientState::WAIT_READ),
        );
        $client_socks_write = array_map(
            fn ($client) => $client->sock,
            array_filter(array_values($clients), fn ($client) => $client->state === ClientState::WAIT_WRITE),
        );

        $read = array_merge([$server_sock], $client_socks_read);
        $write = $client_socks_write;
        $except = null;

        $changed = 0;
        try {
            $changed = socket_select($read, $write, $except, SELECT_TIMEOUT_SEC, SELECT_TIMEOUT_USEC);
        } catch (\ErrorException $e) {
            if (!str_contains($e->getMessage(), 'Interrupted system call')) {
                throw $e;
            }
        }

        if ($changed === false) {
            throw new \RuntimeException(socket_strerror(socket_last_error()));
        }

        if ($changed > 0) {
            $first = array_key_first($read);
            if ($first !== null && $read[$first] === $server_sock) {
                array_shift($read);

                $client_sock = socket_accept($server_sock);
                socket_set_nonblock($client_sock);
                $name = client_socket_name($client_sock);

                $clients[$name] = new Client($name, $client_sock, $server);
                $clients[$name]->start();
            }

            $deleted = schedule($clients, $read, $write);
            foreach ($deleted as $client) {
                unset($clients[$client->name]);
            }
        }
    }
}
