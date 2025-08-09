<?php

namespace ragelord\ffi;

use FFI;

function socket_to_fd(\Socket $socket): ?int {
    static $ffi = null;
    if (!$ffi) {
        $bitSize = PHP_INT_SIZE * 8;
        $header = "typedef int{$bitSize}_t zend_long;\n";
        $header .= "typedef uint{$bitSize}_t zend_ulong;\n";
        $header .= "typedef int{$bitSize}_t zend_off_t;\n";
        $header .= file_get_contents(__DIR__ . "/php_api.h");
        $ffi = FFI::cdef($header);
    }

    // Get zval
    $symbolTable = $ffi->zend_rebuild_symbol_table();
    $symbolHashTable = $ffi->zend_array_dup($symbolTable);
    $zval = $symbolHashTable->arData->val;
    $ffi->zend_array_destroy($symbolHashTable);

    // Socket object
    $obj = $zval->value->obj;
    if (FFI::isNull($obj)) return null;

    // Calculate offset using FFI - equivalent to XtOffsetOf(php_socket, std)
    $dummySocket = $ffi->new("php_socket");
    $stdPtr = $ffi->cast("char*", FFI::addr($dummySocket->std));
    $basePtr = $ffi->cast("char*", FFI::addr($dummySocket));
    $offset = $stdPtr - $basePtr;

    $socketPtr = $ffi->cast("char*", $obj) - $offset;
    $socket = $ffi->cast("php_socket*", $socketPtr);
    return $socket->bsd_socket;
}

function fd_to_socket(int $fd): ?\Socket {
    $stream = fopen("php://fd/$fd", "r+");
    if (!$stream) return null;
    return socket_import_stream($stream);
}
