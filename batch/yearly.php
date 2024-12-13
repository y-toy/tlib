<?php

// cronで毎年10月1日に実行するバッチ

$configFilePath = __DIR__ . '/../config.php';
if (!file_exists($configFilePath)){
	$configFilePath = __DIR__ . '/../config_sample.php';
}
include_once $configFilePath;
include_once TLIB_ROOT . 'clsSystem.php';

$objSystem = new clsSystem($db);
$objSystem->createPartitionTables();

