<?php

namespace ragelord;

use Fiber;

const SERVER_SOURCE = 'localhost';

const CLIENT_READ_SIZE = 4096;
const CLIENT_LINE_FEED = "\r\n";
const CLIENT_MAX_WRITE_BUF_SIZE = 8192;

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

class Client {
    function __construct(
        public $name,
        public $sock,
        public ServerState $server,
        public $pending_read = false,
        public $pending_write = false,
        public $pending_close = false,
        public $closed = false,
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

        try {
            $username = $password = $nick = null;

            while (true) {
                if ($username && $nick) {
                    break;
                }

                $msg = $this->read_msg();
                switch ($msg->cmd) {
                    case 'USER':
                        //      Command: USER
                        //   Parameters: <username> 0 * <realname>
                        $username = $msg->params[0];
                        break;
                    case 'PASS':
                        //      Command: PASS
                        //   Parameters: <password>
                        $password = $msg->params[0];
                        break;
                    case 'NICK':
                        //      Command: NICK
                        //   Parameters: <nickname>
                        $nick = $msg->params[0];
                        break;
                    case 'CAP':
                        // TODO: implement proper capability negotiation
                        // ignore for now
                        break;
                    default:
                        throw new \RuntimeException(sprintf('invalid cmd, expected one of %s, got: %s', json_encode($reg_cmds), $msg->cmd));
                }
            }

            $user = $this->server->register($username, $nick);

            // TODO: NICK: check if nick is available, adjust it if not
            // TODO: NICK: allow nick to change
            // TODO: USER: implement realname
            // TODO: various numeric error replies for all of these

            // https://modern.ircdocs.horse/#rplwelcome-001
            $this->write_msg('001', [$user->nick, "Welcome to the rage Network, {$user->nick}"]);
            $this->write_msg('002', [$user->nick, sprintf("Your host is rage, running version 0.0.1")]);
            $this->write_msg('003', [$user->nick, "This server was created in the future"]);
            $this->write_msg('004', [$user->nick, 'rage', '0.0.1', 'o', 'o']);
            $this->write_msg('251', [$user->nick, "There are 0 users and 0 invisible on 1 servers"]);
            $this->write_msg('255', [$user->nick, "I have 0 clients and 1 servers"]);

            // LUSERS
            $this->write_msg('005', [$user->nick, 'CHANTYPES=#', 'PREFIX=(o)@', 'are supported by this server']);

            // MOTD
            $this->write_msg('375', [$user->nick, '- <server> Message of the day - ']);
            $this->write_msg('372', [$user->nick, 'moin']);
            $this->write_msg('376', [$user->nick, 'End of /MOTD command.']);

            // TODO: unimplemented: OPER

            while (true) {
                $msg = $this->read_msg();
                switch ($msg->cmd) {
                    case 'PING':
                        //      Command: PING
                        //   Parameters: <token>
                        $this->write_msg('PONG', [$user->nick, $msg->params[0] ?? null]);
                        break;
                    case 'QUIT':
                        //     Command: QUIT
                        //  Parameters: [<reason>]
                        $this->write_msg('ERROR', [$user->nick, $msg->params[0] ?? null]);
                        $this->close();
                        return;
                    case 'JOIN':
                        //      Command: JOIN
                        //   Parameters: <channel>{,<channel>} [<key>{,<key>}]
                        //   Alt Params: 0
                        if ($msg->params === ['0']) {
                            $this->server->part_all($user);
                            // TODO: send part responses for each parted channel
                            break;
                        }
                        $channels = explode(',', $msg->params[0] ?? '');
                        $keys = explode(',', $msg->params[1] ?? '');
                        $chankeys = array_combine($channels, $keys);
                        foreach ($chankeys as $chan_name => $key) {
                            // TODO: handle key param
                            $channel = $this->server->join($user, $chan_name);
                            $this->write_msg('JOIN', [$user->nick, $channel->name]);
                            if ($channel->topic) {
                                $this->write_msg('332', [$user->nick, $channel->name, $channel->topic]);
                            }
                            foreach ($channel->members as $member) {
                                $this->write_msg('332', [$user->nick, $channel->symbol, $channel->name, $member->nick]);
                            }
                            $this->write_msg('332', [$user->nick, $channel->symbol, 'End of /NAMES list']);
                        }
                        break;
                    case 'PART':
                        //      Command: PART
                        //   Parameters: <channel>{,<channel>} [<reason>]
                        $channels = explode(',', $msg->params[0] ?? '');
                        $reason = $msg->params[1] ?? null;
                        foreach ($channels as $chan_name) {
                            $this->server->part($chan_name);
                            $this->write_msg('PART', [$chan_name, $reason]);
                        }
                        return;
                    case 'PRIVMSG':
                        //      Command: PRIVMSG
                        //   Parameters: <target>{,<target>} <text to be sent>
                        $targets = explode(',', $msg->params[0] ?? '');
                        $text = $msg->params[1];
                        foreach ($targets as $target) {
                            if ($target[0] === '#') {
                                $this->server->privmsg_channel($user, $target, $text);
                            } else {
                                $this->server->privmsg($user, $target, $text);
                            }
                        }
                        return;
                    // TODO: TOPIC, NAMES, LIST, INVITE, KICK
                    // MOTD, VERSION, ADMIN, LUSERS, TIME, STATS, HELP, INFO, MODE
                    default:
                        throw new \RuntimeException("unsupported command {$msg->cmd}");
                }
            }
        } catch (\Exception $e) {
            echo "{$this->name}: ERROR: {$e}\n";
            $this->write_msg('ERROR', [$e->getMessage()]);
            $this->close();
        } finally {
            if ($user) {
                $this->server->unregister($user);
            }
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
        if ($this->closed) {
            throw new \RuntimeException('cannot read from closed socket');
        }
        if ($this->pending_close) {
            throw new \RuntimeException('pending close, cannot read');
        }

        // we already have a message buffered, short circuit
        $line = $this->readbuf_get_line();
        if ($line !== null) {
            $this->pending_read = false;
            return $line;
        }

        $this->pending_read = true;
        return Fiber::suspend();
    }

    // fiber-enabled
    // TODO: implement non-fiberous version of this.
    //       perhaps an io object and a fiberio wrapper that blocks via suspend.
    //       we could also create a separate "inbox" buffer abstraction that the
    //         client must itself consume and then write to the socket.
    //       so that we can buffer the firehose of message delivery.
    //       we also need a maximum write buffer size to handle slow clients.
    function write($data) {
        if ($this->closed) {
            throw new \RuntimeException('cannot read from closed socket');
        }
        // TODO: track individual writes, so we resume when this data was sent
        //       but allow other code to continue writing to the buffer.
        $this->write_async($data);
        return Fiber::suspend();
    }

    // non fiberous, we just queue data for write
    // NOTE: caller must handle buf size exceeded
    function write_async($data) {
        if ($this->closed) {
            throw new \RuntimeException('cannot write to closed socket');
        }
        $this->pending_write = true;
        $this->writebuf .= $data . CLIENT_LINE_FEED;
        if (strlen($this->writebuf) > CLIENT_MAX_WRITE_BUF_SIZE) {
            throw new \RuntimeException('max client write buf exceeded');
        }
    }

    function feed_readbuf() {
        if ($this->closed) {
            throw new \RuntimeException('cannot feed closed socket');
        }
        if (!$this->pending_read) {
            throw new \RuntimeException('no pending read, will not feed buffers');
        }

        $buf = null;
        $n = socket_recv($this->sock, $buf, CLIENT_READ_SIZE-strlen($this->readbuf), MSG_DONTWAIT);

        if ($n === false) {
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
            $this->pending_read = false;
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

    function feed_writebuf() {
        if ($this->closed) {
            throw new \RuntimeException('cannot feed closed socket');
        }
        if (!$this->pending_write) {
            throw new \RuntimeException('no pending write, will not feed buffers');
        }

        $n = socket_write($this->sock, $this->writebuf);

        if ($n === false) {
            throw new \RuntimeException(socket_strerror(socket_last_error()));
        }
        if ($n === 0) {
            $this->close_immediately();
            return;
        }

        printf("%s > %s\n", $this->name, rtrim(substr($this->writebuf, 0, $n)));

        $this->writebuf = substr($this->writebuf, $n);

        if ($this->writebuf === '') {
            if ($this->pending_close) {
                $this->close_immediately();
            } else {
                $this->pending_write = false;
                $this->fiber->resume();
            }
        }
    }

    function force_write_besteffort($data) {
        if ($this->closed) {
            return;
        }
        $n = socket_write($this->sock, $data);
    }

    // fiber-enabled
    function close() {
        if ($this->writebuf) {
            $this->pending_close = true;
            return Fiber::suspend();
        }

        $this->pending_close = false;
        $this->closed = true;
        socket_close($this->sock);
    }

    function close_immediately() {
        $this->closed = true;
        socket_close($this->sock);
    }
}
