<?php

namespace ragelord;

enum Color: int {
    case RESET   = 0;
    case RED     = 31;
    case GREEN   = 32;
    case YELLOW  = 33;
    case BLUE    = 34;
    case MAGENTA = 35;
    case CYAN    = 36;
    case WHITE   = 37;
    case GRAY    = 90;

    function colorize($str) {
        return sprintf("\033[%dm%s\033[%dm", $this->value, $str, self::RESET->value);
    }
}
