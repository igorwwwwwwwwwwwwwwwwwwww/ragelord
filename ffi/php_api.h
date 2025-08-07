/*
 * Auto-generated PHP FFI definitions from pahole
 */

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

/* _zend_refcounted_h */
struct _zend_refcounted_h {
	uint32_t                   refcount;             /*     0     4 */
	union {
		uint32_t           type_info;            /*     4     4 */
	} u;                                             /*     4     4 */

	/* size: 8, cachelines: 1, members: 2 */
	/* last cacheline: 8 bytes */
};


/* _zend_value */
union _zend_value {
	long                  lval;               /*     0     8 */
	double                     dval;               /*     0     8 */
	zend_refcounted *          counted;            /*     0     8 */
	zend_string *              str;                /*     0     8 */
	zend_array *               arr;                /*     0     8 */
	zend_object *              obj;                /*     0     8 */
	zend_resource *            res;                /*     0     8 */
	zend_reference *           ref;                /*     0     8 */
	zend_ast_ref *             ast;                /*     0     8 */
	zval *                     zv;                 /*     0     8 */
	void *                     ptr;                /*     0     8 */
	zend_class_entry *         ce;                 /*     0     8 */
	zend_function *            func;               /*     0     8 */
	struct {
		uint32_t           w1;                 /*     0     4 */
		uint32_t           w2;                 /*     4     4 */
	} ww;                                          /*     0     8 */
};


/* _zval_struct */
struct _zval_struct {
	zend_value                 value;                /*     0     8 */
	union {
		uint32_t           type_info;            /*     8     4 */
		struct {
			unsigned char    type;                 /*     8     1 */
			unsigned char    type_flags;           /*     9     1 */
			union {
				uint16_t extra;          /*    10     2 */
			} u;                             /*    10     2 */
		} v;                                     /*     8     4 */
	} u1;                                            /*     8     4 */
	union {
		uint32_t           next;                 /*    12     4 */
		uint32_t           cache_slot;           /*    12     4 */
		uint32_t           opline_num;           /*    12     4 */
		uint32_t           lineno;               /*    12     4 */
		uint32_t           num_args;             /*    12     4 */
		uint32_t           fe_pos;               /*    12     4 */
		uint32_t           fe_iter_idx;          /*    12     4 */
		uint32_t           guard;                /*    12     4 */
		uint32_t           constant_flags;       /*    12     4 */
		uint32_t           extra;                /*    12     4 */
	} u2;                                            /*    12     4 */

	/* size: 16, cachelines: 1, members: 3 */
	/* last cacheline: 16 bytes */
};


/* _Bucket */
struct _Bucket {
	zval                       val;                  /*     0    16 */
	unsigned long                 h;                    /*    16     8 */
	zend_string *              key;                  /*    24     8 */

	/* size: 32, cachelines: 1, members: 3 */
	/* last cacheline: 32 bytes */
};


/* _zend_array */
struct _zend_array {
	zend_refcounted_h          gc;                   /*     0     8 */
	union {
		struct {
			unsigned char    flags;                /*     8     1 */
			unsigned char    _unused;              /*     9     1 */
			unsigned char    nIteratorsCount;      /*    10     1 */
			unsigned char    _unused2;             /*    11     1 */
		} v;                                     /*     8     4 */
		uint32_t           flags;                /*     8     4 */
	} u;                                             /*     8     4 */
	uint32_t                   nTableMask;           /*    12     4 */
	union {
		uint32_t *         arHash;               /*    16     8 */
		Bucket *           arData;               /*    16     8 */
		zval *             arPacked;             /*    16     8 */
	};                                               /*    16     8 */
	uint32_t                   nNumUsed;             /*    24     4 */
	uint32_t                   nNumOfElements;       /*    28     4 */
	uint32_t                   nTableSize;           /*    32     4 */
	uint32_t                   nInternalPointer;     /*    36     4 */
	long                  nNextFreeElement;     /*    40     8 */
	dtor_func_t                pDestructor;          /*    48     8 */

	/* size: 56, cachelines: 1, members: 10 */
	/* last cacheline: 56 bytes */
};


/* _zend_object */
struct _zend_object {
	zend_refcounted_h          gc;                   /*     0     8 */
	uint32_t                   handle;               /*     8     4 */
	uint32_t                   extra_flags;          /*    12     4 */
	zend_class_entry *         ce;                   /*    16     8 */
	const zend_object_handlers  * handlers;          /*    24     8 */
	HashTable *                properties;           /*    32     8 */
	zval                       properties_table[1];  /*    40    16 */

	/* size: 56, cachelines: 1, members: 7 */
	/* last cacheline: 56 bytes */
};


/* _zend_resource */
struct _zend_resource {
	zend_refcounted_h          gc;                   /*     0     8 */
	long                  handle;               /*     8     8 */
	int                        type;                 /*    16     4 */

	/* XXX 4 bytes hole, try to pack */

	void *                     ptr;                  /*    24     8 */

	/* size: 32, cachelines: 1, members: 4 */
	/* sum members: 28, holes: 1, sum holes: 4 */
	/* last cacheline: 32 bytes */
};


/* php_socket */
typedef struct {
	int                 bsd_socket;           /*     0     4 */
	int                        type;                 /*     4     4 */
	int                        error;                /*     8     4 */
	int                        blocking;             /*    12     4 */
	zval                       zstream;              /*    16    16 */
	struct _zend_object                std;                  /*    32    56 */

	/* size: 88, cachelines: 2, members: 6 */
	/* last cacheline: 24 bytes */
} php_socket;


/* Function declarations */
zend_array *zend_rebuild_symbol_table(void);
HashTable* zend_array_dup(HashTable *source);
void zend_array_destroy(HashTable *ht);
void *zend_fetch_resource2(zend_resource *res, const char *resource_type_name, int resource_type1, int resource_type2);
int php_file_le_stream(void);
int php_file_le_pstream(void);
int _php_stream_cast(php_stream *stream, int castas, void **ret, int show_err);
