<?php

namespace ragelord;

use Fiber;

const LISTEN_PORT = 6667;
const LISTEN_BACKLOG  = 512;

const SELECT_TIMEOUT_SEC  = 10;
const SELECT_TIMEOUT_USEC = 0;

const CLIENT_READ_SIZE = 1024;
const CLIENT_LINE_FEED = "\n"; // "\r\n"

function client_socket_name($sock) {
    socket_getpeername($sock, $addr, $port);
    return "$addr:$port";
}

enum ClientState {
    case IDLE;
    case WAIT_READ;
    case WAIT_WRITE;
    case CLOSED;
    case ERROR;
}

class Client {
    function __construct(
        public $name,
        public $sock,
        public ClientState $state = CLientState::IDLE,
        public $readbuf = '',
        public $writebuf = '',
        public $fiber = null,
    ) {}

    function start() {
        $this->fiber = new Fiber([$this, 'fiber']);
        $this->fiber->start();
    }

    function fiber() {
        echo "{$this->name}: starting fiber\n";

        while (true) {
            $msg = $this->read();
            echo "{$this->name}: got msg: $msg\n";
        }
    }

    function read() {
        echo "read\n";
        if ($this->state !== ClientState::IDLE) {
            throw new \RuntimeException(sprintf('invalid state, expected IDLE, got: %s', $this->state));
        }

        // we already have a message buffered, short circuit
        $msg = $this->readbuf_get_msg();
        if ($msg !== null) {
            return $msg;
        }

        $this->state = ClientState::WAIT_READ;
        return Fiber::suspend();
    }

    function write($data) {
        echo "write\n";
        if ($this->state !== ClientState::IDLE) {
            throw new \RuntimeException(sprintf('invalid state, expected IDLE, got: %s', $this->state));
        }
        $this->state = ClientState::WAIT_WRITE;
        $this->writebuf = $data;
        Fiber::suspend();
    }

    function serve_read() {
        echo "serve_read\n";
        if ($this->state !== ClientState::WAIT_READ) {
            throw new \RuntimeException(sprintf('invalid state, expected WAIT_READ, got: %s', $this->state));
        }

        $buf = null;
        $n = socket_recv($this->sock, $buf, CLIENT_READ_SIZE-strlen($this->readbuf), MSG_DONTWAIT);

        if ($n === false) {
            $this->state !== ClientState::ERROR;
            throw new \RuntimeException(socket_strerror(socket_last_error()));
        }
        if ($n === 0) {
            $this->close();
            return;
        }

        $this->readbuf .= $buf;
        $msg = $this->readbuf_get_msg();
        if ($msg !== null) {
            $this->state = ClientState::IDLE;
            $this->fiber->resume($msg);
        }
    }

    function readbuf_get_msg() {
        if (str_contains($this->readbuf, CLIENT_LINE_FEED)) {
            [$msg, $this->readbuf] = explode(CLIENT_LINE_FEED, $this->readbuf, 2);
            return $msg;
        }

        return null;
    }

    function serve_write() {
        echo "serve_write\n";
        if ($this->state !== ClientState::WAIT_WRITE) {
            throw new \RuntimeException(sprintf('invalid state, expected WAIT_WRITE, got: %s', $this->state));
        }
    }

    function close() {
        echo "close\n";
        $this->state = ClientState::CLOSED;
        socket_write($this->sock, "bye\r\n");
        socket_close($this->sock);
    }
}

function schedule($clients, $read, $write) {
    printf("schedule: read=%d write=%d\n", count($read), count($write));

    $deleted = [];

    foreach ($read as $sock) {
        $name = client_socket_name($sock);
        $client = $clients[$name];
        if ($client->state === ClientState::WAIT_READ) {
            $client->serve_read();
        }

        if ($client->state === ClientState::CLOSED || $client->state === ClientState::ERROR) {
            $deleted[] = $client;
        }
    }

    foreach ($write as $sock) {
        $name = client_socket_name($sock);
        $client = $clients[$name];
        if ($client->state === ClientState::WAIT_WRITE) {
            $client->serve_write();
        }

        if ($client->state === ClientState::CLOSED || $client->state === ClientState::ERROR) {
            $deleted[] = $client;
        }
    }

    return $deleted;
}

function exception_error_handler(
    int $errno, string $errstr, ?string $errfile = null, ?int $errline  = null
) {
    if (!(error_reporting() & $errno)) {
        // This error code is not included in error_reporting
        return;
    }
    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
}

set_error_handler(exception_error_handler(...));

$server_sock = null;
$retries = 0;
while (true) {
    try {
        $server_sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_nonblock($server_sock);
        socket_bind($server_sock, '127.0.0.1', LISTEN_PORT);
        socket_listen($server_sock, LISTEN_BACKLOG);
        break;
    } catch (\ErrorException $e) {
        if (!str_contains($e->getMessage(), 'Address already in use')) {
            throw $e;
        }
        if ($retries > 60) {
            echo "retries exceeded\n";
            throw $e;
        }
        if ($retries === 0) {
            echo "waiting for port to become available...\n";
        }
        $retries++;
        sleep(1);
    }
}

echo "ready\n";

$clients = [];

while (true) {
    echo "loop\n";
    $client_socks_read = array_map(
        fn ($client) => $client->sock,
        array_filter(array_values($clients), fn ($client) => $client->state === ClientState::WAIT_READ),
    );
    $client_socks_write = array_map(
        fn ($client) => $client->sock,
        array_filter(array_values($clients), fn ($client) => $client->state === ClientState::WAIT_WRITE),
    );

    $read = array_merge([$server_sock], $client_socks_read);
    $write = $client_socks_write;
    $except = null;

    $changed = socket_select($read, $write, $except, SELECT_TIMEOUT_SEC, SELECT_TIMEOUT_USEC);
    if ($changed === false) {
        throw new \RuntimeException(socket_strerror(socket_last_error()));
    }

    if ($changed > 0) {
        $first = array_key_first($read);
        if ($first !== null && $read[$first] === $server_sock) {
            array_shift($read);

            $client_sock = socket_accept($server_sock);
            socket_set_nonblock($client_sock);
            $name = client_socket_name($client_sock);

            $clients[$name] = new Client($name, $client_sock);
            $clients[$name]->start();
        }

        $deleted = schedule($clients, $read, $write);
        foreach ($deleted as $client) {
            unset($clients[$client->name]);
        }
    }
}
