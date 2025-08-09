<?php

namespace ragelord\ffi;

use FFI;
use RuntimeException;
use InvalidArgumentException;

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

function send_fds(string $socket_path, array $fd_tag_pairs, $context = null): bool {
    if (count($fd_tag_pairs) === 0) {
        throw new InvalidArgumentException("FD-tag pairs array cannot be empty");
    }

    $fd_count = count($fd_tag_pairs);

    // Extract fds and tags from pairs  
    $fds = [];
    $tags = [];
    foreach ($fd_tag_pairs as $pair) {
        if (!is_array($pair) || count($pair) !== 2) {
            throw new InvalidArgumentException("Each element must be a [fd, tag] pair");
        }
        [$fd, $tag] = $pair;
        $fds[] = $fd;
        $tags[] = $tag;
    }

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

    // Step 1: Send context as a separate message
    $context_json = json_encode($context);
    $context_len = strlen($context_json);
    
    // Pack length as 4 bytes little-endian, then copy JSON data
    $packed_data = pack('V', $context_len) . $context_json;

    // Send context message first
    $result = socket_sendmsg($sock, ['iov' => [$packed_data]]);
    if ($result === false) {
        $error = socket_strerror(socket_last_error($sock));
        socket_close($sock);
        throw new RuntimeException("socket_sendmsg() failed for context: $error");
    }

    // Step 2: Send tags with file descriptors
    $tags_json = json_encode($tags);
    $tags_len = strlen($tags_json);
    
    // Pack length as 4 bytes little-endian, then copy JSON data  
    $packed_tags_data = pack('V', $tags_len) . $tags_json;

    // We need to use FFI for file descriptor passing as socket_sendmsg expects Socket objects
    // but our API works with raw file descriptors
    $ffi = _unix_ffi();
    
    $tags_send_len = 4 + $tags_len;
    $tags_data = $ffi->new("unsigned char[$tags_send_len]");
    
    // Copy packed data to FFI buffer
    for ($i = 0; $i < $tags_send_len; $i++) {
        $tags_data[$i] = ord($packed_tags_data[$i]);
    }

    $iov = $ffi->new("struct iovec");
    $iov->iov_base = $ffi->cast("void*", FFI::addr($tags_data));
    $iov->iov_len = $tags_send_len;

    // Calculate control message size for multiple fds
    $cmsg_hdr_size = FFI::sizeof($ffi->new("struct cmsghdr"));
    $control_size = $cmsg_hdr_size + ($fd_count * 4); // 4 bytes per int fd

    // Allocate control buffer
    $control_buffer = $ffi->new("unsigned char[$control_size]");

    // Set up control message header
    $cmsg = $ffi->cast("struct cmsghdr*", FFI::addr($control_buffer));
    $cmsg->cmsg_len = $control_size;
    $cmsg->cmsg_level = SOL_SOCKET;
    $cmsg->cmsg_type = SCM_RIGHTS;

    // Copy file descriptors into control message data
    $data_ptr = $ffi->cast("int*", $ffi->cast("char*", $cmsg) + $cmsg_hdr_size);
    for ($i = 0; $i < $fd_count; $i++) {
        $data_ptr[$i] = $fds[$i];
    }

    $msg = $ffi->new("struct msghdr");
    $msg->msg_name = null;
    $msg->msg_namelen = 0;
    $msg->msg_iov = FFI::addr($iov);
    $msg->msg_iovlen = 1;
    $msg->msg_control = $ffi->cast("void*", $cmsg);
    $msg->msg_controllen = $control_size;
    $msg->msg_flags = 0;

    // Convert PHP Socket to fd for sendmsg (for file descriptor passing)
    $sock_fd = socket_to_fd($sock);
    if ($sock_fd === null) {
        socket_close($sock);
        throw new RuntimeException("Failed to get fd from socket for file descriptor transfer");
    }

    $result = $ffi->sendmsg($sock_fd, FFI::addr($msg), 0);

    if ($result < 0) {
        $errno = get_errno($ffi);
        socket_close($sock);
        throw new RuntimeException("sendmsg() failed for tags and fds: errno $errno");
    }

    socket_close($sock);
    return true;
}

function receive_fds(string $socket_path): array {
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

    // Convert PHP Socket to fd for recvmsg
    $client_fd = socket_to_fd($client_sock);
    if ($client_fd === null) {
        socket_close($client_sock);
        socket_close($listen_sock);
        throw new RuntimeException("Failed to get fd from client socket");
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
    
    // Allocate exact buffer for complete tags message (length + JSON)
    $tags_total_size = 4 + $tags_json_len;
    $tags_buffer = $ffi->new("char[$tags_total_size]");
    $tags_iov = $ffi->new("struct iovec");
    $tags_iov->iov_base = $ffi->cast("void*", FFI::addr($tags_buffer));
    $tags_iov->iov_len = $tags_total_size;

    // Allocate control buffer for file descriptors (estimate based on reasonable max FDs)
    $max_control_size = 256;
    $control_buffer = $ffi->new("unsigned char[$max_control_size]");
    
    $tags_msg = $ffi->new("struct msghdr");
    $tags_msg->msg_name = null;
    $tags_msg->msg_namelen = 0;
    $tags_msg->msg_iov = FFI::addr($tags_iov);
    $tags_msg->msg_iovlen = 1;
    $tags_msg->msg_control = $ffi->cast("void*", FFI::addr($control_buffer));
    $tags_msg->msg_controllen = $max_control_size;
    $tags_msg->msg_flags = 0;

    $tags_result = $ffi->recvmsg($client_fd, FFI::addr($tags_msg), 0);

    socket_close($client_sock);
    socket_close($listen_sock);
    if (file_exists($socket_path)) {
        unlink($socket_path);
    }

    if ($tags_result < 0) {
        throw new RuntimeException("recvmsg() failed for tags and fds: errno " . get_errno($ffi));
    }

    if ($tags_result < $tags_total_size) {
        throw new RuntimeException("Received tags message incomplete: got $tags_result bytes, expected $tags_total_size");
    }

    // Extract tags JSON data starting from byte 4
    $tags_str = '';
    for ($i = 4; $i < $tags_total_size; $i++) {
        $tags_str .= $tags_buffer[$i];
    }
    
    $tags = json_decode($tags_str, true);
    if ($tags === null) {
        $json_error = json_last_error_msg();
        throw new RuntimeException("Failed to decode tags: '$tags_str' (length: " . strlen($tags_str) . ", JSON error: $json_error)");
    }

    // Validate control message size
    $cmsg_hdr_size = FFI::sizeof($ffi->new("struct cmsghdr"));
    if ($tags_msg->msg_controllen < $cmsg_hdr_size) {
        throw new RuntimeException("Received control message too small: " . $tags_msg->msg_controllen);
    }

    $cmsg = $ffi->cast("struct cmsghdr*", FFI::addr($control_buffer));

    // Validate control message header
    if ($cmsg->cmsg_level !== SOL_SOCKET || $cmsg->cmsg_type !== SCM_RIGHTS) {
        throw new RuntimeException("Received invalid control message: level=" . $cmsg->cmsg_level . " type=" . $cmsg->cmsg_type);
    }

    // Calculate number of file descriptors
    $data_size = $cmsg->cmsg_len - $cmsg_hdr_size;
    $fd_count = intval($data_size / 4); // 4 bytes per int fd

    if ($fd_count <= 0) {
        throw new RuntimeException("No file descriptors received");
    }

    if (count($tags) !== $fd_count) {
        throw new RuntimeException("Mismatch between number of tags (" . count($tags) . ") and file descriptors ($fd_count)");
    }

    // Extract file descriptors and create [fd, tag] pairs
    $data_ptr = $ffi->cast("int*", $ffi->cast("char*", $cmsg) + $cmsg_hdr_size);
    $fd_tag_pairs = [];
    $max_fd = $ffi->getdtablesize();

    for ($i = 0; $i < $fd_count; $i++) {
        $fd = $data_ptr[$i];

        // Validate file descriptor
        if ($fd < 0 || $fd >= $max_fd) {
            throw new RuntimeException("Received invalid fd: $fd (must be 0 <= fd < $max_fd)");
        }

        $fd_tag_pairs[] = [$fd, $tags[$i]];
    }

    return [$fd_tag_pairs, $context];
}

function send_sockets(string $socket_path, array $socket_tag_pairs, $context = null): bool {
    if (count($socket_tag_pairs) === 0) {
        throw new InvalidArgumentException("Socket-tag pairs array cannot be empty");
    }

    $fd_tag_pairs = [];
    foreach ($socket_tag_pairs as $pair) {
        if (!is_array($pair) || count($pair) !== 2) {
            throw new InvalidArgumentException("Each element must be a [socket, tag] pair");
        }
        [$socket, $tag] = $pair;

        $fd = socket_to_fd($socket);
        if ($fd === null) {
            throw new RuntimeException("Failed to extract file descriptor from socket");
        }

        $fd_tag_pairs[] = [$fd, $tag];
    }

    return send_fds($socket_path, $fd_tag_pairs, $context);
}

function receive_sockets(string $socket_path): array {
    [$fd_tag_pairs, $context] = receive_fds($socket_path);
    
    $socket_tag_pairs = [];
    foreach ($fd_tag_pairs as [$fd, $tag]) {
        $socket = fd_to_socket($fd);
        if ($socket === null) {
            throw new RuntimeException("Failed to convert FD $fd to socket");
        }
        $socket_tag_pairs[] = [$socket, $tag];
    }
    
    return [$socket_tag_pairs, $context];
}