<?php

namespace ragelord\passfd;

use RuntimeException;
use InvalidArgumentException;

function send_sockets2(string $socket_path, array $sockets): bool {
    if (count($sockets) === 0) {
        throw new InvalidArgumentException("FD-tag pairs array cannot be empty");
    }

    $stream_resources = [];
    foreach ($sockets as $socket) {
        $stream_resources[] = socket_export_stream($socket);
    }

    $sock = socket_create(AF_UNIX, SOCK_STREAM, 0);
    socket_connect($sock, $socket_path);

    $data = [
        'iov' => [''],
        'control' => [[
            'level' => SOL_SOCKET,
            'type' => SCM_RIGHTS,
            'data' => $stream_resources,
        ]],
    ];
    // var_dump('sendmsg', $stream_resources);
    var_dump('sendmsg', $data);

    socket_sendmsg($sock, $data, 0);

    socket_close($sock);
    return true;
}

function receive_sockets2(string $socket_path): array {
    $listen_sock = socket_create(AF_UNIX, SOCK_STREAM, 0);

    if (file_exists($socket_path)) {
        unlink($socket_path);
    }

    socket_bind($listen_sock, $socket_path);
    socket_listen($listen_sock, 1);

    $client_sock = socket_accept($listen_sock);

    $data = [
        'controllen'  => socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS, 256),
        'controllen'  => socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS, 256),
    ];
    $tags_result = socket_recvmsg($client_sock, $data, 0);

    socket_close($client_sock);
    socket_close($listen_sock);
    if (file_exists($socket_path)) {
        unlink($socket_path);
    }

    if ($tags_result < 0) {
        $error = socket_strerror(socket_last_error($client_sock));
        throw new RuntimeException("recvmsg() failed for tags and fds: $error");
    }

    $fd_count = count($data['control'][0]['data']);

    if ($fd_count <= 0) {
        throw new RuntimeException("No file descriptors received");
    }

    // var_dump('recvmsg', $data['control'][0]['data']);
    var_dump('recvmsg', $data);

    return $data['control'][0]['data'];
}

function send_sockets(string $socket_path, array $socket_tag_pairs, $context = null): bool {
    if (count($socket_tag_pairs) === 0) {
        throw new InvalidArgumentException("FD-tag pairs array cannot be empty");
    }

    $fd_count = count($socket_tag_pairs);

    $stream_resources = [];
    $tags = [];
    foreach ($socket_tag_pairs as $pair) {
        if (!is_array($pair) || count($pair) !== 2) {
            throw new InvalidArgumentException("Each element must be a [fd, tag] pair");
        }
        [$socket_obj, $tag] = $pair;

        $stream = socket_export_stream($socket_obj);
        if ($stream === false) {
            throw new RuntimeException("Failed to export Socket to stream for FD $fd");
        }

        $stream_resources[] = $stream;
        $tags[] = $tag;
    }

    $sock = socket_create(AF_UNIX, SOCK_STREAM, 0);
    socket_connect($sock, $socket_path);

    var_dump('sendmsg', $stream_resources);

    socket_sendmsg($sock, [
        'iov' => [''],
        'control' => [[
            'level' => SOL_SOCKET,
            'type' => SCM_RIGHTS,
            'data' => $stream_resources,
        ]],
    ], 0);

    socket_close($sock);
    return true;
}

function receive_sockets(string $socket_path): array {
    $listen_sock = socket_create(AF_UNIX, SOCK_STREAM, 0);

    if (file_exists($socket_path)) {
        unlink($socket_path);
    }

    socket_bind($listen_sock, $socket_path);
    socket_listen($listen_sock, 1);

    $client_sock = socket_accept($listen_sock);

    $data = [
        'controllen'  => socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS, 256),
    ];
    $tags_result = socket_recvmsg($client_sock, $data, 0);

    socket_close($client_sock);
    socket_close($listen_sock);
    if (file_exists($socket_path)) {
        unlink($socket_path);
    }

    if ($tags_result < 0) {
        $error = socket_strerror(socket_last_error($client_sock));
        throw new RuntimeException("recvmsg() failed for tags and fds: $error");
    }

    $fd_count = count($data['control'][0]['data']);

    if ($fd_count <= 0) {
        throw new RuntimeException("No file descriptors received");
    }

    var_dump('recvmsg', $data['control'][0]['data']);

    return $data['control'][0]['data'];
}
