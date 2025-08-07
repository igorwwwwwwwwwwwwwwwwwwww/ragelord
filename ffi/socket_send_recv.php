<?php

namespace ragelord\ffi;

function _unix_ffi(): FFI {
    static $ffi = null;
    if (!$ffi) {
        $header = file_get_contents(__DIR__ . "/unix_socket.h");

        // Platform-specific type definitions and functions
        if (PHP_OS_FAMILY === 'Darwin') {
            $header = "typedef unsigned int socklen_t;\ntypedef socklen_t cmsg_len_t;\n" . $header;
            $header .= "\nint *__error(void);";
        } else {
            $header = "typedef size_t cmsg_len_t;\n" . $header;
            $header .= "\nint *__errno_location(void);";
        }

        $ffi = FFI::cdef($header);
    }
    return $ffi;
}

function get_errno(FFI $ffi): int {
    return PHP_OS_FAMILY === 'Darwin' ? $ffi->__error()[0] : $ffi->__errno_location()[0];
}

function send_fd(string $socket_path, int $fd): bool {
    $ffi = _unix_ffi();

    // Use PHP socket functions for basic operations
    $sock = socket_create(AF_UNIX, SOCK_STREAM, 0);
    if ($sock === false) {
        throw new RuntimeException("socket_create() failed: " . socket_strerror(socket_last_error()));
    }

    if (!socket_connect($sock, $socket_path)) {
        $error = socket_strerror(socket_last_error($sock));
        socket_close($sock);
        throw new RuntimeException("socket_connect() failed: $error");
    }

    $data = $ffi->new("unsigned char[1]");
    $data[0] = 65;
    $iov = $ffi->new("struct iovec");
    $iov->iov_base = $ffi->cast("void*", FFI::addr($data));
    $iov->iov_len = 1;

    // Use combined struct for proper alignment
    $cmsg_fd = $ffi->new("struct cmsghdr_fd");

    $cmsg_fd->hdr->cmsg_len = FFI::sizeof($cmsg_fd);
    $cmsg_fd->hdr->cmsg_level = SOL_SOCKET;
    $cmsg_fd->hdr->cmsg_type = SCM_RIGHTS;
    $cmsg_fd->fd = $fd;

    $msg = $ffi->new("struct msghdr");
    $msg->msg_name = null;
    $msg->msg_namelen = 0;
    $msg->msg_iov = FFI::addr($iov);
    $msg->msg_iovlen = 1;
    $msg->msg_control = $ffi->cast("void*", FFI::addr($cmsg_fd));
    $msg->msg_controllen = FFI::sizeof($cmsg_fd);
    $msg->msg_flags = 0;

    // Convert PHP Socket to fd for sendmsg
    $sock_fd = socket_to_fd($sock);
    if ($sock_fd === null) {
        socket_close($sock);
        throw new RuntimeException("Failed to get fd from socket");
    }

    $result = $ffi->sendmsg($sock_fd, FFI::addr($msg), 0);

    if ($result < 0) {
        $errno = get_errno($ffi);
        socket_close($sock);
        throw new RuntimeException("sendmsg() failed: errno $errno");
    }

    socket_close($sock);

    return true;
}

function receive_fd(string $socket_path): ?int {
    $ffi = _unix_ffi();

    // Use PHP socket functions for basic operations
    $listen_sock = socket_create(AF_UNIX, SOCK_STREAM, 0);
    if ($listen_sock === false) {
        throw new RuntimeException("socket_create() failed: " . socket_strerror(socket_last_error()));
    }

    // Remove existing socket file
    if (file_exists($socket_path)) {
        if (!unlink($socket_path)) {
            throw new RuntimeException("Failed to remove existing socket file: $socket_path");
        }
    }

    if (!socket_bind($listen_sock, $socket_path)) {
        $error = socket_strerror(socket_last_error($listen_sock));
        socket_close($listen_sock);
        throw new RuntimeException("socket_bind() failed: $error");
    }

    if (!socket_listen($listen_sock, 1)) {
        $error = socket_strerror(socket_last_error($listen_sock));
        socket_close($listen_sock);
        throw new RuntimeException("socket_listen() failed: $error");
    }

    $client_sock = socket_accept($listen_sock);
    if ($client_sock === false) {
        $error = socket_strerror(socket_last_error($listen_sock));
        socket_close($listen_sock);
        throw new RuntimeException("socket_accept() failed: $error");
    }

    $buffer = $ffi->new("char[10]");
    $iov = $ffi->new("struct iovec");
    $iov->iov_base = $ffi->cast("void*", FFI::addr($buffer));
    $iov->iov_len = 10;

    $cmsg_fd = $ffi->new("struct cmsghdr_fd");
    $msg = $ffi->new("struct msghdr");
    $msg->msg_name = null;
    $msg->msg_namelen = 0;
    $msg->msg_iov = FFI::addr($iov);
    $msg->msg_iovlen = 1;
    $msg->msg_control = $ffi->cast("void*", FFI::addr($cmsg_fd));
    $msg->msg_controllen = FFI::sizeof($cmsg_fd);
    $msg->msg_flags = 0;

    // Convert PHP Socket to fd for recvmsg
    $client_fd = socket_to_fd($client_sock);
    if ($client_fd === null) {
        socket_close($client_sock);
        socket_close($listen_sock);
        throw new RuntimeException("Failed to get fd from client socket");
    }

    $result = $ffi->recvmsg($client_fd, FFI::addr($msg), 0);

    socket_close($client_sock);
    socket_close($listen_sock);
    if (file_exists($socket_path)) {
        unlink($socket_path);
    }

    if ($result < 0) {
        throw new RuntimeException("recvmsg() failed: errno " . get_errno($ffi));
    }

    // Validate control message size
    if ($msg->msg_controllen < FFI::sizeof($ffi->new("struct cmsghdr"))) {
        throw new RuntimeException("Received control message too small: " . $msg->msg_controllen);
    }

    // Validate control message header
    if ($cmsg_fd->hdr->cmsg_level !== SOL_SOCKET || $cmsg_fd->hdr->cmsg_type !== SCM_RIGHTS) {
        throw new RuntimeException("Received invalid control message: level=" . $cmsg_fd->hdr->cmsg_level . " type=" . $cmsg_fd->hdr->cmsg_type);
    }

    // Validate file descriptor (use same logic as PHP's php://fd/)
    $fd = $cmsg_fd->fd;
    $max_fd = $ffi->getdtablesize();
    if ($fd < 0 || $fd >= $max_fd) {
        throw new RuntimeException("Received invalid fd: $fd (must be 0 <= fd < $max_fd)");
    }

    return $fd;
}

function socket_handover_send(\Socket $socket, string $socket_path): bool
{
    $fd = socket_to_fd($socket);
    if ($fd === null) {
        throw new RuntimeException("Failed to extract file descriptor from socket");
    }

    return send_fd($socket_path, $fd);
}

function socket_handover_receive(string $socket_path): ?int
{
    return receive_fd($socket_path);
}