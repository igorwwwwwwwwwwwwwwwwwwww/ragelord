<?php

namespace ragelord;

const LISTEN_BACKLOG = 512;

function listen4($addr, $port) {
    $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    socket_set_nonblock($sock);
    socket_bind($sock, $addr, $port);
    socket_listen($sock, LISTEN_BACKLOG);
    return $sock;
}

function listen6($addr, $port) {
    $sock = socket_create(AF_INET6, SOCK_STREAM, SOL_TCP);
    socket_set_nonblock($sock);
    socket_bind($sock, $addr, $port);
    socket_listen($sock, LISTEN_BACKLOG);
    return $sock;
}

// TODO: format depending on ipv4 vs ipv6
function client_socket_name($sock) {
    socket_getpeername($sock, $addr, $port);
    return "[$addr]:$port";
}

function listen_retry(callable $f) {
    $retries = 0;
    while (true) {
        try {
            return $f();
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
