<?php
namespace tlib;


class clsSystem{

	private clsDB $db;

	function __construct(clsDB &$db){
		$this->db = $db;
	}

	/**
	 * Creates partition tables from the DB Table 'PARTITION_TABLE'.
	 * this function called by cron once a year and make partition tables for the next year.
	 * please execut around October every year.
	 */
	function createPartitionTables() {

		try {

			$results = $this->db->getAllAssoc('SELECT TABLE_NAME, LAST_EXECUTION_YEAR, PARTITION_TYPE FROM PARTITION_TABLE');
			if ($results === null) { throw new Exception("PARTITION_TABLEからデータを取得できませんでした。"); }

			$currentYear = (int)date("Y");

			foreach ($results as $result) {
				$tableName = $result['TABLE_NAME'];
				$lastExecutionYear = (int)$result['LAST_EXECUTION_YEAR'];
				$partitionType = (int)$result['PARTITION_TYPE'];

				// 最後の実行年が現在の年と同じ場合はスキップ
				if ($lastExecutionYear >= $currentYear) {
					echo "{$tableName}のパーティションは最新です。処理をスキップします。\n";
					continue;
				}

				// 翌年のパーティションを作成するSQL文を生成
				if ($partitionType === 1) {
					$nextYear = $lastExecutionYear + 1; // 2024年はLESS THAN (2026)を作ることになる。（これで2025年用）
					$nextNextYear = $lastExecutionYear + 2;
					$sql = "ALTER TABLE {$tableName} PARTITION BY RANGE (YEAR(INSERT_TIME)) (
						PARTITION y{$nextYear} VALUES LESS THAN ({$nextNextYear})
					)";
				} elseif ($partitionType === 2) {
					$nextYear = $lastExecutionYear + 1;
					$sql = "ALTER TABLE {$tableName} PARTITION BY RANGE ((YEAR(INSERT_TIME)*100 + MONTH(INSERT_TIME))) (";
					for ($month = 1; $month <= 12; $month++) {
						$partitionName = sprintf("m%04d%02d", $nextYear, $month);
						$partitionValue = sprintf("%04d%02d", $nextYear, $month);
						$sql .= "PARTITION {$partitionName} VALUES LESS THAN ({$partitionValue}),";
					}
					$sql = rtrim($sql, ',') . ")";
				} else {
					echo "{$tableName}の未知のPARTITION_TYPEです。処理をスキップします。\n";
					continue;
				}

				// SQL文を実行
				$ret = $this->db->query($sql);
				if ($ret){
					echo "{$tableName}のパーティションテーブルを作成しました。\n";
				}else{
					echo "{$tableName}のパーティションテーブルの作成に失敗しました。{$sql}\n";
				}

				// PARTITION_TABLEを更新
				$sql = 'UPDATE PARTITION_TABLE SET LAST_EXECUTION_YEAR = ' . $nextYear . ' WHERE TABLE_NAME = "' . $tableName . '"';
				$ret = $this->db->query($sql);
				if ($ret){
					echo "PARTITION_TABLEの更新に成功しました。\n";
				}else{
					echo "PARTITION_TABLEの更新に失敗しました。{$sql}\n";
				}
			}

		}catch (Exception $e) {
			echo "エラー: " . $e->getMessage() . "\n";
		}
	}
}

