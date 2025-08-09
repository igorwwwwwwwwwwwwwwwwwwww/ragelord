<?php

set_error_handler(function (int $errno, string $errstr, ?string $errfile = null, ?int $errline  = null) {
    if (error_reporting() & $errno) {
        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    }
});

$socket_path = "/tmp/test_socket_handover_" . getmypid();

$listen_sock = socket_create(AF_UNIX, SOCK_STREAM, 0);
socket_bind($listen_sock, $socket_path);
socket_listen($listen_sock, 1);

$client = socket_create(AF_UNIX, SOCK_STREAM, 0);
socket_connect($client, $socket_path);

$server = socket_accept($listen_sock);

$sockets_to_send = [
    socket_create(AF_INET, SOCK_STREAM, SOL_TCP),
    socket_create(AF_INET, SOCK_DGRAM, SOL_UDP),
    socket_create(AF_UNIX, SOCK_STREAM, 0),
];
socket_bind($sockets_to_send[0], '127.0.0.1', 9000);
socket_listen($sockets_to_send[0], 1);
$stream_resources = array_map(fn ($sock) => socket_export_stream($sock), $sockets_to_send);

$data = [
    'iov' => [''],
    'control' => [[
        'level' => SOL_SOCKET,
        'type' => SCM_RIGHTS,
        'data' => $stream_resources,
    ]],
];
$res = socket_sendmsg($server, $data, 0);

$data = [
    'controllen' => socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS, 256),
];
$res = socket_recvmsg($client, $data, 0);
var_dump($data);

$passed_server = $data['control'][0]['data'][0];
// socket_import_stream($passed_server);
