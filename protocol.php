<?php

namespace ragelord;

use Fiber;

const SERVER_SOURCE = 'localhost';

const CLIENT_READ_SIZE = 4096;
const CLIENT_LINE_FEED = "\r\n";

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
