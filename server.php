<?php

namespace ragelord;

use Fiber;

const SERVER_SOURCE = 'localhost';

const LISTEN_PORT = 6667;
const LISTEN_BACKLOG  = 512;

const SELECT_TIMEOUT_SEC  = 10;
const SELECT_TIMEOUT_USEC = 0;

const CLIENT_READ_SIZE = 4096;
const CLIENT_LINE_FEED = "\r\n";

// TODO: format depending on ipv4 vs ipv6
function client_socket_name($sock) {
    socket_getpeername($sock, $addr, $port);
    return "[$addr]:$port";
}

class Message {
    function __construct(
        public $cmd,
        public $params,
        public $src = [],
        public $tags = [],
    ) {
        $this->cmd = strtoupper($cmd);
    }

    function __toString() {
        $out = '';

        if ($this->tags) {
            $parts = [];
            foreach ($this->tags as $key => $val) {
                $parts[] = "$key=$val";
            }
            $out .= '@';
            $out .= implode(';', $parts);
            $out .= ' ';
        }

        if ($this->src) {
            $out .= ':';
            $out .= $this->src;
            $out .= ' ';
        }

        $out .= $this->cmd;
        $out .= ' ';

        for ($i = 0; $i < count($this->params); $i++) {
            if ($i === count($this->params)-1) {
                $out .= ':' . $this->params[$i];
                break;
            }
            $out .= $this->params[$i];
            $out .= ' ';
        }

        return $out;
    }
}

function parse_msg($line) {
    $i = 0;

    //   <tags>          ::= <tag> [';' <tag>]*
    //   <tag>           ::= <key> ['=' <escaped value>]
    //   <key>           ::= [ <client_prefix> ] [ <vendor> '/' ] <sequence of letters, digits, hyphens (`-`)>
    //   <client_prefix> ::= '+'
    //   <escaped value> ::= <sequence of any characters except NUL, CR, LF, semicolon (`;`) and SPACE>
    //   <vendor>        ::= <host>
    $tags = [];
    if ($line[$i] === '@') {
        $tags_raw = '';
        while ($line[$i] !== ' ') {
            $tags .= $line[$i++];
        }
        while ($line[$i] === ' ') {
            $i++;
        }

        foreach (explode(';', $tags_raw) as $tag_raw) {
            [$key, $val] = explode('=', $tag_raw, 2);
            $tags[$key] = $val;
        }
    }

    //   source          ::=  <servername> / ( <nickname> [ "!" <user> ] [ "@" <host> ] )
    //   nick            ::=  <any characters except NUL, CR, LF, chantype character, and SPACE> <possibly empty sequence of any characters except NUL, CR, LF, and SPACE>
    //   user            ::=  <sequence of any characters except NUL, CR, LF, and SPACE>
    $src = '';
    if ($line[$i] === ':') {
        while ($line[$i] !== ' ') {
            $src .= $line[$i++];
        }
        while ($line[$i] === ' ') {
            $i++;
        }
    }

    //   command         ::=  letter* / 3digit
    $cmd = '';
    while ($i < strlen($line) && $line[$i] !== ' ') {
        $cmd .= $line[$i++];
    }
    while ($i < strlen($line) && $line[$i] === ' ') {
        $i++;
    }

    //   parameters      ::=  *( SPACE middle ) [ SPACE ":" trailing ]
    //   nospcrlfcl      ::=  <sequence of any characters except NUL, CR, LF, colon (`:`) and SPACE>
    //   middle          ::=  nospcrlfcl *( ":" / nospcrlfcl )
    //   trailing        ::=  *( ":" / " " / nospcrlfcl )
    $params = [];
    while ($i < strlen($line) && $line[$i] !== ':') {
        $param = '';
        while ($i < strlen($line) && $line[$i] !== ' ') {
            $param .= $line[$i++];
        }
        while ($i < strlen($line) && $line[$i] === ' ') {
            $i++;
        }
        $params[] = $param;
    }

    if ($i < strlen($line) && $line[$i] === ':') {
        $params[] = substr($line, $i+1);
    }

    return new Message($cmd, $params, $src, $tags);
}

enum ClientState : string {
    case IDLE       = 'IDLE';
    case WAIT_READ  = 'WAIT_READ';
    case WAIT_WRITE = 'WAIT_WRITE';
    case CLOSING    = 'CLOSING';
    case CLOSED     = 'CLOSED';
    case ERROR      = 'ERROR';
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
        echo "{$this->name} starting fiber\n";

        $pass = $nick = $user = null;

        try {
            while (true) {
                if ($nick && $user) {
                    break;
                }

                $msg = $this->read_msg();
                switch ($msg->cmd) {
                    case 'PASS':
                        //      Command: PASS
                        //   Parameters: <password>
                        $pass = $msg->params[0];
                        break;
                    case 'NICK':
                        //      Command: NICK
                        //   Parameters: <nickname>
                        $nick = $msg->params[0];
                        break;
                    case 'USER':
                        //      Command: USER
                        //   Parameters: <username> 0 * <realname>
                        $user = $msg->params[0];
                        break;
                    case 'CAP':
                        // TODO: implement proper capability negotiation
                        // ignore for now
                        break;
                    default:
                        throw new \RuntimeException(sprintf('invalid cmd, expected one of %s, got: %s', json_encode($reg_cmds), $msg->cmd));
                }
            }

            // TODO: NICK: check if nick is available, adjust it if not
            // TODO: NICK: allow nick to change
            // TODO: USER: implement realname
            // TODO: various numeric error replies for all of these

            // https://modern.ircdocs.horse/#rplwelcome-001
            $this->write_msg('001', [$nick, "Welcome to the rage Network, $nick"]);
            $this->write_msg('002', [$nick, sprintf("Your host is rage, running version 0.0.1")]);
            $this->write_msg('003', [$nick, "This server was created in the future"]);
            $this->write_msg('004', [$nick, 'rage', '0.0.1', 'o', 'o']);
            $this->write_msg('251', [$nick, "There are 0 users and 0 invisible on 1 servers"]);
            $this->write_msg('255', [$nick, "I have 0 clients and 1 servers"]);

            // LUSERS
            $this->write_msg('005', [$nick, 'CHANTYPES=#', 'PREFIX=(o)@', 'are supported by this server']);

            // MOTD
            $this->write_msg('375', [$nick, '- <server> Message of the day - ']);
            $this->write_msg('372', [$nick, 'moin']);
            $this->write_msg('376', [$nick, 'End of /MOTD command.']);

            // TODO: unimplemented: OPER

            while (true) {
                $msg = $this->read_msg();
                switch ($msg->cmd) {
                    case 'PING':
                        //      Command: PING
                        //   Parameters: <token>
                        $this->write_msg('PONG', [$nick, $msg->params[0] ?? null]);
                        break;
                    case 'QUIT':
                        //     Command: QUIT
                        //  Parameters: [<reason>]
                        $this->write_msg('ERROR', [$nick, $msg->params[0] ?? null]);
                        $this->close();
                        return;
                    default:
                        $this->write_msg('ERROR', ["unsupported command {$msg->cmd}"]);
                        $this->close();
                        return;
                }
            }
        } catch (\Exception $e) {
            echo "{$this->name}: ERROR: {$e}\n";
            $this->write_msg('ERROR', [$e->getMessage()]);
            $this->close();
        }
    }

    function read_msg() {
        return parse_msg($this->read());
    }

    function write_msg($cmd, $params) {
        $this->write(new Message(
            $cmd,
            $params,
            SERVER_SOURCE,
        ));
    }

    // fiber-enabled
    function read() {
        if ($this->state !== ClientState::IDLE) {
            throw new \RuntimeException(sprintf('invalid state, expected IDLE, got: %s', $this->state->value));
        }

        // we already have a message buffered, short circuit
        $line = $this->readbuf_get_line();
        if ($line !== null) {
            return $line;
        }

        $this->state = ClientState::WAIT_READ;
        return Fiber::suspend();
    }

    // fiber-enabled
    function write($data) {
        if ($this->state !== ClientState::IDLE) {
            throw new \RuntimeException(sprintf('invalid state, expected IDLE, got: %s', $this->state->value));
        }
        $this->state = ClientState::WAIT_WRITE;
        $this->writebuf .= $data . CLIENT_LINE_FEED;;
        return Fiber::suspend();
    }

    function serve_read() {
        if ($this->state !== ClientState::WAIT_READ) {
            throw new \RuntimeException(sprintf('invalid state, expected WAIT_READ, got: %s', $this->state->value));
        }

        $buf = null;
        $n = socket_recv($this->sock, $buf, CLIENT_READ_SIZE-strlen($this->readbuf), MSG_DONTWAIT);

        if ($n === false) {
            $this->state = ClientState::ERROR;
            throw new \RuntimeException(socket_strerror(socket_last_error()));
        }
        if ($n === 0) {
            $this->close_immediately();
            return;
        }

        $this->readbuf .= $buf;
        $line = $this->readbuf_get_line();
        // TODO: skip empty line
        if ($line !== null) {
            $this->state = ClientState::IDLE;
            $this->fiber->resume($line);
            return;
        }

        if (strlen($this->readbuf) >= CLIENT_READ_SIZE) {
            // TODO: send numeric error code: https://modern.ircdocs.horse/#errinputtoolong-417
            $this->force_write_besteffort(sprintf("ERROR: max message size is %d bytes\n", CLIENT_READ_SIZE));
            $this->fiber->throw(new \RuntimeException('max message size exceeded'));
        }
    }

    function readbuf_get_line() {
        if (str_contains($this->readbuf, CLIENT_LINE_FEED)) {
            [$line, $this->readbuf] = explode(CLIENT_LINE_FEED, $this->readbuf, 2);
            printf("%s < %s\n", $this->name, $line);
            return $line;
        }

        return null;
    }

    function serve_write() {
        if (!in_array($this->state, [ClientState::WAIT_WRITE, ClientState::CLOSING])) {
            throw new \RuntimeException(sprintf('invalid state, expected WAIT_WRITE or CLOSING, got: %s', $this->state));
        }

        $n = socket_write($this->sock, $this->writebuf);

        if ($n === false) {
            $this->state = ClientState::ERROR;
            throw new \RuntimeException(socket_strerror(socket_last_error()));
        }
        if ($n === 0) {
            $this->close_immediately();
            return;
        }

        printf("%s > %s\n", $this->name, rtrim(substr($this->writebuf, 0, $n)));

        $this->writebuf = substr($this->writebuf, $n);

        if ($this->writebuf === '') {
            if ($this->state === ClientState::CLOSING) {
                $this->close_immediately();
            } else {
                $this->state = ClientState::IDLE;
                $this->fiber->resume();
            }
        }
    }

    function force_write_besteffort($data) {
        $n = socket_write($this->sock, $data);
    }

    // fiber-enabled
    function close() {
        if ($this->writebuf) {
            $this->state = ClientState::CLOSING;
            return Fiber::suspend();
        }

        $this->state = ClientState::CLOSED;
        socket_close($this->sock);
    }

    function close_immediately() {
        $this->state = ClientState::CLOSED;
        socket_close($this->sock);
    }
}

function schedule($clients, $read, $write) {
    // printf("schedule: read=%d write=%d\n", count($read), count($write));

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

function create_server_socket6($addr = '::1', $port = LISTEN_PORT) {
    $retries = 0;
    while (true) {
        try {
            // TODO: support both ipv4 and ipv6
            $server_sock = socket_create(AF_INET6, SOCK_STREAM, SOL_TCP);
            socket_set_nonblock($server_sock);
            socket_bind($server_sock, $addr, $port);
            socket_listen($server_sock, LISTEN_BACKLOG);
            return $server_sock;
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
}

function server($server_sock, $sigbuf) {
    $clients = [];

    while (true) {
        // echo "loop\n";
        foreach ($sigbuf->consume() as $signo) {
            printf("received signal: %s\n", signo_name($signo));
            switch ($signo) {
                case SIGINT:
                    // TODO: notify all clients before shutting down
                    return;
                case SIGTERM:
                    return;
            }
        }

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

        $changed = 0;
        try {
            $changed = socket_select($read, $write, $except, SELECT_TIMEOUT_SEC, SELECT_TIMEOUT_USEC);
        } catch (\ErrorException $e) {
            if (!str_contains($e->getMessage(), 'Interrupted system call')) {
                throw $e;
            }
        }

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

class SignalBuffer {
    function __construct(
        public $signals = [],
    ) {}

    function handler($signo) {
        $this->signals[] = $signo;
    }

    function consume() {
        $signals = $this->signals;
        $this->signals = [];
        return $signals;
    }
}

function signo_name($signo) {
    foreach (get_defined_constants(true)['pcntl'] as $name => $num) {
        if ($num === $signo && strncmp($name, 'SIG', 3) === 0 && $name[3] !== '_') {
            return $name;
        }
    }
}

$sigbuf = new SignalBuffer();
pcntl_async_signals(true);
pcntl_signal(SIGINT, [$sigbuf, 'handler']);
pcntl_signal(SIGTERM, [$sigbuf, 'handler']);

set_error_handler(exception_error_handler(...));

try {
    $server_sock = create_server_socket6();
    echo "ready\n";
    server($server_sock, $sigbuf);
} finally {
    socket_close($server_sock);
}
