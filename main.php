<?php

namespace ragelord;

require 'socket.php';
require 'server.php';
require 'protocol.php';
require 'sched.php';
require 'sync/channel.php';
require 'signal.php';

set_error_handler(exception_error_handler(...));

$sigbuf = new SignalBuffer();
pcntl_async_signals(true);
pcntl_signal(SIGINT, [$sigbuf, 'handler']);
pcntl_signal(SIGTERM, [$sigbuf, 'handler']);
pcntl_signal(SIGINFO, [$sigbuf, 'handler']);

// TODO: implement gracceful termination
go(function () {
    $server = new ServerState();;
    $server_socks = [
        listen_retry(fn () => listen4('127.0.0.1', 6667)),
        listen_retry(fn () => listen6('::1', 6667)),
    ];
    echo "ready\n";

    foreach ($server_socks as $server_sock) {
        go(function () use ($server, $server_sock) {
            try {
                while (true) {
                    $sock = accept($server_sock);
                    go(function () use ($server, $sock) {
                        $name = client_socket_name($sock);
                        $client = new Client($name, $sock, $server);
                        go(fn () => $client->reader());
                        go(fn () => $client->writer());
                    });
                }
            } finally {
                socket_close($server_sock);
            }
        });
    }
});

event_loop($sigbuf);
