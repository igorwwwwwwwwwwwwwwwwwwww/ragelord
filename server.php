<?php

namespace ragelord;

use Fiber;

const LISTEN_BACKLOG  = 512;

const SELECT_TIMEOUT_SEC  = 10;
const SELECT_TIMEOUT_USEC = 0;

const CLIENT_READ_SIZE = 1024;

function socket_name($sock) {
    socket_getpeername($sock, $addr, $port);
    return "$addr:$port";
}

$listen_port = 6667;
$listen_port = 6668; // override to avoid conflict

$server_sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_nonblock($server_sock);
socket_bind($server_sock, '127.0.0.1', $listen_port);
socket_listen($server_sock, LISTEN_BACKLOG);

$clients = [];
$fibers = [];

while (true) {
    echo "loop\n";
    $read = array_values($clients) + [$server_sock];
    $write = null;
    $except = null;
    $changed = socket_select($read, $write, $except, SELECT_TIMEOUT_SEC, SELECT_TIMEOUT_USEC);
    var_dump($changed);
    if ($changed === false) {
        throw new \RuntimeException(socket_strerror(socket_last_error()));
    }
    if ($changed > 0) {
        foreach ($read as $sock) {
            if ($sock === $server_sock) {
                $client_sock = socket_accept($server_sock);
                socket_set_nonblock($client_sock);
                $name = socket_name($client_sock);
                $clients[$name] = $client_sock;
                $fiber = new Fiber(function ($name) {
                    echo "$name: starting fiber\n";

                    while (true) {
                        $msg = Fiber::suspend();
                        $msg_type = array_shift($msg);
                        switch ($msg_type) {
                            case 'read':
                                [$bytes] = $msg;
                                echo "$name: bytes received: $bytes\n";
                            break;
                            case 'stop':
                                echo "$name: stop signal received\n";
                                return;
                            default:
                                throw new \InvalidArgumentException("unsupported message type: $msg_type");
                        }
                    }
                });
                $fiber->start($name);
                $fibers[$name] = $fiber;
            } else {
                $name = socket_name($sock);
                $buf = null;
                $n = socket_recv($sock, $buf, CLIENT_READ_SIZE, MSG_DONTWAIT);
                if ($n === 0) {
                    // disconnect
                    socket_close($sock);
                    $fibers[$name]->resume(['stop']);
                    unset($fibers[$name]);
                    unset($clients[$name]);
                } else {
                    $fibers[$name]->resume(['read', $buf]);
                }
            }
        }
    }
}
