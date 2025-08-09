<?php

require_once __DIR__ . '/ffi/socket_fd.php';
require_once __DIR__ . '/ffi/socket_send_recv.php';

use ragelord\ffi;

function test_socket_fd_conversion() {
    echo "=== Testing Socket FD Conversion ===\n";
    
    // Create a socket pair for testing
    $sockets = [];
    if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets)) {
        throw new RuntimeException("socket_create_pair failed: " . socket_strerror(socket_last_error()));
    }
    
    [$sock1, $sock2] = $sockets;
    
    // Test socket_to_fd
    echo "Testing socket_to_fd...\n";
    $fd1 = ffi\socket_to_fd($sock1);
    $fd2 = ffi\socket_to_fd($sock2);
    
    if ($fd1 === null || $fd2 === null) {
        throw new RuntimeException("Failed to extract file descriptors");
    }
    
    echo "Socket 1 FD: $fd1\n";
    echo "Socket 2 FD: $fd2\n";
    
    // Test fd_to_socket
    echo "Testing fd_to_socket...\n";
    $new_sock1 = ffi\fd_to_socket($fd1);
    $new_sock2 = ffi\fd_to_socket($fd2);
    
    if ($new_sock1 === null || $new_sock2 === null) {
        throw new RuntimeException("Failed to convert FDs back to sockets");
    }
    
    // Test communication through converted sockets
    echo "Testing communication through converted sockets...\n";
    $test_message = "Hello from converted socket!";
    
    if (!socket_write($new_sock1, $test_message)) {
        throw new RuntimeException("Failed to write to converted socket");
    }
    
    $received = socket_read($new_sock2, strlen($test_message));
    if ($received !== $test_message) {
        throw new RuntimeException("Message mismatch: expected '$test_message', got '$received'");
    }
    
    echo "✓ Socket FD conversion test passed!\n";
    
    // Cleanup
    socket_close($sock1);
    socket_close($sock2);
    socket_close($new_sock1);
    socket_close($new_sock2);
}

function test_socket_handover() {
    echo "\n=== Testing Socket Handover ===\n";
    
    $socket_path = "/tmp/test_socket_handover_" . getmypid();
    
    // Create a test socket
    $test_sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if ($test_sock === false) {
        throw new RuntimeException("Failed to create test socket");
    }
    
    // Fork to test handover between processes
    $pid = pcntl_fork();
    
    if ($pid == -1) {
        throw new RuntimeException("Fork failed");
    } elseif ($pid == 0) {
        // Child process - receive the socket
        try {
            echo "[Child] Waiting to receive socket...\n";
            [$received_socket_pairs, $context] = ffi\receive_sockets($socket_path);
            
            if (count($received_socket_pairs) === 0) {
                echo "[Child] Failed to receive socket\n";
                exit(1);
            }
            
            echo "[Child] Received context: " . ($context ? json_encode($context) : 'null') . "\n";
            
            // Get the first (and only) socket pair
            [$received_sock, $socket_tag] = $received_socket_pairs[0];
            
            // Get FD for display purposes
            $received_fd = ffi\socket_to_fd($received_sock);
            echo "[Child] Received socket '$socket_tag' FD: $received_fd\n";
            
            echo "[Child] ✓ Successfully received and converted socket!\n";
            socket_close($received_sock);
            exit(0);
            
        } catch (Exception $e) {
            echo "[Child] Error: " . $e->getMessage() . "\n";
            exit(1);
        }
    } else {
        // Parent process - send the socket
        try {
            // Give child time to start listening
            usleep(100000); // 100ms
            
            echo "[Parent] Sending socket...\n";
            $context = ['server_name' => 'TestServer', 'pid' => getmypid(), 'timestamp' => time()];
            $result = ffi\send_sockets($socket_path, [[$test_sock, 'test_socket']], $context);
            
            if (!$result) {
                throw new RuntimeException("Failed to send socket");
            }
            
            echo "[Parent] ✓ Socket sent successfully!\n";
            
            // Wait for child
            $status = 0;
            pcntl_waitpid($pid, $status);
            
            if (pcntl_wexitstatus($status) === 0) {
                echo "✓ Socket handover test passed!\n";
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
    
    socket_close($test_sock);
    
    // Cleanup socket file
    if (file_exists($socket_path)) {
        unlink($socket_path);
    }
}

function test_tagged_socket_handover() {
    echo "\n=== Testing Tagged Socket Handover ===\n";
    
    $socket_path = "/tmp/test_named_socket_handover_" . getmypid();
    
    // Create multiple test sockets
    $socket1 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    $socket2 = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    $socket3 = socket_create(AF_UNIX, SOCK_STREAM, 0);
    
    if ($socket1 === false || $socket2 === false || $socket3 === false) {
        throw new RuntimeException("Failed to create test sockets");
    }
    
    $socket_tag_pairs = [
        [$socket1, 'web_server'],
        [$socket2, 'dns_client'], 
        [$socket3, 'ipc_socket']
    ];
    
    // Fork to test handover between processes
    $pid = pcntl_fork();
    
    if ($pid == -1) {
        throw new RuntimeException("Fork failed");
    } elseif ($pid == 0) {
        // Child process - receive the named sockets
        try {
            echo "[Child] Waiting to receive tagged sockets...\n";
            [$received_socket_pairs, $context] = ffi\receive_sockets($socket_path);
            
            if (count($received_socket_pairs) === 0) {
                echo "[Child] Failed to receive sockets\n";
                exit(1);
            }
            
            echo "[Child] Received context: " . ($context ? json_encode($context) : 'null') . "\n";
            echo "[Child] Received " . count($received_socket_pairs) . " tagged sockets:\n";
            foreach ($received_socket_pairs as [$socket, $tag]) {
                // Get FD for display purposes
                $fd = ffi\socket_to_fd($socket);
                echo "[Child]   - $tag: FD $fd\n";
                
                // Validate we received the expected tags
                if (!in_array($tag, ['web_server', 'dns_client', 'ipc_socket'])) {
                    echo "[Child] Unexpected socket tag: $tag\n";
                    exit(1);
                }
                
                // Socket is already converted and ready to use
                socket_close($socket);
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
            
            $context = [
                'application' => [
                    'name' => 'WebServerApplication',
                    'version' => '3.2.1-release',
                    'build_id' => 'build-2024-12-345',
                    'commit_hash' => 'a1b2c3d4e5f67890abcdef1234567890abcdef12'
                ],
                'runtime' => [
                    'pid' => getmypid(),
                    'started_at' => date('Y-m-d H:i:s.u'),
                    'uptime_seconds' => 0,
                    'memory_usage' => memory_get_usage(true),
                    'peak_memory' => memory_get_peak_usage(true)
                ],
                'configuration' => [
                    'environment' => 'production',
                    'debug_mode' => false,
                    'log_level' => 'info',
                    'max_connections' => 10000,
                    'request_timeout' => 30,
                    'ssl_enabled' => true,
                    'compression_enabled' => true,
                    'cache_enabled' => true
                ],
                'features' => [
                    'websockets', 'http2', 'grpc', 'compression',
                    'ssl_termination', 'load_balancing', 'metrics',
                    'health_checks', 'rate_limiting', 'caching'
                ],
                'endpoints' => [
                    'http' => 'localhost:8080',
                    'https' => 'localhost:8443', 
                    'grpc' => 'localhost:9090',
                    'metrics' => 'localhost:9091'
                ],
                'metadata' => [
                    'deployment_id' => 'deploy-prod-2024-001',
                    'cluster_name' => 'web-cluster-us-east-1',
                    'instance_id' => 'i-0123456789abcdef0',
                    'availability_zone' => 'us-east-1a',
                    'container_runtime' => 'docker-24.0.6'
                ]
            ];
            $result = ffi\send_sockets($socket_path, $socket_tag_pairs, $context);
            
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
    
    // Check if pcntl is available for fork test
    if (!function_exists('pcntl_fork')) {
        echo "Warning: pcntl extension not available, skipping handover test\n";
        test_socket_fd_conversion();
    } else {
        test_socket_fd_conversion();
        test_socket_handover();
        test_tagged_socket_handover();
    }
    
    echo "\n🎉 All tests passed!\n";
    
} catch (Exception $e) {
    echo "\n❌ Test failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}