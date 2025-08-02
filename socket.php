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
        if ($client->pending_read) {
            $client->feed_readbuf();
        }

        if ($client->closed) {
            $deleted[] = $client;
        }
    }

    foreach ($write as $sock) {
        $name = client_socket_name($sock);
        $client = $clients[$name];
        if ($client->pending_write) {
            $client->drain_writebuf();
        }

        if ($client->closed) {
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
                case SIGINFO:
                    foreach ($clients as $client) {
                        if (!$client->fiber->isStarted()) {
                            printf("%s [not started]\n", $client->name);
                            printf("\n");
                        } else if ($client->fiber->isTerminated()) {
                            printf("%s [terminated]\n", $client->name);
                            printf("\n");
                        } else {
                            printf("%s\n", $client->name);
                            $r = new \ReflectionFiber($client->fiber);
                            foreach ($r->getTrace() as $i => $frame) {
                                $args = array_map(fn ($arg) => var_export($arg, true), $frame['args']);
                                printf("#%d %s:%d %s%s%s(%s)\n", $i, $frame['file'] ?? '', $frame['line'] ?? '', $frame['class'] ?? '', $frame['type'] ?? '', $frame['function'] ?? '', implode(', ', $args));
                            }
                            printf("\n");
                        }
                    }
                    debug_print_backtrace();
                    break;
            }
        }

        $client_socks_read = array_map(
            fn ($client) => $client->sock,
            array_filter(array_values($clients), fn ($client) => $client->pending_read),
        );
        $client_socks_write = array_map(
            fn ($client) => $client->sock,
            array_filter(array_values($clients), fn ($client) => $client->pending_write),
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
