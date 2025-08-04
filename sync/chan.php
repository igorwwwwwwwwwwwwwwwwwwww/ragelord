<?php

namespace ragelord\sync;

use Fiber;
use function ragelord\read;
use function ragelord\write;

// TODO: buffered, i.e. block on max size or exception
// note: we assume a single receiver
// we cannot directly resume from a signal handler, so we create a socket pair indirection,
//   because apparently that's ok.
class Chan {
    function __construct(
        public $buf = [],
        public $waiter = null,
        public $r = null,
        public $w = null,
    ) {
        $rw = [];
        if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $rw)) {
            throw new \RuntimeException(socket_strerror(socket_last_error()));
        }
        [$this->r, $this->w] = $rw;
        socket_set_nonblock($this->r);
        socket_set_nonblock($this->w);
    }

    function send($msg) {
        $this->buf[] = $msg;
        $this->notify();
    }

    // TODO: iterator/generator
    function recv() {
        while (count($this->buf) === 0) {
            $buf = null;
            read($this->r, $buf, 1);
        }

        return array_shift($this->buf);
    }

    function notify() {
        socket_write($this->w, '1');
    }
}
