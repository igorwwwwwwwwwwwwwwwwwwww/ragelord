<?php

namespace ragelord\sync;

use Fiber;

// TODO: buffered, i.e. block on max size or exception
// note: we assume a single receiver
class Channel {
    function __construct(
        public $buf = [],
        public $waiter = null,
    ) {}

    function send($msg) {
        $this->buf[] = $msg;
        $this->notify();
    }

    function recv() {
        if ($this->buf) {
            return array_shift($this->buf);
        }

        if ($this->waiter) {
            throw new \RuntimeException('somebody else is alread waiting on this channel');
        }

        $this->waiter = Fiber::getCurrent();
        return Fiber::suspend();
    }

    function notify() {
        if ($this->waiter) {
            $fiber = $this->waiter;
            $this->waiter = null;
            $fiber->resume(array_shift($this->buf));
        }
    }
}
