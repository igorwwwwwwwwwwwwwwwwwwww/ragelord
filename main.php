<?php

namespace ragelord;

require 'socket.php';
require 'state.php';
require 'proto.php';
require 'server.php';
require 'sched.php';
require 'sync/chan.php';
require 'signal.php';

set_error_handler(exception_error_handler(...));

$sigbuf = new SignalBuffer();
pcntl_async_signals(true);
pcntl_signal(SIGINT, [$sigbuf, 'handler']);
pcntl_signal(SIGTERM, [$sigbuf, 'handler']);
pcntl_signal(SIGINFO, [$sigbuf, 'handler']);
$sigbuf->bottom_half();

// TODO: implement gracceful termination
go(function () use ($sigbuf) {
    $server_fibers = [];
    $canceled = false;

    go(function () use ($sigbuf, &$server_fibers, &$canceled) {
        foreach ($sigbuf->ch as $signo) {
            printf("received signal: %s\n", signo_name($signo));
            switch ($signo) {
                case SIGINT: // fallthru
                case SIGTERM:
                    foreach ($server_fibers as $fiber) {
                        $fiber->throw(new \RuntimeException(sprintf("received signal: %s\n", signo_name($signo))));
                    }
                    $canceled = true;
                    return;
                case SIGINFO:
                    engine_print_backtrace();
                    debug_print_backtrace();
                    break;
            }
        }
    });

    // TODO: nicer cancel mechanism, perhaps via some context / channel
    $server_socks = [
        listen_retry(fn () => listen4('127.0.0.1', 6667), $canceled),
        listen_retry(fn () => listen6('::1', 6667), $canceled),
    ];
    echo "ready\n";

    if ($canceled) {
        return;
    }

    $server = new ServerState();
    foreach ($server_socks as $server_sock) {
        $server_fibers[] = go(function () use ($server, $server_sock) {
            try {
                while (true) {
                    $sock = accept($server_sock);
                    go(function () use ($server, $sock) {
                        $name = socket_name($sock);
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
});

event_loop();
