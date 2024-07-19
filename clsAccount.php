<?php
namespace tlib;

use Exception;

/**
 * アカウント関係のテーブル処理を行う。
 *  ・継承先は、簡易なログインとOAuthログインを想定。（OAuthは保留）
 *  ・アカウントの個人情報は持たない。アカウント認証のみに特化させる。
 */
class clsAccount {

	public const MIN_USER_NAME_LENGTH = 2;
	public const MAX_USE_NAME_LENGTH = 255;

	public const MIN_PASSWORD_LENGTH = 8;
	public const MAX_PASSWORD_LENGTH = 32;
	public const PASSWORD_CHECK_MODE = 1; // at least digits and lowercase letters

	public const MAX_AUTH_VERIFY_FAILED_COUNT = 5;

	protected ?clsDB $db = null; // アカウント専用のDBを持つ場合は外部で対応のこと。
	protected $loginExpiredDays = 0; // ログインのセッションの有効期限日数
	protected $strError = ''; // エラーが発生した際にエラー内容を入れておくメンバ変数
	protected $msg = null;

	///////////////////////////////////////////////////////////////////////////
	// construct / destruct

	public function __construct(clsDB &$db, int $loginExpiredDays = 365){
		// 各引数の内容はメンバ変数参照
		$this->db = $db;
		$this->loginExpiredDays = $loginExpiredDays;
		$this->msg = clsLocale(get_class($this));
	}

	public function __destruct(){}

	///////////////////////////////////////////////////////////////////////////
	// アカウント関係

	/**
	 * アカウント登録
	 * 　=> $TEMP_SESSION_KEYが指定された場合は2段階認証の結果チェックも行う。正しい認証コードであれば、AUTH_VERIFYからACCOUNT_INFO_VERIFIEDへのデータの移動も行う。
	 * 　=> ログイン関連の設定は行わない（TFAを使用するかとログイン通知をするか。）ので、別関数で設定のこと。
	 *   => checkAccountPara()をこの関数を呼び出す前に呼び出しエラーチェックすること。$ret = $this->checkAccountPara(0, $USER_NAME, $PASS);
	 *
	 * @param integer $APP_ID アカウント登録するAPP 0の場合は、アプリ登録無しでアカウントのみ作成
	 * @param string $USER_NAME ユーザ名　$TEMP_SESSION_KEYを指定した場合は、AUTH_VERIFYのAUTH_INFOが使用される。
	 * @param string $PASS パスワード 内部でハッシュ化
	 * @param integer &$ACCOUNT_ID 登録が成功した場合に返されるアカウントID
	 * @param string $TEMP_SESSION_KEY 指定された場合、AUTH_VERIFYで2段認証のチェックを行う。 空文字の場合は行わない。
	 * @param string $VERIFY_CODE $TEMP_SESSION_KEYが指定された場合に2段認証のチェックで使用。
	 *
	 * @return int 0 正常終了 1 認証エラー 99 システムエラー
	 */
	public function addAccount(int $APP_ID = 0, string $USER_NAME, string $PASS, int &$ACCOUNT_ID, string $TEMP_SESSION_KEY = '', string $VERIFY_CODE = '') : int {

		$this->strError = '';

		$ACCOUNT_ID = 0;
		$verifyInfo = array();

		// 認証チェックする場合
		if ($TEMP_SESSION_KEY != ''){
			if (!$this->checkVerify($TEMP_SESSION_KEY, $VERIFY_CODE, $verifyInfo)){ return 1; } // $this->strErrorは内部で設定済み
		}

		// 新規登録
		$this->db->autocommit(FALSE);

		try{

			// 初期登録でメール登録などを行う場合は、USER_NAMEはメールアドレスかSMS
			$USER_NAME_VERIFIED_ID = 0;
			if ($TEMP_SESSION_KEY != ''){
				$USER_NAME = $verifyInfo['AUTH_INFO'];
				$USER_NAME_VERIFIED_ID = 1;
			}

			// ACCOUNT table
			for ($i=0;$i < 10;$i++){
				$ACCOUNT_ID_CHARS = hash('sha3-256', Date('YmdHis') . bin2hex(random_bytes(16)));
				$PASS = $this->hashPass($PASS);
				$sql = 'INSERT INTO ACCOUNT (ACCOUNT_ID_CHARS, USER_NAME, PASS, USER_NAME_VERIFIED_ID, TFA_VERIFIED_ID, LOGIN_NOTICE_VERIFIED_ID, LOCKED, VALID, INSERT_TIME, UPDATE_TIME)
					VALUES ("' . $ACCOUNT_ID_CHARS . '", "' . $this->db->real_escape_string($USER_NAME) . '", "' . $PASS . '", ' . $USER_NAME_VERIFIED_ID . ', 0, 0, 0, 1, now(), now())';
				$ret = $this->db->query($sql);
				if ($ret == true){ break; }
				if ($i==9){ throw new \Exception($this->msg->_('Failed to insert ACCOUNT table.')); }
			}

			$ACCOUNT_ID = $this->db->getFirstOne('SELECT LAST_INSERT_ID()');

			if ($TEMP_SESSION_KEY != ''){
				if (!$this->moveVeryfyToAccount($ACCOUNT_ID, $TEMP_SESSION_KEY, $verifyInfo, false)){ throw new \Exception($this->strError); }
			}

			// アプリケーションユーザとして登録
			if ($APP_ID > 0){
				if (!$this->addAccountToApp($APP_ID, $ACCOUNT_ID)){ throw new \Exception($this->msg->_('Failed to Add OAUTH_USER_PERMIT table.')); }
			}

		}catch(\Exception $e){
			$this->db->rollback();
			$this->db->autocommit(TRUE);
			$this->strError = $e->getMessage();

			return 99;
		}

		$this->db->commit();
		$this->db->autocommit(TRUE);

		return 0;
	}


	/**
	 * アカウント情報を更新する。指定された修正情報の内、nullじゃないもののみDB更新。
	 *  => ユーザ名、もしくは、パスワードを指定する場合は外部でcheckAccountPara()を呼びエラーをチェックすること。
	 *     if (!is_null($USER_NAME) || !is_null($PASS)){
	 *       $ret = $this->checkAccountPara($ACCOUNT_ID, $USER_NAME, $PASS);
	 *     }
	 *
	 * @param integer $ACCOUNT_ID 修正するアカウント情報のID
	 * @param string|null $USER_NAME ユーザ名 (ユーザ名を指定する場合は必ずパスワードも設定のこと。)
	 * @param string|null $PASS パスワード（未ハッシュ）
	 * @param int|null $TFA_VERIFIED_ID 2FAで使う認証済みの情報。TFAを使用しない場合は0。
	 * @param int|null $LOGIN_NOTICE_VERIFIED_ID ログイン通知で使う認証済みの情報。ログイン通知を使用しない場合は0。
	 * @param integer|null $LOCKED 一時的に使用不可とする場合1
	 * @param integer|null $VALID 有効なアカウントの場合1 退会などの場合は0
	 * @return integer 0 正常終了 1 引数エラー 99 システムエラー
	 */
	function modAccount(int $ACCOUNT_ID, ?string $USER_NAME = null, ?string $PASS = null, ?int $TFA_VERIFIED_ID = null, ?int $LOGIN_NOTICE_VERIFIED_ID = null, ?int $LOCKED = null, ?int $VALID = null) : int{

		$this->strError = '';

		///////////////////////////////////////////////////////////////////////
		// 引数チェック

		if ($ACCOUNT_ID == 0){ $this->strError = $this->msg->_('ACCOUNT_ID must be 1 or higher.'); return 1; }

		// ユーザ名のみの指定はNG
		if (!is_null($USER_NAME) && is_null($PASS)){ $this->strError = $this->msg->_('You need to specify your password when changing your account name.'); return 1; }

		if ($LOCKED != 0 && $LOCKED != 1){ $this->strError = $this->msg->_('The "LOCKED" only accept 1 or 0.'); return 1; }
		if ($VALID != 0 && $VALID != 1){ $this->strError = $this->msg->_('The "VALID" only accept 1 or 0.'); return 1; }

		///////////////////////////////////////////////////////////////////////
		// SQL実行

		$sql = 'UPDATE ACCOUNT SET ';

		if (!is_null($USER_NAME) && !is_null($PASS)){
			$sql .= ' USER_NAME = "' . $this->db->real_escape_string($USER_NAME) . '", PASS = "' . $this->hashPass($PASS) . '",';
		}

		if (is_null($USER_NAME) && !is_null($PASS)){
			$sql .= ' PASS = "' . $this->hashPass($PASS) . '",';
		}

		if (!is_null($TFA_VERIFIED_ID)){ $sql .= ' TFA_VERIFIED_ID = ' . $TFA_VERIFIED_ID . ','; } // 2段認証の設定をする
		if (!is_null($LOGIN_NOTICE_VERIFIED_ID)){ $sql .= ' TFA_VERIFIED_ID = ' . $LOGIN_NOTICE_VERIFIED_ID . ','; } // ログイン時の通知先の設定をする
		if (!is_null($LOCKED)){ $sql .= ' LOCKED = ' . $LOCKED . ','; } // 不正利用などでロックする場合1
		if (!is_null($VALID) ){ $sql .= ' VALID = ' . $VALID . ','; } // 大会などでアカウント停止する場合0

		$sql = substr($sql, 0, -1); // 最後の,を取り除く
		$sql .= ' WHERE ACCOUNT_ID = ' . $ACCOUNT_ID;

		$ret = $this->db->query($sql);
		if ($ret === false){
			$this->strError = $this->msg->_('SYSTEM ERROR : FAILED TO MODIFY THE INFORMATION.');
			return 99;
		}

		return 0;
	}

	/**
	 * 指定のアカウント関係を全て削除する。
	 *
	 * @param integer $ACCOUNT_ID
	 * @return bool 正常終了 true 異常終了 false
	 */
	function delAccount(int $ACCOUNT_ID) : bool{

		$this->strError = '';

		// アカウントがあるかチェック
		$sql = 'SELECT COUNT(*) FROM ACCOUNT WHERE ACCOUNT_ID = ' . $ACCOUNT_ID;
		$cnt = $this->db->getFirstOne($sql);
		if (is_null($cnt) || $cnt <= 0){ $this->strError = $this->msg->_('Could not find your account.'); return false; }

		$this->db->autocommit(FALSE);

		try{
			$ret = $this->db->query('DELETE FROM ACCOUNT WHERE ACCOUNT_ID = ' . $ACCOUNT_ID);
			if ($ret === false){ throw new Exception($this->msg->_('SYSTEM ERROR : DELETE ACCOUNT INFORMATION 01')); }

			$ret = $this->db->query('DELETE FROM ACCOUNT_INFO_VERIFIED WHERE ACCOUNT_ID = ' . $ACCOUNT_ID);
			if ($ret === false){ throw new Exception($this->msg->_('SYSTEM ERROR : DELETE ACCOUNT INFORMATION 02')); }

			$ret = $this->db->query('DELETE FROM AUTH_VERIFY WHERE ACCOUNT_ID = ' . $ACCOUNT_ID);
			if ($ret === false){ throw new Exception($this->msg->_('SYSTEM ERROR : DELETE ACCOUNT INFORMATION 03')); }

			$ret = $this->db->query('DELETE FROM AUTH_VERIFY WHERE ACCOUNT_ID = ' . $ACCOUNT_ID);
			if ($ret === false){ throw new Exception($this->msg->_('SYSTEM ERROR : DELETE ACCOUNT INFORMATION 04')); }

			$ret = $this->db->query('DELETE FROM OAUTH_USER_PERMIT WHERE ACCOUNT_ID = ' . $ACCOUNT_ID);
			if ($ret === false){ throw new Exception($this->msg->_('SYSTEM ERROR : DELETE ACCOUNT INFORMATION 05')); }

			$ret = $this->db->query('DELETE FROM OAUTH_CODE_SESSION WHERE ACCOUNT_ID = ' . $ACCOUNT_ID);
			if ($ret === false){ throw new Exception($this->msg->_('SYSTEM ERROR : DELETE ACCOUNT INFORMATION 06')); }

			$ret = $this->db->query('DELETE FROM OAUTH_SESSION WHERE ACCOUNT_ID = ' . $ACCOUNT_ID);
			if ($ret === false){ throw new Exception($this->msg->_('SYSTEM ERROR : DELETE ACCOUNT INFORMATION 07')); }

			$ret = $this->db->query('DELETE FROM ACCOUNT_SESSION WHERE ACCOUNT_ID = ' . $ACCOUNT_ID);
			if ($ret === false){ throw new Exception($this->msg->_('SYSTEM ERROR : DELETE ACCOUNT INFORMATION 08')); }

			$ret = $this->db->query('DELETE FROM ACCOUNT_ACTIVITY WHERE ACCOUNT_ID = ' . $ACCOUNT_ID);
			if ($ret === false){ throw new Exception($this->msg->_('SYSTEM ERROR : DELETE ACCOUNT INFORMATION 09')); }

		}catch(Exception $e){
			$this->db->rollback();
			$this->db->autocommit(true);

			$this->strError = $e->getMessage();
			return false;
		}

		$this->db->commit();
		$this->db->autocommit(true);

		return true;
	}

	/**
	 * ユーザ名とパスワードが妥当か調べる
	 *
	 * @param integer $ACCOUNT_ID 通常は0指定 >0でここで指定されたアカウントを除外して調べる。
	 * @param string $USER_NAME nullの場合はチェックせず
	 * @param string $PASS nullの場合はチェックせず
	 * @return integer 	0 問題なし
	 * 					1 ユーザ名が短すぎる
	 * 					2 ユーザ名が長すぎる
	 * 					3 パスワードが短すぎる
	 * 					4 パスワードが長すぎる
	 * 					5 パスワードに数字しか含まれない
	 * 					6 パスワードに数字がない
	 * 					7 パスワードに小文字がない
	 * 					8 パスワードに大文字がない
	 * 					9 パスワードに記号がない
	 * 					10 パスワードが使用済み
	 * 					90 ユーザ名もパスワードも未指定
	 * 					91 ユーザ名のみの変更にはパスワードが必要
	 * 					99 DBエラー　システムエラー
	 */
	function checkAccountPara(int $ACCOUNT_ID, ?string $USER_NAME, ?string $PASS) : int{

		$this->strError = '';

		// ユーザ名もパスワードも指定されない場合はエラー
		if (is_null($USER_NAME) && is_null($PASS)){ $this->strError = $this->msg->_('You need to specify USER_NAME or PASS.'); return 90; }

		// ユーザ名のみの変更の場合は、パスワード必須
		if (!is_null($USER_NAME) && is_null($PASS)){ $this->strError = $this->msg->_('You need to specify your password when changing your user name.'); return 91; }

		$passMinLen = ((defined(TLIB_MIN_PASSWORD_LENGTH))?TLIB_MIN_PASSWORD_LENGTH:self::MIN_PASSWORD_LENGTH);
		$passMaxLen = ((defined(TLIB_MAX_PASSWORD_LENGTH))?TLIB_MAX_PASSWORD_LENGTH:self::MAX_PASSWORD_LENGTH);
		$passMode = ((defined(TLIB_PASSWORD_CHECK_MODE))?TLIB_PASSWORD_CHECK_MODE:self::PASSWORD_CHECK_MODE);

		if (!is_null($USER_NAME)){
			if (mb_strlen($USER_NAME) < self::MIN_USER_NAME_LENGTH){ $this->strError = $this->msg->_('Your user name is too short.'); return 1; }
			if (mb_strlen($USER_NAME) > self::MAX_USER_NAME_LENGTH){ $this->strError = $this->msg->_('Your user name is too long.');return 2; }
		}
		if (!is_null($PASS)){
			$ret = isThis::password($PASS, $passMinLen, $passMaxLen, $passMode);  // 0 正常 1 短すぎる 2 長すぎる 3 数字のみエラー 4 数字なし 5 小文字なし 6 大文字なし 7 記号無し
			if ($ret > 0){
				switch($ret){
					case 1 : $this->strError = $this->msg->_('Your password is too short.'); break;
					case 2 : $this->strError = $this->msg->_('Your password is too long.'); break;
					case 3 : $this->strError = $this->msg->_('Please include some letters other than numbers in your password..'); break;
					case 4 : $this->strError = $this->msg->_('Please include some numbers in your password.'); break;
					case 5 : $this->strError = $this->msg->_('Please put lowercase letters in your password.'); break;
					case 6 : $this->strError = $this->msg->_('Please put uppercase letters in your password.'); break;
					case 7 : $this->strError = $this->msg->_('Please put a symbol in the password.'); break;
					default:
					$this->strError = $this->msg->_('Unknown error occured when checking your password.');
				}

				return ($ret + 2); // 3 ～ 9
			}
		}

		// パスワードのみが指定された場合は、変更後の同じユーザ／パスワードがいなかチェック ($ACCOUNT_ID == 0)の場合もエラー
		if (is_null($USER_NAME) && !is_null($PASS)){
			if ($ACCOUNT_ID == 0){ return 99; } // イレギュラーすぎるのでエラーになればなんでも良い。

			$sql = 'SELECT USER_NAME FROM ACCOUNT WHERE ACCOUNT_ID = ' . $ACCOUNT_ID;
			$USER_NAME = $this->db->getFirstOne($sql);
			if (is_null($USER_NAME)){ $this->strError = $this->msg->_('SYSTEM ERROR : PROBABLY WRONG ACCOUNT ID.'); return 99; } // イレギュラーすぎるのでエラーになればなんでも良い。

		 }

		// 既に使われているユーザとパスワードの場合はNG
		$sql = 'SELECT COUNT(*) FROM ACCOUNT WHERE USER_NAME = "' . $this->db->real_escape_string($USER_NAME) . '" AND PASS = "' . $this->hashPass($PASS) . '"';
		if ($ACCOUNT_ID != 0){ $sql .= ' AND ACCOUNT_ID <> ' . $ACCOUNT_ID; }
		$exists = $this->db->getFirstOne($sql);
		if ($exists === null){ $this->strError = $this->msg->_('SYSTEM ERROR : COULD NOT FIND YOUR INFORMATION.'); return 99; } // システムエラー
		if ($exists > 0){ $this->strError = $this->msg->_('Not allowed to use the password.'); return 10; }

		return 0;
	}

	///////////////////////////////////////////////////////////////////////////
	// アプリ登録／削除 (アカウントは登録済みであること)

	/**
	 * アプリにアカウントを登録する。
	 *
	 * @param integer $APP_ID
	 * @param integer $ACCOUNT_ID
	 * @return bool true 正常 false 異常
	 */
	function addAccountToApp(int $APP_ID, int $ACCOUNT_ID) : bool{
		// 既に登録済み？
		$cnt = $this->db->getFirstOne('SELECT COUNT(*) FROM OAUTH_USER_PERMIT WHERE APP_ID = ' . $APP_ID . ' AND ACCOUNT_ID = ' . $ACCOUNT_ID);
		if ($cnt == null){ return false; }
		if ($cnt > 0){ return true; } // 既に登録済みならそれはそれで良い。

		return $this->db->query('INSERT INTO OAUTH_USER_PERMIT (APP_ID, ACCOUNT_ID, SCOPE) VALUES (' . $APP_ID . ', ' . $ACCOUNT_ID . ' , "[]")');
	}

	/**
	 * アプリからアカウントを削除する。
	 *  => 登録アプリが0になる場合はアカウント毎の削除となるため注意のこと。
	 *
	 * @param integer $APP_ID
	 * @param integer $ACCOUNT_ID
	 * @return bool true 正常 false 異常
	 */
	function dellAccountFromApp(int $APP_ID, int $ACCOUNT_ID) : bool{

		// 存在する？
		$cnt = $this->db->getFirstOne('SELECT COUNT(*) FROM OAUTH_USER_PERMIT WHERE APP_ID = ' . $APP_ID . ' AND ACCOUNT_ID = ' . $ACCOUNT_ID);
		if ($cnt == null){ return false; }
		if ($cnt == 0){ return true; } // 存在しないならそれはそれで良い

		// 削除したら登録アプリ数が0になる？
		$cnt = $this->db->getFirstOne('SELECT COUNT(*) FROM OAUTH_USER_PERMIT WHERE ACCOUNT_ID = ' . $ACCOUNT_ID);
		if ($cnt == null){ return false; }
		if ($cnt == 1){ return $this->delAccount($ACCOUNT_ID); } // 0になる場合はアカウント毎削除

		$this->db->autocommit(FALSE);

		try{

			$ret = $this->db->query('DELETE FROM OAUTH_USER_PERMIT  WHERE APP_ID = ' . $APP_ID . ' AND ACCOUNT_ID = ' . $ACCOUNT_ID); if ($ret === false){ throw new Exception(); }
			$ret = $this->db->query('DELETE FROM OAUTH_CODE_SESSION WHERE APP_ID = ' . $APP_ID . ' AND ACCOUNT_ID = ' . $ACCOUNT_ID); if ($ret === false){ throw new Exception(); }
			$ret = $this->db->query('DELETE FROM OAUTH_SESSION      WHERE APP_ID = ' . $APP_ID . ' AND ACCOUNT_ID = ' . $ACCOUNT_ID); if ($ret === false){ throw new Exception(); }
			$ret = $this->db->query('DELETE FROM ACCOUNT_SESSION    WHERE APP_ID = ' . $APP_ID . ' AND ACCOUNT_ID = ' . $ACCOUNT_ID); if ($ret === false){ throw new Exception(); }
			$ret = $this->db->query('DELETE FROM ACCOUNT_ACTIVITY   WHERE APP_ID = ' . $APP_ID . ' AND ACCOUNT_ID = ' . $ACCOUNT_ID); if ($ret === false){ throw new Exception(); }

		}catch(Exception $e){
			$this->db->rollback();
			$this->db->autocommit(true);

			return false;
		}

		$this->db->commit();
		$this->db->autocommit(true);

		return true;
	}


	///////////////////////////////////////////////////////////////////////////
	// 2FA認証関係

	/**
	 * 認証開始
	 *
	 * @param integer $ACCOUNT_ID アカウントのID 0の場合 未登録ユーザ
	 * @param integer $AUTH_TYPE 1 e-mail 2 SMS
	 * @param string $AUTH_INFO $ID_TYPE=1の場合 e-mail 2の場合 電話番号
	 * @param string $VERIFY_CODE 認証コード（返却）
	 * @param string $TEMP_SESSION_KEY セッションキー（返却）
	 * @param boolean $bAuthExists 既に登録済みである場合、trueを返す。（戻り値はtrueの場合チェック この場合、指定されたメールアドレス／SMSにユーザ名を送るなど処理をする必要あり。）
	 * @return boolean true 正常終了 false 異常終了
	 */
	public function startVerify(int $ACCOUNT_ID = 0, int $AUTH_TYPE, string $AUTH_INFO, string &$VERIFY_CODE, string &$TEMP_SESSION_KEY, bool &$bAuthExists) : bool{

		$bAuthExists = false;

		try{

			// 既に認証済みではないか確認
			$sql = 'SELECT COUNT(*) FROM ACCOUNT_INFO_VERIFIED WHERE AUTH_TYPE = ' . $AUTH_TYPE . ' AND AUTH_INFO = "' . $this->db->real_escape_string($AUTH_INFO) . '"';
			$cnt = $this->db->getFirstOne($sql);
			if ($cnt === null){ throw new \Exception($this->msg->_('SYSTEM ERROR : failed to check the TFA information exists.')); }
			if ($cnt > 0){
				// 既に認証済み => メールアドレス／SMSにユーザ名を送る。
				$bAuthExists = true;
				return true;
			}

			$VERIFY_CODE = random_int(10000000, 99999999); // とりあえず8桁
			// $TEMP_SESSION_KEYが被ってNGになるかもしれないので、10回繰り返す。
			$inserted = false;
			for ($i=0;$i < 10;$i++){
				$TEMP_SESSION_KEY = hash(TLIB_HASH_ALGO_PASS, date('YYYYmmddhhiiss') . TLIB_HASH_SOLT . $AUTH_INFO . bin2hex(random_bytes(16)));
				$sql = 'INSERT INTO AUTH_VERIFY (ACCOUNT_ID, AUTH_TYPE, AUTH_INFO, VERIFY_CODE, TEMP_SESSION_KEY, FAILED_CNT, EXPIRE_TIME, INSERT_TIME)
				VALUES (' . $ACCOUNT_ID . ', ' . $AUTH_TYPE . ', "' . $AUTH_INFO . '", "' . $VERIFY_CODE . '", "' . $TEMP_SESSION_KEY . '", 0, now() + INTERVAL 30 MINUTE, now())';
				$ret = $this->db->query($sql);
				if ($ret === true){ $inserted = true; break; }
			}
			if (!$inserted){
				throw new \Exception($this->msg->_('SYSTEM ERROR : FAILED TO INSERT AUTH_VERIFY'));
			}

		}catch(\Exception $e){
			$this->strError = $e->getMessage();
			return false;
		}

		return true;
	}

	/**
	 * 認証をチェックする。
	 *
	 * @param string $TEMP_SESSION_KEY セッションキー
	 * @param string $VERIFY_CODE チェックするコード
	 * @param array AUTH_VERIFYのレコード情報戻し用 色々使うので。
	 * @return boolean true false
	 */
	function checkVerify(string $TEMP_SESSION_KEY, string $VERIFY_CODE, ?array &$verifyInfo) : bool{

		try{

			$ESCAPE_TEMP_SESSION_KEY = $this->db->real_escape_string($TEMP_SESSION_KEY);
			$sql = 'SELECT * FROM AUTH_VERIFY WHERE TEMP_SESSION_KEY = "' . $ESCAPE_TEMP_SESSION_KEY . '" AND EXPIRE_TIME >= now() AMD FAILED_CNT <= ' . self::MAX_AUTH_VERIFY_FAILED_COUNT;
			$verifyInfo = $this->db->getFirstRowAssoc($sql);

			// セッションが見つからないか有効期限エラー
			if ($verifyInfo == null){ throw new \Exception($this->msg->_('Could not find the user session. Probably the authentication time has expired.')); }

			// 認証回数エラー
			if ($verifyInfo['FAILED_CNT'] >= self::MAX_AUTH_VERIFY_FAILED_COUNT){ throw new \Exception($this->msg->_('Too many times to enter the code.')); }

			// 認証エラー
			if ($verifyInfo['VERIFY_CODE'] != $VERIFY_CODE){
				$sql = 'UPDATE AUTH_VERIFY SET FAILED_CNT = FAILED_CNT+1 WHERE TEMP_SESSION_KEY = "' . $ESCAPE_TEMP_SESSION_KEY . '"';
				$ret = $this->db->query($sql);
				if ($ret === false){ throw new \Exception($this->msg->_('SYSTEM ERROR : DB ACCESS FAILED.')); }

				throw new \Exception($this->msg->_('Wrong code specifyed via two facter authentication.'));
			}

		}catch(\Exception $e){
			$this->strError = $e->getMessage();
			return false;
		}

		return true;
	}

	/**
	 * AUTH_VERIFY => ACCOUNT_INFO_VERIFIEDへの移動。移動後、AUTH_VERIFY側のデータは削除する。
	 * 事前に$this->checkVerify()を呼び出しエラーないことを確認すること。
	 *
	 * @param integer $ACCOUNT_ID
	 * @param string $TEMP_SESSION_KEY
	 * @param boolean $bUseTransaction
	 * @return boolean true 正常終了 false 異常終了
	 */
	function moveVeryfyToAccount(int $ACCOUNT_ID, string $TEMP_SESSION_KEY, ?array $verifyInfo=null, bool $bUseTransaction = false) : bool{

		// 必要情報の取得
		$ESCAPE_TEMP_SESSION_KEY = $this->db->real_escape_string($TEMP_SESSION_KEY);
		if ($verifyInfo == null){
			$sql = 'SELECT AUTH_TYPE, AUTH_INFO FROM AUTH_VERIFY WHERE TEMP_SESSION_KEY = "' . $ESCAPE_TEMP_SESSION_KEY . '"';
			$verifyInfo = $this->db->getFirstRowAssoc($sql);
			// セッションが見つからない
			if ($verifyInfo == null){ $this->strError = $this->msg->_('Could not find the user session.'); return false; }
		}

		$sql = 'SELECT COUNT(*) FROM ACCOUNT_INFO_VERIFIED WHERE ACCOUNT_ID = ' . $ACCOUNT_ID;
		$VERIFIED_ID = $this->db->getFirstOne($sql);
		if ($VERIFIED_ID == null){  $this->strError = $this->msg->_('SYSTEM ERROR : FAILED TO GET ACCOUNT_INFORMATION.'); return false; }

		if ($bUseTransaction){
			$this->db->autocommit(FALSE);
		}

		try{

			// AUTH_VERIFYからACCOUNT_INFO_VERIFIEDへのデータの移動
			$sql = 'INSERT INTO ACCOUNT_INFO_VERIFIED (ACCOUNT_ID, VERIFIED_ID, VERIFIED_INFO_TYPE, VERIFIED_INFO, VALID, LAST_VALID_TIME, INSERT_TIME)
				VALUES (' . $ACCOUNT_ID . ', ' . ($VERIFIED_ID+1) . ', ' . $verifyInfo['AUTH_TYPE'] . ', "' . $verifyInfo['AUTH_INFO'] . '", 1, now(), now())';
			$ret = $this->db->query($sql);
			if (!$ret){ throw new \Exception($this->msg->_('SYSTEM ERROR : Failed to insert ACCOUNT_INFO_VERIFIED table.')); }

			// AUTH_VERIFYの削除
			$sql = 'DELETE FROM AUTH_VERIFY WHERE TEMP_SESSION_KEY = "' . $ESCAPE_TEMP_SESSION_KEY . '"';
			$ret = $this->db->query($sql);
			if (!$ret){ throw new \Exception($this->msg->_('SYSTEM ERROR : Failed to DELETE AUTH_VERIFY table.')); }

		}catch(Exception $e){

			if ($bUseTransaction){
				$this->db->rollback();
				$this->db->autocommit(true);
			}

			$this->strError = $e->getMessage();
			return false;
		}

		if ($bUseTransaction){
			$this->db->commit();
			$this->db->autocommit(TRUE);
		}

		return true;
	}


	/**
	 * 認証テーブル定期削除用
	 *
	 * @return bool true(正常) / false
	 */
	function delVerifyExpired() : bool{ return $this->db->query('DELETE FROM AUTH_VERIFY WHERE EXPIRE_TIME <= now()'); }

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
			return false;
		}

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

		return $ret;
	}
	/**
	 * アプリ情報を削除する。
	 *
	 * @param integer|string $APP_INDEX intの場合はAPP_ID、stringの場合はAPP_ID_CHARS
	 * @return boolean
	 */
	public function delApp(int|string $APP_INDEX) : bool {

		$sql = 'DELETE FROM OAUTH_APPLICATION';
		if (gettype($APP_INDEX) == 'integer'){
			$sql .= ' WHERE APP_ID = ' . $APP_INDEX;
		}else{
			$sql .= ' WHERE APP_ID_CHARS = "' . $this->db->escape_string($APP_INDEX) . '"';
		}

		$ret = $this->db->query($sql);

		return $ret;
	}

	///////////////////////////////////////////////////////////////////////////
	// utility

	/**
	 * エラー内容を返す。
	 *
	 * @return string
	 */
	public function getErrorMsg() : string { return $this->strError; }

	/**
	 * パスワードをハッシュする。
	 *
	 * @param string $pass 平文のパスワード
	 * @return string ハッシュされたパスワード
	 */
	protected function hashPass(string $pass) : string{
		$solt = (defined('TLIB_HASH_SOLT')?TLIB_HASH_SOLT:'');
		$algos = (defined('TLIB_HASH_ALGO_PASS')?TLIB_HASH_ALGO_PASS:'sha3-256');
		return hash($algos, $solt + $pass);
	}

	/**
	 * $ID_INFOからIDの種類（1 e-mail 2 SMS 99 任意文字列）を判別して返す。
	 * $ID_TYPEが既に判別済みの場合は処理しない。
	 *
	 * @param int $ID_TYPE -1 の場合、$ID_INFOの内容から、emailかSMSか判別する。
	 * @param string $ID_INFO ユーザID emailかSMSか文字列か
	 * @return int 1 e-mail 2 SMS 99 任意文字列
	 */
	protected function judgeIdType(int $ID_TYPE, string $ID_INFO) :int{
		if ($ID_TYPE > 0){ return $ID_TYPE; }

		$ret = 99;
		if (isThis::email($ID_INFO)){
			$ret = 1;
		}else if (isThis::phone($ID_INFO, true)) {
			$ret = 2;
		}
		return $ret;
	}

}
