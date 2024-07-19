<?php
namespace tlib;

/**
 * ログイン関係の関数群
 * isLoginは多用するため、極力軽くする。（なのでlocaleなどは設定しない。）
 *  => まだ作っていない
 */
class clsAuth {

	///////////////////////////////////////////////////////////////////////////
	// ログイン ログアウト

	/**
	 * ログインする (2段認証なし、もしくは、2段認証後)
	 *
	 * @param integer $APP_ID ログイン対象のアプリ
	 * @param integer $ID_TYPE $ID_TYPE 1 e-mail 2 SMS 99 任意文字列 -1の場合、$ID_INFOから自動判別
	 * @param string $ID_INFO ID_TYPE=1の場合 e-mail 2の場合 電話番号 99の場合任意文字列
	 * @param string $PASS 平文のパス
	 * @param bool $useOAUthSessionTable trueの場合OAUTH_SESSIONテーブルにセッションを設定し、$SESSION_CODEにセッション文字列を設定する。
	 * @param string &$SESSION_CODE $useOAUthSessionTableがtrueの場合、セッション文字列を返す。
	 * @return int ACCOUNT_IDを返す。取得出来なかった場合は 0
	 */
	function login(int $APP_ID, int $ID_TYPE=-1, string $ID_INFO, string $PASS, bool $useOAUthSessionTable = true, string &$SESSION_CODE = '') : int {

		$SESSION_CODE = '';
		$ACCOUNT_ID = 0;

		try{
			// $ID_TYPEがない場合は、$ID_INFOから判別
			$ID_TYPE = $this->judgeIdType($ID_TYPE, $ID_INFO);

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
				$error = $this->insertNewSessionRecord($APP_ID, $ACCOUNT_ID, $SESSION_CODE);
				if ($error != ''){  throw new \Exception($error); }
			}

		}catch(\Exception $e){
			$this->strError = $e->getMessage();
			$ACCOUNT_ID = 0;
		}

		return (int)$ACCOUNT_ID;
	}

	/**
	 * OAUTH_SESSIONの当該行を削除する。
	 *
	 * @param string $SESSION_CODE
	 * @return boolean
	 */
	function logout(string $SESSION_CODE) : bool {
		return $this->db->query('DELETE FROM OAUTH_SESSION WHERE ACCESS_TOKEN = "' .  $this->db->real_escape_string($SESSION_CODE) . '"');
	}

	/**
	 * OAUTH_SESSIONテーブルを使い、ログイン中か調べる
	 *
	 * @param integer $APP_ID ログイン中か調べるAPP_ID
	 * @param string $SESSION_CODE セッション $bUpdateSessionCodeの場合、変更の可能性あり。
	 * @param bool $dayOfSessionUpdate 1より大きい場合、最後にOAUTH_SESSIONを更新してからこの日数経過していたら、$SESSION_CODEを別の値に書き換える。0で書き換え無し
	 * @param bool $bUpdateExpireDate $loginExpiredDaysの半分以下の有効期限となっていた場合、有効期限を伸ばす場合はtrue
	 * @return integer ログインしているaccountのID
	 */
	function isLogin(int $APP_ID, string &$SESSION_CODE, int $dayOfSessionUpdate = 0, bool $bUpdateExpireDate = true) : int{

		$ACCOUNT_ID = 0;

		try{

			// $SESSION_CODEは外部から渡される可能性があるので、一応エスケープしておく。
			$escapeSESSION_CODE = $this->db->real_escape_string($SESSION_CODE);

			$sql = 'SELECT ACCOUNT_ID, DATEDIFF(now(),LAST_CHECK_TIME), DATEDIFF(now(), EXPIRE_TIME) FROM OAUTH_SESSION
			WHERE APP_ID = ' . $APP_ID . ' AND ACCESS_TOKEN = "' . $escapeSESSION_CODE . '" AND EXPIRE_TIME <= now()';
			$SESSION_INFO = $this->db->getFirstRow($sql);
			if ($SESSION_INFO === null){ throw new \Exception('Not Found the session'); }
			$ACCOUNT_ID = $SESSION_INFO[0];
			$daysFromLastChecked = abs($SESSION_INFO[1]);
			$daysToExpire = abs($SESSION_INFO[2]);

			// セッションコードを変更するか
			if ($dayOfSessionUpdate > 0 && $daysFromLastChecked >= $dayOfSessionUpdate){

				// 変更する
				$error = $this->insertNewSessionRecord($APP_ID, $ACCOUNT_ID, $SESSION_CODE);
				if ($error != ''){  throw new \Exception($error); }

				// 現在のSESSIONは5分後に切れるようにする。
				$sql = 'UPDATE OAUTH_SESSION SET EXPIRE_TIME = ADDTIME(now(), "0:5:0.0"), LAST_CHECK_TIME=now()
				WHERE APP_ID = ' . $APP_ID . ' AND ACCESS_TOKEN = "' . $escapeSESSION_CODE . '"';
				$ret = $this->db->query($sql);
				if (!$ret){ throw new \Exception('failed to delete old session'); }

			}else{
				// 有効期限を伸ばす (伸ばす場合は、LAST_CHECK_TIMEも合わせて更新)
				if ($bUpdateExpireDate && (floor($this->loginExpiredDays/2) > $daysToExpire) ){
					$sql = 'UPDATE OAUTH_SESSION SET EXPIRE_TIME = ADDDATE(now(), ' . $this->loginExpiredDays . '), LAST_CHECK_TIME=now()
					WHERE APP_ID = ' . $APP_ID . ' AND ACCESS_TOKEN = "' . $escapeSESSION_CODE . '"';

					$ret = $this->db->query($sql);
					if (!$ret){ throw new \Exception('failed to update token'); }

				// 通常の処理 LAST_CHECK_TIMEのみ更新
				}else{
					$sql = 'UPDATE OAUTH_SESSION SET LAST_CHECK_TIME=now()
					WHERE APP_ID = ' . $APP_ID . ' AND ACCESS_TOKEN = "' . $escapeSESSION_CODE . '"';
				}
			}


		}catch(\Exception $e){
			$this->strError = $e->getMessage();
			$ACCOUNT_ID = 0;
		}
		return $ACCOUNT_ID;
	}

	/**
	 * セッション管理テーブルに指定のアカウントの新しいレコードを追加する。（isLoginでログイン状態になる。）
	 *
	 * @param integer $APP_ID アプリケーションの固有ID
	 * @param integer $ACCOUNT_ID ユーザの固有アカウント
	 * @return string エラー文言 エラーが無い場合は空文字
	 */
	function insertNewSessionRecord(int $APP_ID, int $ACCOUNT_ID, string &$SESSION_CODE = '') : string{
		$USER_INFO = array ('IP'=> $_SERVER['REMOTE_ADDR']);
		// SESSION_CODEが被らないように一応10回試す。
		for ($i=0;$i < 10;$i++){
			$NEW_SESSION_CODE = json_encode(hash("sha3-256", $APP_ID . $ACCOUNT_ID .  time() . random_bytes(32)));
			$sql = 'INSERT INTO OAUTH_SESSION VALUES (APP_ID, ACCOUNT_ID, ACCESS_TOKEN, USER_INFO, LAST_CHECK_TIME, EXPIRE_TIME, INSERT_TIME) VALUE (
				' . $APP_ID . ', ' . $ACCOUNT_ID . ',"' . $NEW_SESSION_CODE . '","' . $USER_INFO . '", now(), ADDDATE(now(), ' . $this->loginExpiredDays . '), now())';
			$ret = $this->db->query($sql);
			if ($ret){ $SESSION_CODE = $NEW_SESSION_CODE; return ''; }
		}
		return 'FAILED TO INSERT OAUTH_SESSION';
	}

	/**
	 * 期限切れのSESSIONを削除する。
	 *
	 * @return boolean
	 */
	function deleteExpiredSession() : bool {
		$ret = $this->db->query('DELETE FROM OAUTH_SESSION WHERE EXPIRE_TIME <= now()');
		return $ret;
	}



}

