<?php
$sock_path = "/tmp/fdpass.sock";

// Create a TCP listening socket to pass
$tcp_sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_bind($tcp_sock, "127.0.0.1", 9000);
socket_listen($tcp_sock);

echo "Sender: created TCP listening socket on 127.0.0.1:9000\n";

// Connect to receiver's Unix socket
$client = socket_create(AF_UNIX, SOCK_STREAM, 0);
socket_connect($client, $sock_path);

// Send the TCP socket FD via SCM_RIGHTS
$control = [
    [
        "level" => SOL_SOCKET,
        "type"  => SCM_RIGHTS,
        "data"  => [$tcp_sock] // pass socket object directly
    ]
];

$msg = [
    "iov"     => ["socket incoming\n"],
    "control" => $control
];

socket_sendmsg($client, $msg, 0);
echo "Sender: sent TCP socket to receiver.\n";

socket_close($tcp_sock);
socket_close($client);
