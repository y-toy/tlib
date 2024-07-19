<?php
namespace tlib;

/*
 * DB ラッパークラス
 */

class clsDB extends \mysqli {

	private ?bool $bConnected = false;
	private string $errorInfo = '';
	private bool $bRockTable = false;
	private string $logFolder = ''; // これが''でなければ、エラー時にこのフォルダの下にログを出力する。

	//
	// トランザクションのやり方は以下
	//
	// $this->autocommit(FALSE);
	//  エラー時；
	//    $this->rollback();
	//    $this->autocommit(TRUE);
	//  正常時：
	//    $this->commit();
	//    $this->autocommit(TRUE);
	//
	// MAX()+1などやっている場合は、table lock xxx writeが必要。
	// トランザクション内の全てのテーブルをロックする必要がある。
	// ロックは本クラスのwriteLockTableを利用のこと。
	//

	public function __construct(array $conf) {
		if ($this->bConnected){ $this->close(); $this->bConnected = false; }
		$this->logFolder = defined(TLIB_LOG)?TLIB_LOG:'';

		try{
			parent::__construct($conf['host'], $conf['user'], $conf['pass'], $conf['name'], $conf['port'], $conf['socket']);
		}catch(mysqli_sql_exception $e){

		}

		// エラー時
		if ($this->connect_errno){
			$this->errorInfo = $this->connect_errno . ' : ' . $this->connect_error;
			if ($this->logFolder != ''){
				\tlib\util::vitalLogOut(3, $this->errorInfo, $this->logFolder);
			}
		}
		$this->bConnected = true;
	}
	public function __destruct(){
		if ($this->bRockTable){ $this->unlockTables(); }
	}

	public function query(string $query, ?int $resultmode = NULL) : \mysqli_result|bool {
		$ret = parent::query($query, $resultmode);
		if ($ret === false){ $this->whenQueryError($query); }
		return $ret;
	}

	// 接続済みか
	public function isThisConnected(){ return $this->bConnected; }
	// エラー時のエラー情報
	public function getErrorInfo(){ return $this->errorInfo; }

	// 1行目の1つ目のカラムのみ取得（count(*)などで使用）
	// 結果に1行も無い場合もエラーを返すので注意のこと
	public function getFirstOne(string $sql) : null|string|int|float {
		$res = $this->query($sql);
		if ($res === FALSE){ $this->whenQueryError($sql); return null; }
		if ($res->num_rows == 0){ return null; }
		$row = $res->fetch_row();
		$res->free();
		return $row[0];
	}

	// 1行目の1つ目のカラムのみ取得（count(*)などで使用）
	// 結果に1行も無い場合やエラーの場合、空文字''を返すので注意のこと
	public function getFirstOneStr(string $sql) : string {
		$res = $this->query($sql);
		if ($res === FALSE){ $this->whenQueryError($sql); return ''; }
		if ($res->num_rows == 0){ return ''; }
		$row = $res->fetch_row();
		$res->free();
		return (string)$row[0];
	}

	// 最初の1行目のみ取得 (fetch_row)
	// 結果に1行も無い場合もエラーを返すので注意のこと
	public function getFirstRow(string $sql) : null | array {
		$res = $this->query($sql);
		if ($res === FALSE){ $this->whenQueryError($sql); return null; }
		if ($res->num_rows == 0){ return null; }
		$row = $res->fetch_row();
		$res->free();
		return $row;
	}

	// 最初の1行目のみ取得 (fetch_assoc)
	// 結果に1行も無い場合もエラーを返すので注意のこと
	public function getFirstRowAssoc(string $sql) : null | array {
		$res = $this->query($sql);
		if ($res === FALSE){ $this->whenQueryError($sql); return null; }
		if ($res->num_rows == 0){ return null; }
		$row = $res->fetch_assoc();
		$res->free();
		return $row;
	}

	// 検索結果の特定カラムのみで配列化し返す。
	// エラー時はFALSEを返す。
	public function getArrayClm(string $sql, int $clm=0) : null|array {
		$res = $this->query($sql);
		if ($res === FALSE){ $this->whenQueryError($sql); return null; }
		$aryRet = array();
		while(($row = $res->fetch_row())){ $aryRet[] = $row[$clm]; }
		$res->free();
		return $aryRet;
	}

	// 全結果の配列化 戻りが巨大になりそうな場合はメモリを食うので使用を避けること。
	public function getAll(string $sql) : null|array {
		$res = $this->query($sql);
		if ($res === FALSE){ $this->whenQueryError($sql); return null; }
		$aryRet = array();
		while(($row = $res->fetch_row())){
			$aryRet[] = $row;
		}
		$res->free();
		return $aryRet;
	}
	// 全結果の配列化のfetch_assoc版
	public function getAllAssoc(string $sql){
		$res = $this->query($sql);
		if ($res === FALSE){ $this->whenQueryError($sql); return null; }
		$aryRet = array();
		while(($row = $res->fetch_assoc())){
			$aryRet[] = $row;
		}
		$res->free();
		return $aryRet;
	}

	// テーブルロックを行う。（NG時は1秒waitし、10回トライ）
	// $tablesはarray
	public function writeLockTable(array $tables) : bool{
		$sql = 'LOCK TABLES ' . implode(' WRITE,', $tables) . ' WRITE';
		for ($i=0;$i < 10;$i++){
			$ret = $this->query($sql);
			if ($ret){ $this->bRockTable = true; return TRUE; }
			sleep(1);
		}
		$this->whenQueryError($sql);
		return FALSE;
	}
	// アンロックテーブル
	public function unlockTables(){
		$this->bRockTable = false;
		return $this->query('UNLOCK TABLES');
	}

	private function whenQueryError($sql){
		$this->errorInfo = $this->errno . ' : ' . $this->error . ' : ' . $sql;
		if ($this->logFolder != ''){
			\tlib\util::vitalLogOut(3, $this->errorInfo, $this->logFolder);
		}
	}
}
