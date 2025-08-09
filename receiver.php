<?php
$sock_path = "/tmp/fdpass.sock";
@unlink($sock_path);

$server = socket_create(AF_UNIX, SOCK_STREAM, 0);
socket_bind($server, $sock_path);
socket_listen($server);

echo "Receiver: waiting for sender...\n";
$client = socket_accept($server);

$data = [
    "iov"        => [""],
    "control"    => [],
    "controllen" => socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS, 1)
];

socket_recvmsg($client, $data, 0);
print_r($data);

$stream_sock = $data["control"][0]["data"][0];
$meta = stream_get_meta_data($stream_sock);
$fd = intval($meta['stream_id'] ?? $stream_sock); // not portable in all PHP builds
var_dump($fd);

// This will only work if you can get the numeric FD (not always possible without FFI)
$fd_stream = fopen("php://fd/$fd", "r");
$tcp_sock  = socket_import_stream($fd_stream);

echo "Receiver: waiting for TCP connection...\n";
$tcp_client = socket_accept($tcp_sock);
socket_write($tcp_client, "Hello from receiver!\n");
socket_close($tcp_client);

socket_close($tcp_sock);
socket_close($client);
socket_close($server);
@unlink($sock_path);
