<?php

namespace ragelord\passfd;

use RuntimeException;
use InvalidArgumentException;

function first($it) {
    foreach ($it as $v) {
        return $v;
    }
}

function buf_read_bytes($buf, $len) {
    $bytes = substr($buf, 0, $len);
    $rest = substr($buf, $len);
    return [$bytes, $rest];
}

function decode_uint32($bytes) {
    return first(unpack('V', $bytes));
}

function encode_uint32($num) {
    return pack('V', $num);
}

function send_sockets(string $socket_path, array $socket_tag_pairs, $context = null): bool {
    if (count($socket_tag_pairs) === 0) {
        throw new InvalidArgumentException("FD-tag pairs array cannot be empty");
    }

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

    $context_json = json_encode($context);
    $tags_json = json_encode($tags);

    socket_sendmsg($sock, [
        'iov' => [
            encode_uint32(strlen($context_json)),
            $context_json,
            encode_uint32(strlen($tags_json)),
            $tags_json,
        ],
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
    // Use PHP socket functions for basic operations
    $listen_sock = socket_create(AF_UNIX, SOCK_STREAM, 0);
    if ($listen_sock === false) {
        throw new RuntimeException("socket_create() failed: " . socket_strerror(socket_last_error()));
    }

    // Remove existing socket file
    if (file_exists($socket_path)) {
        unlink($socket_path);
    }

    socket_bind($listen_sock, $socket_path);
    socket_listen($listen_sock, 1);

    $client_sock = socket_accept($listen_sock);
    if ($client_sock === false) {
        $error = socket_strerror(socket_last_error($listen_sock));
        socket_close($listen_sock);
        throw new RuntimeException("socket_accept() failed: $error");
    }

    // maximum context+tags: 4k
    // maximum fd count: 256
    // TODO: validate on send
    $data = [
        'buffer_size' => 4096,
        'controllen'  => socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS, 256),
    ];
    $result = socket_recvmsg($client_sock, $data, 0);

    // TODO: do this in finally?
    socket_close($client_sock);
    socket_close($listen_sock);
    if (file_exists($socket_path)) {
        unlink($socket_path);
    }

    if ($result < 0) {
        $error = socket_strerror(socket_last_error($client_sock));
        throw new RuntimeException("recvmsg() failed for tags and fds: $error");
    }
    // TODO: check TRUNC flag

    $buf = $data['iov'][0];
    [$bytes, $buf] = buf_read_bytes($buf, 4);
    $context_len = decode_uint32($bytes);
    [$context_str, $buf] = buf_read_bytes($buf, $context_len);

    [$bytes, $buf] = buf_read_bytes($buf, 4);
    $tags_len = decode_uint32($bytes);
    [$tags_str, $buf] = buf_read_bytes($buf, $tags_len);

    $context = json_decode($context_str, true);
    if ($context === null) {
        $json_error = json_last_error_msg();
        throw new RuntimeException("Failed to decode context: '$context_str' (length: " . strlen($context_str) . ", JSON error: $json_error)");
    }

    $tags = json_decode($tags_str, true);
    if ($tags === null) {
        $json_error = json_last_error_msg();
        throw new RuntimeException("Failed to decode tags: '$tags_str' (length: " . strlen($tags_str) . ", JSON error: $json_error)");
    }

    $fd_count = count($data['control'][0]['data']);
    if ($fd_count <= 0) {
        throw new RuntimeException("No file descriptors received");
    }
    if (count($tags) !== $fd_count) {
        throw new RuntimeException("Mismatch between number of tags (" . count($tags) . ") and file descriptors ($fd_count)");
    }

    $sock_tag_pairs = [];
    foreach ($data['control'][0]['data'] as $i => $sock) {
        $sock_tag_pairs[] = [$sock, $tags[$i]];
    }

    return [$sock_tag_pairs, $context];
}
