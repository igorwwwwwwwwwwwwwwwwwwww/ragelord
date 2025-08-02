<?php

namespace ragelord;

require 'server.php';
require 'protocol.php';
require 'socket.php';
require 'signal.php';

set_error_handler(exception_error_handler(...));

$server_sock = create_server_socket6();
echo "ready\n";

$sigbuf = new SignalBuffer();
pcntl_async_signals(true);
pcntl_signal(SIGINT, [$sigbuf, 'handler']);
pcntl_signal(SIGTERM, [$sigbuf, 'handler']);
pcntl_signal(SIGINFO, [$sigbuf, 'handler']);

$server = new ServerState();

try {
    server($server_sock, $sigbuf, $server);
} finally {
    if ($server_sock) {
        socket_close($server_sock);
    }
}
