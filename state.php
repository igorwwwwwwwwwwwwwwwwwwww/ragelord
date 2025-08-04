<?php

namespace ragelord;

class User {
    function __construct(
        public $name,
        public $nick,
        public $sess, // instead of session, pass something smaller, like a writer; maybe decouple this somehow
    ) {}
}

class Channel {
    function __construct(
        public $name,
        public $topic = null,
        public $members = [], // SplObjecStorage set?
        public $symbol = '=', // public
    ) {}

    // TODO: membership flags, e.g. op
    function join($user) {
        foreach ($this->members as $member) {
            $member->sess->write_msg('JOIN', [$this->name], $user->nick);
        }

        $this->members[$user->nick] = $user;
    }

    function part($user, $reason = null) {
        if (!isset($this->members[$user->nick])) {
            throw new \RuntimeException(sprintf('cannot part: user %s is not a member of %s', $user->nick, $this->name));
        }
        unset($this->members[$user->nick]);

        foreach ($this->members as $member) {
            $member->sess->write_msg('PART', [$this->name, $reason], $user->nick);
        }
    }

    function set_topic($topic) {
        $this->topic = $topic;
        foreach ($this->members as $member) {
            // TODO: RPL_TOPICWHOTIME
            if ($topic) {
                $member->sess->write_msg('332', [$member->nick, $this->name, $topic]);
            } else {
                $member->sess->write_msg('331', [$member->nick, $this->name]);
            }
        }
    }
}

class ServerState {
    function __construct(
        public $users = [],
        public $channels = [],
    ) {}

    function register($username, $nick, $sess) {
        if (isset($this->users[$nick])) {
            throw new \RuntimeException('nick already exists');
        }
        $this->users[$nick] = new User($username, $nick, $sess);
        return $this->users[$nick];
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
    }

    function join($user, $chan_name) {
        $this->channels[$chan_name] ??= new Channel($chan_name);
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

        $this->users[$target]->sess->write_msg('PRIVMSG', [$this->users[$target]->nick, $text], $user->nick);
    }

    function privmsg_channel($user, $chan_name, $text) {
        if (!isset($this->channels[$chan_name])) {
            throw new \RuntimeException(sprintf('no such channel: %s', $chan_name));
        }

        if (!in_array($user, $this->channels[$chan_name]->members)) {
            throw new \RuntimeException(sprintf('user %s is not a member in channel: %s', $user->nick, $chan_name));
        }

        foreach ($this->channels[$chan_name]->members as $member) {
            $member->sess->write_msg('PRIVMSG', [$chan_name, $text], $user->nick);
        }
    }
}
