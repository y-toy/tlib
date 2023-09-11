<?php

/**
 * -- config sample for tlib library. --
 * Please modify this file to suit your environment.
 */

// set root folder.
$GLOBALS['tlib_root'] = __DIR__ . '/';

// set folder to output logs that cannot be output by normal way. must be end /.
// Requires read/write permission from this program.
$GLOBALS['tlib_log'] = $GLOBALS['tlib_root'] . 'logs/';

// must read
include_once $GLOBALS['tlib_root'] . 'lib.php';

// must use classes
include_once $GLOBALS['tlib_root'] . 'clsLocale.php';
include_once $GLOBALS['tlib_root'] . 'clsDB.php';

// DB setting
$db_info = array(
	'host' => 'p:localhost', // "p" will be a persistent connection
	'user' => 'anon',
	'pass' => 'anonpass',
	'name' => 'dbname',
	'port' => 3306,
	'socket' => '/var/run/mysqld/mysqld.sock' // set here if you want to use socket connection
);

// make DB obj if you need
$db = new \tlib\clsDB($db_info);


// below setting is for clsLocal.php

// Set the default language code. lang code must be CLDR's first harf if en_us then en is the language code.
// We will use this language when we could not get the user's language.
$GLOBALS['TLIB_DEAFAULT_LANG'] = 'en';
// Set the accept language
$GLOBALS['TLIB_ACCEPT_LANG'] = array('en', 'ja');
