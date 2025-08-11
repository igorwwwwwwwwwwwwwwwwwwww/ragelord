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
// NOTE: log records depend on this format to determine server
function client_socket_name($sock) {
    $server_addr = $server_port = null;
    $client_addr = $client_port = null;
    socket_getsockname($sock, $server_addr, $server_port);
    socket_getpeername($sock, $client_addr, $client_port);
    return "[$server_addr]:$server_port <-> [$client_addr]:$client_port";
}

function server_socket_name($sock) {
    $server_addr = $server_port = null;
    socket_getsockname($sock, $server_addr, $server_port);
    return "[$server_addr]:$server_port";
}

class LogReplaySocket {
    function __construct(
        public $name,
        public $sock = null,
        public $fiber_read = null,
        public $fiber_write = null,
    ) {}

    function read() {
        $this->fiber_read = Fiber::getCurrent();
        return Fiber::suspend();
    }

    function resume_read($val) {
        if (!$this->fiber_read) {
            throw new \RuntimeException('cannot deliver read without a waiting reader');
        }
        $this->fiber_read->resume($val);
    }

    function write($msg) {
        $this->fiber_write = Fiber::getCurrent();
        return Fiber::suspend();
    }

    function resume_write() {
        if (!$this->fiber_write) {
            throw new \RuntimeException('cannot resume write without a waiting writer');
        }
        $this->fiber_write->resume();
    }
}
