<?php

$socket_path = "/tmp/test_socket_handover_" . getmypid();
$pid = pcntl_fork();

if ($pid == -1) {
    throw new RuntimeException('Fork failed');
} elseif ($pid == 0) {
    $listen_sock = socket_create(AF_UNIX, SOCK_STREAM, 0);
    if ($listen_sock === false) {
        $error = socket_strerror(socket_last_error($listen_sock));
        throw new RuntimeException("socket_create() failed: $error");
    }
    if (file_exists($socket_path)) {
        if (!unlink($socket_path)) {
            throw new RuntimeException("Failed to remove existing socket file: $socket_path");
        }
    }
    if (!socket_bind($listen_sock, $socket_path)) {
        $error = socket_strerror(socket_last_error($listen_sock));
        throw new RuntimeException("socket_bind() failed: $error");
    }
    if (!socket_listen($listen_sock, 1)) {
        $error = socket_strerror(socket_last_error($listen_sock));
        throw new RuntimeException("socket_listen() failed: $error");
    }

    $sock = socket_accept($listen_sock);
    if ($sock === false) {
        $error = socket_strerror(socket_last_error($listen_sock));
        throw new RuntimeException("socket_accept() failed: $error");
    }

    $data = [
        'controllen' => socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS, 256),
    ];
    socket_recvmsg($sock, $data, 0);
    var_dump($data);
} else {
    usleep(100000); // 100ms

    $sock = socket_create(AF_UNIX, SOCK_STREAM, 0);
    if ($sock === false) {
        $error = socket_strerror(socket_last_error($sock));
        throw new RuntimeException("socket_create() failed: $error");
    }
    if (!socket_connect($sock, $socket_path)) {
        $error = socket_strerror(socket_last_error($sock));
        throw new RuntimeException("socket_connect() failed: $error");
    }

    $sockets_to_send = [
        socket_create(AF_INET, SOCK_STREAM, SOL_TCP),
        socket_create(AF_INET, SOCK_DGRAM, SOL_UDP),
        socket_create(AF_UNIX, SOCK_STREAM, 0),
    ];
    $stream_resources = array_map(fn ($sock) => socket_export_stream($sock), $sockets_to_send);

    $data = [
        'iov' => [''],
        'control' => [[
            'level' => SOL_SOCKET,
            'type' => SCM_RIGHTS,
            'data' => $stream_resources,
        ]],
    ];
    socket_sendmsg($sock, $data, 0);
    $status = 0;
    pcntl_waitpid($pid, $status);
    $exit = pcntl_wexitstatus($status);
    if ($exit !== 0) {
        throw new RuntimeException("child process failed with exit code $exit");
    }
}
