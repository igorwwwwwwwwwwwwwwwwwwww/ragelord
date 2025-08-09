<?php

namespace ragelord\passfd;

use RuntimeException;
use InvalidArgumentException;

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

    // Step 1: Send context as a separate message
    $context_json = json_encode($context);
    $context_len = strlen($context_json);

    // Pack length as 4 bytes little-endian, then copy JSON data
    $packed_data = pack('V', $context_len) . $context_json;

    // Send context message first
    socket_sendmsg($sock, ['iov' => [$packed_data]]);

    // Step 2: Send tags with file descriptors
    $tags_json = json_encode($tags);
    $tags_len = strlen($tags_json);

    // Pack length as 4 bytes little-endian, then copy JSON data
    $packed_tags_data = pack('V', $tags_len) . $tags_json;

    var_dump('sendmsg', $stream_resources);

    socket_sendmsg($sock, [
        'iov' => [$packed_tags_data],
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

    // Step 1: Receive context message - peek at length first, then allocate exact buffer
    $peek_data = '';
    $peek_result = socket_recv($client_sock, $peek_data, 4, MSG_PEEK);

    if ($peek_result === false) {
        $error = socket_strerror(socket_last_error($client_sock));
        socket_close($client_sock);
        socket_close($listen_sock);
        if (file_exists($socket_path)) {
            unlink($socket_path);
        }
        throw new RuntimeException("socket_recv() failed for context length peek: $error");
    }

    if (strlen($peek_data) < 4) {
        // TODO: do this in finally
        socket_close($client_sock);
        socket_close($listen_sock);
        if (file_exists($socket_path)) {
            unlink($socket_path);
        }
        throw new RuntimeException("Cannot peek context length: got " . strlen($peek_data) . " bytes, expected 4");
    }

    // Extract context length from peek
    $context_json_len = unpack('V', $peek_data)[1];

    // Now receive the complete context message using socket_recv
    $context_total_size = 4 + $context_json_len;
    $context_data = '';
    $context_result = socket_recv($client_sock, $context_data, $context_total_size, 0);

    if ($context_result === false) {
        $error = socket_strerror(socket_last_error($client_sock));
        socket_close($client_sock);
        socket_close($listen_sock);
        if (file_exists($socket_path)) {
            unlink($socket_path);
        }
        throw new RuntimeException("socket_recv() failed for context: $error");
    }

    if (strlen($context_data) < $context_total_size) {
        socket_close($client_sock);
        socket_close($listen_sock);
        if (file_exists($socket_path)) {
            unlink($socket_path);
        }
        throw new RuntimeException("Received context message incomplete: got " . strlen($context_data) . " bytes, expected $context_total_size");
    }

    // Extract context JSON data starting from byte 4
    $context_str = substr($context_data, 4, $context_json_len);

    $context = json_decode($context_str, true);
    if ($context === null) {
        $json_error = json_last_error_msg();
        socket_close($client_sock);
        socket_close($listen_sock);
        if (file_exists($socket_path)) {
            unlink($socket_path);
        }
        throw new RuntimeException("Failed to decode context: '$context_str' (length: " . strlen($context_str) . ", JSON error: $json_error)");
    }

    // Step 2: Receive tags and file descriptors message - peek at length first
    $tags_peek_data = '';
    $tags_peek_result = socket_recv($client_sock, $tags_peek_data, 4, MSG_PEEK);

    if ($tags_peek_result === false) {
        $error = socket_strerror(socket_last_error($client_sock));
        socket_close($client_sock);
        socket_close($listen_sock);
        if (file_exists($socket_path)) {
            unlink($socket_path);
        }
        throw new RuntimeException("socket_recv() failed for tags length peek: $error");
    }

    if (strlen($tags_peek_data) < 4) {
        socket_close($client_sock);
        socket_close($listen_sock);
        if (file_exists($socket_path)) {
            unlink($socket_path);
        }
        throw new RuntimeException("Cannot peek tags length: got " . strlen($tags_peek_data) . " bytes, expected 4");
    }

    // Extract tags length from peek
    $tags_json_len = unpack('V', $tags_peek_data)[1];

    $tags_total_size = 4 + $tags_json_len;
    $data = [
        'buffer_size' => $tags_total_size,
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

    if ($tags_result < $tags_total_size) {
        throw new RuntimeException("Received tags message incomplete: got $tags_result bytes, expected $tags_total_size");
    }

    // Extract tags JSON data starting from byte 4
    $tags_str = substr($data['iov'][0], 4);

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

    var_dump('recvmsg', $data['control'][0]['data']);

    $sock_tag_pairs = [];
    foreach ($data['control'][0]['data'] as $i => $sock) {
        $sock_tag_pairs[] = [$sock, $tags[$i]];
    }

    return [$sock_tag_pairs, $context];
}
