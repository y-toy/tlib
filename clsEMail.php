<?php

include_once("Mail.php");
include_once("Mail/mime.php");

/**
 * メール送信用クラス 継承して使うことを想定しているが単独で使用も可能
 * 　=> pear MAIL と pear Mail_MIME が必要
 *   => htmlメール用
 *   => 画像埋め込み機能あり。
 *   => 大量のメールを送付する際の軽量化メンバあり。（対して軽量化していないので注意）
 *
 * ■ 基本の使い方
 *
 * $obj = new clsEMail('mx.some.jp', 587, 'smtpuser', 'password'); // 後から、$obj->setServerInfo()に設定も可能
 * $obj->setFromAddress('My Name', 'myAddress@some.jp');
 * $obj->setToAddress('friendA@gmail.com', 'Friend A');
 * $obj->setToAddress('friendB@gmail.com', 'Friend B'); // 複数宛先は繰り返しコールする。 CC/BCC/ファイル添付などはクラス内参照。
 * $obj->sendHTML('Hi freiends', 'How do you like your office?<br />Is there anything you want or need?');
 *
 * ■ ファイル添付
 * $obj->setAttachedFile('/document/hogehoge.pdf');
 *
 * ■ 本文に画像埋め込み
 *
 * ・送信するメッセージに<img src="cid:xxxxxxxx" /> (xxxxxxxxは一意)
 * ・メール送信前にcidとファイルを設定
 * $obj->setHTMLEmbededImage('/images/hoge.png', 'xxxxxxxx', $contentType='image/png'); // xxxxxxxxは↑のimgに設定するcid
 *
 * ■ 一度に何通もメール送信する場合
 *
 * $this->sendHTMLManyStart(); // 初期処理
 * for ($i=0;繰り返し;$i++){
 *    $this->setToAddress('hoge','hoge'); // あて先などの設定。
 *    $this->sendHTMLManySend('件名', '<b>内容</b>'); // メール送信
 *    $this->clear(); // あて先などクリア
 * }
 * $this->sendHTMLManyEnd(); // 最後にメモリ開放
 *
 *
 */

class clsEMail {

	protected $smtp_server;
	protected $smtp_port;
	protected $smtp_user;
	protected $smtp_pass;

	protected $mail_info = array('TO'=>array(), 'NAMETO'=>array(), 'Cc'=>array(), 'Bcc' =>array(),'ATTACHED'=>array(), 'addHeaders' => array(), 'embeddedImages' => array());

	protected $mailObject;

	public function __construct(string $smtp_server = '',int $smtp_port = 587,string $smtp_user = '',string $smtp_pass = ''){
		$this->smtp_server = $smtp_server;
		$this->smtp_port   = $smtp_port;
		$this->smtp_user   = $smtp_user;
		$this->smtp_pass   = $smtp_pass;
	}

	// 送付先を消去（送信元は消去せず。送信元は必要に応じてsetFromAddress()で再設定）
	function clear(){
		unset($this->mail_info);
		$this->mail_info = array('TO'=>array(), 'NAMETO'=>array(), 'Cc'=>array(), 'Bcc' =>array(),'ATTACHED'=>array(), 'addHeaders'=>array(), 'embeddedImages' => array());
	}

	// 送信元メールアドレスの設定（コンストラクタで設定しない場合のみ）
	function setFromAddress(string $address, string $dispName) : void{
		$this->mail_info['NAMEFROM'] = $dispName;
		$this->mail_info['FROM'] = $address;
	}
	// 送信先メールアドレスの設定（コンストラクタで設定しない場合のみ）
	function setToAddress(string $address, string $dispName='') : void{
			$this->mail_info['TO'][] = $address;
			$this->mail_info['NAMETO'][] = $dispName;
	}
	function setCcAddress (string $address) : void{ $this->mail_info['Cc'][] = $address; }// 送信先CCメールアドレスの設定（コンストラクタで設定しない場合のみ）
	function setBccAddress(string $address) : void{ $this->mail_info['Bcc'][] = $address; }// 送信先BCCメールアドレスの設定（コンストラクタで設定しない場合のみ）
	function setAttachedFile(string $attachedFile) : void{ $this->mail_info['ATTACHED'][] = $attachedFile; } // アタッチするファイルのパス

	// HTML埋め込み画像
	function setHTMLEmbededImage(string $filepath, string $cid, string $contentType='image/png'):void{ $this->mail_info['embeddedImages'][] = array($filepath, $cid, $contentType); }

	// $flgMime エンコードするか true/false
	function setAddHeaders(bool $flgMime, string $headerTitle, string $headerData):void{ $this->mail_info['addHeaders'][] = array($flgMime, $headerTitle, $headerData); }

	// サーバ再設定
	function setServerInfo(string $smtp_server, int $smtp_port, string $smtp_user, string $smtp_pass) : void{
		$this->smtp_server = $smtp_server;
		$this->smtp_port = $smtp_port;
		$this->smtp_user = $smtp_user;
		$this->smtp_pass = $smtp_pass;
	}

	/**
	 *  HTMLメール送信
	 *
	 * @param string $subject 件名
	 * @param string $message 送信するHTML本文
	 * @param boolean $addHF $messageにhtml文の頭<html>...<body>とお尻</body></html>を付けるかどうか。つけておけば、$messageの作成が少し楽になる。
	 * @return string 正常終了：'' / 異常終了：エラーメッセージ
	 */
	function sendHTML(string $subject, string $message, bool $addHF=true) : string{ return $this->sendHTMLCore($subject, $message, $recipients, $headers, $body, $addHF); }

	/**
	 * HTMLメール送信の実装
	 * 「sentフォルダーに送信済みを保存する」などこのメンバの外で送信したデータを使うケースを想定し、引数に送信データを返す
	 *
	 * @param string $subject 件名
	 * @param string $message 送信するHTML本文
	 * @param array $recipients 送信した宛先
	 * @param array $headers 送信したヘッダー
	 * @param string $body 送信した本文(エンコード済み)
	 * @param boolean $addHF $messageにhtml文の頭<html>...<body>とお尻</body></html>を付けるかどうか。つけておけば、$messageの作成が少し楽になる。
	 * @return string 正常終了：'' / 異常終了：エラーメッセージ
	 */
	function sendHTMLCore(string $subject, string $message,array &$recipients,array &$headers,string &$body,bool $addHF=true) : string{

		$params = array(
			'host' => $this->smtp_server,
			'port' => $this->smtp_port,
			'auth' => true,
			'username' => $this->smtp_user,
			'password' => $this->smtp_pass,
			'debug' => false,
			'socket_options' => array(
							'ssl' => array(
								'verify_peer' => false,
								'verify_peer_name' => false,
								'allow_self_signed' => true
							)
			)
		);
		$mailObject = Mail::factory("smtp", $params);

		mb_language("uni");
		mb_internal_encoding("UTF-8");

		$recipients = array(); $headers = array(); $body = '';
		$this->makeMimeParams($subject, $message, $addHF, $recipients, $headers, $body);

		$send = $mailObject->send($recipients, $headers, $body);
		if (PEAR::isError($send)){ return 'Mail send error! ' . $send->getMessage(); }

		return '';
	}

	/**
	 * imapServerの指定フォルダにメッセージを保管する。sentフォルダーに送信済みなど。
	 *   => imapServerのuser / passは、$this->smtp_user / $this->smtp_pass
	 *
	 * @param string $imapServer imapサーバー名
	 * @param string $folderName 保存するフォルダー
	 * @param boolean $bTLS TLSを使うかどうか。ポートが993(TLS)か143かの指定。
	 * @param boolean $bQmail dovecotではない場合はtrue
	 * @param array $headers $this->sendHTMLCoreで取得したメッセージのヘッダ
	 * @param string $body $this->sendHTMLCoreで取得した本文内容
	 * @param string $flag imap_appendに指定する保存フラグ
	 * @return string 正常時は'' 異常時は異常メッセージ
	 */
	function saveMessageToFolder(string $imapServer, string $folderName, bool $bTLS, bool $bQmail, array $headers, string $body, string $flag = ''):string{

		$message = '';
		foreach($headers as $key => $value){
			$message .= $key . ': ' . $value . "\r\n";
		}
		$message = $message . "\r\n" . $body;
		return $this->saveMessageToFolderCore( $imapServer,  $folderName,  $bTLS,  $bQmail,  $message,  $flag);

	}

	/**
	 * imapServerの指定フォルダにメッセージを保管する。sentフォルダーに送信済みなど。
	 *  => imapServerのuser / passは、$this->smtp_user / $this->smtp_pass
	 *
	 * @param string $imapServer imapサーバー名
	 * @param string $folderName 保存するフォルダー
	 * @param boolean $bTLS TLSを使うかどうか。ポートが993(TLS)か143かの指定。
	 * @param boolean $bQmail dovecotではない場合はtrue
	 * @param string $message 保管するメッセージ
	 * @param string $flag imap_appendに指定する保存フラグ
	 * @return string 正常時は'' 異常時は異常メッセージ
	 */
	function saveMessageToFolderCore(string $imapServer, string $folderName, bool $bTLS, bool $bQmail, string $message, string $flag = '') : string{

		if (!$bQmail && $folderName == ''){ $folderName = 'INBOX'; }
		//$encodeFolder = imap_utf7_decode(mb_convert_encoding($folderName, 'UTF7-IMAP', 'UTF-8'));
		$encodeFolder = mb_convert_encoding($folderName, 'UTF7-IMAP', 'UTF-8');

		$port = 143;
		if ($bTLS){ $port = 993; }
		$path = "{" . $imapServer . ":" . $port . "/imap/ssl}" . (($bQmail)?'INBOX.':'') . $encodeFolder;

		$imapStream = imap_open($path, $this->smtp_user, $this->smtp_pass);
		if ($imapStream === FALSE){ return FALSE; }
		$result = imap_append($imapStream, $path, $message, $flag);
		$strError = '';
		if ($result === false){
			$strError = imap_last_error();
		}
		imap_close($imapStream);
		return $strError;
	}


	/**
	 * Mail::send関数に渡すパラメーターを作成する。
	 *
	 * @param string $subject 件名
	 * @param string $message 送信メッセージ
	 * @param boolean $addHF $messageにhtml文の頭<html>...<body>とお尻</body></html>を付けるかどうか。true : 付ける　false つけない。
	 * @param array $recipients 内部で作成される宛先
	 * @param array $headers 内部で作成されるヘッダー
	 * @param string $body 内部で作成される本文
	 * @return void
	 *
	 */
	public function makeMimeParams(string $subject, string $message, bool $addHF, array &$recipients, array &$headers, string &$body) : void{

		if ($addHF){ $message = $this->getHTMLHead() . $message . $this->getHTMLFoot(); }

		$toArray  = $this->mail_info['TO'];
		$toNameArray  = $this->mail_info['NAMETO'];
		$ccArray  = $this->mail_info['Cc'];
		$bccArray = $this->mail_info['Bcc'];

		$toCount  = count($this->mail_info['TO']);
		//$toNameCount  = count($this->mail_info['NAMETO']);
		$ccCount  = count($this->mail_info['Cc']);
		$bccCount = count($this->mail_info['Bcc']);

		/*** メールあて先 ***/
		$headers['To'] = '';
		for($i=0;$i<$toCount;$i++){
			if (array_key_exists($i,$toNameArray) && $toNameArray[$i] != ''){
				$headers['To'] .= mb_encode_mimeheader($toNameArray[$i]) . ' <' . $toArray[$i] . '>';
			}else{
				$headers['To'] .= $toArray[$i];
			}
			if ($i != ($toCount-1)){ $headers['To'] .= ','; }
		}
		$headers['From']    = mb_encode_mimeheader($this->mail_info['NAMEFROM']) . ' <' . $this->mail_info['FROM'] . '>';
		$headers['Subject'] = mb_encode_mimeheader($subject);
		$headers['Content-Type'] = 'text/html; charset=UTF-8';
		$headers['Date'] = Date('r');

		$headers['Cc'] = '';
		for($i=0;$i<$ccCount;$i++){
			$headers['Cc'] .= $ccArray[$i];
			if ($i != ($ccCount-1)){ $headers['Cc'] .= ','; }
		}
		//
		// headerにBccは不要 $recipientsに入っていれば良い。
		//
		// $headers['Bcc'] = '';
		// for($i=0;$i<$bccCount;$i++){
		// 	$headers['Bcc'] .= $bccArray[$i];
		// 	if ($i != ($bccCount-1)){ $headers['Bcc'] .= ','; }
		// }
		//

		$cntAddHeaders = count($this->mail_info['addHeaders']);
		for ($i=0;$i < $cntAddHeaders;$i++){
			$oneHeaderData = $this->mail_info['addHeaders'][$i];
			$headers[$oneHeaderData[1]] = ($oneHeaderData[0])?mb_encode_mimeheader($oneHeaderData[2]):$oneHeaderData[2];
		}

		$mime_params = array(
			'text_encoding' => '7bit',
			'text_charset' => 'UTF-8',
			'html_charset' => 'UTF-8',
			'head_charset' => 'UTF-8'
		);

		$mime = new Mail_mime();
		$mime->setHTMLBody($message);
		$embededImageCount = count($this->mail_info['embeddedImages']);
		for ($i=0;$i < $embededImageCount;$i++){
			$mime->addHTMLImage($this->mail_info['embeddedImages'][$i][0], $this->mail_info['embeddedImages'][$i][2], '', true, $this->mail_info['embeddedImages'][$i][1]);
		}

		$attachedCount = count($this->mail_info['ATTACHED']);
		for($i=0;$i<$attachedCount;$i++){
			// $mime->addAttachment($this->mail_info['ATTACHED'][$i]);
			// 文字化け対応
			$mime->addAttachment($this->mail_info['ATTACHED'][$i] // file
				,'application/octet-stream'  // content-type
				,'' // attached file name
				,true // isfile
				,'base64' // encoding
				,'attachment' // disposition
				,'' // charset
				,'' // language
				,'' // location
				,'base64' // n_encoding
				,'base64' // f_encoding
				,'' // description
				,'UTF-8' // h_charset
			);
		}
		$body = $mime->get($mime_params);
		$headers = $mime->headers($headers);

		$recipients = array();
		for ($i=0;$i<$toCount ;$i++){ $recipients[] = $toArray[$i]; }
		for ($i=0;$i<$ccCount ;$i++){ $recipients[] = $ccArray[$i]; }
		for ($i=0;$i<$bccCount;$i++){ $recipients[] = $bccArray[$i]; }

	}

	public function getHTMLHead(){ return '<html><head><meta http-equiv="Content-Type" Content="text/html;charset=UTF-8"></head><body>'; }
	public function getHTMLFoot(){ return '</body></html>'; }


	///////////////////////////////////////////////////////////////////////////
	// 以下連続送信用のメンバ関数

	// HTMLメール送信、複数連続送信用 開始
	public function sendHTMLManyStart(){
		$params = array(
			'host' => $this->smtp_server,
			'port' => $this->smtp_port,
			'auth' => true,
			'username' => $this->smtp_user,
			'password' => $this->smtp_pass,
			'persist'  => true,
		);
		$this->mailObject = Mail::factory("smtp", $params);

		mb_language("uni");
		mb_internal_encoding("UTF-8");
	}

	/**
	 * HTMLメール送信、複数連続送信用 送信
	 *
	 * @param string $subject 件名
	 * @param string $message 送信メッセージ
	 * @param boolean $addHF $messageにhtml文の頭<html>...<body>とお尻</body></html>を付けるかどうか。true : 付ける　false つけない。
	 * @return string 正常時は'' 異常時は異常メッセージ
	 */
	 public function sendHTMLManySend(string $subject, string $message, bool $addHF=true) : string{

		$recipients = array(); $headers = array(); $body = '';
		$this->makeMimeParams($subject, $message, $addHF, $recipients, $headers, $body);

		$send = $this->mailObject->send($recipients, $headers, $body);
		if (PEAR::isError($send)){ return 'Mail send error! ' . $send->getMessage(); }

		return '';

	}

	// HTMLメール送信、複数連続送信用 終了
	public function sendHTMLManyEnd(){ unset($this->mailObject); }

}

