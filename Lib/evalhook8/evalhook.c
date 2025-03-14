/*
  +----------------------------------------------------------------------+
  | PHP Version 5                                                        |
  +----------------------------------------------------------------------+
  | Copyright (c) 1997-2010 The PHP Group                                |
  +----------------------------------------------------------------------+
  | This source file is subject to version 3.01 of the PHP license,      |
  | that is bundled with this package in the file LICENSE, and is        |
  | available through the world-wide-web at the following url:           |
  | http://www.php.net/license/3_01.txt                                  |
  | If you did not receive a copy of the PHP license and are unable to   |
  | obtain it through the world-wide-web, please send a note to          |
  | license@php.net so we can mail you a copy immediately.               |
  +----------------------------------------------------------------------+
  | Author: Stefan Esser                                                 |
  +----------------------------------------------------------------------+
*/

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"
#include "zend_compile.h"
#include "php_evalhook.h"


PHP_FUNCTION(eval_log);
static zend_function_entry eval_log_functions[] = {
    PHP_FE(eval_log, NULL)
    {NULL, NULL, NULL}
};


zend_module_entry evalhook_module_entry = {
#if ZEND_MODULE_API_NO >= 20010901
	STANDARD_MODULE_HEADER,
#endif
	"evalhook",
    eval_log_functions,
	PHP_MINIT(evalhook),
	PHP_MSHUTDOWN(evalhook),
	PHP_RINIT(evalhook),
	PHP_RSHUTDOWN(evalhook),
	PHP_MINFO(evalhook),
#if ZEND_MODULE_API_NO >= 20010901
	"0.1",
#endif
	STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_EVALHOOK
ZEND_GET_MODULE(evalhook)
#endif

ZEND_BEGIN_MODULE_GLOBALS(evalhook)
    zval new_array;
ZEND_END_MODULE_GLOBALS(evalhook)

#ifdef ZTS
#define COUNTER_G(v) TSRMG(evalhook_globals_id, zend_evalhook_globals *, v)
#else
#define COUNTER_G(v) (evalhook_globals.v)
#endif

ZEND_DECLARE_MODULE_GLOBALS(evalhook)

static zend_op_array *(*orig_compile_string)(zval *source_string, char *filename, zend_compile_position position);
static zend_bool evalhook_hooked = 0;

static zend_op_array *evalhook_compile_string(zval *source_string, char *filename, zend_compile_position position)
{
	int c, len, yes;
	char *copy;

  if(strstr(filename, "eval()'d code")) {
    len  = ZSTR_LEN((zend_string *)source_string);
    copy = estrndup(ZSTR_VAL((zend_string *)source_string), len);
    add_next_index_string(&COUNTER_G(new_array), copy);
  }

	return orig_compile_string(source_string, filename, ZEND_COMPILE_POSITION_AFTER_OPEN_TAG);
}


PHP_MINIT_FUNCTION(evalhook)
{
	if (evalhook_hooked == 0) {
		evalhook_hooked = 1;
		orig_compile_string = zend_compile_string;
		zend_compile_string = evalhook_compile_string;
    }
    return SUCCESS;
}

PHP_RINIT_FUNCTION(evalhook){
    array_init(&COUNTER_G(new_array));
    return SUCCESS;
}


PHP_MSHUTDOWN_FUNCTION(evalhook)
{
	if (evalhook_hooked == 1) {
		evalhook_hooked = 0;
		zend_compile_string = orig_compile_string;
    }
	return SUCCESS;
}

PHP_RSHUTDOWN_FUNCTION(evalhook){
    return SUCCESS;
}

PHP_MINFO_FUNCTION(evalhook)
{
  php_info_print_table_start();
	php_info_print_table_header(2, "evalhook support", "enabled");
	php_info_print_table_end();
}

PHP_FUNCTION(eval_log)
{
    HashTable *arr_hash;
    HashPosition pointer;
    zval *data;

    array_init(return_value);

    arr_hash = Z_ARRVAL_P(&(COUNTER_G(new_array)));

    zend_hash_internal_pointer_reset_ex(arr_hash, &pointer);
    ZEND_HASH_FOREACH_VAL(arr_hash, data){
      add_next_index_string(return_value, Z_STRVAL_P(data));
    }
    ZEND_HASH_FOREACH_END();
    
    return;
}
