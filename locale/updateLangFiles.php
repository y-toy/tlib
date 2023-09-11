<?php
namespace tlib;

// read config file. Change the path according to your environment.
$configFilePath = __DIR__ . '/../config.php';
if (!file_exists($configFilePath)){
	$configFilePath = __DIR__ . '/../config_sample.php';
}

include_once $configFilePath;

$obj = new clsLocale('', '', '');
// $error = $obj->updateLangFile($srcFilePath, $domainName);
// if ($error == ''){}

