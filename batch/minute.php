<?php

// クーロンで1分毎に実行するバッチ

$configFilePath = __DIR__ . '/../config.php';
if (!file_exists($configFilePath)){
	$configFilePath = __DIR__ . '/../config_sample.php';
}
include_once $configFilePath;
include_once TLIB_ROOT . 'clsOAuth2.php';

$objOAuth2 = new clsOAuth2($db);
$objOAuth2->deleteExpiredRecords();

