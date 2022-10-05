<?php
namespace tlib;

include 'vendor/autoload.php';

/**
 * アカウント処理のベース アカウント関係のテーブル処理を行う。
 *  ・継承先は、簡易なログインとOAuthログインを想定。（OAuthは将来）
 *  ・アカウントの個人情報は持たない。アカウント認証のみに特化させる。
 */
class clsAccount {

	protected ?clsDB $db = null;
	protected $loginExpiredDays = 0; // ログインのセッションの有効期限日数
	protected $strError = ''; // エラーが発生した際にエラー内容を入れておくメンバ変数
	protected $bChangeDB = false; // 実行時にDBを変更する場合 true
	protected $strBackDBName = ''; //  実行時にDBを変更する場合、処理後にDBを戻す名前

	///////////////////////////////////////////////////////////////////////////
	// construct / destruct

	public function __construct(clsDB $db, int $loginExpiredDays = 365, bool $bChangeDB = false, string $strBackDBName=''){
		$this->db = $db;
		$this->loginExpiredDays = $loginExpiredDays;
		$this->bChangeDB = $bChangeDB;
		$this->strBackDBName = $strBackDBName;
	}

	public function __destruct(){

	}

	///////////////////////////////////////////////////////////////////////////
	// アカウント関係

	/**
	 * アカウント登録 認証系の場合、self::checkVerifyを実行すればアカウント登録までされるので注意
	 *
	 * @param integer $APP_ID アカウント登録するAPP
	 * @param integer $ID_TYPE 1 e-mail 2 SMS 99 任意文字列
	 * @param string $ID_INFO ID_TYPE=1の場合 e-mail 2の場合 電話番号 99の場合任意文字列
	 * @param string $PASS hash("sha3-256", "password")済みのデータ
	 * @param integer $TFA 2段階認証を行うか 1 行う 0 行わない
	 * @param integer $LOGIN_NOTICE ログイン時に通知するか 1 する 0 しない
	 * @return boolean
	 */
	public function addNewAccount(int $APP_ID, int $ID_TYPE, string $ID_INFO, string $PASS, int $TFA=1, int $LOGIN_NOTICE=1) : bool {

		$this->useAccountDB();

		// 新規登録
		$this->db->autocommit(FALSE);

		try{
			// accountの登録
			for ($i=0;$i < 10;$i++){
				$ACCOUNT_ID_CHARS = hash('sha3-256', Date('YmdHis') . bin2hex(random_bytes(16)));;
				$sql = 'INSERT INTO ACCOUNT (ACCOUNT_ID_CHARS, PASS, TFA, TFA_AUTH_ID, LOGIN_NOTICE, LOGIN_NOTICE_AUTH_ID, LAST_LOGIN_TIME, VALID, INSERT_TIME, UPDATE_TIME)
					VALUES ("' . $ACCOUNT_ID_CHARS . '","' . $PASS . '", ' . $TFA . ', 1, ' . $LOGIN_NOTICE . ', 1, now(), 1, now(), now())';
				$ret = $this->db->query($sql);
				if ($ret == true){ break; }
				if ($i==9){ throw new \Exception('Failed to insert ACCOUNT table.'); }
			}

			$ACCOUNT_ID = $this->db->getFirstOne('SELECT LAST_INSERT_ID()');

			// ACCOUNT_AUTHの登録 最初の登録なのでAUTH_IDは必ず１
			$sql = 'INSERT INTO ACCOUNT_AUTH (ACCOUNT_ID, AUTH_ID, ID_TYPE, ID_INFO, INSERT_TIME, VALID)
				VALUES (' . $ACCOUNT_ID . ', 1, ' . $ID_TYPE . ', "' . $ID_INFO . '", now(), 1)';

			$ret = $this->db->query($sql);
			if (!$ret){ throw new \Exception('Failed to insert ACCOUNT_AUTH table.'); }

			// OAUTH_USER_PERMITの登録
			$sql = 'INSERT INTO OAUTH_USER_PERMIT (APP_ID, ACCOUNT_ID, SCOPE) VALUES (' . $APP_ID . ',' . $ACCOUNT_ID . ',"")';
			$ret = $this->db->query($sql);
			if (!$ret){ throw new \Exception('Failed to insert OAUTH_USER_PERMIT table.'); }

		}catch(\Exception $e){
			$this->db->rollback();
			$this->db->autocommit(TRUE);
			$this->strError = $e->getMessage();

			$this->useOrgDB();
			return false;
		}

		$this->db->commit();
		$this->db->autocommit(TRUE);

		$this->useOrgDB();
		return true;
	}

	///////////////////////////////////////////////////////////////////////////
	// ログイン ログアウト

	/**
	 * ログインする
	 *
	 * @param integer $APP_ID ログイン対象のアプリ
	 * @param integer $ID_TYPE $ID_TYPE 1 e-mail 2 SMS 99 任意文字列 -1の場合、$ID_INFOから自動判別
	 * @param string $ID_INFO ID_TYPE=1の場合 e-mail 2の場合 電話番号 99の場合任意文字列
	 * @param string $PASS 平文のパス
	 * @param bool $useOAUthSessionTable trueの場合OAUTH_SESSIONテーブルにセッションを設定し、$SESSION_CODEにセッション文字列を設定する。
	 * @param string &$SESSION_CODE $useOAUthSessionTableがtrueの場合、セッション文字列を返す。
	 * @return int ACCOUNT_IDを返す。取得出来なかった場合は 0
	 */
	function login(int $APP_ID, int $ID_TYPE, string $ID_INFO, string $PASS, bool $useOAUthSessionTable = true, string &$SESSION_CODE) : int {

		$this->useAccountDB();

		$SESSION_CODE = '';
		$ACCOUNT_ID = 0;

		try{
			// $ID_TYPEがない場合は、$ID_INFOから判別
			if ($ID_TYPE < 0){
				if (isThis::email($ID_INFO)){
					$ID_TYPE = 1;
				}else if (isThis::int($ID_INFO, true)) {
					$ID_TYPE = 2;
				}else{
					$ID_TYPE = 99;
				}
			}

			// 2つのSQLでチェックするのは冗長だが、1SQLでのチェックに自信が無い＆テストデータもないので。
			// 以下は1SQLでのチェック
			// $sql = 'SELECT OUP.ACCOUNT_ID FROM OAUTH_USER_PERMIT AS OUP
			// LEFT JOIN ACCOUNT_AUTH AS AA ON OUP.ACCOUNT_ID = AA.ACCOUNT_ID
			// LEFT JOIN ACCOUNT AS ACT ON OUP.ACCOUNT_ID = ACT.ACCOUNT_ID
			// WHERE OUP.APP_ID = ' . $APP_ID . ' AND AA.ID_TYPE=' . $ID_TYPE . ' AND AA.ID_INFO ="' . $this->db->real_escape_string($ID_INFO) . '" AND ACT.PASS ="' . $this->hashPass($PASS) . '"';

			// $ACCOUNT_ID = $this->db->getFirstOne($sql);
			// if ($ACCOUNT_ID === null){ throw new Exception('FAILED AUTH'); }

			$sql = 'SELECT AA.ACCOUNT_ID FROM ACCOUNT_AUTH AS AA
			LEFT JOIN ACCOUNT AS ACT ON AA.ACCOUNT_ID = ACT.ACCOUNT_ID
			WHERE AA.ID_TYPE=' . $ID_TYPE . ' AND AA.ID_INFO ="' . $this->db->real_escape_string($ID_INFO) . '" AND ACT.PASS ="' . $this->hashPass($PASS) . '"';
			$aryAccountId = $this->db->getArrayClm($sql);
			if ($aryAccountId === null){ throw new \Exception('FAILED TO CHECK ACCOUNT_AUTH SQL ERROR AUTH'); }
			if (count($aryAccountId) == 0){ throw new \Exception('NO USER FOUND'); }

			$sql = 'SELECT ACCOUNT_ID FROM OAUTH_USER_PERMIT WHERE APP_ID = ' . $APP_ID . ' AND ACCOUNT_ID IN (' . implode(',',$aryAccountId) . ')';
			$ACCOUNT_ID = $this->db->getFirstOne($sql);
			if ($ACCOUNT_ID === null){ throw new \Exception('NO USER FOUND ON THE APP OR SQL ERROR'); }

			if ($useOAUthSessionTable){
				$USER_INFO = array ('IP'=> $_SERVER['REMOTE_ADDR']);
				// SESSION_CODEが被らないように一応10回試す。
				for ($i=0;$i < 10;$i++){
					$SESSION_CODE = json_encode(hash("sha3-256", $APP_ID . $ACCOUNT_ID .  time() . random_bytes(32)));
					$sql = 'INSERT INTO OAUTH_SESSION VALUES (APP_ID, ACCOUNT_ID, ACCESS_TOKEN, USER_INFO, EXPIRE_TIME, INSERT_TIME) VALUE (
						' . $APP_ID . ', ' . $ACCOUNT_ID . ',"' . $SESSION_CODE . '","' . $USER_INFO . '", ADDDATE(now(), ' . $this->loginExpiredDays . '), now())';
					$ret = $this->db->query($sql);
					if ($ret){ break; }
					if ($i == 9){ throw new \Exception('FAILED TO INSERT OAUTH_SESSION'); }
				}
			}


		}catch(\Exception $e){
			$this->strError = $e->getMessage();
			$ACCOUNT_ID = 0;
		}

		$this->useOrgDB();
		return (int)$ACCOUNT_ID;
	}

	/**
	 * OAUTH_SESSIONの当該行を削除する。
	 *
	 * @param string $SESSION_CODE
	 * @return boolean
	 */
	function logout(string $SESSION_CODE) : bool {
		$this->useAccountDB();
		$ret = $this->db->query('DELETE FROM OAUTH_SESSION WHERE ACCESS_TOKEN = "' .  $this->db->real_escape_string($SESSION_CODE) . '"');
		$this->useOrgDB();
		return $ret;
	}

	/**
	 * OAUTH_SESSIONテーブルを使い、ログイン中か調べる
	 *
	 * @param integer $APP_ID ログイン中か調べるAPP_ID
	 * @param string $SESSION_CODE セッション
	 * @param bool $bUpdateExpireDate ログイン中だった場合、有効期限を伸ばす場合はtrue
	 * @return integer ログインしているaccountのID
	 */
	function isLogin(int $APP_ID, string $SESSION_CODE, bool $bUpdateExpireDate = true) : int{

		$this->useAccountDB();

		$ACCOUNT_ID = 0;

		try{
			$sql = 'SELECT ACCOUNT_ID FROM OAUTH_SESSION
			WHERE APP_ID = ' . $APP_ID . ' AND ACCESS_TOKEN = "' . $this->db->real_escape_string($SESSION_CODE) . '" AND EXPIRE_TIME <= now()';
			$ACCOUNT_ID = $this->db->getFirstOne($sql);
			if ($ACCOUNT_ID === null){ throw new \Exception('Not Found the session'); }

			// 有効期限を伸ばす
			if ($bUpdateExpireDate){
				$sql = 'UPDATE OAUTH_SESSION SET EXPIRE_TIME = ADDDATE(now(), ' . $this->loginExpiredDays . ')
				WHERE APP_ID = ' . $APP_ID . ' AND ACCESS_TOKEN = "' . $this->db->real_escape_string($SESSION_CODE) . '"';

				$ret = $this->db->query($sql);
				if (!$ret){ throw new \Exception('failed to update token'); }
			}
		}catch(\Exception $e){
			$this->strError = $e->getMessage();
			$ACCOUNT_ID = 0;
		}
		$this->useOrgDB();
		return $ACCOUNT_ID;
	}

	/**
	 * 期限切れのSESSIONを削除する。
	 *
	 * @return boolean
	 */
	function deleteExpiredSession() : bool {
		$this->useAccountDB();
		$ret = $this->db->query('DELETE FROM OAUTH_SESSION WHERE EXPIRE_TIME <= now()');
		$this->useOrgDB();
		return $ret;
	}

	///////////////////////////////////////////////////////////////////////////
	// 2FA認証関係

	/**
	 * 認証開始
	 *
	 * @param int $APP_ID 認証を使うAPPのID
	 * @param integer $ACCOUNT_ID アカウントのID 0の場合 未登録ユーザ
	 * @param integer $ID_TYPE 1 e-mail 2 SMS 99 任意文字列
	 * @param string $ID_INFO $ID_TYPE=1の場合 e-mail 2の場合 電話番号 99の場合任意文字列
	 * @param string $PASS 平文パスワード $ACCOUNT_ID=0の場合必須 それ以外は無視 (DBにはNULL設定)
	 * @param string &$VERIFY_CODE 認証コード（返却）
	 * @param string &$TEMP_SESSION_KEY セッションキー（返却）
	 * @param boolean &$bAuthExists 既に登録済みである場合、trueを返す。（戻り値がfalseの場合チェック この場合認証不要）
	 * @return boolean
	 */
	public function startVerify(int $APP_ID, int $ACCOUNT_ID, int $ID_TYPE, string $ID_INFO, string $PASS, string &$VERIFY_CODE, string &$TEMP_SESSION_KEY, bool &$bAuthExists) : bool{

		$this->useAccountDB();

		try{
			$bAuthExists = false;

			// 10分以内に3回以上同じID登録は怪しいので一旦NGにする。
			$sql = 'SELECT COUNT(*) FROM AUTH_VERIFY WHERE ID_INFO = "' . $ID_INFO . '" AND INSERT_TIME >= now() - INTERVAL 10 MINUTE';
			$cnt = $this->db->getFirstOne($sql);
			if ($cnt === null){ throw new \Exception('AUTH_VERIFY CHECK SQL ERROR'); }
			if ($cnt >= 3){ throw new \Exception('TOO MUCH VERIFY'); }


			// 既に認証済みではないか確認
			$sql = 'SELECT COUNT(*) FROM OAUTH_USER_PERMIT AS OUP
				LEFT JOIN ACCOUNT_AUTH AS AAUTH ON OUP.ACCOUNT_ID = AAUTH.ACCOUNT_ID
				WHERE OUP.APP_ID = ' . $APP_ID . ' AND AAUTH.ID_TYPE = ' . $ID_TYPE . ' AND AAUTH.ID_INFO = "' .  $ID_INFO . '"';
			$cnt = $this->db->getFirstOne($sql);
			if ($cnt === null){ throw new \Exception('OAUTH_USER_PERMIT CHECK SQL ERROR'); }
			if ($cnt > 0){
				// 既に認証済み
				$bAuthExists = true;
				throw new \Exception('ALREADY AUTHED');
			}

			// 認証開始
			if ($ACCOUNT_ID == 0){
				if ($PASS == ''){throw new \Exception('NOT SET PASS'); }
				$PASS = $this->hashPass($PASS);
			}

			$VERIFY_CODE = random_int(100000, 999999); // とりあえず6桁
			// $TEMP_SESSION_KEYが被ってNGになるかもしれないので、5回繰り返す。
			$inserted = false;
			for ($i=0;$i < 4;$i++){
				$TEMP_SESSION_KEY = hash('sha3-256', $ID_INFO . bin2hex(random_bytes(16)));
				$sql = 'INSERT INTO AUTH_VERIFY (ACCOUNT_ID, ID_TYPE, ID_INFO, VERIFY_CODE, TEMP_SESSION_KEY, PASS, IP_ADDRESS, AUTHED_CNT, EXPIRE_TIME, INSERT_TIME)
				VALUES (' . $ACCOUNT_ID . ', ' . $ID_TYPE . ', "' . $ID_INFO . '", "' . $VERIFY_CODE . '", "' . $TEMP_SESSION_KEY . '", ' . (($ACCOUNT_ID==0)?'"' . $PASS . '"':'NULL') . ', INET6_ATON("' . $_SERVER['REMOTE_ADDR'] . '"), 0,now() + INTERVAL 30 MINUTE, now())';
				$ret = $this->db->query($sql);
				if ($ret === true){ $inserted = true; break; }
			}
			if (!$inserted){
				throw new \Exception('FAILED TO INSERT AUTH_VERIFY');
			}

		}catch(\Exception $e){
			$this->strError = $e->getMessage();
			$this->useOrgDB();
			return false;
		}

		$this->useOrgDB();
		return true;
	}

	/**
	 * 認証をチェックする。
	 * チェックが問題なければ、以下。新規登録の場合、ログイン済みとなっているので注意。
	 * 当該チェック認証のACCOUNT_ID = 0であれば、ユーザ新規登録（ACCOUNTとACCOUNT_AUTHに追加）
	 * 当該チェック認証のACCOUNT_ID > 0であれば、ACCOUNT_AUTHに追加
	 *
	 * @param int $APP_ID
	 * @param string $TEMP_SESSION_KEY
	 * @param string $VERIFY_CODE
	 * @return boolean
	 */
	function checkVerify(int $APP_ID, string $TEMP_SESSION_KEY, string $VERIFY_CODE) : bool{

		$this->useAccountDB();

		try{

			$sql = 'SELECT * FROM AUTH_VERIFY WHERE TEMP_SESSION_KEY = "' . $TEMP_SESSION_KEY . '"';
			$row = $this->db->getFirstRowAssoc($sql);
			if ($row === null){ throw new \Exception('TEMP_SESSION_KEY DOES NOT EXISTS'); }

			// 10回失敗していたら攻撃
			if ($row['VERIFY_CODE'] > 10){ throw new \Exception('CONSIDER ATTACKED'); }

			if ($row['VERIFY_CODE'] != $VERIFY_CODE){
				$sql = 'UPDATE AUTH_VERIFY SET AUTHED_CNT = AUTHED_CNT+1 WHERE TEMP_SESSION_KEY = "' . $TEMP_SESSION_KEY . '"';
				$this->db->query($sql);
				throw new \Exception('VERIFY_CODE NOT MUCH');
			}

			if ($row['ACCOUNT_ID'] == 0){

				// 新規登録
				$ret = $this->addNewAccount($APP_ID, (int)$row['ID_TYPE'], $row['ID_INFO'], $row['PASS'], 1, 1);
				if (!$ret){ throw new \Exception('Failed to Add account : ' . $this->getError()); }

				// 上記関数ないで元のDBに戻されるので、
				$this->useAccountDB();

			}else{
				// 認証情報追加 同一人物内の+1なので被ることはまずないし、被ったら当人がわかるはず
				$sql = 'SELECT MAX(AUTH_ID) + 1 FROM ACCOUNT_AUTH WHERE ACCOUNT_ID = ' . $row['ACCOUNT_ID'];
				$nextAuthId = $this->db->getFirstOne($sql);
				if ($nextAuthId == null){ throw new \Exception('FAILED TO GET NEXT ID'); }

				// ACCOUNT_AUTHの登録
				$sql = 'INSERT INTO ACCOUNT_AUTH (ACCOUNT_ID, AUTH_ID, ID_TYPE, ID_INFO, INSERT_TIME, VALID)
					VALUES (' . $row['ACCOUNT_ID'] . ', ' . $nextAuthId . ', ' . $row['ID_TYPE'] . ', "' . $row['ID_INFO'] . '", now(), 1)';

				$ret = $this->db->query($sql);
				if (!$ret){ throw new \Exception('FAILED TO INSERT ACCOUNT_AUTH'); }
			}

			// 認証出来たら、当該行を削除
			$sql = 'DELETE FROM AUTH_VERIFY WHERE TEMP_SESSION_KEY = "' . $TEMP_SESSION_KEY . '"';
			$this->db->query($sql);

		}catch(\Exception $e){
			$this->strError = $e->getMessage();
			$this->useOrgDB();
			return false;
		}

		$this->useOrgDB();
		return true;
	}

	/**
	 * 認証テーブル定期削除用
	 *
	 * @return void
	 */
	function delVerifyRowAuto(){
		$this->useAccountDB();
		$this->db->query('DELETE FROM AUTH_VERIFY WHERE EXPIRE_TIME <= now()');
		$this->useOrgDB();
	}

	///////////////////////////////////////////////////////////////////////////
	// アプリ関係（OAUTH_APPLICATION）

	/**
	 * 指定された情報でアプリを登録する。
	 *
	 * @param array $appName アプリの名前 OAuth2でログイン画面に表示する名前
	 * @param array $appTittle アプリのタイトル OAuth2でログイン画面に表示するタイトル
	 * @param string $siteUrl optional サイトのURL
	 * @param int $APP_ID 登録されたアプリのID
	 * @param string $APP_ID_CHARS 登録されたアプリのID（文字列）
	 * @param string $APP_ID_SECRET return the value of client secret when app register succeed
	 * @return boolean
	 */
	public function addApp(array $appName /*= array('jp'=>'','en'=>'')*/, array $appTittle /*= array('jp'=>'','en'=>'')*/, string $siteUrl,
	int &$APP_ID, string &$APP_ID_CHARS, string &$APP_ID_SECRET) :bool {

		// 必要データ設定/初期化
		$APP_ID_CHARS = '';
		$APP_ID_SECRET = bin2hex(random_bytes(32));
		$APP_INFO = JSON_ENCODE(array(
			'APP_NAME' => $appName,
			'APP_LOGIN_TITLE' => $appTittle), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
		$SCOPE = JSON_ENCODE(array(), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

		$this->useAccountDB();

		try{

			// ID取得
			for($i=0;$i < 10;$i++){
				$tempVal = Date('YmdHis') . mt_rand(1000000, 9000000);
				foreach ($appName as $key => $value){
					$tempVal .= $key . $value;
				}
				$APP_ID_CHARS = hash('tiger160,4', $tempVal);

				// IDが被らないように
				$sql = 'SELECT COUNT(*) FROM OAUTH_APPLICATION WHERE APP_ID_CHARS = "' .  $APP_ID_CHARS . '"';
				$res = $this->db->query($sql);
				if ($res === FALSE || $res->num_rows == 0){ throw new \Exception('SQL ERROR : FAILED TO GET new ID chars'); }
				$row = $res->fetch_row();
				$res->free();
				if ($row[0] == 0){ break; }
				if ($i == 9){ throw new \Exception('FAILED TO GET new ID chars : MAX count'); }
			}

			// インサート実行
			$sql = 'INSERT INTO OAUTH_APPLICATION (APP_ID_CHARS, APP_ID_SECRET, SITE_URL, APP_INFO, SCOPE)
			VALUES (
				"' . $APP_ID_CHARS . '",
				"' . $APP_ID_SECRET . '",
				"' . $this->db->escape_string($siteUrl) . '",
				"' . $this->db->escape_string($APP_INFO) . '",
				"' . $this->db->escape_string($SCOPE) . '",
			)';
			$ret = $this->db->query($sql);
			if (!$ret){ throw new \Exception('FAILED TO INSERT OAUTH_APPLICATION'); }

			$APP_ID = $this->db->getFirstOne('SELECT LAST_INSERT_ID()');
			if ($APP_ID === null){ throw new \Exception('FAILED TO GET LAST_INSERTED_ID'); }

		}catch(\Exception $e){
			$this->strError = $e->getMessage();
			$this->useOrgDB();
			return false;
		}

		$this->useOrgDB();
		return true;
	}

	/**
	 * アプリ情報を修正する。
	 *
	 * @param integer|string $APP_INDEX intの場合はAPP_ID、stringの場合はAPP_ID_CHARS
	 * @param array $appName
	 * @param array $appTittle
	 * @return boolean
	 */
	public function modAppInfoWithID(int|string $APP_INDEX, array $appName /*= array('jp'=>'','en'=>'')*/, array $appTittle /*= array('jp'=>'','en'=>'')*/) : bool {

		$this->useAccountDB();

		$APP_INFO = JSON_ENCODE(array(
			'APP_NAME' => $appName,
			'APP_LOGIN_TITLE' => $appTittle), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

		$sql = 'UPDATE OAUTH_APPLICATION SET APP_INFO = "' . $this->db->escape_string($APP_INFO) . '"';
		if (gettype($APP_INDEX) == 'integer'){
			$sql .= ' WHERE APP_ID = ' . $APP_INDEX;
		}else{
			$sql .= ' WHERE APP_ID_CHARS = "' . $this->db->escape_string($APP_INDEX) . '"';
		}

		$ret = $this->db->query($sql);

		$this->useOrgDB();
		return $ret;
	}
	/**
	 * アプリ情報を削除する。
	 *
	 * @param integer|string $APP_INDEX intの場合はAPP_ID、stringの場合はAPP_ID_CHARS
	 * @return boolean
	 */
	public function delApp(int|string $APP_INDEX) : bool {

		$this->useAccountDB();

		$sql = 'DELETE FROM OAUTH_APPLICATION';
		if (gettype($APP_INDEX) == 'integer'){
			$sql .= ' WHERE APP_ID = ' . $APP_INDEX;
		}else{
			$sql .= ' WHERE APP_ID_CHARS = "' . $this->db->escape_string($APP_INDEX) . '"';
		}

		$ret = $this->db->query($sql);

		$this->useOrgDB();
		return $ret;
	}

	///////////////////////////////////////////////////////////////////////////
	// utility

	/**
	 * あればエラー内容を返す。
	 *
	 * @return string
	 */
	public function getError() : string { return $this->strError; }

	/**
	 * パスワードをハッシュする。
	 *
	 * @param string $pass 平文のパスワード
	 * @return string ハッシュされたパスワード
	 */
	protected function hashPass(string $pass) : string{ return hash('sha3-256', $pass); }

	/**
	 * account dbに切り替える
	 *
	 * @return void
	 */
	protected function useAccountDB(){
		if ($this->bChangeDB){ $this->db->select_db('account'); }
	}

	/**
	 * 元のDBに切り替える
	 *
	 * @return void
	 */
	protected function useOrgDB(){
		if ($this->bChangeDB){ $this->db->select_db($this->strBackDBName); }
	}

}
