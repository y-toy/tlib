<?php

namespace tlib;

/**
 * ■ システムの前提
 * 1) gettextをインストール eg. apt install gettext
 * 2) 使う言語パックをインストール eg. apt install language-pack-ja language-pack-en
 * 3) locale -a で使う言語が全て見つかるか確認する。
 *
 * ■ 概要
 * 多言語メッセージを扱うためのクラス　内部的にはgettextを使用する。
 * 各クラスのコンストラクタで宣言し、本クラスを使って翻訳されたメッセージを取得・表示する。
 *
 * 当初自作していたが、色々不安なので、おとなしくgettextを利用。domainはクラス名になり、名前空間がある場合は"名前空間_クラス名"となる。
 *
 * このクラスのmakePoFiles('トップフォルダ')を実行すると、各ロケールフォルダにpoファイルが作成される。（既にpoファイルがある場合はアップデート）
 * このpoファイルを編集したのち、makeMoFiles():を実行し、moファイル（機械語）を作成する。
 *
 * ■ 定数設定
 *  ロケールファイルの場所：TLIB_LOCALE_FILE_PATH (未設定の場合は、クラス内定数のDEFAULT_LOCALE_FILE_PATH)
 *  利用可能な言語：TLIB_ACCEPT_LANGS (未設定の場合は、クラス内定数のDEFAULT_ACCEPT_LANGS)
 *  デフォルトで使用する言語：TLIB_DEFAULT_LANG (未設定の場合は、クラス内定数のDEFAULT_LANG)
 *
 * ■ 初期処理
 * 最初に使用言語を指定するため、clsLocale::setLocale()を呼び出す。
 *
 * clsLocale::setLocale(); // <- 引数が無い場合、ブラウザの言語を設定。ブラウザの言語が「利用可能な言語(TLIB_ACCEPT_LANGS)」似ない場合はデフォルトの言語(TLIB_DEFAULT_LANG)を使用。
 * clsLocale::setLocale('ja_JP.utf8'); // local -a で出てくる言語を正確に指定する。
 * clsLocale::setLocale('ja'); // TLIB_ACCEPT_LANGSで指定している場合はこの方法も可。
 *
 * ■ クラスで使用する場合
 * class clsTest{
 *   // 宣言
 *   function __construct(){
 * 	   $this->obj = new \tlib\clsLocale(get_class($this));
 *   }
 *   // メンバ内での利用
 *   function someFunc(){
 *     echo $this->obj->_('text to translate').; // 対応するメッセージが翻訳ファイルから読み込まれ、返される。取れない場合は引数に指定された文字列が買える。
 *     printf($this->obj->_('The age of %s is %d'), 'Joe', 39); // 変数は読み込めないので注意 printf/sprintf を利用
 *   }
 * }
 *
 * ■ ファイルで使用する場合
 * $objLocale = new \tlib\clsLocale(__FILE__);
 *
 * echo $objLocale->_('ブラウザから呼ばれる文言１') . PHP_EOL;
 * echo $objLocale->_('ブラウザから呼ばれる文言２') . PHP_EOL;
 *
 * ■ フォルダ構成を以下としたとき、poファイルの作成はフォルダ構成の下に示したようになる。
 * project
 * ├── greeting.php
 * ├── locale
 * │   ├── en_US.utf8
 * │   │   └── LC_MESSAGES
 * │   │       ├── messages.mo
 * │   │       └── messages.po
 * │   └── ja_JP.utf8
 * │       └── LC_MESSAGES
 * │           ├── messages.mo
 * │           └── messages.po
 * └── messages.po
 *
 * clsLocale::makePoFiles("/xxxx/xxxx/project/","/xxxx/xxxx/project/locale/");
 *
 *
 */
// 必要なdefine
// define('TLIB_LOCALE_FILE_PATH', TLIB_ROOT . 'locale/'); // locale folder
// define('TLIB_LOCALE_FILE_PATH_FOR_TLIB_CLASSES', TLIB_ROOT . 'locale/'); // locale folder for tlib classes
// define('TLIB_DEFAULT_LANG', 'en_US.utf8'); // DEFAULT LANGUAGE when it could not be determined from the environment
// define('TLIB_ACCEPT_LANGS', array(
// 	'en' => 'en_US.utf8', // langcode => system locale code. you can see the system locale code list using command "locale -a" in UBUNTU.
// 	'ja' => 'ja_JP.utf8'
// ));
// define('TLIB_DEFAULT_PO_TARGET_EXTENSIONS', array('php')); // extensions of target files which needed to make po files (needed to translate).

// $setLocale = ''; // for browser
// if (php_sapi_name() == 'cli'){ $setLocale = TLIB_DEFAULT_LANG; } // for script using command line
// tlib\clsLocale::setLocale($setLocale);

// 2 letters of choosed language
//$LANG = substr($setLocale, 0, 2);

class clsLocale {

	public const DEFAULT_LANG = 'en_US.utf8';
	public const DEFAULT_LOCALE_FILE_PATH = '../locale/';
	public const DEFAULT_ACCEPT_LANGS =
		array(
			'en' => 'en_US.utf8',
			'ja' => 'ja_JP.utf8'
		);
	public const DEFAULT_PO_TARGET_EXTENSIONS = array('php'); // 翻訳対象となるファイルの拡張子

	protected $domainName = '';
	protected $localeFolder = '';
	protected $localeLang = ''; // en jaなど2文字

	/**
	 * コンストラクタ
	 *
	 * @param string $className get_class($this)
	 * @param string $localFolder ロケールフォルダー 指定しない場合、configやこのクラスのconst値が使われる。クラスやその他によってフォルダを分けたい場合は利用。
	 */
	public function __construct(string $className, string $localFolder=''){
		// namesapce対応
		$this->domainName = str_replace('\\','_',$className);
		if ($localFolder == ''){
			$this->localeFolder = self::getLocaleFolder();
		}else{
			$this->localeFolder = $localFolder;
		}

		bindtextdomain($this->domainName, $this->localeFolder);
	}

	/**
	 * このクラスのpoファイルからデータを読み込み、gettextを返す。
	 *
	 * @param string $mstgId
	 * @return string
	 */
	public function _($mstgId){
		if (textdomain(null) != $this->domainName){
			textdomain($this->domainName);
		}
		return _($mstgId);
	}

	///////////////////////////////////////////////////////////////////////////
	// 初期化用static関数

	/**
	 * ロケールを設定する。
	 * 引数の$setLocalが''の場合、ブラウザの設定かシステムの設定から言語を特定して設定する。
	 *
	 * setLocale($setLocale)
	 * $lang = substr($setLocal, 0, 2);でja / enが取れる
	 *
	 * @param string $setLocal 設定するロケール / 終了時は設定したロケール
	 * @return boolean false / true
	 */
	static function setLocale(?string &$setLocal):bool{

		if ($setLocal == null){ $setLocal = ''; }

		$acceptLangs = ((defined('TLIB_ACCEPT_LANGS')? TLIB_ACCEPT_LANGS : self::DEFAULT_ACCEPT_LANGS));
		$defaultLang = ((defined('TLIB_DEFAULT_LANG')? TLIB_DEFAULT_LANG : self::DEFAULT_LANG));

		if ($setLocal != ''){
			// ロケールが指定された場合
			$trueSetLocaleName = '';
			if (isset($acceptLangs[$setLocal])){
				$trueSetLocaleName = $acceptLangs[$setLocal];
			}else{
				if (in_array($setLocal, $acceptLangs)){
					$trueSetLocaleName = $setLocal;
				}else{
					$trueSetLocaleName = $defaultLang;
				}
			}
			//putenv('LANG=' . $trueSetLocaleName);
			$setLocal = $trueSetLocaleName;
			return setlocale(LC_ALL, $trueSetLocaleName);
		}

		// ロケールが指定されなかった場合
		$setLocal = $defaultLang;

		// ブラウザかシステムから言語を判別
		$lang = '';
		if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])){
			$lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
		}else{
			$lang = exec('echo $LANG');
			if ($lang === false){
				$lang = '';
			}else{
				$lang = substr($lang, 0, 2);
			}
		}
		if (isset($acceptLangs[$lang])){ $setLocal = $acceptLangs[$lang]; }

		//return putenv('LANG=' . $setLocal);
		return setlocale(LC_ALL, $setLocal);
	}

	///////////////////////////////////////////////////////////////////////////
	// poファイル一括作成

	/**
	 * 指定されたフォルダ$folderの下のphpファイル（クラスのみ）を読み込み、poファイルを作成する。
	 * poファイルは$localeFolderの下に言語ごとに作成され、すでにある場合はマージして作成される。
	 * poファイル名は、基本名前空間+クラス名、クラスではない場合は、ファイル名になる。
	 *
	 * 作業的にはこの後poファイルを手動で変更して、moファイルを作成する。
	 *
	 * @param string $folder
	 * @param string $localeFolder
	 * @return boolean
	 */
	static function makePoFiles(string $folder, string $localeFolder = ''):bool{
		if ($localeFolder == ''){ $localeFolder = self::getLocaleFolder(); }

		$targetExtensions =  ((defined('TLIB_DEFAULT_PO_TARGET_EXTENSIONS')? TLIB_DEFAULT_PO_TARGET_EXTENSIONS : self::DEFAULT_PO_TARGET_EXTENSIONS));

		if (!file_exists($localeFolder)){ echo 'Could not find the locale folder [' . $localeFolder . ']'; return false; }

		if (substr($localeFolder, -1) != '/'){ $localeFolder .= '/'; }

		return self::makePoFilesCore($folder, $localeFolder, $targetExtensions);
	}

	static function getLocaleFolder(){ return (defined('TLIB_LOCALE_FILE_PATH'))?TLIB_LOCALE_FILE_PATH:self::DEFAULT_LOCALE_FILE_PATH; }

	/**
	 * poファイルの作成
	 *
	 * @param string $folder 翻訳ファイルを作成するphpがあるフォルダー
	 * @param string $localeFolder　作成するpoファイルを置くlocaleフォルダー
	 * @param array $targetExtensions POファイル作成対象となる拡張子の配列 この配列に無い拡張子のファイルはPOファイル作成から除外 高速化のため参照渡し
	 * @return boolean
	 */
	static function makePoFilesCore(string $folder, string $localeFolder, array &$targetExtensions):bool{

		// フォルダから.phpのファイルを一つづつ読み込み、ネームスペースとクラス名を特定、poファイルを作成する。
		if ($dh = opendir($folder)) {
			while (($file = readdir($dh)) !== false) {
				if ($file == '.' || $file == '..'){ continue; }

				$fullPath = $folder . $file;
				echo $fullPath . PHP_EOL;
				// フォルダの場合は再帰
				if (is_dir($fullPath)){
					if (substr($fullPath, -1) != '/'){ $fullPath .= '/'; }
					$ret = self::makePoFilesCore($fullPath, $localeFolder, $targetExtensions);
					if (!$ret){ return false; }
				// ファイルはpoファイル作成
				}else{
					$ext = substr($fullPath, strrpos($fullPath, '.') + 1);
					if (!in_array(strtolower($ext), $targetExtensions)){ continue; }

					$domainName = self::getDomainNameFromFile($fullPath);
					// ドメイン名が取れていた場合のみ
					if ($domainName != ''){
						// poファイルを作成
						self::makeAPoFile($domainName, $localeFolder, $fullPath);
					}
				}
			}
			closedir($dh);
		}

		return true;
	}

	/**
	 * 引数に指定された$srcPathからpoファイルを作成する。
	 *
	 * @param string $domainName 作成するpoファイルのドメイン名
	 * @param string $localeFolder 作成するpoファイルを置くlocaleフォルダー
	 * @param string $srcPath poファイルのもとになるソースファイル
	 * @return void
	 */
	static function makeAPoFile(string $domainName, string $localeFolder, string $srcPath){

		// まずはpotファイルの作成
		$folder = pathinfo($srcPath, PATHINFO_DIRNAME) . '/';
		$potFileName = $folder . $domainName . '.pot';
		exec('xgettext --keyword="_" --from-code=UTF-8 --msgid-bugs-address="anon@anon" -o ' . $potFileName . ' ' . $srcPath, $output);

		if (file_exists($potFileName)){
			// 各言語ごとのpotファイル作成、poファイル作成（or merge）
			foreach(self::DEFAULT_ACCEPT_LANGS as $key => $value){
				$langLocaleFolder = $localeFolder . $value . '/LC_MESSAGES/';
				if (!file_exists($langLocaleFolder)){ mkdir($langLocaleFolder, 0775, true); }
				$poFileName = $langLocaleFolder . $domainName . '.po';
				$tempPoFileName = $langLocaleFolder . 'temp_' . $domainName . '.po';

				// まずテンポラリーでpoファイルを作成し、msgcatでコメント行などを除いたpoファイルを最後に作成する。
				// コメント行がちょっと違うだけでバージョン管理に影響が出るのを避ける。
				if (file_exists($poFileName)){
					// 既にpoファイルがある場合
					exec('msgmerge "' . $poFileName . '" "' . $potFileName . '" -o "' . $tempPoFileName . '"');
					unlink($poFileName); // 一旦削除
				}else{
					// 新規でpoファイルを作る場合
					exec('msginit --no-translator -i "' . $potFileName . '" -o "' . $tempPoFileName . '"');
				}
				exec('msgcat "' . $tempPoFileName . '" --no-location --no-wrap --output "' . $tempPoFileName . '"'); // ファイルの行数などのコメント部分を削除
				exec('cat "' . $tempPoFileName . '" | grep -v -e POT-Creation-Date -e PO-Revision-Date -e "^#" > ' . $poFileName);
				unlink($tempPoFileName);
			}
			// 使い終わったpotは削除
			unlink($potFileName);
		}
	}

	/**
	 * 引数に指定したphpファイルから、ローカライズ用のドメイン名を取得する。
	 * クラスファイルの場合、名前空間+クラス名(\は_に置換)。
	 * その他はフォルダ名_ファイル名（拡張子無し）。TLIB_PROJECT_ROOTが設定されている場合、TLIB_PROJECT_ROOTはフォルダから除外。
	 *
	 * @param string $file phpファイルフルパス
	 * @return string ドメイン名
	 */
	static function getDomainNameFromFile(string $file) : string{

		// ファイルから空白、コメント、改行などを除去して取得
		$fileContents = php_strip_whitespace($file);
		// ;に改行追加 namespaceとclassは十中八九先頭付近なので、処理中の文字列に";"が含まれた場合などは無視。
		$fileContents = preg_replace( '/\s+/', ' ', $fileContents); // 連続する空白は一つにまとめる。
		$fileContents = str_replace(array('<?php', ';', '; ', "\r\n", "\r", "\n"), "\n", $fileContents);
		$fileContents = str_replace('{', "{\n", $fileContents);
		$fileContents = str_replace('}', "}\n", $fileContents);
		$fileContents = str_replace('abstract', "class", $fileContents); // abstractはclassとしてしまう。
		$fileContents = str_replace('readonly', '', $fileContents); // readonlyは消す

		// // $fileContents = file_get_contents($file);
		// // if ($fileContents === false){ return ''; }
		// $fileContents = str_replace(array("\r\n", "\r", "\n"), "\n", $fileContents);
		$aryFileLines = explode("\n", $fileContents);

		$namespace = '';
		$className = '';
		$len = count($aryFileLines);
		for ($i=0;$i < $len;$i++){
			$line = strtolower(trim($aryFileLines[$i]));
			if ($line == ''){ continue; }

			if (strpos($line, 'namespace') === 0){
				$line = str_replace("\t", " ", $line);
				$aryTemp = explode(' ', $line);
				$lenTemp = count($aryTemp);
				for($k=0;$k < $lenTemp;$k++){
					if ($aryTemp[$k] != '' && $aryTemp[$k] != 'namespace'){
						$namespace = trim($aryTemp[$k]);
						break;
					}
				}

			}else if (strpos($line, 'class') === 0){
				$line = str_replace(array("\t", "{", "}", '(', ')'), ' ', $line);
				$aryTemp = explode(' ', $line);
				$lenTemp = count($aryTemp);
				for($k=0;$k < $lenTemp;$k++){
					if ($aryTemp[$k] != '' && $aryTemp[$k] != 'class'){
						$className = trim($aryTemp[$k]);
						break;
					}
				}
			}

			// // 万一;で分割する場合
			// $code = explode(';', $line);
			// $codeLen = count($code);
			// for($j=0;$j < $codeLen;$j++){
			// 	$code[$j] = strtolower(trim($code[$j]));
			// 	if (strpos($code[$j], 'namespace') === 0){
			// 		$code[$j] = str_replace("\t", " ", $code[$j]);
			// 		$aryTemp = explode(' ', $code[$j]);
			// 		$lenTemp = count($aryTemp);
			// 		for($k=0;$k < $lenTemp;$k++){
			// 			if ($aryTemp[$k] != '' && $aryTemp[$k] != 'namespace'){
			// 				$namespace = trim($aryTemp[$k]);
			// 				break;
			// 			}
			// 		}
			// 	}else if (strpos($code[$j], 'class') === 0){
			// 		$code[$j] = str_replace(array("\t", "{", "}"), " ", $code[$j]);
			// 		$aryTemp = explode(' ', $code[$j]);
			// 		$lenTemp = count($aryTemp);
			// 		for($k=0;$k < $lenTemp;$k++){
			// 			if ($aryTemp[$k] != '' && $aryTemp[$k] != 'class'){
			// 				$className = trim($aryTemp[$k]);
			// 				break;
			// 			}
			// 		}
			// 	}
			//}

			if ($className != ''){
				break;
			}
		}

		// クラス名が見つからない場合はファイル名をドメイン名にする。
		// 各フォルダのindex.phpなどを想定し、同じファイル名でぶつからないよう、フォルダも不可
		if ($className == ''){
			$tempFileName = $file;
			if (defined('TLIB_PROJECT_ROOT')){
				$tempFileName = str_replace(TLIB_PROJECT_ROOT,'',$tempFileName);
			}
			// $className = pathinfo($file, PATHINFO_FILENAME);
			$className = str_replace(array('\\','/'),'_', $tempFileName);
		}
		if ($namespace != ''){
			$domainName = str_replace('\\','_',$namespace) . '_' . $className;
		}else{
			$domainName = $className;
		}

		return $domainName;
	}

	/**
	 * poファイルからmoファイルを作成する。
	 *
	 * @param string $poFile 作成元のpoファイル（フルパス）
	 * @param string $mofile 作成されるmoファイル（フルパス）未指定の場合はpoと同じフォルダに同じファイル名.moで作成
	 * @return void
	 */
	static function makeAMoFile(string $poFile, string $mofile = ''){
		// moファイルが指定されていない場合はpoファイルからmoファイル名を作成
		if ($mofile == ''){
			$path = pathinfo($poFile);
			$mofile = $path['dirname'] . '/' . $path['filename'] . '.mo';
		}

		exec('msgfmt -o "' . $mofile .'" "' . $poFile . '"');
	}

	/**
	 * 指定フォルダの下（再帰）の全てのpoファイルをmoファイルに変換する。
	 * /oooo/xxxx/hoge.po => /oooo/xxxx/hoge.mo
	 *
	 * @param string $folder poファイルを検索するフォルダ
	 * @return boolean
	 */
	static function makeAllMoFiles(string $folder) : bool{

		if (substr($folder, -1) != '/'){ $folder .= '/'; }

		if ($dh = opendir($folder)) {
			while (($file = readdir($dh)) !== false) {
				if ($file == '.' || $file == '..'){ continue; }
				$fullPath = $folder . $file;
				// フォルダの場合は再帰
				if (is_dir($fullPath)){
					$ret = self::makeAllMoFiles($fullPath);
					if (!$ret){ return false; }
				// ファイルはpoファイル作成
				}else{
					$ext = substr($fullPath, strrpos($fullPath, '.') + 1);
					if ($ext != 'po'){ continue; } /* poファイル以外は対象外 */
					self::makeAMoFile($fullPath);
				}
			}
		}else{
			// フォルダが見つからない
			return false;
		}

		return true;
	}
}