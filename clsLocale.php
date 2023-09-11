<?php

/**
 * ■ 概要
 * 多言語メッセージを扱うためのクラス
 * 各クラスのコンストラクタで宣言し、本クラスを使って翻訳されたメッセージを取得する。
 *
 * 例：
 * class clsTest{
 *   // 宣言
 *   function __construct(){
 * 	   $this->msg = new clsLocale(__FILE__, get_class($this)); // 翻訳ファイルがある場合は読み込み。無い場合はひな形ファイルを設置、バッチファイルに当該ファイルを追加。
 *   }
 *   // メンバ内での利用
 *   function someFunc(){
 *     echo $this->msg->__(100, "デフォルトメッセージ"); // 100に対応するメッセージが翻訳ファイルから読み込まれ、返される。
 *     printf($this->msg->__(200, "hello %s! You already use %d%% data!"), 'Joe', 39); // 変数は読み込めないので注意 printf/sprintf を利用
 *   }
 * }
 *
 * ■ 使用される言語
 * 使用する言語の指定が特に無い場合、WEBのHTTP_ACCEPT_LANGUAGEから取得する。
 * HTTP_ACCEPT_LANGUAGE 取得に失敗した場合の、$GLOBALS['TLIB_DEAFAULT_LANG']を使用する。
 *
 * ■ 翻訳ファイル
 * ファイルパス：$GLOBALS['tlib_root'] . /local/{domainName}/{en|ja}.php
 * フォーマット：
 * ---
 * <?php
 *
 * $msgCode = array(
 * 	100=>'ほげほげ',
 * 	101=>'なんとか',
 * 	102=>'コード'
 * );
 * // 以下は更新判断に使用するため更新しないこと。消してしまうととひな形に戻るので注意。
 * $msgHash = array(
 * 	100=>'1f3870be274f6c49b3e31a0c6728957f',
 * 	101=>'1f3870be274f6c49b3e31a0c6728957f',
 * 	102=>'1f3870be274f6c49b3e31a0c6728957f'
 * );
 * ---
 *
 * ■ 翻訳ファイル作成方法
 * 初回はクラスのコンストラクタに上記方法を指定すると所定の位置にひな形ファイルが作成される。
 * そのひな形ファイルを修正することで翻訳ファイルとなる。
 *
 * 2回目以降は以下のバッチを叩くこと。
 * $GLOBALS['tlib_root'] . 'local/' . updateLangFiles.php
 *
 * ■ その他
 * ・PHP標準のi18nでやろうと思ったが、重い & setlocalはプロセスごとの設定であり、使いずらい。
 * ・作成した翻訳ファイルが更新されない場合、翻訳ファイルから当該コードを削除し、再実行するか直接編集。
 *
 *
 * 言語ファイルのひな形は、元のクラスから自動生成できる。
 * ファイル内に以下のような文言があると自動で取得し設定
 * ->__(101,'ほげほげ'); => array(101=>"ほげほげ")
 * // お尻は必ず空白なしの");"で終わること。")  ;"で終わると認識できないので注意
 *
 * 'ほげほげ'部分を各言語ファイルで修正
 */
class clsLocale {

	public $defaultLang = 'en'; // CLDR(ja_JPみたいな・・)の前半の言語部分のみ使用する。$GLOBALS['TLIB_DEAFAULT_LANG']の設定が無い場合ここで設定している値を利用
	public $acceptLangs = array('en','ja');  // CLDR(ja_JPみたいな・・)の前半の言語部分のみ使用する。$GLOBALS['TLIB_ACCEPT_LANG']の設定が無い場合ここで設定している値を利用

	protected $domainName = '';
	protected $lang = '';
	protected $aryMsgCode = array();

	/**
	 * コンストラクタ
	 *
	 * @param string $fileName このクラスの呼び出し元のファイル名 呼び出し元では__FILE__を指定 ''が指定された場合はファイルの読み出しを行わない。
	 * @param string $domainName 本ドメイン名
	 * @param string $lang 使用言語 ''の場合、$GLOBALS['TLIB_LOCALE_LANG']、ないしは、デフォルトの言語を利用
	 *                     ファイルの場所は$domainNameと$localから自動で判定
	 */
	public function __construct(string $fileName, string $domainName, string $lang = ''){

		// デフォルト言語の設定がconfigにある場合はそちらを利用
		if (isset($GLOBALS['TLIB_DEAFAULT_LANG'])){ $this->defaultLang = $GLOBALS['TLIB_DEAFAULT_LANG']; }

		// 対応言語一覧の設定がconfigにある場合はそちらを利用
		if (isset($GLOBALS['TLIB_ACCEPT_LANG']) && is_array($GLOBALS['TLIB_ACCEPT_LANG'])){
			$this->acceptLangs = $GLOBALS['TLIB_ACCEPT_LANG'];
		}

		$this->domainName = $domainName;
		$this->lang = $this->getLocalLang($lang);

		// 言語の読み込みができない => 言語ファイルがない => 言語ファイル作成してみる。
		if ($fileName != '' && !$this->getLangFileContents()){
			$error = $this->updateLangFile($fileName, $domainName);
			if ($error == ''){
				$this->getLangFileContents(); // 読み込み
			}else{
				vitalLogOut(3, $domainName . ' : Failed to update lang files : ' . $error);
			}
		}
	}

	/**
	 * 指定されたドメイン名と言語から、言語ファイルを読み込み、$aryMsgCodeに設定する
	 * ドメイン名、言語が指定されない場合は内部メンバから取得
	 *
	 * @param string $domainName ドメイン名（区別名 クラスの場合、通常クラス名）
	 * @param string $lang 言語
	 * @return boolean false when error occured
	 */
	public function getLangFileContents(string $domainName = '', string $lang = '') : bool{

		if ($domainName == ''){ $domainName = $this->domainName; }
		if ($lang == ''){ $lang = $this->lang; }

		$file = $this->getMsgFilePath();
		if (!file_exists($file)){ return false; }


		//unset($msgCode);
		// eval(file_get_contents($file));
		include($file);
		if (isset($msgCode)){ $this->aryMsgCode = $msgCode; }

		return true;
	}

	/**
	 * 指定ドメイン、言語の言語ファイルパスを取得する。
	 * ドメイン名、言語が指定されない場合は内部メンバから取得
	 *
	 * @param string $domainName
	 * @param string $lang
	 * @return string 言語ファイル名
	 */
	public function getMsgFilePath(string $domainName = '', string $lang = '') : string{
		if ($domainName == ''){ $domainName = $this->domainName; }
		if ($lang == ''){ $lang = $this->lang; }

		if (!isset($GLOBALS['tlib_root'])){ $GLOBALS['tlib_root'] = __dir__ . '/'; }
		return $GLOBALS['tlib_root'] . 'local/' . $domainName . '/' . $lang . '.php';
	}

	/**
	 * メッセージを取得する
	 *
	 * @param int $code int or string
	 * @return string $codeに対応した使用言語のメッセージを取得
	 */
	function __(int $code, string $defaultMsg = '') : string{
		if (isset($this->aryMsgCode[$code])){ return $this->aryMsgCode[$code]; }
		return $defaultMsg;
	}

	/**
	 * ブラウザの"HTTP_ACCEPT_LANGUAGE"から言語コードを取得する。WEB系ではない場合、デフォルトを返す
	 * 取得できない場合、対応言語外の場合は、'en'を返す。
	 *
	 * @param string $lang 使用したい言語
	 * @return 言語コード ('ja' or 'en' )
	 */
	public function getLocalLang(string $lang = '') : string {

		// 使用したい言語がある場合、言語一覧にあれば可。無い場合はdefaultを返す。
		if ($lang != ''){
			if (in_array($lang, $this->acceptLangs)){
				return $lang;
			}else{
				return $this->defaultLang;
			}
		}

		// 使用したい言語が無い場合自動取得 今のところWEBのみ
		if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])){

			$httpCode = locale_accept_from_http($_SERVER['HTTP_ACCEPT_LANGUAGE']);
			if ($httpCode === false){ return $this->defaultLang; }
			$aryLang = explode($httpCode, '_');
			if (count($aryLang) > 0 && in_array($aryLang[0], $this->acceptLangs)){
				return $aryLang[0];
			}
		}

		return $this->defaultLang;
	}

	///////////////////////////////////////////////////////////////////////////
	// 言語ファイル作成などの管理系クラス

	public function updateLangFile(string $srcFilePath, string $domainName = '') : string{

		if ($domainName == ''){ $domainName = $this->domainName; }

		// とりあえず指定されたファイルを読み込み、$srcMsgにコードと文字列を詰め込む
		if (!file_exists($srcFilePath)){ return 'Could not find the file : ' . $srcFilePath; }
		$strFileContents = file_get_contents($srcFilePath);
		if ($strFileContents === false){ return 'Failed to read the file : ' . $srcFilePath; }

		// メッセージ詰め込み用 array(array(100, msg), array(101, msg), array(102, msg))
		$srcMsg = array();

		// ソースファイルからデフォルトのセットを取得
		$pattern = '/->\s*__\s*\(\s*(\d+)\s*,([^(\);)]+)/s';
		$len = preg_match_all($pattern, $strFileContents, $match);
		if ($len === false){
			return 'Failed to disassemble the file : ' . $srcFilePath;
		}

		// 設定がないなら何もしない　ファイルがあれば削除しようかと思ったが残しておく。
		if ($len == 0){ return ''; }

		// 重複チェック
		$cntVals = array_count_values($match[1]);
		if (max($cntVals) > 1){
			$error = 'Below codes are duplicated. Revise the file : ' . $srcFilePath . PHP_EOL;
			foreach($cntVals as $key => $val){
				if ($val > 1){
					for ($i=0;$i < $len;$i++){
						if ($match[1][$i] == $key){
							$error .= ' => ' . $match[1][$i] . ' : ' . trim($match[2][$i]) . PHP_EOL;
						}
					}
				}
			}
			return $error;
		}

		// とりあえずデフォルト値を詰め込み
		for ($i=0;$i < $len;$i++){
			$srcMsg[] = array((int)$match[1][$i], trim($match[2][$i]));
		}

		// 各言語翻訳ファイルの出力
		// 既にある場合は追加分や変更分を更新。（デフォルトを変更した部分はデフォルトになるので注意）

		$srcLen = count($srcMsg);
		$cntLang = count($this->acceptLangs);
		for($i=0;$i < $cntLang;$i++){
			$filePath = $this->getMsgFilePath($domainName, $this->acceptLangs[$i]);

			// if内の分岐がほぼ同じため、書き換えのこと
			$bExist  = file_exists($filePath);
			if ($bExist){
				include $filePath;
				if (!isset($msgCode) || !is_array($msgCode)){ return 'This traslated file is something wrong : ' . $filePath; }
				if (!isset($msgHash) || !is_array($msgHash)){ return 'This traslated file is something wrong : ' . $filePath; }
			}

			$outputContentsMsg = '$msgCode = array(' . PHP_EOL;
			$outputContentsMD5 = '$msgHash = array(' . PHP_EOL;

			for($k=0;$k < $srcLen;$k++){

				$msgData = '';
				if ($bExist){
					// 翻訳ファイルがある場合は、現在の内容を調べ、値が更新されているものは反映、存在しないものは追加、不要になったものは削除
					if (
						isset($msgCode[$theCode]) &&
						isset($msgHash[$theCode]) &&
						$msgHash[$theCode] == $theHash
					){
						$msgData = $msgCode[$theCode];
					}else{
						$msgData = $srcMsg[$k][1];
					}

				}else{
					// 翻訳ファイルが無い場合は、デフォルト値
					$msgData = $srcMsg[$k][1];
				}


				$outputContentsMsg .= $srcMsg[$k][0] . '=>\'' . tilb\util::mb_str_replace('\'', '\\\'', $msgData) . '\',' . PHP_EOL;
				$outputContentsMD5 .= $srcMsg[$k][0] . '=>\'' . md5($srcMsg[$k][1]) . '\',' . PHP_EOL;
			}

			$outputContentsMsg .= ');' . PHP_EOL;
			$outputContentsMD5 .= ');' . PHP_EOL;

			// ファイル出力
			$outputContents = '<?php' . PHP_EOL . PHP_EOL
			.  $outputContentsMsg . PHP_EOL
			. '// The array below is required for processing. It should never be modified or deleted.' . PHP_EOL
			. $outputContentsMD5;

			$ret = file_put_contents($filePath, $outputContents);
			if ($ret === false){
				return 'Failed output the contens : ' . $domainName . ' : ' . $filePath;
			}
		}

		// バッチファイルを確認し、データが無いようであれば、最後に追加
		$batchFile = $GLOBALS['tlib_root'] . 'local/updateLangFiles.php';
		if (file_exists($batchFile)){
			$batchFileContents= file_get_contents($batchFile);
			if ($batchFileContents === false){ return 'Failed to read the batch file : ' . $batchFile; }

			$pattern = '/->\s*updateLangFile\s*\(([^,]+),([^(\);)]+)/s';
			$len = preg_match_all($pattern, $batchFileContents, $match);
			if ($len === false){
				return 'Failed to disassemble the file : ' . $batchFile;
			}

			$bExist = false;
			for ($i=0;$i < $len;$i++){

				$tempFilePath = trim(trim($match[1][$i]),"'\"");
				$tempDomainName = trim(trim($match[2][$i]),"'\"");

				if ($tempFilePath == $srcFilePath &&
					$tempDomainName == $domainName ){
						$bExist = true;
				}
			}
			if (!$bExist){
				$batchFileContents .= PHP_EOL . '$error = $obj->updateLangFile(\'' .  $srcFilePath. '\', \'' .  $domainName. '\');' . PHP_EOL
									. 'if ($error != \'\'){ echo \'' .  $domainName. ' : $error\' . PHP_EOL; }' . PHP_EOL;

				$ret = file_put_contents($filePath, $batchFileContents);
				if ($ret === false){
					return 'Failed output the batch file : ' . $domainName;
				}
			}
		}


		return '';
	}

	// public static function encodeAll($value){
	// 	return tilb\util::mb_str_replace(
	// 		array("\x00", '\\', "\t", "\r", "\n", "'", '"'),
	// 		array('', '\\\\', '\t', '\r', '\n', "\'", '\"'),
	// 		$value
	// 	);
	// }
	// public static function decodeAll($value){
	// 	return tilb\util::mb_str_replace(
	// 		array('\\\\', '\t', '\r', '\n', "\'", '\"'),
	// 		array('\\', "\t", "\r", "\n", "'", '"'),
	// 		$value
	// 	);
	// }
	// // ファイル内の文字列はシングルクォートで囲むのでシングルクォートのみエスケープ
	// public static function outputEncode($value){
	// 	return tilb\util::mb_str_replace(
	// 		array("\x00", '\\', "'"),
	// 		array('', '\\\\', "\'"),
	// 		$value
	// 	);
	// }

}