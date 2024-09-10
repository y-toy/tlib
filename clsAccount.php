<?php

include_once TLIB_ROOT . 'clsAccountBase.php';
include_once TLIB_ROOT . 'clsEMail.php';
include_once TLIB_ROOT . 'clsTemplate.php';

class clsAccout {

	private clsAccountBase $objAccntBase;
	private clsDB $db;
	private clsLocale $msg;

	function __construct(clsDB &$db){
		$this->db = $db;
		$this->objAccntBase = new clsAccountBase($db);
		$this->msg = clsLocale(get_class($this), TLIB_LOCALE_FILE_PATH_FOR_TLIB_CLASSES);
	}

	///////////////////////////////////////////////////////////////////////////
	// アカウントの登録
	// Step01から順番に呼び出すことで一般的なアカウント登録が出来るようにする。

	function addAccountStep01(string $subject = '', string $tmpltEmailContent = '', string $email, string $pass, string &$VERIFY_CODE, string &$TEMP_SESSION_KEY) : string{

		// 引数チェック
		if (!isthis::email($email)){ return $this->msg->_('The email address specified is incorrect.'); }
		$ret = $this->objAccntBase->checkAccountPara(0, $email, $pass);
		if ($ret > 0){
			if ($ret == 60){ return $this->msg->_('You specified wrong email and password.'); } // 攻撃者にヒントを与えないため、エラー内容は明示しない
			return $this->objAccntBase->getErrorMsg();
		}

		// AUTH_VERIFYテーブルに仮登録
		$ADDITIONAL_INFO = array('user'=>$email, 'pass'=>$pass);
		$VERIFY_CODE = '';
		$TEMP_SESSION_KEY = '';
		$bAuthExists = false;
		$ret = $this->objAccntBase->startVerify(0, 1, $email, $VERIFY_CODE, $TEMP_SESSION_KEY, $ADDITIONAL_INFO, $bAuthExists);
		if (!$ret){ return $this->objAccntBase->getErrorMsg(); }

		// メール送信
		if ($subject == ''){ $subject = $this->msg->_('Confirm your email address'); }
		if ($tmpltEmailContent == ''){
			$tmpltEmailContent = TLIB_ROOT . 'template/emailVerification/' . $GLOBALS['lang'] . '_forNewAccount.php';
		}

		if (!file_exists($tmpltEmailContent)){ return $this->msg->_('System Error : Could not find email template file.'); }

		$objTemplate = new clsTemplate();
		$objTemplate->clear();
		$VERIFY_CODE_TO_SHOW = substr($VERIFY_CODE, 0, 4) . ' ' . substr($VERIFY_CODE, 4, 4); // 表示用に4桁づつに分ける
		$objTemplate->param['VERIFY_CODE'] = $VERIFY_CODE_TO_SHOW;
		$objTemplate->param['TEMP_SESSION_KEY'] = $TEMP_SESSION_KEY; // 使用しないかもしれないが・・

		$emailContens = $objTemplate->getTemplateResult($tmpltEmailContent);

		$objEmail = new clsEMail();



		return '';

	}



}