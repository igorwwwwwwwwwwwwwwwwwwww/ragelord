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
        public $closed = false,
        public $readbuf = '',
        public $writech = new sync\Channel(),
    ) {}

    function reader() {
        echo "{$this->name} starting fiber\n";

        $user = null;

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

            $user = $this->server->register($username, $nick, $this);

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
                                $this->write_msg('353', [$user->nick, $channel->symbol, $channel->name, $member->nick]);
                            }
                            $this->write_msg('366', [$user->nick, $channel->name, 'End of /NAMES list']);
                        }
                        break;
                    case 'PART':
                        //      Command: PART
                        //   Parameters: <channel>{,<channel>} [<reason>]
                        $channels = explode(',', $msg->params[0] ?? '');
                        $reason = $msg->params[1] ?? null;
                        foreach ($channels as $chan_name) {
                            $this->server->part($user, $chan_name, $reason);
                            $this->write_msg('PART', [$user->nick, $chan_name, $reason]);
                        }
                        break;
                    case 'TOPIC':
                        //      Command: TOPIC
                        //   Parameters: <channel> [<topic>]
                        $chan_name = $msg->params[0];
                        $topic = $msg->params[1] ?? null;
                        if ($topic === null) {
                            $topic = $this->server->get_topic($chan_name);
                            // TODO: RPL_TOPICWHOTIME
                            if ($topic) {
                                $this->write_msg('332', [$user->nick, $chan_name, $topic]);
                            } else {
                                $this->write_msg('331', [$user->nick, $chan_name]);
                            }
                        } else {
                            // broadcast happens internally
                            $this->server->set_topic($chan_name, $topic);
                        }
                        break;
                    case 'PRIVMSG':
                        //      Command: PRIVMSG
                        //   Parameters: <target>{,<target>} <text to be sent>
                        $targets = explode(',', $msg->params[0] ?? '');
                        $text = $msg->params[1];
                        foreach ($targets as $target) {
                            if ($target[0] === '#' || $target[0] === '&') {
                                $this->server->privmsg_channel($user, $target, $text);
                            } else {
                                $this->server->privmsg($user, $target, $text);
                            }
                        }
                        break;
                    // TODO: NOTICE
                    // TODO: TOPIC, NAMES, LIST, INVITE, KICK
                    // TODO: MOTD, VERSION, ADMIN, LUSERS, TIME, STATS, HELP, INFO, MODE
                    // TODO: WHO, WHOIS, WHOWAS
                    // TODO: KILL, REHASH, RESTART, SQUIT
                    // TODO: AWAY, LINKS, USERHOST, WALLOPS
                    default:
                        throw new \RuntimeException("unsupported command {$msg->cmd}");
                }
            }
        } catch (\Exception $e) {
            echo "{$this->name} ERROR: {$e}\n";
            if (!$this->closed) {
                $this->write_msg('ERROR', [$e->getMessage()]);
                $this->close();
            }
        } finally {
            echo "{$this->name} client terminated\n";
            if ($user) {
                $this->server->unregister($user);
            }
        }
    }

    // TODO: handle socket close
    function writer() {
        while ($msg = $this->writech->recv()) {
            $this->write($msg);
        }
    }

    function read_msg() {
        return parse_msg($this->read_line());
    }

    function write_msg($cmd, $params, $source = SERVER_SOURCE) {
        $this->write_async(new Message(
            $cmd,
            $params,
            $source,
        ));
    }

    function read_line() {
        if ($this->closed) {
            throw new \RuntimeException('cannot read from closed socket');
        }

        $len = CLIENT_READ_SIZE;
        while (!str_contains($this->readbuf, CLIENT_LINE_FEED)) {
            $buf = null;
            $n = read($this->sock, $buf, $len);

            if ($n === false) {
                throw new \RuntimeException(socket_strerror(socket_last_error()));
            }

            if ($n === 0) {
                // EOF
                throw new \RuntimeException('socket closed');
            }

            $this->readbuf .= $buf;
            $len -= $n;

            if ($len <= 0) {
                throw new \RuntimeException(sprintf('input line too long, must be under %d bytes', CLIENT_READ_SIZE));
            }
        }

        [$line, $this->readbuf] = explode(CLIENT_LINE_FEED, $this->readbuf, 2);
        printf("%s < %s\n", $this->name, $line);
        return $line;
    }

    // TODO: write buffering? we kinda need it in order to be able
    //         to enqueue writes from other fibers. unless we implement
    //         some sort of channel where each client receives items
    //         and then writes them to its own socket.
    //       so the core loop for each client would be something like:
    //         select
    //           msg := <-read_line
    //             parse_and_handle(msg)
    //           msg := <-pending_write
    //             write(msg)
    //       that does make the clients more complex though.
    //       could we create a separate fiber for writes? that could work.
    //         that makes them fully async. and we can enforce max size there,
    //         as long as we make sure the error propagates to the client.
    function write($data) {
        $remaining = strlen($data);

        while ($remaining > 0) {
            $n = write($this->sock, $data);

            if ($n === false) {
                throw new \RuntimeException(socket_strerror(socket_last_error()));
            }

            if ($n === 0) {
                // EOF
                return null;
            }

            $remaining -= $n;
            $data = substr($data, $n);
        }
    }

    function write_async($data) {
        if ($this->closed) {
            throw new \RuntimeException('cannot write to closed socket');
        }
        $this->writech->send($data . CLIENT_LINE_FEED);
    }

    function close() {
        printf('closing\n');
        $this->closed = true;
        socket_close($this->sock);
    }
}
