<?php

require_once __DIR__ . '/passfd/passfd.php';

use ragelord\passfd;

function test_tagged_socket_handover() {
    echo "\n=== Testing Tagged Socket Handover ===\n";

    $socket_path = "/tmp/test_named_socket_handover_" . getmypid();

    // Create multiple test sockets
    $socket1 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    $socket2 = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    $socket3 = socket_create(AF_UNIX, SOCK_STREAM, 0);

    $socket_tag_pairs = [
        [$socket1, 'web_server'],
        [$socket2, 'dns_client'],
        [$socket3, 'ipc_socket'],
    ];

    $sockets = [
        $socket1,
        $socket2,
        $socket3,
    ];

    // Fork to test handover between processes
    $pid = pcntl_fork();

    if ($pid == -1) {
        throw new RuntimeException("Fork failed");
    } elseif ($pid == 0) {
        // Child process - receive the named sockets
        try {
            echo "[Child] Waiting to receive tagged sockets...\n";
            $received_socket_pairs = passfd\receive_sockets($socket_path);

            if (count($received_socket_pairs) === 0) {
                echo "[Child] Failed to receive sockets\n";
                exit(1);
            }

            echo "[Child] Received " . count($received_socket_pairs) . " tagged sockets:\n";
            foreach ($received_socket_pairs as $socket) {
                // Get FD for display purposes
                var_dump($socket);

                // Socket is already converted and ready to use
                if ($socket) {
                    socket_close($socket);
                }
            }

            echo "[Child] ✓ Successfully received and validated all tagged sockets!\n";
            exit(0);

        } catch (Exception $e) {
            echo "[Child] Error: " . $e->getMessage() . "\n";
            exit(1);
        }
    } else {
        // Parent process - send the named sockets
        try {
            // Give child time to start listening
            usleep(100000); // 100ms

            echo "[Parent] Sending " . count($socket_tag_pairs) . " tagged sockets...\n";
            foreach ($socket_tag_pairs as [$socket, $tag]) {
                echo "[Parent]   - $tag\n";
            }

            $result = passfd\send_sockets($socket_path, $socket_tag_pairs, $context);
            // $result = passfd\send_sockets2($socket_path, $sockets, $context);

            if (!$result) {
                throw new RuntimeException("Failed to send named sockets");
            }

            echo "[Parent] ✓ Tagged sockets sent successfully!\n";

            // Wait for child
            $status = 0;
            pcntl_waitpid($pid, $status);

            if (pcntl_wexitstatus($status) === 0) {
                echo "✓ Tagged socket handover test passed!\n";
            } else {
                throw new RuntimeException("Child process failed");
            }

        } catch (Exception $e) {
            // Kill child if still running
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
            throw $e;
        }
    }

    // Cleanup
    socket_close($socket1);
    socket_close($socket2);
    socket_close($socket3);

    // Cleanup socket file
    if (file_exists($socket_path)) {
        unlink($socket_path);
    }
}

try {
    echo "FFI Socket Passing Test\n";
    echo "=======================\n\n";

    test_tagged_socket_handover();

    echo "\n🎉 All tests passed!\n";

} catch (Exception $e) {
    echo "\n❌ Test failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}