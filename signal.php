<?php

namespace ragelord;

function exception_error_handler(
    int $errno, string $errstr, ?string $errfile = null, ?int $errline  = null
) {
    if (!(error_reporting() & $errno)) {
        // This error code is not included in error_reporting
        return;
    }
    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
}

class SignalBuffer {
    public $r = null;
    public $w = null;

    function __construct(
        public $ch = new sync\Chan(),
        public $sigs = [],
    ) {
        $pair = [];
        if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
            throw new \RuntimeException(socket_strerror(socket_last_error()));
        }
        [$this->r, $this->w] = $pair;
        socket_set_nonblock($this->r);
        socket_set_nonblock($this->w);
    }

    // we cannot directly resume a fiber from a signal handler
    //   so we create a socket pair to resume via event loop instead.
    //
    // for this reason we must use socket_write() here directly.
    //   socket buffers are 8k in size, so we are very unlikely to fail
    //   notifying here, unless we manage to pile up 8k in unprocessed
    //   signals, lmao. (which we could deal with by storing these in
    //   a set and coalescing, just like linux does).
    //
    // note: signals handlers will not be called in a nested manner.
    //   if a signal comes in while a signal handler is running, that
    //   signal will be saved in the pending set, and its handler will
    //   be invoked once the current handler is done. multiple invocations
    //   of the same signal are coalesced.
    // result: handlers do not need to worry about concurrency, they are
    //   guaranteed to be serialized.
    //
    // ideally we would be able to monitor this via signalfd and stream_select,
    //   but php does not expose signalfd. and then we would also have to call
    //   socket_import_stream() or figure out how to do the inverse, because php
    //   does not have a generic select() api for fds. ffi could be an option, e.g.
    //   https://github.com/chopins/php-epoll/blob/a05eac8ff482c85e782b449e4e2a28592c09b9e6/src/Epoll.php#L190.
    function handler($signo) {
        $this->sigs[] = $signo;
        socket_write($this->w, '1');
    }

    // bottom half is in a Fiber, so we can use recv() here.
    function bottom_half() {
        go(function () {
            while (true) {
                while (count($this->sigs) === 0) {
                    $buf = null;
                    recv($this->r, $buf, 1);
                }

                while (($signo = array_shift($this->sigs)) !== null) {
                    $this->ch->send($signo);
                }
            }
        });
    }
}

function signo_name($signo) {
    foreach (get_defined_constants(true)['pcntl'] as $name => $num) {
        if ($num === $signo && strncmp($name, 'SIG', 3) === 0 && $name[3] !== '_') {
            return $name;
        }
    }
}
