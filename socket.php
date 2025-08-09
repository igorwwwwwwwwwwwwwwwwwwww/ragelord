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
    // TODO: Remove existing socket file?
    socket_bind($sock, $socket_path);
    socket_listen($sock, 1);
    return $sock;
}

function connect_unix($socket_path) {
    $sock = socket_create(AF_UNIX, SOCK_STREAM, 0);
    socket_set_nonblock($sock);
    socket_connect($sock, $socket_path);
    return $sock;
}

// TODO: format depending on ipv4 vs ipv6
function socket_name($sock) {
    socket_getpeername($sock, $addr, $port);
    return "[$addr]:$port";
}
