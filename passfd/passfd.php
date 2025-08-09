<?php

namespace ragelord\passfd;

use RuntimeException;
use InvalidArgumentException;

use function ragelord\recvmsg;
use function ragelord\sendmsg;
use function ragelord\debug_enabled;

function buf_read_bytes($buf, $len) {
    $bytes = substr($buf, 0, $len);
    $rest = substr($buf, $len);
    return [$bytes, $rest];
}

function decode_uint32($bytes) {
    return unpack('V', $bytes)[1];
}

function encode_uint32($num) {
    return pack('V', $num);
}

function send_sockets($upgrade_sock, array $socket_tag_pairs, $context_str = ''): bool {
    if (count($socket_tag_pairs) === 0) {
        throw new InvalidArgumentException("socket-tag pairs cannot be empty");
    }

    $stream_resources = [];
    $tags = [];
    foreach ($socket_tag_pairs as [$sock, $tag]) {
        // TODO: enforce tag format, must not contain ","
        if ($sock instanceof \Socket) {
            $stream = socket_export_stream($sock);
            if ($stream === false) {
                throw new RuntimeException("failed to export socket to stream");
            }
        } else if (is_resource($sock)) {
            // support for file descriptors
            $stream = $sock;
        } else {
            throw new \InvalidArgumentException('invalid fd provided');
        }

        $stream_resources[] = $stream;
        $tags[] = $tag;
    }

    $tags_str = implode(',', $tags);

    $msg = [
        'iov' => [
            encode_uint32(strlen($context_str)),
            $context_str,
            encode_uint32(strlen($tags_str)),
            $tags_str,
        ],
        'control' => [[
            'level' => SOL_SOCKET,
            'type' => SCM_RIGHTS,
            'data' => $stream_resources,
        ]],
    ];
    socket_sendmsg($upgrade_sock, $msg, 0);

    if (debug_enabled('passfd')) {
        printf("passfd: sendmsg\n");
        var_dump($msg);
    }

    socket_close($upgrade_sock);
    return true;
}

function receive_sockets($client_sock): array {
    // maximum context+tags: 4k
    // maximum fd count: 256
    // TODO: validate on send
    $msg = [
        'buffer_size' => 4096,
        'controllen'  => socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS, 256),
    ];
    $result = recvmsg($client_sock, $msg);

    if ($result < 0) {
        $error = socket_strerror(socket_last_error($client_sock));
        throw new RuntimeException("recvmsg() failed for tags and fds: $error");
    }
    // TODO: check TRUNC flag

    if (debug_enabled('passfd')) {
        printf("passfd: recvmsg\n");
        var_dump($msg);
    }

    if (!count($msg['iov'])) {
        throw new RuntimeException("recvmsg() was empty");
    }

    $buf = $msg['iov'][0];
    [$bytes, $buf] = buf_read_bytes($buf, 4);
    $context_len = decode_uint32($bytes);
    [$context_str, $buf] = buf_read_bytes($buf, $context_len);

    [$bytes, $buf] = buf_read_bytes($buf, 4);
    $tags_len = decode_uint32($bytes);
    [$tags_str, $buf] = buf_read_bytes($buf, $tags_len);

    $tags = explode(',', $tags_str);

    $fd_count = count($msg['control'][0]['data']);
    if ($fd_count <= 0) {
        throw new RuntimeException("no file descriptors received");
    }
    if (count($tags) !== $fd_count) {
        throw new RuntimeException("mismatch between number of tags (" . count($tags) . ") and file descriptors ($fd_count)");
    }

    $sock_tag_pairs = [];
    foreach ($msg['control'][0]['data'] as $i => $upgrade_sock) {
        $sock_tag_pairs[] = [$upgrade_sock, $tags[$i]];
    }

    return [$sock_tag_pairs, $context_str];
}
