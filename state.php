<?php

namespace ragelord;

class User {
    function __construct(
        public $name,
        public $nick,
        public $mode = [],
    ) {}
}

class Channel {
    public $topic = null;
    public $members = []; // SplObjecStorage set?
    public $symbol = '='; // public

    function __construct(
        public $name,
        public \WeakMap $sessions,
    ) {}

    function __sleep() {
        return ['topic', 'members', 'symbol', 'name'];
    }

    function __wakeup() {
        $this->sessions = new \WeakMap();
    }

    // TODO: membership flags, e.g. op
    function join($user) {
        $this->members[$user->nick] = $user;

        foreach ($this->members as $member) {
            $this->sessions[$member]->write_msg('JOIN', [$this->name], $user->nick);
        }
    }

    function part($user, $reason = null) {
        if (!isset($this->members[$user->nick])) {
            throw new \RuntimeException(sprintf('cannot part: user %s is not a member of %s', $user->nick, $this->name));
        }
        unset($this->members[$user->nick]);

        foreach ($this->members as $member) {
            $this->sessions[$member]->write_msg('PART', [$this->name, $reason], $user->nick);
        }
    }

    function set_topic($topic) {
        $this->topic = $topic;
        foreach ($this->members as $member) {
            // TODO: RPL_TOPICWHOTIME
            if ($topic) {
                $this->sessions[$member]->write_msg('332', [$member->nick, $this->name, $topic]);
            } else {
                $this->sessions[$member]->write_msg('331', [$member->nick, $this->name]);
            }
        }
    }
}

class ServerState {
    public \WeakMap $sessions;

    function __construct(
        public $users = [],
        public $channels = [],
        public $socket_nick = [], // used for reconstructing sessions after upgrade passfd

    ) {
        $this->sessions = new \WeakMap();
    }

    function __sleep() {
        return ['users', 'channels', 'socket_nick'];
    }

    function __wakeup() {
        $this->sessions = new \WeakMap();
    }

    // we do this during upgrade
    function register_existing_session($nick, $sess) {
        if (!isset($this->users[$nick])) {
            throw new \RuntimeException("cannot register session for missing user $nick");
        }
        $user = $this->users[$nick];
        $this->sessions[$user] = $sess;
    }

    function register($username, $nick, $sess) {
        if (isset($this->users[$nick])) {
            throw new \RuntimeException('nick already exists');
        }
        $user = new User($username, $nick);
        $this->users[$nick] = $user;
        $this->sessions[$user] = $sess;
        $this->socket_nick[socket_name($sess->sock)] = $user->nick;
        return $user;
    }

    function unregister($user) {
        if (!isset($this->users[$user->nick])) {
            return;
        }

        foreach ($this->channels as $channel) {
            if (isset($channel->members[$user->nick])) {
                $channel->part($user);
            }
        }
        unset($this->users[$user->nick]);
        unset($this->sessions[$user]);

        // TODO: optimize via reverse index
        foreach ($this->socket_nick as $socket_name => $nick) {
            if ($nick === $user->nick) {
                unset($this->socket_nick[$socket_name]);
            }
        }
    }

    function nick($user, $new_nick) {
        if (isset($this->users[$new_nick])) {
            throw new \RuntimeException('nick already exists');
        }
        $this->users[$new_nick] = $user;
        unset($this->users[$user->nick]);

        // no bookkeeping in sessions needed because it refers to the
        // underlying User object which is modified in place

        foreach ($this->channels as $channel) {
            if (isset($channel->members[$user->nick])) {
                $channel->members[$new_nick] = $user;
                unset($channel->members[$user->nick]);
            }
        }

        foreach ($this->users as $other_user) {
            $this->sessions[$other_user]->write_msg('NICK', [$new_nick], $user->nick);
        }
        $user->nick = $new_nick;
    }

    function join($user, $chan_name) {
        $this->channels[$chan_name] ??= new Channel($chan_name, $this->sessions);
        $this->channels[$chan_name]->join($user);
        return $this->channels[$chan_name];
    }

    function part($user, $chan_name, $reason) {
        $this->channels[$chan_name]->part($user, $reason);
    }

    // TODO: more efficient mapping of channel membership
    function part_all($user, $reason) {
        foreach ($this->channels as $channel) {
            if (isset($channel->members[$user->nick])) {
                $channel->part($user, $reason);
            }
        }
    }

    function get_topic($chan_name) {
        if (!isset($this->channels[$chan_name])) {
            throw new \RuntimeException(sprintf('no such channel: %s', $chan_name));
        }

        return $this->channels[$chan_name]->topic;
    }

    function set_topic($chan_name, $topic) {
        if (!isset($this->channels[$chan_name])) {
            throw new \RuntimeException(sprintf('no such channel: %s', $chan_name));
        }

        $this->channels[$chan_name]->set_topic($topic);
    }

    function privmsg($user, $target, $text) {
        if (!isset($this->users[$target])) {
            throw new \RuntimeException(sprintf('no such user: %s', $target));
        }

        $targetUser = $this->users[$target];
        $this->sessions[$targetUser]->write_msg('PRIVMSG', [$this->users[$target]->nick, $text], $user->nick);
    }

    function privmsg_channel($user, $chan_name, $text) {
        if (!isset($this->channels[$chan_name])) {
            throw new \RuntimeException(sprintf('no such channel: %s', $chan_name));
        }

        if (!in_array($user, $this->channels[$chan_name]->members)) {
            throw new \RuntimeException(sprintf('user %s is not a member in channel: %s', $user->nick, $chan_name));
        }

        foreach ($this->channels[$chan_name]->members as $member) {
            if ($member === $user) {
                continue;
            }
            $this->sessions[$member]->write_msg('PRIVMSG', [$chan_name, $text], $user->nick);
        }
    }

    function replay(log\Log $log) {
        foreach ($log->records as $record) {
            // find session, invoke.
            // perhaps we need a separate SessionState or such which can do this

            // NOTE: we need to blackhole all writes (write_msg) while we replay.
            //       we don't even need a writer fiber active yet.
            //
            // the process would be:
            // - on accept
            //   - find accept call to unblock (do we need a fake ReplaySocket or abstraction?)
            //   - create session
            //   - start reader in log replay mode
            //   - configure write_msg to blackhole
            // - on msg
            //   - find session to unblock
            //   - unblock reader fiber
            // - on close
            //   - find session
            //   - close it
            //
            // then, after log has been replayed, we should have an up-to-date state,
            //   and an up-to-date set of sessions.
            // we can now upgrade these sessions to real sockets. then we can start
            //   the writer fiber. turn off log replay mode.
            // 🚀 FULL STEAM AHEAD!

            // thinking about this a bit more, we might be able to do this entirely
            //   at the io abstraction layer. instrument accept, read, write.
            //   if we get a ReplaySocket, we skip the event loop, we still suspend,
            //   but we will get resumed by the replayer instead of the event loop.
            // once replay is done do we upgrade the sockets in place.
            //   we can even signal the calls blocked on a fake operation that the
            //   socket upgrade happened via throw. each operation catches, extracts
            //   the real socket (maybe even from the exception itself), and then
            //   just falls back to its normal behaviour.

            // NOTE: we may need to add trailing \r\n when writing messages to
            // the fake socket on replay.
        }
    }
}
