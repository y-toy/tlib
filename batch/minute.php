<?php

// クーロンで1分毎に実行するバッチ

$configFilePath = __DIR__ . '/../config.php';
if (!file_exists($configFilePath)){
	$configFilePath = __DIR__ . '/../config_sample.php';
}
include_once $configFilePath;
include_once TLIB_ROOT . 'clsAccountBase.php';

$objAccount = new clsAccountBase();
$objAccount->delVerifyExpired();

