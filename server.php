<?php

namespace ragelord;

use Fiber;

const SERVER_SOURCE = 'localhost';

const CLIENT_READ_SIZE = 4096;
const CLIENT_LINE_FEED = "\r\n";
const CLIENT_MAX_WRITE_BUF_SIZE = 8192;

class Session {
    public $closing = false;
    public $closed = false;
    public $readbuf = '';

    function __construct(
        public $name,
        public $sock,
        public ServerState $state,
        public log\Log $log,
        public $user = null,
        public $writech = new sync\Chan(),
    ) {}

    function reader() {
        echo "{$this->name} starting reader\n";

        try {
            $user = $this->user;
            if (!$user) {
                $user = $this->user = $this->handshake();
            }

            while (true) {
                $msg = $this->read_msg();
                switch ($msg->cmd) {
                    case 'PING':
                        //      Command: PING
                        //   Parameters: <token>
                        $this->write_msg('PONG', [$user->nick, $msg->params[0] ?? null]);
                        break;
                    case 'NICK':
                        //     Command: NICK
                        //  Parameters: <nickname>
                        $newNick = $msg->params[0];
                        $this->state->nick($user, $newNick);
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
                            $this->state->part_all($user);
                            // TODO: send part responses for each parted channel
                            break;
                        }
                        $channels = explode(',', $msg->params[0] ?? '');
                        $keys = explode(',', $msg->params[1] ?? '');
                        $chankeys = array_combine($channels, $keys);
                        foreach ($chankeys as $chan_name => $key) {
                            // TODO: handle key param
                            $channel = $this->state->join($user, $chan_name);
                            if ($channel->topic) {
                                $this->write_msg('332', [$user->nick, $channel->name, $channel->topic]);
                            } else {
                                $this->write_msg('331', [$user->nick, $channel->name, 'No topic is set']);
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
                            $this->state->part($user, $chan_name, $reason);
                            $this->write_msg('PART', [$user->nick, $chan_name, $reason]);
                        }
                        break;
                    case 'TOPIC':
                        //      Command: TOPIC
                        //   Parameters: <channel> [<topic>]
                        $chan_name = $msg->params[0];
                        $topic = $msg->params[1] ?? null;
                        if ($topic === null) {
                            $topic = $this->state->get_topic($chan_name);
                            // TODO: RPL_TOPICWHOTIME
                            if ($topic) {
                                $this->write_msg('332', [$user->nick, $chan_name, $topic]);
                            } else {
                                $this->write_msg('331', [$user->nick, $chan_name]);
                            }
                        } else {
                            // broadcast happens internally
                            $this->state->set_topic($chan_name, $topic);
                        }
                        break;
                    case 'PRIVMSG':
                        //      Command: PRIVMSG
                        //   Parameters: <target>{,<target>} <text to be sent>
                        $targets = explode(',', $msg->params[0] ?? '');
                        $text = $msg->params[1];
                        foreach ($targets as $target) {
                            if ($target[0] === '#' || $target[0] === '&') {
                                $this->state->privmsg_channel($user, $target, $text);
                            } else {
                                $this->state->privmsg($user, $target, $text);
                            }
                        }
                        break;
                    case 'MODE':
                        //     Command: MODE
                        //  Parameters: <target> [<modestring> [<mode arguments>...]]
                        $target = $msg->params[0];
                        $modestring = $msg->params[1] ?? null;
                        if ($target[0] === '#' || $target[0] === '&') {
                            // TODO: implement channel modes
                        } else {
                            if ($target !== $user->nick) {
                                $this->write_msg('502', ['Cant change mode for other users']);
                                break;
                            }
                            if (!$modestring) {
                                $modes = $this->state->users[$user]->mode;
                                $this->write_msg('221', [$user->nick, $modes]);
                                break;
                            }
                            // TODO: implement setting user modes
                        }
                        break;
                    // TODO: NOTICE
                    // TODO: TOPIC, NAMES, LIST, INVITE, KICK
                    // TODO: MOTD, VERSION, ADMIN, LUSERS, TIME, STATS, HELP, INFO
                    // TODO: WHO, WHOIS, WHOWAS
                    // TODO: KILL, REHASH, RESTART, SQUIT
                    // TODO: AWAY, LINKS, USERHOST, WALLOPS
                    default:
                        throw new \RuntimeException("unsupported command {$msg->cmd}");
                }
            }
        } catch (\Exception $e) {
            echo "{$this->name} ERROR: {$e}\n";
            if (!$this->closed && !$this->closing) {
                $this->write_msg('ERROR', [$e->getMessage()]);
                $this->close();
            }
        } finally {
            // TODO: suppress this on upgrade, e.g. via UpgradeInitiatedException
            echo "{$this->name} client terminated\n";
            if ($user) {
                $this->state->unregister($user);
            }
        }
    }

    function handshake() {
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
                    throw new \RuntimeException(sprintf(
                        'invalid cmd, expected one of %s, got: %s',
                        '[USER, PASS, NICK, CAP]',
                        $msg->cmd
                    ));
            }
        }

        $user = $this->state->register($username, $nick, $this);

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

        return $user;
    }

    function read_msg() {
        // if replaying log, return from log.
        // actually, we probably want to do a blocking call
        // here since we will suspend once log has reached
        // this session.
        //
        // in fact the same goes for accept() / session creation
        // and shutdown.

        $msg = parse_msg($this->read_line());
        if (!in_array($msg->type, ['PING', 'PRIVMSG'])) {
            // we only log state changes
            $this->log->append(log\RecordType::CLIENT_MESSAGE, $this->name, (string) $msg);
        }
        return $msg;
    }

    function read_line() {
        if ($this->closed) {
            throw new \RuntimeException('cannot read from closed socket');
        }

        $len = CLIENT_READ_SIZE;
        while (!str_contains($this->readbuf, CLIENT_LINE_FEED)) {
            $buf = null;
            $n = recv($this->sock, $buf, $len);

            if ($n === false) {
                throw new \RuntimeException(socket_strerror(socket_last_error()));
            }

            if ($n === 0) {
                // EOF
                $this->close_immediately();
                // TODO: catch this exception and avoid backtrace
                throw new \RuntimeException('socket closed');
            }

            $this->readbuf .= $buf;
            $len -= $n;

            if ($len <= 0) {
                throw new \RuntimeException(sprintf('input line too long, must be under %d bytes', CLIENT_READ_SIZE));
            }
        }

        [$line, $this->readbuf] = explode(CLIENT_LINE_FEED, $this->readbuf, 2);
        if (debug_enabled('io') || debug_enabled('io=r') || debug_enabled('io=read')) {
            printf("%s < %s\n", $this->name, $line);
        }
        return $line;
    }

    function writer() {
        echo "{$this->name} starting writer\n";

        foreach ($this->writech as $msg) {
            if ($this->closed) {
                break;
            }
            $this->write($msg);
        }

        if (!$this->closed) {
            $this->closed = true;
            socket_close($this->sock);
        }

        echo "{$this->name} writer closed\n";
    }

    function write_msg($cmd, $params, $source = SERVER_SOURCE) {
        $this->write_async(new Message(
            $cmd,
            $params,
            $source,
        ));
    }

    function write_async($data) {
        if ($this->closed) {
            throw new \RuntimeException('cannot write to closed socket');
        }
        $this->writech->send($data . CLIENT_LINE_FEED);
    }

    function write($data) {
        $remaining = strlen($data);

        while ($remaining > 0) {
            $n = write($this->sock, $data);

            if ($n === false) {
                throw new \RuntimeException(socket_strerror(socket_last_error()));
            }

            if ($n === 0) {
                // EOF
                $this->close_immediately();
                return null;
            }

            if (debug_enabled('io') || debug_enabled('io=w') || debug_enabled('io=write')) {
                printf("%s > %s\n", $this->name, rtrim(substr($data, 0, $n)));
            }

            $remaining -= $n;
            $data = substr($data, $n);
        }
    }

    // TODO: actually we want to socket_shutdown() here
    function close() {
        if ($this->closed || $this->closing) {
            return;
        }
        $this->closing = true;
        $this->writech->close();
    }

    function close_immediately() {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        socket_close($this->sock);
        $this->writech->close();
    }
}
