#!/bin/bash

# Script to generate php_api.h from pahole output.
#
# Run this from linux. Install the dwarves package
# for pahole. php should be compiled with --enable-debug.
#
# Usage: ./generate_header.sh path/to/php/binary (e.g. sapi/cli/php)

PHP_BINARY=${1:-"php"}
OUTPUT_FILE="php_api.h"

echo "Generating header from $PHP_BINARY..."

cat > "$OUTPUT_FILE" << 'EOF'
/*
 * Auto-generated PHP FFI definitions from pahole
 */

EOF

echo '/*' >> "$OUTPUT_FILE"
"$PHP_BINARY" --version >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"
uname -a >> "$OUTPUT_FILE"
echo '*/' >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

# Function to extract struct/union and clean it up
extract_struct() {
    local type_name="$1"
    echo "/* $type_name */" >> "$OUTPUT_FILE"
    pahole -C "$type_name" "$PHP_BINARY" | \
    sed 's/uint8_t/unsigned char/g' | \
    sed 's/zend_long/long/g' | \
    sed 's/zend_ulong/unsigned long/g' >> "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"
}

# Forward declarations first
cat >> "$OUTPUT_FILE" << 'EOF'
/* Forward declarations */
typedef struct _zval_struct zval;
typedef struct _zend_array zend_array;
typedef struct _zend_object zend_object;
typedef struct _zend_array HashTable;
typedef struct _php_stream php_stream;
typedef struct _zend_resource zend_resource;
typedef struct _zend_class_entry zend_class_entry;
typedef struct _zend_object_handlers zend_object_handlers;
typedef struct _Bucket Bucket;
typedef struct _zend_refcounted_h zend_refcounted_h;
typedef union _zend_value zend_value;
typedef void (*dtor_func_t)(zval *pDest);

/* Missing types that pahole references */
typedef void zend_refcounted;
typedef void zend_string;
typedef void zend_reference;
typedef void zend_ast_ref;
typedef void zend_function;

/* Basic types */
typedef int PHP_SOCKET;

EOF

# Extract the structs we need
extract_struct "_zend_refcounted_h"
extract_struct "_zend_value"
extract_struct "_zval_struct"
extract_struct "_Bucket"
extract_struct "_zend_array"
extract_struct "_zend_object"
extract_struct "_zend_resource"

# Extract php_socket differently since it's a typedef
echo "/* php_socket */" >> "$OUTPUT_FILE"
pahole -C "php_socket" "$PHP_BINARY" | \
sed 's/PHP_SOCKET/int/g' | \
sed 's/zend_object/struct _zend_object/g' >> "$OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE"

# Add function declarations
cat >> "$OUTPUT_FILE" << 'EOF'
/* Function declarations */
zend_array *zend_rebuild_symbol_table(void);
HashTable* zend_array_dup(HashTable *source);
void zend_array_destroy(HashTable *ht);
void *zend_fetch_resource2(zend_resource *res, const char *resource_type_name, int resource_type1, int resource_type2);
int php_file_le_stream(void);
int php_file_le_pstream(void);
int _php_stream_cast(php_stream *stream, int castas, void **ret, int show_err);
EOF

echo "Generated $OUTPUT_FILE"