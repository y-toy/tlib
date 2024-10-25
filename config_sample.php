<?php
namespace tlib;

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
define('TLIB_HASH_SOLT', 'somethingbetter'); // hash solt when using hush functions

// about session
define('TLIB_LOGIN_EXPIRE_DAYS', 90);

// Default maximam number of iterations when index generation fails to avoid infinite loop.
define('TLIB_MAX_INDEX_GENERATION', 20);

///////////////////////////////////////////////////////////////////////////////
// read classes and lib files

// This library's library.
include_once TLIB_ROOT . 'lib.php';

// Class files (delete the line of the class you dont need )
include_once TLIB_ROOT . 'clsTransfer.php';
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
//$db = new clsDB($db_info);

///////////////////////////////////////////////////////////////////////////////
// locale setting (set here if you want to use clsLocal.php)

define('TLIB_LANG_FILE_PATH', TLIB_ROOT . 'locale/'); // locale folder
$LANG = clsTransfer::getLang();

///////////////////////////////////////////////////////////////////////////////
// email setting

define('TLIB_EMAIL_NOTICE', array('mx.some.jp', 587, 'notice@some.jp', 'password', 'Disp Name', 'Disp eMail from', 'bcc@some.jp'));
define('TLIB_EMAIL_SYSTEM', array('mx.some.jp', 587, 'system@some.jp', 'password', 'Disp Name', 'Disp eMail from', 'bcc@some.jp'));

