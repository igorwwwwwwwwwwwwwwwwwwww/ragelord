<?php

namespace ragelord;

require 'server.php';
require 'protocol.php';
require 'socket.php';
require 'signal.php';

set_error_handler(exception_error_handler(...));

$sigbuf = new SignalBuffer();
pcntl_async_signals(true);
pcntl_signal(SIGINT, [$sigbuf, 'handler']);
pcntl_signal(SIGTERM, [$sigbuf, 'handler']);

$server = new ServerState();

try {
    $server_sock = create_server_socket6();
    echo "ready\n";
    server($server_sock, $sigbuf, $server);
} finally {
    if ($server_sock) {
        socket_close($server_sock);
    }
}
