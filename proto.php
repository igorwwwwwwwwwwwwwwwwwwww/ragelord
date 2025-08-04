<?php

namespace ragelord;

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
