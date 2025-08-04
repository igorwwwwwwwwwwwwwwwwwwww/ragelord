<?php

namespace ragelord;

require 'socket.php';
require 'state.php';
require 'proto.php';
require 'server.php';
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
go(function () use ($sigbuf) {
    $server = new ServerState();;
    $server_socks = [
        listen_retry(fn () => listen4('127.0.0.1', 6667)),
        listen_retry(fn () => listen6('::1', 6667)),
    ];
    echo "ready\n";

    $server_fibers = [];
    foreach ($server_socks as $server_sock) {
        $server_fibers[] = go(function () use ($server, $server_sock) {
            try {
                while (true) {
                    $sock = accept($server_sock);
                    go(function () use ($server, $sock) {
                        $name = client_socket_name($sock);
                        $sess = new Session($name, $sock, $server);
                        go(fn () => $sess->reader());
                        go(fn () => $sess->writer());
                    });
                }
            } finally {
                socket_close($server_sock);
            }
        });
    }

    go(function () use ($server_fibers, $sigbuf) {
        while ($signo = $sigbuf->ch->recv()) {
            printf("received signal: %s\n", signo_name($signo));
            switch ($signo) {
                case SIGINT:
                    // TODO: notify all clients before shutting down
                    foreach ($server_fibers as $fiber) {
                        $fiber->throw(new \RuntimeException(sprintf("received signal: %s\n", signo_name($signo))));
                    }
                    return;
                case SIGTERM:
                    foreach ($server_fibers as $fiber) {
                        $fiber->throw(new \RuntimeException(sprintf("received signal: %s\n", signo_name($signo))));
                    }
                    return;
                case SIGINFO:
                    foreach ($clients as $client) {
                        client_backtrace($client);
                    }
                    debug_print_backtrace();
                    break;
            }
        }
    });
});

event_loop($sigbuf);
