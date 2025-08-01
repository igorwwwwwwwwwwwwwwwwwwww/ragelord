<?php

namespace ragelord;

class User {
    function __construct(
        public $name,
        public $nick,
    ) {}
}

class Channel {
    function __construct(
        public $name,
        public $topic = null,
        public $members = [],
        public $symbol = '=', // public
    ) {}

    // TODO: membership flags, e.g. op
    function join($user) {
        $this->members[$user->nick] = $user;
    }

    function part($user) {
        if (!isset($this->members[$user->nick])) {
            throw new \RuntimeException(sprintf('cannot part: user %s is not a member of %s', $user->nick, $this->name));
        }
        unset($this->members[$user->nick]);
    }
}

class ServerState {
    function __construct(
        public $users = [],
        public $channels = [],
    ) {}

    function register($username, $nick) {
        if (isset($this->users[$nick])) {
            throw new \RuntimeException('nick already exists');
        }
        $this->users[$nick] = new User($username, $nick);
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

    function part($user, $chan_name) {
        $this->channels[$chan_name]->part($user);
    }

    // TODO: more efficient mapping of channel membership
    function part_all($user) {
        foreach ($this->channels as $channel) {
            if (isset($channel->members[$user->nick])) {
                $channel->part($user);
            }
        }
    }

    function privmsg($user, $target, $text) {
        if (!isset($this->users[$target])) {
            throw new \RuntimeException(sprintf('no such user: %s', $target));
        }

        // TODO: enqueue to target user buffer
    }

    function privmsg_channel($user, $chan_name, $text) {
        if (!isset($this->channels[$chan_name])) {
            throw new \RuntimeException(sprintf('no such channel: %s', $chan_name));
        }

        // TODO: enqueue to buffer of all channel members
    }
}
