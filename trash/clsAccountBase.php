<?php
namespace tlib;

/**
 * アカウント関係のテーブル処理を行い、以下の機能の部品を提供
 *  ・アカウントの追加／修正／削除
 *  ・アプリへのアカウントの登録／削除
 *  ・アプリの登録／修正／削除
 *  ・セッション追加／削除
 */
class clsAccountBase {

	public const MIN_USER_NAME_LENGTH = 2;
	public const MAX_USER_NAME_LENGTH = 255;

	public const MIN_PASSWORD_LENGTH = 8;
	public const MAX_PASSWORD_LENGTH = 32;
	public const PASSWORD_CHECK_MODE = 1; // at least digits and lowercase letters

	public const MAX_AUTH_VERIFY_FAILED_COUNT = 5;

	public const USER_NAME_TYPE_EMAIL = 1;
	public const USER_NAME_TYPE_PHONE = 2;
	public const USER_NAME_TYPE_OTHER = 99;

	protected ?clsDB $db = null; // アカウント専用のDBを持つ場合は外部で対応のこと。
	protected $strError = ''; // エラーが発生した際にエラー内容を入れておくメンバ変数
	private ?clsTransfer $msg= null;

	///////////////////////////////////////////////////////////////////////////
	// construct / destruct

	public function __construct(clsDB &$db){
		// 各引数の内容はメンバ変数参照
		$this->db = $db;
		$this->msg= clsTransfer(pathinfo(__FILE__, PATHINFO_FILENAME), __DIR__ . '/locale/');
	}

	public function __destruct(){}

	///////////////////////////////////////////////////////////////////////////
	// アカウント関係

	/**
	 * アカウント登録
	 * 　=> ログイン関連の設定は行わないので、別関数で設定のこと。
	 *   => checkAccountPara()をこの関数を呼び出す前に呼び出しエラーチェックすること。$ret = $this->checkAccountPara(0, $USER_NAME, $PASS);
	 *
	 * @param string $USER_NAME ユーザ名　$TEMP_SESSION_KEYを指定した場合は、AUTH_VERIFYのAUTH_INFOが使用される。
	 * @param string $PASS パスワード 内部でハッシュ化
	 * @param integer $USER_NAME_VERIFIED_ID ユーザ名がメールアドレスかSMSで認証済みの場合指定するACCOUNT_INFO_VERIFIEDのID。初期登録でメールがユーザ名の場合は1　(この場合、USER_NAMEはメールアドレスかSMSになる) それ以外は0
	 * @param integer &$ACCOUNT_ID 登録が成功した場合に返されるアカウントID
	 *
	 * @return bool true 正常終了 false 異常終了
	 */
	public function addAccount(string $USER_NAME, string $PASS, int $USER_NAME_VERIFIED_ID, int &$ACCOUNT_ID) : bool {

		$this->strError = '';

		$ACCOUNT_ID = 0;
		try{

			// ACCOUNT table
			for ($i=0;$i < 10;$i++){
				$ACCOUNT_ID_CHARS = hash('sha3-256', Date('YmdHis') . bin2hex(random_bytes(16)));
				$PASS = $this->hashPass($PASS);
				$sql = 'INSERT INTO ACCOUNT (ACCOUNT_ID_CHARS, USER_NAME, PASS, USER_NAME_VERIFIED_ID, TFA_VERIFIED_ID, LOGIN_NOTICE_VERIFIED_ID, LOCKED, VALID, INSERT_TIME, UPDATE_TIME)
					VALUES ("' . $ACCOUNT_ID_CHARS . '", "' . $this->db->real_escape_string($USER_NAME) . '", "' . $PASS . '", ' . $USER_NAME_VERIFIED_ID . ', 0, 0, 0, 1, now(), now())';
				$ret = $this->db->query($sql);
				if ($ret == true){ break; }
				if ($i==9){ throw new \Exception($this->baseMsg->_('SYSTEM ERROR : Failed to insert ACCOUNT table.')); }
			}

			$ACCOUNT_ID = $this->db->getFirstOne('SELECT LAST_INSERT_ID()');

		}catch(\Exception $e){
			$this->strError = $e->getMessage();
			return false;
		}

		return true;
	}


	/**
	 * アカウント情報を更新する。指定された修正情報の内、nullじゃないもののみDB更新。
	 *  => ユーザ名、もしくは、パスワードを指定する場合は外部でcheckAccountPara()を呼びエラーをチェックすること。
	 *     if (!is_null($USER_NAME) || !is_null($PASS)){
	 *       $ret = $this->checkAccountPara($ACCOUNT_ID, $USER_NAME, $PASS);
	 *     }
	 *
	 * @param integer $ACCOUNT_ID 修正するアカウント情報のID
	 * @param string|null $USER_NAME ユーザ名
	 * @param string|null $PASS パスワード（未ハッシュ）
	 * @param int|null $USER_NAME_VERIFIED_ID ユーザ名がメールアドレスかSMSで認証済みの場合指定するACCOUNT_INFO_VERIFIEDのID
	 * @param int|null $TFA_VERIFIED_ID 2FAで使う認証済みの情報。TFAを使用しない場合は0。
	 * @param int|null $LOGIN_NOTICE_VERIFIED_ID ログイン通知で使う認証済みの情報。ログイン通知を使用しない場合は0。
	 * @param integer|null $LOCKED 一時的に使用不可とする場合1
	 * @param integer|null $VALID 有効なアカウントの場合1 退会などの場合は0
	 * @return integer 0 正常終了 1 引数エラー 99 システムエラー
	 */
	function modAccount(int $ACCOUNT_ID, ?string $USER_NAME = null, ?string $PASS = null,
	?int $USER_NAME_VERIFIED_ID = null, ?int $TFA_VERIFIED_ID = null, ?int $LOGIN_NOTICE_VERIFIED_ID = null, ?int $LOCKED = null, ?int $VALID = null) : int{

		$this->strError = '';

		///////////////////////////////////////////////////////////////////////
		// 引数チェック

		if ($ACCOUNT_ID == 0){ $this->strError = $this->baseMsg->_('ACCOUNT_ID must be 1 or higher.'); return 1; }

		if ($USER_NAME === null){ $USER_NAME = ''; }
		if ($PASS === null){ $PASS = ''; }
		$ret = $this->checkAccountPara($ACCOUNT_ID, $USER_NAME, $PASS, $USER_NAME_TYPE, $aryAppId);
		if ($ret > 0){ return 1; }

		if ($LOCKED != 0 && $LOCKED != 1){ $this->strError = $this->baseMsg->_('The "LOCKED" only accept 1 or 0.'); return 1; }
		if ($VALID != 0 && $VALID != 1){ $this->strError = $this->baseMsg->_('The "VALID" only accept 1 or 0.'); return 1; }

		///////////////////////////////////////////////////////////////////////
		// SQL実行

		$sql = 'UPDATE ACCOUNT SET ';

		if ($USER_NAME != ''){ $sql .= ' USER_NAME = "' . $this->db->real_escape_string($USER_NAME) . '",'; }
		if ($PASS != ''){ $sql .= ' PASS = "' . $this->hashPass($PASS) . '",'; }

		if (!is_null($USER_NAME_VERIFIED_ID)){ $sql .= ' USER_NAME_VERIFIED_ID = ' . $USER_NAME_VERIFIED_ID . ','; } // ユーザ名がメールアドレスかSMSで認証済み
		if (!is_null($TFA_VERIFIED_ID)){ $sql .= ' TFA_VERIFIED_ID = ' . $TFA_VERIFIED_ID . ','; } // 2段認証の設定をする
		if (!is_null($LOGIN_NOTICE_VERIFIED_ID)){ $sql .= ' TFA_VERIFIED_ID = ' . $LOGIN_NOTICE_VERIFIED_ID . ','; } // ログイン時の通知先の設定をする
		if (!is_null($LOCKED)){ $sql .= ' LOCKED = ' . $LOCKED . ','; } // 不正利用などでロックする場合1
		if (!is_null($VALID) ){ $sql .= ' VALID = ' . $VALID . ','; } // 大会などでアカウント停止する場合0

		$sql = substr($sql, 0, -1); // 最後の,を取り除く
		$sql .= ' WHERE ACCOUNT_ID = ' . $ACCOUNT_ID;

		$ret = $this->db->query($sql);
		if ($ret === false){
			$this->strError = $this->baseMsg->_('SYSTEM ERROR : FAILED TO MODIFY THE INFORMATION.');
			return 99;
		}

		return 0;
	}

	/**
	 * 指定のアカウント関係を全て削除する。
	 * $this->delApp()にもアカウント削除の処理があるので、修正する場合はそちらも修正のこと。
	 *
	 * @param integer $ACCOUNT_ID
	 * @param boolean $bDelFromApp アプリ関係のテーブルから削除するかどうか。通常はtrue
	 * @param boolean $bUseTransaction
	 * @return bool 正常終了 true 異常終了 false
	 */
	function delAccount(int $ACCOUNT_ID, bool $bDelFromApp = true, bool $bUseTransaction = false) : bool{

		$this->strError = '';

		// アカウントがあるかチェック
		$sql = 'SELECT COUNT(*) FROM ACCOUNT WHERE ACCOUNT_ID = ' . $ACCOUNT_ID;
		$cnt = $this->db->getFirstOne($sql);
		if (is_null($cnt) || $cnt <= 0){ $this->strError = $this->baseMsg->_('Could not find your account.'); return false; }

		if ($bUseTransaction){
			$this->db->autocommit(FALSE);
		}

		try{
			$ret = $this->db->query('DELETE FROM ACCOUNT WHERE ACCOUNT_ID = ' . $ACCOUNT_ID);
			if ($ret === false){ throw new Exception($this->baseMsg->_('SYSTEM ERROR : DELETE ACCOUNT INFORMATION 01')); }

			$ret = $this->db->query('DELETE FROM ACCOUNT_INFO_VERIFIED WHERE ACCOUNT_ID = ' . $ACCOUNT_ID);
			if ($ret === false){ throw new Exception($this->baseMsg->_('SYSTEM ERROR : DELETE ACCOUNT INFORMATION 02')); }

			$ret = $this->db->query('DELETE FROM AUTH_VERIFY WHERE ACCOUNT_ID = ' . $ACCOUNT_ID);
			if ($ret === false){ throw new Exception($this->baseMsg->_('SYSTEM ERROR : DELETE ACCOUNT INFORMATION 03')); }

			if ($bDelFromApp){
				$ret = $this->db->query('DELETE FROM OAUTH_USER_PERMIT WHERE ACCOUNT_ID = ' . $ACCOUNT_ID);
				if ($ret === false){ throw new Exception($this->baseMsg->_('SYSTEM ERROR : DELETE ACCOUNT INFORMATION 05')); }

				$ret = $this->db->query('DELETE FROM OAUTH_CODE_SESSION WHERE ACCOUNT_ID = ' . $ACCOUNT_ID);
				if ($ret === false){ throw new Exception($this->baseMsg->_('SYSTEM ERROR : DELETE ACCOUNT INFORMATION 06')); }

				$ret = $this->db->query('DELETE FROM OAUTH_SESSION WHERE ACCOUNT_ID = ' . $ACCOUNT_ID);
				if ($ret === false){ throw new Exception($this->baseMsg->_('SYSTEM ERROR : DELETE ACCOUNT INFORMATION 07')); }

				$ret = $this->db->query('DELETE FROM ACCOUNT_SESSION WHERE ACCOUNT_ID = ' . $ACCOUNT_ID);
				if ($ret === false){ throw new Exception($this->baseMsg->_('SYSTEM ERROR : DELETE ACCOUNT INFORMATION 08')); }

				$ret = $this->db->query('DELETE FROM ACCOUNT_ACTIVITY WHERE ACCOUNT_ID = ' . $ACCOUNT_ID);
				if ($ret === false){ throw new Exception($this->baseMsg->_('SYSTEM ERROR : DELETE ACCOUNT INFORMATION 09')); }
			}

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
			$this->db->autocommit(true);
		}

		return true;
	}

	/**
	 * ユーザ名とパスワードが妥当か調べる
	 *
	 * @param integer $ACCOUNT_ID 0が指定された場合は新規登録時のチェック 0以上が指定された場合は、更新用
	 * @param string $USER_NAME ユーザ名 メールアドレスか電話番号が基本
	 * @param string $PASS パスワード
	 * @param int $USER_NAME_TYPE $USER_NAMEの判別した種類を返す。
	 * @param array $aryAppId 既に登録済みの場合、登録済みのアプリのリストを返す。
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
	 * 					50 ユーザ名未指定
	 * 					51 パスワード未指定
	 * 					55 アカウントがない（更新時）
	 * 					56 現アカウントから変更がない (更新時)
	 * 					60 既に登録済み　攻撃の可能性があるので攻撃登録すること
	 * 					99 DBエラー　システムエラー
	 */
	function checkAccountPara(int $ACCOUNT_ID, string $USER_NAME, string $PASS, int &$USER_NAME_TYPE, array &$aryAppId) : int{

		$this->strError = '';
		$aryAppId = array();
		$USER_NAME_TYPE = self::USER_NAME_TYPE_OTHER;

		if ($USER_NAME == ''){ $this->strError = $this->baseMsg->_('Please enter your account name.'); return 50; }
		if ($PASS == ''){ $this->strError = $this->baseMsg->_('Please enter your password.'); return 51; }

		$passMinLen = ((defined(TLIB_MIN_PASSWORD_LENGTH))?TLIB_MIN_PASSWORD_LENGTH:self::MIN_PASSWORD_LENGTH);
		$passMaxLen = ((defined(TLIB_MAX_PASSWORD_LENGTH))?TLIB_MAX_PASSWORD_LENGTH:self::MAX_PASSWORD_LENGTH);
		$passMode = ((defined(TLIB_PASSWORD_CHECK_MODE))?TLIB_PASSWORD_CHECK_MODE:self::PASSWORD_CHECK_MODE);

		// ユーザ名のタイプ判断
		$USER_NAME_TYPE = $this->judgeIdType(-1,$USER_NAME);
		if ($USER_NAME_TYPE == self::USER_NAME_TYPE_OTHER){
			if (mb_strlen($USER_NAME) < self::MIN_USER_NAME_LENGTH){ $this->strError = $this->baseMsg->_('Your user name is too short.'); return 1; }
			if (mb_strlen($USER_NAME) > self::MAX_USER_NAME_LENGTH){ $this->strError = $this->baseMsg->_('Your user name is too long.');return 2; }
		}

		if (!is_null($PASS)){
			$ret = isThis::password($PASS, $passMinLen, $passMaxLen, $passMode);  // 0 正常 1 短すぎる 2 長すぎる 3 数字のみエラー 4 数字なし 5 小文字なし 6 大文字なし 7 記号無し
			if ($ret > 0){
				switch($ret){
					case 1 : $this->strError = $this->baseMsg->_('Your password is too short.'); break;
					case 2 : $this->strError = $this->baseMsg->_('Your password is too long.'); break;
					case 3 : $this->strError = $this->baseMsg->_('Please include some letters other than numbers in your password..'); break;
					case 4 : $this->strError = $this->baseMsg->_('Please include some numbers in your password.'); break;
					case 5 : $this->strError = $this->baseMsg->_('Please put lowercase letters in your password.'); break;
					case 6 : $this->strError = $this->baseMsg->_('Please put uppercase letters in your password.'); break;
					case 7 : $this->strError = $this->baseMsg->_('Please put a symbol in the password.'); break;
					default:
					$this->strError = $this->baseMsg->_('Unknown error occured when checking your password.');
				}

				return ($ret + 2); // 3 ～ 9
			}
		}

		// 新規登録
		if ($ACCOUNT_ID == 0){

			if ($USER_NAME_TYPE == self::USER_NAME_TYPE_OTHER){
				// 同じユーザ名／パスワードが登録されていないか。
				$sql = 'SELECT ifnull(ACCOUNT_ID,0) FROM ACCOUNT WHERE USER_NAME = "' . $USER_NAME . '" PASS = "' .  $this->db->real_escape_string($this->hashPass($PASS)) . '"  AND VALID = 1';
			}else{
				// 同じメール／電話番号で既に登録されていないか。
				$sql = 'SELECT ifnull(ACCOUNT_ID,0) FROM ACCOUNT WHERE USER_NAME = "' . $USER_NAME . '" AND VALID = 1';
			}

			$existACCOUNT_ID = $this->db->getFirstOne($sql);
			if ($existACCOUNT_ID === null){ $this->strError = $this->baseMsg->_('SQL Error : when checking existing account'); return 99;  }
			if ($existACCOUNT_ID > 0){

				// 既に登録済み
				$sql = 'SELECT APP_ID FROM OAUTH_USER_PERMIT WHERE ACCOUNT_ID = ' . $existACCOUNT_ID;
				$aryAppId = $this->db->getTheClmArray($sql);

				// エラーで終了
				$this->strError = $this->baseMsg->_('It is possible that the user_name has already been registered.');
				return 60;
			}

		// 更新のためのチェック
		}else{

			// 既存との違いをチェック
			$sql = 'SELECT USER_NAME, PASS FROM ACCOUNT WHERE ACCOUNT_ID = ' . $ACCOUNT_ID;
			$row = $this->db->getFirstRow($sql);
			if ($row === null){
				$this->strError = $this->baseMsg->_('Could not find your accout.');
				return 55;
			}
			if ($row[0] == $USER_NAME){
				// ユーザ名が同じでパスワードも同じ
				if ($row[1] == $this->hashPass($PASS)){
					$this->strError = $this->baseMsg->_('The specified change information is the same as the current one.');
					return 56;
				}
			}else{
				// ユーザ名が違う。既に登録されていないかチェック
				$sql = 'SELECT ifnull(ACCOUNT_ID,0) FROM ACCOUNT WHERE USER_NAME = "' . $USER_NAME . '" AND VALID = 1';
				$existACCOUNT_ID = $this->db->getFirstOne($sql);
				if ($existACCOUNT_ID === null){ $this->strError = $this->baseMsg->_('SQL Error : when checking existing account'); return 99;  }
				if ($existACCOUNT_ID > 0){

					// 既に登録済み
					$sql = 'SELECT APP_ID FROM OAUTH_USER_PERMIT WHERE ACCOUNT_ID = ' . $existACCOUNT_ID;
					$aryAppId = $this->db->getTheClmArray($sql);

					// エラーで終了
					$this->strError = $this->baseMsg->_('It is possible that the user_name has already been registered.');
					return 60;
				}

			}

		}

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
	 *  => 登録アプリが0になる場合はアカウントも削除となるため注意のこと。
	 *
	 * @param integer $APP_ID
	 * @param integer $ACCOUNT_ID
	 * @param boolean $bUseTransaction
	 * @return bool true 正常 false 異常
	 */
	function dellAccountFromApp(int $APP_ID, int $ACCOUNT_ID, bool $bUseTransaction = false) : bool{

		// 存在する？
		$cnt = $this->db->getFirstOne('SELECT COUNT(*) FROM OAUTH_USER_PERMIT WHERE APP_ID = ' . $APP_ID . ' AND ACCOUNT_ID = ' . $ACCOUNT_ID);
		if ($cnt == null){ return false; }
		if ($cnt == 0){ return true; } // 存在しないならそれはそれで良い

		// 削除したら登録アプリ数が0になる？
		$cnt = $this->db->getFirstOne('SELECT COUNT(*) FROM OAUTH_USER_PERMIT WHERE ACCOUNT_ID = ' . $ACCOUNT_ID);
		if ($cnt == null){ return false; }
		if ($cnt == 1){ return $this->delAccount($ACCOUNT_ID); } // 0になる場合はアカウント毎削除

		if ($bUseTransaction){
			$this->db->autocommit(FALSE);
		}

		try{

			$ret = $this->db->query('DELETE FROM OAUTH_USER_PERMIT  WHERE APP_ID = ' . $APP_ID . ' AND ACCOUNT_ID = ' . $ACCOUNT_ID); if ($ret === false){ throw new Exception($this->baseMsg->_('SYSTEM ERROR : Failed to delete from the table 01')); }
			$ret = $this->db->query('DELETE FROM OAUTH_CODE_SESSION WHERE APP_ID = ' . $APP_ID . ' AND ACCOUNT_ID = ' . $ACCOUNT_ID); if ($ret === false){ throw new Exception($this->baseMsg->_('SYSTEM ERROR : Failed to delete from the table 02')); }
			$ret = $this->db->query('DELETE FROM OAUTH_SESSION      WHERE APP_ID = ' . $APP_ID . ' AND ACCOUNT_ID = ' . $ACCOUNT_ID); if ($ret === false){ throw new Exception($this->baseMsg->_('SYSTEM ERROR : Failed to delete from the table 03')); }
			$ret = $this->db->query('DELETE FROM ACCOUNT_SESSION    WHERE APP_ID = ' . $APP_ID . ' AND ACCOUNT_ID = ' . $ACCOUNT_ID); if ($ret === false){ throw new Exception($this->baseMsg->_('SYSTEM ERROR : Failed to delete from the table 04')); }
			$ret = $this->db->query('DELETE FROM ACCOUNT_ACTIVITY   WHERE APP_ID = ' . $APP_ID . ' AND ACCOUNT_ID = ' . $ACCOUNT_ID); if ($ret === false){ throw new Exception($this->baseMsg->_('SYSTEM ERROR : Failed to delete from the table 05')); }

		}catch(Exception $e){
			if ($bUseTransaction){
				$this->db->rollback();
				$this->db->autocommit(true);
			}

			return false;
		}

		if ($bUseTransaction){
			$this->db->commit();
			$this->db->autocommit(true);
		}

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
	 * @param string $VERIFY_CODE 認証コード（返却）8桁の数字
	 * @param string $TEMP_SESSION_KEY セッションキー（返却）
	 * @param array $ADDITIONAL_INFO 記録しておく追加情報
	 * @param boolean $bAuthExists 既に認証済みである場合、trueを返す。（戻り値はtrueの場合チェック この場合、指定されたメールアドレス／SMSにユーザ名を送るなど処理をする必要あり。）
	 * @return boolean true 正常終了 false 異常終了
	 */
	public function startVerify(int $ACCOUNT_ID = 0, int $AUTH_TYPE, string $AUTH_INFO, string &$VERIFY_CODE, string &$TEMP_SESSION_KEY, ?array $ADDITIONAL_INFO, bool &$bAuthExists) : bool{

		$bAuthExists = false;

		try{

			// ACCOUNT_INFO_VERIFIEDには複数登録を許可する。
			// 基本はcheckAccountParaでACCOUNTテーブル側の重複はＮＧとなっているはず。
			// // 既に認証済みではないか確認
			// $sql = 'SELECT COUNT(*) FROM ACCOUNT_INFO_VERIFIED WHERE AUTH_TYPE = ' . $AUTH_TYPE . ' AND AUTH_INFO = "' . $this->db->real_escape_string($AUTH_INFO) . '" AND VALID = 1';
			// $cnt = $this->db->getFirstOne($sql);
			// if ($cnt === null){ throw new \Exception($this->baseMsg->_('SYSTEM ERROR : failed to check the TFA information exists.')); }
			// if ($cnt > 0){
			// 	// 既に認証済み
			// 	$bAuthExists = true;
			// 	return true;
			// }

			if ($ADDITIONAL_INFO === null){ $ADDITIONAL_INFO = array(); }
			$encoded_ADDITIONAL_INFO = JSON_ENCODE($ADDITIONAL_INFO, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

			$VERIFY_CODE = (string)random_int(10000000, 99999999); // とりあえず8桁
			// $TEMP_SESSION_KEYが被ってNGになるかもしれないので、10回繰り返す。
			$inserted = false;
			for ($i=0;$i < 10;$i++){
				$TEMP_SESSION_KEY = hash(TLIB_HASH_ALGO_PASS, date('YYYYmmddhhiiss') . TLIB_HASH_SOLT . $AUTH_INFO . bin2hex(random_bytes(16)));
				$sql = 'INSERT INTO AUTH_VERIFY (ACCOUNT_ID, AUTH_TYPE, AUTH_INFO, VERIFY_CODE, TEMP_SESSION_KEY, ADDITIONAL_INFO FAILED_CNT, EXPIRE_TIME, INSERT_TIME)
				VALUES (' . $ACCOUNT_ID . ', ' . $AUTH_TYPE . ', "' . $AUTH_INFO . '", "' . $VERIFY_CODE . '", "' . $TEMP_SESSION_KEY . '","' . $this->db->real_escape_string($encoded_ADDITIONAL_INFO) .'", 0, now() + INTERVAL 30 MINUTE, now())';
				$ret = $this->db->query($sql);
				if ($ret === true){ $inserted = true; break; }
			}
			if (!$inserted){
				throw new \Exception($this->baseMsg->_('SYSTEM ERROR : FAILED TO INSERT AUTH_VERIFY'));
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
			if ($verifyInfo == null){ throw new \Exception($this->baseMsg->_('Could not find the user session. Probably the authentication time has expired.')); }

			// 認証回数エラー
			if ($verifyInfo['FAILED_CNT'] >= self::MAX_AUTH_VERIFY_FAILED_COUNT){ throw new \Exception($this->baseMsg->_('Too many times to enter the code.')); }

			// 認証エラー
			if ($verifyInfo['VERIFY_CODE'] != $VERIFY_CODE){
				$sql = 'UPDATE AUTH_VERIFY SET FAILED_CNT = FAILED_CNT+1 WHERE TEMP_SESSION_KEY = "' . $ESCAPE_TEMP_SESSION_KEY . '"';
				$ret = $this->db->query($sql);
				if ($ret === false){ throw new \Exception($this->baseMsg->_('SYSTEM ERROR : DB ACCESS FAILED.')); }

				throw new \Exception($this->baseMsg->_('Wrong code specifyed via two facter authentication.'));
			}

		}catch(\Exception $e){
			$this->strError = $e->getMessage();
			return false;
		}

		// デコード
		$verifyInfo['ADDITIONAL_INFO'] = json_decode($verifyInfo['ADDITIONAL_INFO'], true);

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
			if ($verifyInfo == null){ $this->strError = $this->baseMsg->_('Could not find the user session.'); return false; }
		}

		$sql = 'SELECT COUNT(*) FROM ACCOUNT_INFO_VERIFIED WHERE ACCOUNT_ID = ' . $ACCOUNT_ID;
		$VERIFIED_ID = $this->db->getFirstOne($sql);
		if ($VERIFIED_ID == null){  $this->strError = $this->baseMsg->_('SYSTEM ERROR : FAILED TO GET ACCOUNT_INFORMATION.'); return false; }

		if ($bUseTransaction){
			$this->db->autocommit(FALSE);
		}

		try{

			// AUTH_VERIFYからACCOUNT_INFO_VERIFIEDへのデータの移動
			$sql = 'INSERT INTO ACCOUNT_INFO_VERIFIED (ACCOUNT_ID, VERIFIED_ID, VERIFIED_INFO_TYPE, VERIFIED_INFO, VALID, LAST_VALID_TIME, INSERT_TIME)
				VALUES (' . $ACCOUNT_ID . ', ' . ($VERIFIED_ID+1) . ', ' . $verifyInfo['AUTH_TYPE'] . ', "' . $verifyInfo['AUTH_INFO'] . '", 1, now(), now())';
			$ret = $this->db->query($sql);
			if (!$ret){ throw new \Exception($this->baseMsg->_('SYSTEM ERROR : Failed to insert ACCOUNT_INFO_VERIFIED table.')); }

			// AUTH_VERIFYの削除
			$sql = 'DELETE FROM AUTH_VERIFY WHERE TEMP_SESSION_KEY = "' . $ESCAPE_TEMP_SESSION_KEY . '"';
			$ret = $this->db->query($sql);
			if (!$ret){ throw new \Exception($this->baseMsg->_('SYSTEM ERROR : Failed to DELETE AUTH_VERIFY table.')); }

		}catch(Exception $e){

			if ($bUseTransaction){
				$this->db->rollback();
				$this->db->autocommit(true);
			}

			$this->strError = __METHOD__ . ' : ' . $e->getMessage();
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
				$cnt = $this->db->getFirstOne($sql);
				if ($cnt === null){ throw new \Exception($this->baseMsg->_('SYSTEM ERROR : FAILED TO GET new ID chars')); }
				if ($cnt == 0){ break; }
				if ($i == 9){ throw new \Exception($this->baseMsg->_('SYSTEM ERROR : FAILED TO GET new ID chars : Over MAX count')); }
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
			if (!$ret){ throw new \Exception($this->baseMsg->_('SYSTEM ERROR : FAILED TO INSERT OAUTH_APPLICATION')); }

			$APP_ID = $this->db->getFirstOne('SELECT LAST_INSERT_ID()');
			if ($APP_ID === null){ throw new \Exception($this->baseMsg->_('SYSTEM ERROR : FAILED TO GET LAST_INSERTED_ID')); }

		}catch(\Exception $e){
			$this->strError = __METHOD__ . ' : ' . $e->getMessage();
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

		return $this->db->query($sql);

	}
	/**
	 * アプリ情報を削除する。登録アプリが無くなるユーザも削除するので注意。ユーザ数が多いととても時間がかかる。
	 *
	 * @param integer|string $APP_INDEX intの場合はAPP_ID、stringの場合はAPP_ID_CHARS
	 * @param boolean $bUseTransaction トランザクションを使うか
	 * @return boolean
	 */
	public function delApp(int|string $APP_INDEX, bool $bUseTransaction = false) : bool {

		$APP_ID = 0;
		if (gettype($APP_INDEX) == 'integer'){
			$APP_ID = $APP_INDEX;
		}else{
			$sql = 'SELECT APP_ID FROM OAUTH_APPLICATION WHERE APP_ID_CHARS = "' . $this->db->escape_string($APP_INDEX) . '"';;
			$APP_ID = $this->getFirstOne($sql);
			if ($APP_ID === null){ // 当該IDが見つからない
				return true; // それはそれでOK
			}
		}

		// 登録されているアカウントの一覧を取得
		$sql = 'SELECT ACCOUNT_ID FROM OAUTH_USER_PERMIT WHERE APP_ID = ' . $APP_ID;
		$accountIds = $this->db->getTheClmArray($sql);
		if ($accountIds == null){
			$this->strError = __METHOD__ . ' : SYSTEM ERROR : Could not get the list of account';
			return false;
		}

		// トランザクション内でやるには気が引けるので、ここで本アプリにしか登録していないアカウントを探す。
		// 1000件づつ処理
		$targetAccounts = array();
		$len = count($accountIds);
		if ($len > 0){
			$chunkedAccountIds = array_chunk($accountIds, 1000);
			foreach ($chunkedAccountIds as $chunk) {
				$idString = implode(',', $chunk);
				$sql = 'SELECT ACCOUNT_ID, COUNT(*) AS APP_CNT FROM OAUTH_USER_PERMIT WHERE ACCOUNT_ID IN (' . $idString . ') GROUP BY ACCOUNT_ID HAVING APP_CNT = 1';
				$row = $this->db->getAll($sql);
				$lenRow = count($row);
				for($i=0;$i < $lenRow;$i++){
					$targetAccounts[] = $row[$i][0];
				}
			}
		}

		if ($bUseTransaction){
			$this->db->autocommit(FALSE);
		}

		try{

			$ret = $this->db->query('DELETE FROM OAUTH_APPLICATION  WHERE APP_ID = ' . $APP_ID); if ($ret === false){ throw new Exception($this->baseMsg->_('SYSTEM ERROR : Failed to delete from the table 01')); }
			$ret = $this->db->query('DELETE FROM OAUTH_USER_PERMIT  WHERE APP_ID = ' . $APP_ID); if ($ret === false){ throw new Exception($this->baseMsg->_('SYSTEM ERROR : Failed to delete from the table 01')); }
			$ret = $this->db->query('DELETE FROM OAUTH_CODE_SESSION  WHERE APP_ID = ' . $APP_ID); if ($ret === false){ throw new Exception($this->baseMsg->_('SYSTEM ERROR : Failed to delete from the table 01')); }
			$ret = $this->db->query('DELETE FROM OAUTH_SESSION  WHERE APP_ID = ' . $APP_ID); if ($ret === false){ throw new Exception($this->baseMsg->_('SYSTEM ERROR : Failed to delete from the table 01')); }
			$ret = $this->db->query('DELETE FROM ACCOUNT_SESSION  WHERE APP_ID = ' . $APP_ID); if ($ret === false){ throw new Exception($this->baseMsg->_('SYSTEM ERROR : Failed to delete from the table 01')); }
			$ret = $this->db->query('DELETE FROM ACCOUNT_ACTIVITY  WHERE APP_ID = ' . $APP_ID); if ($ret === false){ throw new Exception($this->baseMsg->_('SYSTEM ERROR : Failed to delete from the table 01')); }

			// アカウントの削除は1000件づつ処理
			$len = count($targetAccounts);
			if ($len > 0){
				$chunkedAccounts = array_chunk($targetAccounts, 1000);
				foreach ($chunkedAccounts as $chunk) {
					$idString = implode(',', $chunk);
					$ret = $this->db->query('DELETE FROM ACCOUNT WHERE ACCOUNT_ID IN (' . $idString . ')');
					if ($ret === false){ throw new Exception($this->baseMsg->_('SYSTEM ERROR : DELETE ACCOUNT INFORMATION 01')); }

					$ret = $this->db->query('DELETE FROM ACCOUNT_INFO_VERIFIED WHERE ACCOUNT_ID IN (' . $idString . ')');
					if ($ret === false){ throw new Exception($this->baseMsg->_('SYSTEM ERROR : DELETE ACCOUNT INFORMATION 02')); }

					$ret = $this->db->query('DELETE FROM AUTH_VERIFY WHERE ACCOUNT_ID IN (' . $idString . ')');
					if ($ret === false){ throw new Exception($this->baseMsg->_('SYSTEM ERROR : DELETE ACCOUNT INFORMATION 03')); }
				}
			}

		}catch(Exception $e){
			if ($bUseTransaction){
				$this->db->rollback();
				$this->db->autocommit(true);
			}

			$this->strError = __METHOD__ . ' : ' . $e->getMessage();
			return false;
		}

		if ($bUseTransaction){
			$this->db->commit();
			$this->db->autocommit(TRUE);
		}

		return $this->db->query($sql);

	}

	///////////////////////////////////////////////////////////////////////////
	// ログイン ログアウト関係

	/**
	 * 当該APPに指定されたユーザとパスでログインできるかどうか。
	 *
	 * @param integer $APP_ID
	 * @param string $USER_NAME
	 * @param string $PASS
	 * @param integer $accountId 当該ユーザのアカウントID
	 * @return integer 0 正常終了 1 アカウント無し 2 アプリに登録なし 99エラー
	 */
	function verifyUserPass(int $APP_ID, string $USER_NAME, string $PASS, int &$accountId) : int {

		$accountId = 0;

		// まずアカウントがあるか。なければそもそもNG。
		$sql = 'SELECT ACCOUNT_ID FROM USER_NAME = "' . $this->db->real_escape_string($USER_NAME) . '" AND PASS = "' . $this->db->real_escape_string($this->hashPass($PASS)) . '" AND VALID = 1';
		$ret = $this->db->getFirstOne($sql);
		if ($ret === null){ return 1; }

		$accountId = (int)$ret;

		// アプリにアカウントの登録があるか。
		$sql = 'SELECT COUNT(*) FROM OAUTH_USER_PERMIT WHERE APP_ID = ' . $APP_ID . ' AND ACCOUNT_ID = ' . $accountId;
		$ret = $this->db->getFirstOne($sql);
		if ($ret === null){ return 99; }
		if ($ret == 0){ return 2; }

		return 0;
	}

	/**
	 * 指定のアカウントのセッションを追加
	 *
	 * @param integer $APP_ID
	 * @param integer $ACCOUNT_ID
	 * @param string $SESSION_CODE
	 * @return boolean
	 */
	function addSession(int $APP_ID, int $ACCOUNT_ID, string &$SESSION_CODE) : bool{

		$USER_INFO = json_encode(array ('IP'=> $_SERVER['REMOTE_ADDR']));
		$SESSION_CODE = '';
		// SESSION_CODEが被らないように一応10回試す。
		for ($i=0;$i < 10;$i++){
			$NEW_SESSION_CODE = lib::getSessionHashCode($APP_ID, $ACCOUNT_ID);
			$sql = 'INSERT INTO OAUTH_SESSION VALUES (APP_ID, ACCOUNT_ID, ACCESS_TOKEN, USER_INFO, ACCESS_TOKEN, EXPIRE_TIME, INSERT_TIME) VALUE (
				' . $APP_ID . ', ' . $ACCOUNT_ID . ',"' . $NEW_SESSION_CODE . '","' . $this->db->real_escape_string($USER_INFO) . '", now(), ADDDATE(now(), ' . TLIB_LOGIN_EXPIRE_DAYS . '), now())';
			$ret = $this->db->query($sql);
			if ($ret){ $SESSION_CODE = $NEW_SESSION_CODE; return true; }
		}
		return false;
	}

	/**
	 * OAUTH_SESSIONの当該行を削除する。
	 *
	 * @param string $SESSION_CODE
	 * @return boolean
	 */
	function delSession(int $APP_ID, string $SESSION_CODE) : bool {
		return $this->db->query('DELETE FROM OAUTH_SESSION WHERE APP_ID = ' . $APP_ID . ' AND ACCESS_TOKEN = "' .  $this->db->real_escape_string($SESSION_CODE) . '"');
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
	 * $USER_NAMEからIDの種類（1 e-mail 2 SMS 99 任意文字列）を判別して返す。
	 * $ID_TYPEが既に判別済みの場合は処理しない。
	 *
	 * @param int $ID_TYPE -1 の場合、$USER_NAMEの内容から、emailかSMSか判別する。
	 * @param string $USER_NAME ユーザID emailかSMSか文字列か
	 * @return int 1 e-mail 2 SMS 99 任意文字列
	 */
	protected function judgeIdType(int $ID_TYPE, string $USER_NAME) :int{
		if ($ID_TYPE > 0){ return $ID_TYPE; }

		$ret = self::USER_NAME_TYPE_OTHER;
		if (isThis::email($USER_NAME)){
			$ret = self::USER_NAME_TYPE_EMAIL;
		}else if (isThis::phone($USER_NAME, true)) {
			$ret = self::USER_NAME_TYPE_PHONE;
		}
		return $ret;
	}

}
