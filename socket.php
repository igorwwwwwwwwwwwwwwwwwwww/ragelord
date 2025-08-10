<?php

namespace ragelord;

const LISTEN_BACKLOG = 512;

function listen4($addr, $port) {
    $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    socket_set_nonblock($sock);
    socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
    socket_bind($sock, $addr, $port);
    socket_listen($sock, LISTEN_BACKLOG);
    return $sock;
}

function listen6($addr, $port) {
    $sock = socket_create(AF_INET6, SOCK_STREAM, SOL_TCP);
    socket_set_nonblock($sock);
    socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
    socket_bind($sock, $addr, $port);
    socket_listen($sock, LISTEN_BACKLOG);
    return $sock;
}

function listen_unix($socket_path) {
    $sock = socket_create(AF_UNIX, SOCK_STREAM, 0);
    socket_set_nonblock($sock);
    socket_bind($sock, $socket_path);
    socket_listen($sock, 512);
    return $sock;
}

function connect_unix($socket_path) {
    $sock = socket_create(AF_UNIX, SOCK_STREAM, 0);
    socket_set_nonblock($sock);
    socket_connect($sock, $socket_path);
    return $sock;
}

// mainly relevant for recvmsg on macos
function accept_debounce($listen_sock) {
    while (true) {
        $sock = accept($listen_sock);
        if ($sock) {
            return $sock;
        }
        // EAGAIN race, happens a lot on macOS
        $err = socket_last_error($listen_sock);
        if ($err === SOCKET_EAGAIN || $err === SOCKET_EWOULDBLOCK || $err === 0) {
            sleep(1);
            continue;
        }

        throw new \RuntimeException(sprintf('accept failed: %s', socket_strerror($err)));
    }
}

// TODO: format depending on ipv4 vs ipv6
// NOTE: changes in format here will impact upgrades (see ServerState socket_user)
function socket_name($sock) {
    socket_getpeername($sock, $addr, $port);
    return "[$addr]:$port";
}
