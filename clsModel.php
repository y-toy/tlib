<?php
namespace tlib;

/*
 *
 * DBテーブル操作用クラス
 * 基本的には登録・更新・削除として使用。
 * APCuが使える場合は、メモリ上に格納されたテーブル情報を利用
 * APCuのクリアは php -r "apcu_clear_cache();"
 *
 */

class myModel {

	const CLM_NAME_VALID = 'VALID';
	const CLM_NAME_I_TIME = 'INSERT_TIME';
	const CLM_NAME_U_TIME = 'UPDATE_TIME';

	protected array $clms; 			// 全カラム情報 array('カラム名', 'カラム型', primaryかどうか, auto_incrementかどうか)のarray
	protected int $clmsCount;		// 全カラム数

	protected array $priClms;		// PrimaryKeyカラム情報 array('カラム名', 'カラム型', auto_incrementかどうか)のarray
	protected int $priClmsCnt;		// PrimaryKeyカラム数

	protected clsDb $db;			// DB接続用
	protected string $tablename;	// テーブル名

	protected array $val;

	function __construct(clsDb &$db, string $tableName){

		$this->clms = array();
		$this->clmsCount = 0;
		$this->priClms = array();
		$this->priClmsCnt = 0;

		$this->val = array();

		$this->tablename = $tableName;
		$this->db = $db;

		// apcuが使える場合はapcuから情報を取得できるか試す。
		$bUseAPCu = \apcu_enabled();
		if ($bUseAPCu && apcu_exists($this->tablename)){
			$cacheData = apcu_fetch($this->tablename);
			if ($cacheData !== false){
				$this->clms = $cacheData['clms'];
				$this->clmsCount = count($this->clms);
			}
		}

		// apcuから情報を取得出来なかった場合はDBから
		if ($this->clmsCount == 0){
			$ret = $this->db->query('show columns from ' . $this->tablename);
			if ($ret === FALSE || $ret->num_rows == 0){ return; } // 存在しない
			$this->clmsCount = $ret->num_rows;
			for($i=0;$i<$this->clmsCount;$i++) {
				$row = $ret->fetch_row();
				$this->clms[] = array(
					$row[0], /* カラム名 */
					$this->convClmType($row[1]),  /* カラム型 */
					($row[3] == 'PRI'), /* プライマリキーかどうか */
					(strpos($row[5], 'auto_increment') !== false) /* auto_incrementかどうか */
				);
			}
			if ($bUseAPCu && $this->clmsCount > 0){
				apcu_store($this->tablename, $this->clms);
			}
		}
		if ($this->clmsCount == 0){ return; } // 存在しない

		// primaryのカラム作成
		for ($i=0;$i<$this->clmsCount;$i++){
			if ($this->clms[$i][2]){
				$this->priClms[] = array($this->clms[$i][0], $this->clms[$i][1], $this->clms[$i][3]);
			}
		}
		$this->priClmsCnt = count($this->priClms);
	}

	function __destruct(){}

	// カラムタイプを数値型=0 / 日付／文字列方=1 日付型=2に変換する。
	// 文字列の囲い''が必要か判断する。
	function convClmType(string $type) : int{
		if (strpos($type, 'char') !== FALSE ){ return 1; }
		if (strpos($type, 'text') !== FALSE ){ return 1; }
		if (strpos($type, 'date') !== FALSE ){ return 2; }
		return 0;
	}

	///////////////////////////////////////////////////////////////////////////
	// this->val関係

	// データの取得
	function getVal(string $clmName, string $defaultVal=''){
		if (array_key_exists($clmName, $this->val)){ return $this->val[$clmName]; }
		return $defaultVal;
	}
	function getValArray() : array { return $this->val; }

	// データの設定
	function setVal(string $clmName, mixed $Val){ $this->val[$clmName] = $Val; }
	// 丸ごと設定
	function setValArray(array $valArray){ unset($this->val); $this->val = $valArray; }

	// データの初期化
	function initVal(){ unset($this->val); }

	///////////////////////////////////////////////////////////////////////////
	// ライブラリ

	/**
	 * 指定したカラムがテーブルに存在するかチェック
	 *
	 * @param string $clmName カラム名
	 * @return boolean 存在する場合 true
	 */
	function doesClmExist(string $clmName) : bool {
		for($i=0;$i<$this->clmsCount;$i++) { if ( $this->clms[$i][0] == $clmName){ return TRUE; } }
		return FALSE;
	}

	// カラム情報の取得
	function getClmInfo(string $clmName) : array|bool {
		for($i=0;$i<$this->clmsCount;$i++) { if ( $this->clms[$i][0] == $clmName){ return $this->clms[$i]; } }
		return FALSE;
	}

	/**
	 * where文を作成する
	 *
	 * @param mixed $key Where句で使うデータ。array('clmName'=>'value', 'clmName'=>'value', ...) or 配列ではない場合はプライマリキーが１つとしてそれが指定されたものとみなす。
	 * @return string エラー時は''
	 */
	private function getWhere(mixed $key) : string {
		$ret = '';
		if (!is_array($key)){
			if ($this->priClmsCnt == 1){
				if ($this->priClms[0][1] > 0){
					$ret = 'WHERE ' . $this->priClms[0][0] . ' = "' . $this->db->real_escape_string($key) . '"';
				}else{
					$ret = 'WHERE ' . $this->priClms[0][0] . ' = ' . $key;
				}
			}
		}else{
			$ret = 'WHERE ';
			$keyCnt = count($key);
			$nowCnt = 0;
			foreach($key as $k => $v){
				$info = $this->getClmInfo($k);
				if ($info === false){ return ''; } // 指定ミスはエラー
				if ($info[1] > 0){
					$ret .= $k . ' = "' . $this->db->real_escape_string($v) . '"';
				}else{
					$ret .= $k . ' = ' . $v;
				}
				$nowCnt++;
				if ($nowCnt < $keyCnt){
					$ret .= ' AND ';
				}
			}

		}
		return $ret;
	}

	/**
	 * $keyがプライマリーキーと一致するかチェックする。
	 *
	 * @param mixed $key Where句で使うデータ。array('clmName'=>'value', 'clmName'=>'value', ...) or 配列ではない場合はプライマリキーが１つとしてそれが指定されたものとみなす。
	 * @return boolean $keyがプライマリーキーと一致したらtrue
	 */
	private function checkPrikeySame(mixed $key) : bool{
		// arrayじゃない場合は単純にプライマリーキーの数で判断
		if (!is_array($key)){ return ($this->priClmsCnt == 1); }

		// arrayの場合は、数と内容
		$cntKey = count($key);
		if ($this->priClmsCnt != $cntKey){ return FALSE; }

		// 数が合っている場合は全部が含まれているか
		foreach($key as $k => $v){
			// 含まれていないカラム名があれば、一致しない。
			$info = $this->getClmInfo($k);
			if ($info === false){ return FALSE; }
			if ($info[2] !== true){ return FALSE; }
		}

		return TRUE;
	}


	///////////////////////////////////////////////////////////////////////////
	// 検索

	/**
	 * $keyの条件で検索し結果の1行目を返す。
	 *
	 * @param mixed $key Where句で使うデータ。array('clmName'=>'value', 'clmName'=>'value', ...) or 配列ではない場合はプライマリキーが１つとしてそれが指定されたものとみなす。
	 * @param bool $bPriKeyCheck プライマリーキーが$keyに指定されているかチェックする場合はtrue チェックがNGの場合は検索しない。
	 * @return array
	 */
	function selectOne(mixed $key, bool $bPriKeyCheck = false) : array{

		if ($bPriKeyCheck && !$this->checkPrikeySame($key)){ return array(); }

		$where = $this->getWhere($key);
		if ($where === ''){ return array(); }
		$sql = 'SELECT * FROM '. $this->tablename . ' ' . $where;
		return $this->db->getFirstRowAssoc($sql);
	}

	/**
	 * 検索し最初の一行を$this->val内部格納 コピーしてinsert等の場合にしよう
	 *
	 * @param mixed $key Where句で使うデータ。array('clmName'=>'value', 'clmName'=>'value', ...) or 配列ではない場合はプライマリキーが１つとしてそれが指定されたものとみなす。
	 * @param bool $bPriKeyCheck プライマリーキーが$keyに指定されているかチェックする場合はtrue チェックがNGの場合は検索しない。
	 * @return boolean
	 */
	function selectOneToVal(mixed $key, bool $bPriKeyCheck = false) : bool{
		if ($bPriKeyCheck && !$this->checkPrikeySame($key)){ return false; }

		$ret = $this->selectOne($key);
		if (count($ret) ==0){ return FALSE; }
		$this->val = $ret;
		return TRUE;
	}

	// 検索し全ての行を返す。$keyが配列ではない場合は、1つしかないprimarykeyの条件として指定。primarykeyが1つではない場合はエラー
	function selectAll(mixed $key) : array{
		$where = $this->getWhere($key);
		if ($where === ''){ return array(); }
		$sql = 'SELECT * FROM '. $this->tablename . ' ' . $where;
		return $this->db->getAll($sql);
	}

	///////////////////////////////////////////////////////////////////////////
	// 削除

	/**
	 * 通常削除
	 *
	 * @param mixed $key Where句で使うデータ。array('clmName'=>'value', 'clmName'=>'value', ...) or 配列ではない場合はプライマリキーが１つとしてそれが指定されたものとみなす。
	 * @param bool $bPriKeyCheck プライマリーキーが$keyに指定されているかチェックする場合はtrue チェックがNGの場合は削除しない。
	 * @return boolean
	 */
	function delete(mixed $key, bool $bPriKeyCheck = false) : bool{
		if ($bPriKeyCheck && !$this->checkPrikeySame($key)){ return FALSE; }

		$where = $this->getWhere($key);
		if ($where === ''){ return false; }
		$sql = 'DELETE FROM ' . $this->tablename . ' ' . $where;
		return $this->db->query($sql); // FALSE or TRUE
	}

	/**
	 * 行を残し削除 VALIDがある場合0にする $this->updateより軽い
	 *
	 * @param mixed $key Where句で使うデータ。array('clmName'=>'value', 'clmName'=>'value', ...) or 配列ではない場合はプライマリキーが１つとしてそれが指定されたものとみなす。
	 * @param bool $bPriKeyCheck プライマリーキーが$keyに指定されているかチェックする場合はtrue チェックがNGの場合は削除しない。
	 * @return boolean
	 */
	function validOff(mixed $key, bool $bPriKeyCheck = false) : bool{
		if (!$this->doesClmExist(self::CLM_NAME_VALID)){ return FALSE; }
		if ($bPriKeyCheck && !$this->checkPrikeySame($key)){ return FALSE; }

		$where = $this->getWhere($key);
		if ($where === ''){ return false; }
		$sql = 'UPDATE ' . $this->tablename . ' SET ' . self::CLM_NAME_VALID . '=0 ' . $where;
		return $this->db->query($sql); // FALSE or TRUE
	}

	///////////////////////////////////////////////////////////////////////////
	// 更新

	/**
	 * 更新を行う
	 * UPDATE_TIMEがある場合は自動更新
	 *
	 * @param mixed $key Where句で使うデータ。array('clmName'=>'value', 'clmName'=>'value', ...) or 配列ではない場合はプライマリキーが１つとしてそれが指定されたものとみなす。
	 * @param array $aryUpdateData 更新するデータarray(array('clmName'=>'value'),...) 'value'がnullの場合はnullを設定
	 * @param bool $bPriKeyCheck プライマリーキーが$keyに指定されているかチェックする場合はtrue チェックがNGの場合は更新しない。
	 * @return boolean
	 */
	function update(mixed $key, array $aryUpdateData, bool $bPriKeyCheck = false) : bool {

		if ($bPriKeyCheck && !$this->checkPrikeySame($key)){ return FALSE; }
		$cntUpdateClm = count($aryUpdateData);
		if ($cntUpdateClm == 0){ return FALSE; } // 更新するものがない・・

		$where = $this->getWhere($key);
		if ($where === ''){ return false; }

		$sql = 'UPDATE ' . $this->tablename . ' SET ';
		$nowPos = 0;
		$bUPDATE_TIME = false;
		foreach($aryUpdateData as $k => $v){
			if ($k == self::CLM_NAME_U_TIME){ $bUPDATE_TIME = true; }
			$info = $this->getClmInfo($k);
			if ($info === false){ return false; } // 指定ミスはエラー
			// 数値型
			if ($info[1] == 0){
				if ($v == null){ $v = 'NULL'; }
				$sql .= $k . ' = ' . $v;
			// 文字列型
			}else if ($info[1] == 1){
				if ($v == null || $v == 'NULL'){
					$sql .= $k . ' = NULL';
				}else{
					$sql .= $k . ' = "' . $this->db->real_escape_string($v) . '"';
				}
			// 日付型
			}else if ($info[1] == 2){
				if ($v == null || $v == 'NULL' || $v == ''){
					$sql .= $k . ' = NULL';
				}elseif ($v == 'now()'){
					$sql .= $k . ' = now()';
				}else{
					$sql .= $k . ' = "' . $this->db->real_escape_string($v) . '"';
				}
			// 型未定義（文字列として扱う。）
			}else{
				$sql .= $k . ' = "' . $this->db->real_escape_string($v) . '"';
			}

			$nowPos++;
			if ($nowPos < $cntUpdateClm){
				$sql .= ',';
			}
		}
		if (!$bUPDATE_TIME && $this->doesClmExist(self::CLM_NAME_U_TIME)){
			$sql .= ', ' . self::CLM_NAME_U_TIME . ' = now()';
		}

		return $this->db->query($sql . ' ' . $where); // FALSE or TRUE
	}

	///////////////////////////////////////////////////////////////////////////
	// 登録

	/**
	 * INSERT処理を行う。
	 * プライマリーキーの自動取得が指定された場合は、自動で取得(MAX()+1)、もしくは、auto_incrementの場合は何もしない
	 * auto_incrementの場合、$this->getLastIndexId()で挿入したIDを取得可
	 *
	 * @param boolean $bAutoPriKey プライマリーキーを自動取得（MAX()+1 or auto_increment考慮）
	 * @param boolean $bLock プライマリーキーをMAX+1で取得することになった場合、テーブルをロックするかどうか。
	 *                true(ロックする)にするとtransactionが切れるため注意
	 * @return boolean
	 */
	function insert(bool $bAutoPriKey = true, $bLock = false) : bool{
		$bGetMaxIndex = false;
		if ($bAutoPriKey){
			if ($this->priClmCnt == 1 && $this->priClms[0][1] = 2){
				if ($this->priClms[0][2]){
					// auto_incrementの場合は、特に何もしない。
					// insertCoreで当該カラムは無視される。
				}else{
					$bGetMaxIndex = true;
				}
			}else{
				// 自動設定出来ない
				return FALSE;
			}
		}
		if ($bGetMaxIndex){
			if ($bLock){ $this->db->writeLockTable(array($this->tablename)); }
			$this->val[$this->priClms[0][0]] = $this->getNextNewId();
		}

		$ret = $this->insertCore();

		if ($bGetMaxIndex && $bLock){ $this->db->unlockTables(); }

		return $ret;

	}

	/**
	 * $this->valに設定している内容で全て登録する。
	 */
	function insertCore() : bool{
		$strClmNames = '';
		$strValues = '';
		for($i=0;$i<$this->clmsCount;$i++){

			// primarykeyでautoincrementの場合がある場合は指定しない。
			if ($this->clms[$i][2] && $this->clms[$i][3]){ continue; }

			$clmName = $this->clms[$i][0];
			$clmType = $this->clms[$i][1];

			// カラム名
			$strClmNames .= $clmName . ',';

			// value
			if ($clmName == self::CLM_NAME_U_TIME || $clmName == self::CLM_NAME_I_TIME){
				$strValues .= 'now()';
			}else{
				$setVal = $this->val[$clmName];
				// 数値型
				if ($clmType == 0) {
					if ($setVal === NULL){
						$strValues .= 'NULL';
					}else{
						$strValues .= $setVal;
					}
				// 文字列型
				}elseif ($clmType == 1) {
					if ($setVal === NULL || $setVal == 'NULL'){
						$strValues .= 'NULL';
					}else{
						$strValues .= '\'' . $this->db->real_escape_string($setVal) . '\'';
					}
				// 日付型
				}elseif ($clmType == 2) {
					if ($setVal === NULL || $setVal == ''){
						$strValues .= 'NULL';
					}else if($setVal == 'now()'){
						$strValues .= 'now()';
					}else{
						$strValues .= '\'' . $this->db->real_escape_string($setVal) . '\'';
					}
				// 型未定義（文字列として扱う。）
				}else{
					$strValues .= '\'' . $this->db->real_escape_string($setVal) . '\'';
				}
			}
			$strValues .= ',';
		}
		$strClmNames = mb_substr($strClmNames, 0, -1, 'UTF-8');
		$strValues = mb_substr($strValues, 0, -1, 'UTF-8');

		$sql = 'INSERT INTO ' . $this->tablename . ' (' . $strClmNames . ') VALUES (' . $strValues . ')';
		return $this->db->query($sql); // FALSE or TRUE
	}

	// MAX_ID + 1の取得 (primarykeyが１つの場合)
	function getNextNewId(): bool|int{
		if ($this->priClmCnt != 1){ return FALSE; }

		$sql = 'SELECT MAX(' . $this->priClmName[0][0] . ') FROM ' . $this->tablename;
		$id = $this->db->getFirstOne($sql);
		if ($id === null){ return 1; }
		return ($id + 1);
	}

	// MAX_ID + 1の取得 (PrimaryKeyが指定できない場合)
	function getNextNewIdWithClmName(string $clmName): bool|int{
		$sql = 'SELECT MAX(' . $clmName . ') FROM ' . $this->tablename;
		$id = $this->db->getFirstOne($sql);
		if ($id === null){ return FALSE; }
		// NULLなら１を返す
		if(is_null($id)){ return 1; }
		return ($id + 1);
	}

	// auto_incrementでインサートされた場合の追加されたindex値を取得する。
	function getLastIndexId(){
		return $this->db->getFirstOne('SELECT LAST_INSERT_ID()');
	}

}
