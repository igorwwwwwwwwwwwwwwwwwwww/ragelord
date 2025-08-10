<?php

namespace ragelord;

require 'socket.php';
require 'state.php';
require 'proto.php';
require 'server.php';
require 'sched.php';
require 'sync/chan.php';
require 'passfd/passfd.php';
require 'signal.php';
require 'color.php';

const UPGRADE_LOCK_FILE = '/tmp/ragelord.upgrade.lock';
const UPGRADE_SOCK_FILE = '/tmp/ragelord.upgrade.sock';

class UpgradeInitiatedException extends \Exception {}

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
    $server_fibers = [];

    $sigbuf_fiber = go(function () use ($sigbuf, &$server_fibers) {
        try {
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
                        foreach ($server_fibers as $fiber) {
                            $fiber->throw(new \RuntimeException(sprintf("received signal: %s\n", signo_name($signo))));
                        }
                        return;
                }
            }
        } catch (UpgradeInitiatedException $e) {
            // noop
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

        $upgrade_sock = null;
        $server_socks = [];
        $client_socks = new \WeakMap();

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
                    $client_socks[spl_object_id($sock)] = $sock;
                    break;
            }
        }

        // TODO: unserialize sessions here as well
        //       so that we only need to perform the socket
        //       mapping there.
        //
        // we could also consider implementing more of an erlang style
        //   upgrade flow where everything is in state machine and msg
        //   receive. tricky for local state though since we likely
        //   cannot migrate the fiber state to the new process.
        $state = unserialize($context, ['allowed_classes' => [
            'ragelord\ServerState',
            'ragelord\Channel',
            'ragelord\User',
        ]]);

        foreach ($client_socks as $client_sock) {
            $user = $state->socket_nick[socket_name($client_sock)] ?? null;
            if (!$user) {
                // user was caught in registration flow
                // sacrifice.
                socket_close($client_sock);
                continue;
            }
            $sess = new Session(
                socket_name($client_sock),
                $client_sock,
                $state,
                $user,
            );
            $state->register($user, $sess);
            go(fn () => $sess->reader());
            go(fn () => $sess->writer());
        }
    } else {
        // clean init
        if (debug_enabled('upgrade')) {
            printf(Color::YELLOW->colorize("upgrade: init\n"));
        }

        $upgrade_lock = fopen(UPGRADE_LOCK_FILE, 'c');
        if (!flock($upgrade_lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('could not obtain upgrade lock');
        }

        $upgrade_sock = listen_unix(UPGRADE_SOCK_FILE);
        $server_socks = [
            // listen4('127.0.0.1', 6667),
            // listen6('::1', 6667),
            listen4('0.0.0.0', 6667),
        ];
        $client_socks = new \WeakMap();
        $state = new ServerState();
    }

    // upgrade: send
    $server_fibers[] = go(function () use ($sigbuf_fiber, $upgrade_lock, $upgrade_sock, $server_socks, $client_socks, $state) {
        $upgrade_client_sock = accept_debounce($upgrade_sock);

        if (debug_enabled('upgrade')) {
            printf(Color::YELLOW->colorize("upgrade: send\n"));
        }

        $sigbuf_fiber->throw(new UpgradeInitiatedException());
        foreach ($server_socks as $server_sock) {
            pause($server_sock);
        }

        // TODO: pause client sockets

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
        $context = serialize($state);

        passfd\send_sockets($upgrade_client_sock, $sockets, $context);
        exit(0); // TODO: make this exit cleanly
    });

    foreach ($server_socks as $server_sock) {
        $server_fibers[] = go(function () use ($server_sock, $state) {
            try {
                while (true) {
                    $client_sock = accept_debounce($server_sock);
                    $client_socks[spl_object_id($client_sock)] = $client_sock;
                    go(function () use ($state, $client_sock) {
                        $name = socket_name($client_sock);
                        $sess = new Session($name, $client_sock, $state);
                        go(fn () => $sess->reader());
                        go(fn () => $sess->writer());
                    });
                }
            } catch (UpgradeInitiatedException $e) {
                // noop
            } finally {
                socket_close($server_sock);
            }
        });
    }
});

event_loop();
