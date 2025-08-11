<?php

namespace ragelord\log;

use ragelord\Color;
use function ragelord\debug_enabled;

enum RecordType: int {
    case CLIENT_ACCEPT    = 0;
    case CLIENT_READ_MSG  = 1;
    case CLIENT_WRITE_MSG = 2;
    case CLIENT_CLOSE     = 3;
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

    // highly dependent on the format of client_socket_name()
    function get_peer_server() {
        return explode('<->', $record->peer)[0];
    }
}

// TODO: zlib compress in batches
// TODO: compaction
class Log {
    public static $log;

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
            $data_str = json_encode($data);
            printf(Color::GRAY->colorize("log: append id=$id ts=$ts type={$type->value} peer=$peer data=$data_str\n"));
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

class LogState {
    public static $log;
}
LogState::$log = new Log();

function replay($servers, $server_socks, $client_socks) {
    $servers_by_name = [];
    foreach ($servers as $server) {
        $servers_by_name[$server->name] = $server;
    }

    $log_replay_sockets = [];

    foreach (LogState::$log->records as $record) {
        switch ($record->type) {
            case RecordType::CLIENT_ACCEPT:
                $sock = new LogReplaySocket($record->peer);
                $log_replay_sockets[$record->peer] = $sock;

                $server = $servers_by_name[$record->get_peer_server()];
                $server->accept_resume();
                break;
            case RecordType::CLIENT_READ_MSG:
                $sock = $log_replay_sockets[$record->peer];

                // PROBLEM: i want to find a specific session by name.
                // server only stores a weakmap, whose keys must be objects.
                // i would need a weakmap whose key is a string and the value
                // is an object. but this does not exist.
                // and managing the lifecycle of a session from server is a
                // bit of a pain. so here we are, looping over all sessions,
                // i guess.
                // unless... we could store the fiber in the fake socket
                // and then resume that.

                $server = $servers_by_name[$record->get_peer_server()];
                $server->read_resume();
                break;
            case RecordType::CLIENT_WRITE_MSG:
                break;
            case RecordType::CLIENT_CLOSE:
                unset($log_replay_sockets[$record->peer]);
                break;
        }
    }

    // TODO: upgrade all fake sockets to real ones

    $server_socks_by_name = [];
    foreach ($server_socks as $sock) {
        $server_socks_by_name[server_sock_name($sock)] = $sock;
    }

    $client_socks_by_name = [];
    foreach ($client_socks as $sock) {
        $client_socks_by_name[client_sock_name($sock)] = $sock;
    }

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
