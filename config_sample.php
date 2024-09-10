<?php

/**
 * A config sample of tlib library.
 * Please modify this file to meet your environment and read this file first when using tlib library
 */


///////////////////////////////////////////////////////////////////////////////
// const values

// Set your porject root folder. needed / at the last. (probably same as apache's document root)
define('TLIB_PROJECT_ROOT',  __DIR__ . '/');

// Set this library's root folder.
define('TLIB_ROOT', __DIR__ . '/');

// Set the log folder for this library. using at util::vitalLogOut()
// Requires write permission.
define('TLIB_LOG', TLIB_ROOT . 'logs/');

// const values for clsAccount
define('TLIB_MIN_PASSWORD_LENGTH', 8);
define('TLIB_MAX_PASSWORD_LENGTH', 32); // 0 means no max length.
define('TLIB_PASSWORD_CHECK_MODE', 0); // 0 anything ok 1 Digits and lowercase alphabets 2 + uppercase alphabets 3 + special chars
define('TLIB_HASH_ALGO_PASS', 'sha3-256'); // will be used this algorithm when store password to your data base.
define('TLIB_HASH_ALGO_SESS', 'sha3-512'); // will be used this algorithm when store session key to your data base.
define('TLIB_HASH_SOLT', 'somethingbetter'); // hash solt when using hush functions

// about session
define('TLIB_LOGIN_EXPIRE_DAYS', 365);

///////////////////////////////////////////////////////////////////////////////
// read classes and lib files

// This library's library.
include_once TLIB_ROOT . 'lib.php';

// Class files (delete the line of class you dont use )
include_once TLIB_ROOT . 'clsLocale.php';
include_once TLIB_ROOT . 'clsDB.php';

///////////////////////////////////////////////////////////////////////////////
// DB setting (set here if you want to use clsDB.php)

// DB setting
$db_info = array(
	'host' => 'p:localhost', // "p" will be a persistent connection
	'user' => 'anon',
	'pass' => 'anonpass',
	'name' => 'dbname',
	'port' => 3306,
	'socket' => '/var/run/mysqld/mysqld.sock', // set this value if you want to use socket connection
	'dbLogFolder' => TLIB_LOG, // Set this value if you want to out log info when errors are occured
);

// make DB obj if you need
//$db = new \tlib\clsDB($db_info);

///////////////////////////////////////////////////////////////////////////////
// locale setting (set here if you want to use clsLocal.php)

define('TLIB_LOCALE_FILE_PATH', TLIB_ROOT . 'locale/'); // locale folder
define('TLIB_LOCALE_FILE_PATH_FOR_TLIB_CLASSES', TLIB_ROOT . 'locale/'); // locale folder for tlib classes
define('TLIB_DEFAULT_LANG', 'en_US.utf8'); // DEFAULT LANGUAGE when it could not be determined from the environment
define('TLIB_ACCEPT_LANGS', array(
	'en' => 'en_US.utf8', // langcode => system locale code. you can see the system locale code list using command "locale -a" in UBUNTU.
	'ja' => 'ja_JP.utf8'
));
define('TLIB_DEFAULT_PO_TARGET_EXTENSIONS', array('php')); // extensions of target files which needed to make po files (needed to translate).

$setLocale = ''; // for browser
if (php_sapi_name() == 'cli'){ $setLocale = TLIB_DEFAULT_LANG; } // for script using command line
tlib\clsLocale::setLocale($setLocale);

// 2 letters of choosed language
$lang = substr($setLocale, 0, 2);

///////////////////////////////////////////////////////////////////////////////
// email setting

define('TLIB_EMAIL_NOTICE', array('mx.some.jp', 587, 'notice@some.jp', 'password'));
define('TLIB_EMAIL_SYSTEM', array('mx.some.jp', 587, 'system@some.jp', 'password'));

