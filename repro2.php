<?php

set_error_handler(function (int $errno, string $errstr, ?string $errfile = null, ?int $errline  = null) {
    if (error_reporting() & $errno) {
        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    }
});

function run_in_child(callable $fn) {
    $pid = pcntl_fork();
    if ($pid == -1) {
        throw new RuntimeException("Fork failed");
    }
    if ($pid == 0) {
        // child
        $fn();
        exit(0);
    }
    // parent
    return $pid;
}

function send_sockets($parent_pid, $sockets_to_send) {
    $socket_path = "/tmp/test_socket_handover_" . $parent_pid;

    $listen_sock = socket_create(AF_UNIX, SOCK_STREAM, 0);
    socket_bind($listen_sock, $socket_path);
    socket_listen($listen_sock, 1);

    $sender = socket_accept($listen_sock);

    $stream_resources = array_map(fn ($sock) => socket_export_stream($sock), $sockets_to_send);

    $data = [
        'iov' => [''],
        'control' => [[
            'level' => SOL_SOCKET,
            'type' => SCM_RIGHTS,
            'data' => $stream_resources,
        ]],
    ];
    $res = socket_sendmsg($sender, $data, 0);
}

function receive_sockets($parent_pid) {
    $socket_path = "/tmp/test_socket_handover_" . $parent_pid;

    $receiver = socket_create(AF_UNIX, SOCK_STREAM, 0);
    socket_connect($receiver, $socket_path);

    $data = [
        'controllen' => socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS, 256),
    ];
    $res = socket_recvmsg($receiver, $data, 0);

    return $data['control'][0]['data'];
}

function server($parent_pid, $server_version, $sockets = null) {
    if ($sockets) {
        $server = array_shift($sockets);
        $clients = $sockets;
    } else {
        $server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_nonblock($server);
        socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($server, '127.0.0.1', 9000);
        socket_listen($server, 512);

        $clients = [];
    }

    echo "s $server_version: boot\n";

    while (true) {
        $read = array_merge([$server], $clients);
        $write = null;
        $except = null;
        if (socket_select($read, $write, $except, 10)) {
            foreach ($read as $r) {
                if ($r === $server) {
                    $clients[] = socket_accept($server);
                    echo "s $server_version: accept\n";
                } else {
                    $client = $r;
                    $buf = socket_read($client, 1024);
                    if ($buf === '') {
                        socket_close($client);
                        echo "s $server_version: close\n";
                        $i = array_search($client, $clients);
                        if ($i !== false) {
                            unset($clients[$i]);
                        }
                        continue;
                    }
                    if (trim($buf) === 'upgrade') {
                        echo "s $server_version: upgrade\n";
                        break 2;
                    }
                    echo "s $server_version: < $buf\n";
                    socket_write($client, $buf);
                    echo "s $server_version: > $buf\n";
                }
            }
        }
    }

    echo "s $server_version: sending sockets\n";
    send_sockets($parent_pid, array_merge([$server], $clients));
    echo "s $server_version: sockets sent\n";
}

function client_count() {
    $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    socket_connect($client, '127.0.0.1', 9000);

    foreach (range(0, 60) as $i) {
        $buf = "$i";
        socket_write($client, $buf);
        // echo "c: > $buf\n";
        $buf = socket_read($client, 1024);
        // echo "c: < $buf\n";
        sleep(1);
    }
}

function client_upgrade() {
    $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    socket_connect($client, '127.0.0.1', 9000);

    $buf = "upgrade";
    socket_write($client, $buf);
    // echo "c: > $buf\n";
}

$parent_pid = getmypid();

$pids[] = run_in_child(function () use ($parent_pid) {
    server($parent_pid, 1);
});

$pids[] = run_in_child(function () use ($parent_pid) {
    client_count();
});

$pids[] = run_in_child(function () use ($parent_pid) {
    sleep(5);
    client_upgrade();
    sleep(1);
    $sockets = receive_sockets($parent_pid);
    server($parent_pid, 2, $sockets);
});

foreach ($pids as $pid) {
    $status = 0;
    pcntl_waitpid($pid, $status);
}
