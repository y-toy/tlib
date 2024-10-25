<?php
namespace tlib;

/**
 * ユーザー登録処理を行うクラス
 *
 * how to use :
 * $objUserRegistration = new clsUserRegistration($db);
 * $error = $objUserRegistration->startUserRegistration($userName, $password, $USER_ID, $USER_ID_CHARS);
 * $objUserRegistration->issueVerificationCode($USER_ID, $userName, ['USER_DISP_NAME' => XXXXXX, 'Data to succeed' => xxxx], 'XXXX 認証コード', '/path/to/template.php', $verificationCode, $sessionCode);
 * // after this point, the user must enter the verification code.
 * $error = $objUserRegistration->checkVerificationCode($sessionCode, $verificationCode, 1, $additionalData); // $additionalData will be ['USER_DISP_NAME' => XXXXXX, 'Data to succeed' => xxxx]
 *
 */
class clsUserRegistration {

	protected clsDB $db;
	private clsTransfer $msg;

    public function __construct(myDB &$db) {
        $this->db = $db;
		$this->msg = new clsTransfer(pathinfo(__FILE__, PATHINFO_FILENAME), __DIR__ . '/locale/');
    }

	/**
	 * Starts the user registration process. This function make a temporary user data.
	 *
	 * @param string $userName The username of the user. email or phone number.
	 * @param string $password The password of the user.
	 * @param int &$USER_ID The reference to the user ID. return value.
	 * @param string &$USER_ID_CHARS The reference to the user ID characters. return value.
	 * @return string Error message. Empty string if no error.
	 */
	public function startUserRegistration(string $userName, string $password, int &$USER_ID, string &$USER_ID_CHARS) : string{

		// data check
		if (!isThis::email($userName) && !isThis::phone($userName)){ return $this->msg->_('Invalid email address specified.'); }
		if (isThis::password($password,8,32,1)){ return $this->msg->_('Password must be 8 to 32 characters long and contain digits and alphabets.'); }

        // メールアドレス/PHONEが既に登録されていないか確認
        $sql = 'SELECT count(*) FROM USERS WHERE USER_NAME = "'  . $this->db->real_escape_string($userName) . '" AND VALID = 1';
		if ($this->db->getFirstOne($sql) > 0){ return $this->msg->_('Your email address / phone number is already registered.'); }

		// make an data
		$USER_ID_CHARS = '';
		for ($i=0;;$i++){
			if ($i >= TLIB_MAX_INDEX_GENERATION){ return $this->msg->_('Could not generate a unique USER ID after ' . $i . ' attempts.'); }

			$USER_ID_CHARS = util::getRandamCode128(Date('YmdHis'));
			$sql = 'SELECT count(*) FROM USERS WHERE USER_ID_CHARS = "'  . $USER_ID_CHARS . '"';
			$ret = $this->db->getFirstOne($sql);
			if ($ret === null){ return $this->msg->_('System error occured when generating USER ID.'); }
			if ($ret == 0){ break; }

		}

		// hash password
		$password = util::getPasswordHash($password);

		// insert user temporary, it might be deleted if user does not complete registration (does not verify email address)
		$sql = 'INSERT INTO USERS (USER_ID_CHARS, USER_NAME, PASS, INSERT_TIME, UPDATE_TIME) VALUES ("' . $USER_ID_CHARS . '","' . $this->db->real_escape_string($userName) . '", "' . $this->db->real_escape_string($password) . '", NOW(), NOW())';
		$ret = $this->db->query($sql);
		if (!$ret){ return $this->msg->_('System error occured when inserting user data.'); }
		$USER_ID = $this->db->insert_id;

		return '';
	}

	/**
	 * Issues a verification code for user registration.
	 * This function sends a verification code to the user's email address or phone number. (phone number is not implemented yet)
	 *
	 * @param int $USER_ID The temporary ID of the user.
	 * @param string $userName The username of the user. email or phone number.
	 * @param array|null $additionalData Additional data for the user. optional.
	 * @param string $subject The subject of the email to send.
	 * @param string $tmpltPathOfEmailContent The path of the email template.
	 * @param string &$verificationCode The generated verification code.
	 * @param string &$sessionCode The generated session code.
	 * @return string Error message. Empty string if no error.
	 */
	public function issueVerificationCode(int $USER_ID, string $userName, ?array $additionalData, string $subject, string $tmpltPathOfEmailContent, string &$verificationCode, string &$sessionCode) : string{

		// 認証コードを生成
        $verificationCode = (string)rand(10000000, 99999999);

		$CONTACT_TYPE = 1; // email
		if (isThis::phone($userName)){ $CONTACT_TYPE = 2; } // sms

		// セッションコードを生成
		$sessionCode = '';
		for($i=0;;$i++){
			if ($i >= TLIB_MAX_INDEX_GENERATION){ return $this->msg->_('Could not generate a unique code after ' . $i . ' attempts.'); }
			$sessionCode = util::getRandamCode128($USER_ID);

			$sql = 'SELECT count(*) FROM USER_VERIFICATIONS_SESSION WHERE SESSION_CODE = "' . $sessionCode . '"';
			$ret = $this->db->getFirstOne($sql);
			if ($ret === null){ return $this->msg->_('System error occured when generating unique code.'); }
			if ($ret == 0){ break; }
		}

        // USER_VERIFICATIONS_SESSIONテーブルに認証コードを保存
		$additionalData = $additionalData ? ('"' . $this->db->real_escape_string(json_encode($additionalData)) . '"') : 'JSON_OBJECT()';
		$sql = 'INSERT INTO USER_VERIFICATIONS_SESSION (USER_ID, SESSION_CODE, CONTACT, CONTACT_TYPE, VERIFICATION_CODE, ADDITIONAL_DATA, EXPIRATION_TIME)
		VALUES (' . $USER_ID . ', "' .  $this->db->real_escape_string($sessionCode) . '", "' . $this->db->real_escape_string($userName) . '", ' . $CONTACT_TYPE . ', "' . $verificationCode . '",' . $additionalData . ',DATE_ADD(NOW(), INTERVAL 1 HOUR))';
		$ret = $this->db->query($sql);
		if (!$ret){ return $this->msg->_('System error occured when making verification code.'); }

		// メール送信
		if ($CONTACT_TYPE == 1){
			if ($subject == ''){ $subject = $this->msg->_('Verification Code'); }
			if ($tmpltPathOfEmailContent == ''){
				$tmpltPathOfEmailContent = TLIB_ROOT . 'template/emailVerify/addAccount/' . $GLOBALS['LANG'] . '.php';
			}

			if (!file_exists($tmpltPathOfEmailContent)){ return $this->msg->_('System Error : Could not find email template file.'); }

			$objTemplate = new clsTemplate();
			$objTemplate->clear();
			$VERIFY_CODE_TO_SHOW = substr($verificationCode, 0, 4) . ' ' . substr($verificationCode, 4, 4); // 表示用に4桁づつに分ける
			$objTemplate->param['VERIFY_CODE'] = $VERIFY_CODE_TO_SHOW;
			$objTemplate->param['TEMP_SESSION_KEY'] = $sessionCode;

			$emailContens = $objTemplate->getTemplateResult($tmpltPathOfEmailContent);

			// メール送信
			$objEmail = new clsEMail(TLIB_EMAIL_NOTICE[0], TLIB_EMAIL_NOTICE[1], TLIB_EMAIL_NOTICE[2], TLIB_EMAIL_NOTICE[3]);
			$objEmail->setFromAddress(TLIB_EMAIL_NOTICE[5], TLIB_EMAIL_NOTICE[4]);
			$objEmail->setToAddress($userName);
			if (isset(TLIB_EMAIL_NOTICE[6]) && isthis::email(TLIB_EMAIL_NOTICE[6])){
				$objEmail->setBccAddress(TLIB_EMAIL_NOTICE[6]);
			}

			$ret = $objEmail->sendHTML($subject, $emailContens);
			if ($ret != ''){
				$this->msg->_('Failed to send verify email code to your email address. please try later.');
				util::vitalLogOut(util::LEVEL_ERROR, '[' . __CLASS__ . '::' . __METHOD__ . '] email send error : ' . $ret);
			}
		}else{
			// SMSは未定
		}

		return '';
    }

	/**
	 * This function checks the verification code against the session code and if they match, it registers the user.
	 *
	 * @param string $sessionCode The session code to compare against.
	 * @param string $verificationCode The verification code to check.
	 * @param int $flg_main The main flag. If 1, the checked user contact will be used mainly. Default is 1.
	 * @param array &$additionalData Additional data passed by reference.
	 * @return string Error message. Empty string if no error.
	 */
	public function checkVerificationCode(string $sessionCode, string $verificationCode, int $flg_main = 1, array &$additionalData) : string{

		$verificationCode = str_replace(" ", "", mb_convert_kana($verificationCode, "s")); // 空白除去
		if (!is_numeric($verificationCode) || strlen($verificationCode) != 8){ return $this->msg->_('Invalid verification code specified.'); }

		// USER_VERIFICATIONSテーブルから認証コードを取得
		$sql = 'SELECT USER_ID, CONTACT, CONTACT_TYPE, ADDITIONAL_DATA FROM USER_VERIFICATIONS_SESSION WHERE SESSION_CODE = "' . $this->db->real_escape_string($sessionCode)  . '" AND VERIFICATION_CODE = ' . $verificationCode . ' AND EXPIRATION_TIME > NOW()';
        $USER_VERIFICATIONS_INFO = $this->db->getFirstRow($sql);
		if ($USER_VERIFICATIONS_INFO === null){ return $this->msg->_('Invalid or expired verification code specified. The code expire one hour after issued.'); }

		// 正式登録
		$sql = 'INSERT INTO USER_VERIFICATIONS (USER_ID, CONTACT, CONTACT_TYPE, FLG_MAIN, INSERT_TIME) VALUES (' . $USER_VERIFICATIONS_INFO[0] .  ', "' . $USER_VERIFICATIONS_INFO[1] . '", ' . $USER_VERIFICATIONS_INFO[2] . ', ' . $flg_main . ', NOW())';
		$ret = $this->db->query($sql);
		if (!$ret){ return $this->msg->_('System error occured when inserting your contact data.'); }

		$sql = 'UPDATE USERS SET VALID = 1, UPDATE_TIME = now() WHERE USER_ID = ' . $USER_VERIFICATIONS_INFO[0];
		$ret = $this->db->query($sql);
		if (!$ret){ return $this->msg->_('System error occured when updating your user data.'); }

		// USER_VERIFICATIONS_SESSIONテーブルからデータを削除
		$sql = 'DELETE FROM USER_VERIFICATIONS_SESSION WHERE SESSION_CODE = "' . $this->db->real_escape_string($sessionCode) . '" AND VERIFICATION_CODE = ' . $verificationCode;
		$ret = $this->db->query($sql); // エラーでも良い

		$additionalData = json_decode($USER_VERIFICATIONS_INFO[3], true);

		return '';

	}

}