<?php

namespace ragelord\log;

use ragelord\Color;
use function ragelord\debug_enabled;

enum RecordType: int {
    case CLIENT_ACCEPT  = 0;
    case CLIENT_CLOSE   = 1;
    case CLIENT_MESSAGE = 2;
}

// TODO: peer should also include the server-side ip:port pair
//       since connections are only unique per 4-tuple
class Record {
    function __construct(
        public $id,
        public $ts,
        public RecordType $type,
        public $peer,
        public $data = null,
    ) {}
}

// TODO: zlib compress in batches
// TODO: compaction
class Log {
    function __construct(
        public $records = [],
        public $id_seq = 0,
    ) {}

    function append(
        RecordType $type,
        $peer,
        $data = null,
    ) {
        $id = $this->id_seq++;
        $ts = hrtime(true);
        if (debug_enabled('log')) {
            printf(Color::GRAY->colorize("log: append id=$id ts=$ts type={$type->value} peer=$peer data=$data\n"));
        }
        $this->records[] = new Record(
            $id,
            $ts,
            $type,
            $peer,
            $data,
        );
    }
}
