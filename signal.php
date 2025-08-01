<?php

namespace ragelord;

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
