<?php

namespace ragelord;

require 'socket.php';
require 'log/log.php';
require 'state.php';
require 'proto.php';
require 'server.php';
require 'session.php';
require 'sched.php';
require 'sync/chan.php';
require 'passfd/passfd.php';
require 'signal.php';
require 'color.php';

const UPGRADE_LOCK_FILE = '/tmp/ragelord.upgrade.lock';
const UPGRADE_SOCK_FILE = '/tmp/ragelord.upgrade.sock';

set_error_handler(exception_error_handler(...));

$sigbuf = new SignalBuffer();
pcntl_async_signals(true);
pcntl_signal(SIGINT, [$sigbuf, 'handler']);
pcntl_signal(SIGTERM, [$sigbuf, 'handler']);
if (defined('SIGINFO')) {
    // macos only
    pcntl_signal(SIGINFO, [$sigbuf, 'handler']);
}
$sigbuf->bottom_half();

// TODO: implement gracceful termination
go(function () use ($sigbuf) {
    $servers = [];

    $sigbuf_fiber = go(function () use ($sigbuf, &$servers) {
        foreach ($sigbuf->ch as $signo) {
            printf("received signal: %s\n", signo_name($signo));
            if (defined('SIGINFO') && $signo === SIGINFO) {
                engine_print_backtrace();
                debug_print_backtrace();
                continue;
            }
            switch ($signo) {
                case SIGINT: // fallthru
                case SIGTERM:
                    foreach ($servers as $server) {
                        $servers->shutdown();
                    }
                    // TODO: this is currently broken, not shutting down properly, so we force an exit
                    exit(1);
                    return;
            }
        }
    });

    $upgrade_lock = null;
    if (file_exists(UPGRADE_SOCK_FILE) && !file_exists(UPGRADE_LOCK_FILE)) {
        unlink(UPGRADE_SOCK_FILE);
    }
    if (file_exists(UPGRADE_LOCK_FILE)) {
        $fp = fopen(UPGRADE_LOCK_FILE, 'r');
        if (flock($fp, LOCK_EX | LOCK_NB)) {
            // lock file exists but is not held, let's delete it
            unlink(UPGRADE_LOCK_FILE);
            if (file_exists(UPGRADE_SOCK_FILE)) {
                unlink(UPGRADE_SOCK_FILE);
            }
        }
        fclose($fp);
        $fp = null;
    }

    if (file_exists(UPGRADE_SOCK_FILE)) {
        // upgrade: receive
        if (debug_enabled('upgrade')) {
            printf(Color::YELLOW->colorize("upgrade: receive\n"));
        }

        $upgrade_client_sock = connect_unix(UPGRADE_SOCK_FILE);
        [$sockets, $context] = passfd\receive_sockets($upgrade_client_sock);
        socket_close($upgrade_client_sock);

        // TODO: macos introduces second delay here?
        //       kqueue may handle this better.

        if (debug_enabled('upgrade')) {
            printf(Color::YELLOW->colorize(sprintf("sockets: %s\n", count($sockets))));
            printf(Color::YELLOW->colorize(sprintf("context: %s\n", $context)));
        }

        $replay = true;

        $server_socks = [];
        $client_socks = [];

        foreach ($sockets as [$sock, $tag]) {
            switch ($tag) {
                case 'upgrade_lock':
                    $upgrade_lock = $sock;
                case 'upgrade':
                    $upgrade_sock = $sock;
                    break;
                case 'server':
                    $server_socks[] = $sock;
                    break;
                case 'client':
                    $client_socks[] = $sock;
                    break;
            }
        }

        // TODO: storing the log in a single recvmsg packet is not great.
        //       we should probably instead give an indication of how much
        //       data to read after the recvmsg and then treat _that_ as
        //       the context. that also avoids having to worry about buffer
        //       sizes and static string allocation.
        //       in fact we can stream the log incrementally, which is much
        //       better. same for if we want to send a huge snapshot.

        log\LogState::$log = unserialize($context, ['allowed_classes' => [
            'ragelord\log\Log',
            'ragelord\log\LogRecord',
        ]]);

        // TODO: check for __PHP_Incomplete_Class

        // TODO: handle pending reads/writes. they are no in the log yet.
        //       can we transfer those via context?
        // we could actually inject these into the log stream as a custom
        // record type. perhaps added at the very end. sort of like aux data.

        $state = new ServerState();
    } else {
        // clean init
        if (debug_enabled('upgrade')) {
            printf(Color::YELLOW->colorize("upgrade: init\n"));
        }

        $upgrade_lock = fopen(UPGRADE_LOCK_FILE, 'c');
        if (!flock($upgrade_lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('could not obtain upgrade lock');
        }

        $replay = false;
        $server_socks = [];
        $client_socks = [];

        $upgrade_sock = listen_unix(UPGRADE_SOCK_FILE);
        $server_socks = [
            // listen4('127.0.0.1', 6667),
            // listen6('::1', 6667),
            listen4('0.0.0.0', 6667),
        ];
        $state = new ServerState();
    }

    // upgrade: send
    go(function () use ($sigbuf_fiber, $upgrade_lock, $upgrade_sock, $servers) {
        $upgrade_client_sock = accept_debounce($upgrade_sock);

        if (debug_enabled('upgrade')) {
            printf(Color::YELLOW->colorize("upgrade: send\n"));
        }

        $server_socks = [];
        $client_socks = [];
        foreach ($servers as $server) {
            $server_socks[] = $server->sock;
            $client_socks = array_merge($client_socks, $server->get_client_socks());
        }

        foreach ($server_socks as $server_sock) {
            pause($server_sock);
        }
        foreach ($client_socks as $client_sock) {
            pause($client_sock);
        }

        $sockets = [];
        $sockets[] = [$upgrade_lock, 'upgrade_lock'];
        $sockets[] = [$upgrade_sock, 'upgrade'];
        foreach ($server_socks as $server_sock) {
            $sockets[] = [$server_sock, 'server'];
        }
        foreach ($client_socks as $client_sock) {
            $sockets[] = [$client_sock, 'client'];
        }

        // TODO: encode state version for compatibility
        $context = serialize(log\LogState::$log);

        if (debug_enabled('upgrade')) {
            printf(Color::YELLOW->colorize(sprintf("sockets: %s\n", count($sockets))));
            printf(Color::YELLOW->colorize(sprintf("context: $context\n")));
        }

        passfd\send_sockets($upgrade_client_sock, $sockets, $context);
        exit(0); // TODO: make this exit cleanly
    });

    foreach ($server_socks as $server_sock) {
        $server = new Server($server_sock, $state, $replay);
        $server->run();
        $servers[] = $server;
    }

    if ($replay) {
        log\replay($servers, $server_socks, $client_socks);
    }
});

event_loop();
