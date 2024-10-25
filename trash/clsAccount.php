<?php

include_once TLIB_ROOT . 'clsAccountBase.php';
include_once TLIB_ROOT . 'clsEMail.php';
include_once TLIB_ROOT . 'clsTemplate.php';

class clsAccout extends clsAccountBase{

	private clsTransfer $msg;

	function __construct(clsDB &$db){
		parant::__construct($db);
		$this->msg = clsTransfer(pathinfo(__FILE__, PATHINFO_FILENAME), __DIR__ . '/locale/');
	}

	///////////////////////////////////////////////////////////////////////////
	// アカウントの登録
	// Step01から順番に呼び出すことで一般的なアカウント登録が出来るようにする。

	/**
	 * アカウント登録 ステップ1
	 * ユーザ名（メールアドレス）とパスワードを入力してもらった後の処理を行う
	 *  => ユーザにメールアドレス確認コードをメール送信する。
	 *
	 * @param string $subject コードを送付するメールのタイトル
	 * @param string $tmpltPathOfEmailContent コードを送付するメールの内容を記したtemplateファイル
	 * @param string $email アカウントのメールアドレス
	 * @param string $pass アカウントのパスワード
	 * @param array $ADDITIONAL_INFO コード認証後に受け取る追加情報（連想配列 "user"と"pass"は自動で設定されるのでそれ以外を指定）
	 * @param string $VERIFY_CODE 認証キー
	 * @param string $TEMP_SESSION_KEY 認証キーのセッション
	 * @return string 正常終了時 '' エラー時 ユーザに返すエラー内容
	 */
	function addAccountStep01(string $subject = '', string $tmpltPathOfEmailContent = '', string $email, string $pass, array $ADDITIONAL_INFO, string &$VERIFY_CODE, string &$TEMP_SESSION_KEY) : string{

		// 引数チェック
		if (!isthis::email($email)){ return $this->msg->_('The email address specified is incorrect.'); }
		$ret = $this->checkAccountPara(0, $email, $pass);
		if ($ret > 0){
			if ($ret == 60){ return $this->msg->_('You specified wrong email and password.'); } // 攻撃者にヒントを与えないため、エラー内容は明示しない
			return $this->getErrorMsg();
		}

		// AUTH_VERIFYテーブルに仮登録
		$ADDITIONAL_INFO = array('user'=>$email, 'pass'=>$pass);
		$VERIFY_CODE = '';
		$TEMP_SESSION_KEY = '';
		$bAuthExists = false;
		$ret = $this->startVerify(0, 1, $email, $VERIFY_CODE, $TEMP_SESSION_KEY, $ADDITIONAL_INFO, $bAuthExists);
		if (!$ret){ return $this->getErrorMsg(); }

		// メール送信
		if ($subject == ''){ $subject = $this->msg->_('Confirm your email address'); }
		if ($tmpltPathOfEmailContent == ''){
			$tmpltPathOfEmailContent = TLIB_ROOT . 'template/emailVerify/addAccount/' . $GLOBALS['lang'] . '.php';
		}

		if (!file_exists($tmpltPathOfEmailContent)){ return $this->msg->_('System Error : Could not find email template file.'); }

		$objTemplate = new clsTemplate();
		$objTemplate->clear();
		$VERIFY_CODE_TO_SHOW = substr($VERIFY_CODE, 0, 4) . ' ' . substr($VERIFY_CODE, 4, 4); // 表示用に4桁づつに分ける
		$objTemplate->param['VERIFY_CODE'] = $VERIFY_CODE_TO_SHOW;
		$objTemplate->param['TEMP_SESSION_KEY'] = $TEMP_SESSION_KEY; // 使用しないかもしれないが・・

		$emailContens = $objTemplate->getTemplateResult($tmpltPathOfEmailContent);

		// メール送信
		$objEmail = new clsEMail(TLIB_EMAIL_NOTICE[0], TLIB_EMAIL_NOTICE[1], TLIB_EMAIL_NOTICE[2], TLIB_EMAIL_NOTICE[3]);
		$objEmail->setFromAddress(TLIB_EMAIL_NOTICE[5], TLIB_EMAIL_NOTICE[4]);
		$objEmail->setToAddress($email);
		if (isset(TLIB_EMAIL_NOTICE[6]) && isthis::email(TLIB_EMAIL_NOTICE[6])){
			$objEmail->setBccAddress(TLIB_EMAIL_NOTICE[6]);
		}

		$ret = $objEmail->sendHTML($subject, $emailContens);
		if ($ret != ''){
			$this->msg->_('Failed to send verify email code to your email address. please try later.');
			util::vitalLogOut(3, '[' . __CLASS__ . '::' . __METHOD__ . '] email send error : ' . $ret);
		}

		return '';

	}

	function addAccountStep02(int $APP_ID, string $TEMP_SESSION_KEY, string $VERIFY_CODE, array &$ADDITIONAL_INFO){

	}



}