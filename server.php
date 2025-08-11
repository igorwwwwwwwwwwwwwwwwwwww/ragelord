<?php

namespace ragelord;

class Server {
    public $name;
    public $listener_fiber;

    function __construct(
        public $sock,
        public $state,
        public $replay = false,
        public $sessions = new \WeakMap(),
    ) {}

    function run() {
        $this->name = server_socket_name($this->sock);
        $this->listener_fiber = go(fn () => $this->listener());
    }

    function listener() {
        try {
            while (true) {
                [$client_sock, $name] = $this->accept();

                $sess = new Session($name, $client_sock, $this->state, $this->replay);
                $sess->run();

                $this->sessions[$sess] = $sess;
            }
        } finally {
            socket_close($this->sock);
        }
    }

    function accept() {
        if ($this->replay) {
            server_socket_name($this->sock);
            return Fiber::suspend();
        }

        $client_sock = accept_debounce($this->sock);
        $name = client_socket_name($client_sock);
        return [$client_sock, $name];
    }

    function accept_resume($sock) {
        if (!$this->replay || !$this->listener_fiber) {
            throw new \RuntimeException('cannot resume from non-replay context');
        }

        $this->listener_fiber->resume($sock);
    }

    function get_client_socks() {
        $socks = [];
        foreach ($this->sessions as $sess) {
            $socks[] = $sess->sock;
        }
        return $socks;
    }

    function shutdown() {
        if ($this->listener_fiber) {
            $this->listener_fiber->throw(new \RuntimeException(sprintf("initiating shutdown")));
        }
    }
}
