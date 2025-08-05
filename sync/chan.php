<?php

namespace ragelord\sync;

use Fiber;
use function ragelord\read;
use function ragelord\write;

// TODO: buffered, i.e. block on max size or exception.
//         perhaps caller can indicate a preference. e.g. send_noblock vs send_block.
//       alternatively, we can always block and require caller to keep track of
//         pending size, and error out on its own.
//       the tricky bit here is: we want to cancel the receiver if it is not consuming
//         fast enough.
//       perhaps we can do something along the lines of:
//
//       broadcast:
//         select {
//           case ch->send(msg):
//             // success
//           default:
//             // consumer is too slow, buffer full
//             cancel writer
//         }
//
//       writer:
//         foreach (ch as msg) {
//           check cancel
//           write(msg);
//         }

// note: we assume a single receiver
class Chan implements \IteratorAggregate {
    function __construct(
        public $buf = [],
        public $waiter = null,
        public $r = null,
        public $w = null,
        public $closed = false,
    ) {
        $rw = [];
        if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $rw)) {
            throw new \RuntimeException(socket_strerror(socket_last_error()));
        }
        [$this->r, $this->w] = $rw;
        socket_set_nonblock($this->r);
        socket_set_nonblock($this->w);
    }

    // we cannot directly resume a fiber from a signal handler
    // so we create a socket pair to resume via event loop instead.
    function send($msg) {
        $this->buf[] = $msg;
        socket_write($this->w, '1');
    }

    function recv() {
        if ($this->closed && count($this->buf) === 0) {
            return null;
        }

        while (count($this->buf) === 0) {
            $buf = null;
            read($this->r, $buf, 1);
        }

        return array_shift($this->buf);
    }

    function getIterator(): \Traversable {
        while (($msg = $this->recv()) !== null) {
            yield $msg;
        }
    }

    // null represents channel close
    function close() {
        $this->closed = true;
        $this->buf[] = null;
        socket_write($this->w, '1');
    }
}
