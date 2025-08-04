<?php

namespace ragelord;

use Fiber;

const TIME_MICROSECONDS = 1_000_000;
const TIME_NANOSECONDS  = 1_000_000_000;
const SELECT_TIMEOUT = 10 * TIME_NANOSECONDS;

// NOTE: only one fiber can be waiting on a socket
class EngineState {
    public static $procs = [];

    public static $pending_read = [];
    public static $pending_read_waiter = [];
    public static $pending_write = [];
    public static $pending_write_waiter = [];

    public static $pending_sleep_heap;
}
EngineState::$pending_sleep_heap = new \SplMinHeap();

// TODO: remove pending reads and writes on exit
//       perhaps we can do this via finally block in the functions below.
function go(callable $f) {
    $fiber = new Fiber($f);
    EngineState::$procs[] = $fiber;
    $fiber->start();
    return $fiber;
}

// io. call from fiber
function accept($sock) {
    EngineState::$pending_read[spl_object_id($sock)] = $sock;
    EngineState::$pending_read_waiter[spl_object_id($sock)] = Fiber::getCurrent();

    Fiber::suspend();

    $client_sock = socket_accept($sock);
    socket_set_nonblock($client_sock);

    return $client_sock;
}

function read($sock, &$buf, $len) {
    EngineState::$pending_read[spl_object_id($sock)] = $sock;
    EngineState::$pending_read_waiter[spl_object_id($sock)] = Fiber::getCurrent();

    Fiber::suspend();

    return socket_recv($sock, $buf, $len, MSG_DONTWAIT);
}

function write($sock, $buf) {
    EngineState::$pending_write[spl_object_id($sock)] = $sock;
    EngineState::$pending_write_waiter[spl_object_id($sock)] = Fiber::getCurrent();

    Fiber::suspend();

    return socket_write($sock, $buf);
}

// from nanoseconds to [seconds, micros]
function time_from_nanos($duration_nanos) {
    $seconds = (int) floor($duration_nanos / TIME_NANOSECONDS);
    $micros = (int) (($duration_nanos % TIME_NANOSECONDS) / 1_000);
    return [$seconds, $micros];
}

// NOTE: we are shadowing sleep from stdlib
function sleep($duration_seconds) {
    $deadline = hrtime(true) + (int) ($duration_seconds * TIME_NANOSECONDS);
    EngineState::$pending_sleep_heap->insert([
        $deadline,
        Fiber::getCurrent(),
    ]);

    Fiber::suspend();
}

// TODO: remove clients here, look only at fibers, but infer fiber name
//         by reflecting on getCallable() / inferring start location from stack.
//       maybe have a global fiber -> name registry so we can associate socket name.
function client_backtrace() {
    if (!$client->fiber->isStarted()) {
        printf("%s [not started]\n", $client->name);
        printf("\n");
    } else if ($client->fiber->isTerminated()) {
        printf("%s [terminated]\n", $client->name);
        printf("\n");
    } else {
        printf("%s\n", $client->name);
        $r = new \ReflectionFiber($client->fiber);
        foreach ($r->getTrace() as $i => $frame) {
            $args = array_map(fn ($arg) => var_export($arg, true), $frame['args']);
            printf("#%d %s:%d %s%s%s(%s)\n", $i, $frame['file'] ?? '', $frame['line'] ?? '', $frame['class'] ?? '', $frame['type'] ?? '', $frame['function'] ?? '', implode(', ', $args));
        }
        printf("\n");
    }
}

function event_loop($sigbuf) {
    $clients = [];

    while (true) {
        // echo "loop\n";

        $read = array_values(EngineState::$pending_read);
        $write = array_values(EngineState::$pending_write);
        $except = null;

        $to_resume = [];
        while (!EngineState::$pending_sleep_heap->isEmpty()) {
            [$deadline, $fiber] = EngineState::$pending_sleep_heap->top();
            if ($deadline > hrtime(true)) {
                break;
            }
            EngineState::$pending_sleep_heap->extract();
            $to_resume[] = $fiber;
        }
        foreach ($to_resume as $fiber) {
            $fiber->resume();
        }

        $select_timeout = SELECT_TIMEOUT;
        if (!EngineState::$pending_sleep_heap->isEmpty()) {
            [$deadline, $fiber] = EngineState::$pending_sleep_heap->top();
            $now = hrtime(true);
            if ($deadline < ($now + $select_timeout)) {
                $select_timeout = $deadline - $now;
            }
        }
        if ($select_timeout < 0) {
            printf("warning: select timeout was negative: %d\n", $select_timeout/TIME_NANOSECONDS);
            $select_timeout = 0;
        }

        // hrtime(true) returns nanos, but socket_select expects micros
        [$seconds, $micros] = time_from_nanos($select_timeout);

        $changed = 0;
        try {
            // printf("> select read=%d write=%d timeout=%f\n", count($read), count($write), $select_timeout / TIME_NANOSECONDS);
            if ($read && count($read) || $write && count($write) || $except && count($except)) {
                $changed = socket_select($read, $write, $except, $seconds, $micros);
            } else {
                usleep(($seconds * TIME_MICROSECONDS) + $micros);
            }
        } catch (\ErrorException $e) {
            if (!str_contains($e->getMessage(), 'Interrupted system call')) {
                throw $e;
            }
        }

        if ($changed === false) {
            throw new \RuntimeException(socket_strerror(socket_last_error()));
        }

        // printf("< select changed=%d\n", $changed);

        if ($changed > 0) {
            // printf("< select read=%d write=%d\n", count($read), count($write));

            foreach ($read as $sock) {
                $fiber = EngineState::$pending_read_waiter[spl_object_id($sock)];
                unset(EngineState::$pending_read[spl_object_id($sock)]);
                unset(EngineState::$pending_read_waiter[spl_object_id($sock)]);
                $fiber->resume();
            }

            foreach ($write as $sock) {
                $fiber = EngineState::$pending_write_waiter[spl_object_id($sock)];
                unset(EngineState::$pending_write[spl_object_id($sock)]);
                unset(EngineState::$pending_write_waiter[spl_object_id($sock)]);
                $fiber->resume();
            }
        }
    }
}
