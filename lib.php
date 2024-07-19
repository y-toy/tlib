<?php
namespace tlib;

////////////////////////////////////////////////////////////////////////////////
/// 共通関数 ///
////////////////////////////////////////////////////////////////////////////////

// locale poファイル作成用 tlib_lib.poが翻訳ファイルとなる。
class lib{}

/*****************************************************************************/
/*** POST/GET 取得 ***/
/*****************************************************************************/

/*** POST/GET 入力取得 ***/
class myHttp {
	/**
	 *
	 * キャッシュNG
	 *
	 * @return void
	 */
	static function noCache(){
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: Sat, 26 Jul 1997 05:00:00 GMT' );
	}

	/**
	 * POST/GET 入力取得 filter_inputラッパー関数
	 * 　=> エラーを全て空文字で返す''
	 * 　=> trim & xss対策 付き
	 *
	 * @param int $type INPUT_POST or INPUT_GET
	 * @param string $variable_name POST/GET名
	 * @return string 取得文字列 エラー時は''
	*/
	static function getInput(int $type , string $variable_name) : string{
		$ret = trim((string)filter_input($type, $variable_name));
		if ( $ret != '') { $ret = htmlspecialchars($ret, ENT_QUOTES, 'UTF-8'); }
		return $ret;
	}

	/**
	 * POST/GET 入力取得 filter_inputラッパー関数
	 * 　=> エラーを全て空文字で返す''
	 * 　=> trim & xss対策 付き
	 * 　=> 数値入力などで全角が入力されたときに半角として扱うために使用
	 *
	 * @param int $type INPUT_POST or INPUT_GET
	 * @param string $variable_name POST/GET名
	 * @return string 取得文字列 エラー時は''
	*/
	static function getInputConvHankaku(int $type, string $variable_name) : string{
		$ret = (string)filter_input($type, $variable_name);
		if ( $ret != '') {
			$ret = htmlspecialchars(trim(mb_convert_kana($ret, 'as', 'UTF-8')), ENT_QUOTES, 'UTF-8');
		}
		return $ret;
	}

	/**
	 * POST/GET 入力取得 filter_inputラッパー関数 (配列)
	 * 　=> エラーを全て空文字で返す''
	 * 　=> trim & xss対策 付き
	 *
	 * @param int $type INPUT_POST or INPUT_GET
	 * @param string $variable_name POST/GET名
	 * @return array エラー時は空の配列
	*/
	static function getInputArray(int $type, string $variable_name) : array{
		$retArry = array();
		$getArry = filter_input($type, $variable_name, FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
		foreach ($getArry as $ret){
			$ret = trim((string)$ret);
			if ( $ret != '') { $retArry[] = htmlspecialchars($ret, ENT_QUOTES, 'UTF-8'); }
		}
		return $retArry;
	}

	/**
	 * POST/GET 入力取得 filter_inputラッパー関数 (配列)
	 * 　=> エラーを全て空文字で返す''
	 * 　=> trim & xss対策 付き
	 * 　=> 数値入力などで全角が入力されたときに半角として扱うために使用
	 *
	 * @param int $type INPUT_POST or INPUT_GET
	 * @param string $variable_name POST/GET名
	 * @return array エラー時は空の配列
	*/
	static function getInputConvHankakuArray(int $type, string $variable_name) : array{
		$retArry = array();
		$getArry = filter_input($type, $variable_name, FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
		foreach ($getArry as $ret){
			$ret = htmlspecialchars(trim(mb_convert_kana((string)$ret, 'as', 'UTF-8')), ENT_QUOTES, 'UTF-8');
			if ( $ret != '') { $retArry[] = $ret; };
		}
		return $retArry;
	}

	/**
	 * nl2brの逆版
	 *
	 * @param string $str
	 * @return string
	 */
	function br2nl(string $str) : string{
		return preg_replace('/<br[[:space:]]*\/?[[:space:]]*>/i', '', $str);
	}

}

/**
 * 日付関係ユーティリティ
 */
class myDate {

	/**
	 * よく使うyyyy-mm-ddのstringをUnix タイムスタンプ(int)に変換する。
	 *
	 * @param string $date yyyy-mm-dd
	 * @return int Unix タイムスタンプ
	 */
	static function strToDate(string $date) : int { return mktime(0,0,0,(int)substr($date,5,2), (int)substr($date,8,2), (int)substr($date,0,4)); }

	/**
	 * よく使うyyyy-mm-dd H:i:sのstringをUnix タイムスタンプ(int)に変換する。
	 *
	 * @param string $date yyyy-mm-dd H:i:s
	 * @return int Unix タイムスタンプ
	 */
	static function strToDatetime(string $datetime) : int { return mktime((int)substr($datetime,11,2),(int)substr($datetime,14,2),(int)substr($datetime,17,2),(int)substr($datetime,5,2), (int)substr($datetime,8,2), (int)substr($datetime,0,4)); }


	/**
	 * 期間入力の大小が異なる場合は入れ替えを行う。関数名が変なので注意。
	 * また、未入力時は$sttに'2001-01-01'、$endに'2999-12-31'を設定する。
	 *
	 * @param string $stt 'yyyy-mm-dd'
	 * @param string $end 'yyyy-mm-dd'
	 * @return boolean
	 */
	static function swapDate(string &$stt, string &$end) : bool{
		// sttに入力がある場合は、日付の妥当性チェック
		if ($stt != '' && !self::isDate($stt)){ return false; }
		if ($end != '' && !self::isDate($end)){ return false; }

		// $sttのみ入力がある場合
			 if ($stt != '' && $end == ''){ $end = '2999-12-31'; }
		// $endのみ入力がある場合
		else if ($stt == '' && $end != ''){ $stt = '2001-01-01'; }
		// $sttと$end両方入力が無い場合
		else if ($stt == '' && $end == ''){ $stt = '2001-01-01'; $end = '2999-12-31'; }

		// $sttと$end両方に値が入っているはずなので、この状態で逆の場合スワップ
		$timeStt = mktime(0,0,0,substr($stt,5,2), substr($stt,8,2), substr($stt,0,4));
		$timeEnd = mktime(0,0,0,substr($end,5,2), substr($end,8,2), substr($end,0,4));
		if ($timeEnd < $timeStt){
			$tmp = $timeEnd;
			$timeEnd = $timeStt;
			$timeStt = $tmp;
		}

		$stt = Date('Y-m-d', $timeStt);
		$end = Date('Y-m-d', $timeEnd);

		return true;
	}

	/**
	 * theDayがsttDateとendDateの間に入っているかチェックする。
	 * 各日のフォーマットは'yyyy-mm-dd'
	 *
	 * @param string $theDay 'yyyy-mm-dd'
	 * @param string $sttDate 'yyyy-mm-dd'
	 * @param string $endDate 'yyyy-mm-dd'
	 * @return boolean
	 */
	static function isTheDayInTheTerm(string $theDay, string $sttDate, string $endDate) : bool{
		$dtA = mktime(0,0,0,substr($theDay,5,2), substr($theDay,8,2), substr($theDay,0,4));
		$dtStt = mktime(0,0,0,substr($sttDate,5,2), substr($sttDate,8,2), substr($sttDate,0,4));
		$dtEnd = mktime(0,0,0,substr($endDate,5,2), substr($endDate,8,2), substr($endDate,0,4));
		if ($dtA < $dtStt || $dtA > $dtEnd){ return false; }
		return true;
	}

	/**
	 * 文字列 date1 を date2 と比較し、大小を返す。
	 *
	 * @param string $date1 (strtotimeの引数)
	 * @param string $date2 (strtotimeの引数)
	 * @return int before = -1 (date1が小さい) after = 1(date1のほうが大きい) same = 0
	 */
	static function compareDate(string $date1,string $date2) {
		$time1 = 0; $time2 = 0;

		if (strlen($date1) == 10){
			$time1 = self::strToDate($date1);
			$time2 = self::strToDate($date2);
		}else{
			$time1 = self::strToDatetime($date1);
			$time2 = self::strToDatetime($date2);
		}

			if($time1 < $time2) { return -1; }
		elseif($time1 > $time2) { return  1; }
		else                    { return  0; }
	}

	/**
	 * (YYYY-MM-DD)形式の日付チェック (入力日付の形式はこれで統一する。)
	 *
	 * @param string $date
	 * @return bool true/false
	 */
	static function isDate(string $date) : bool{ return isThis::date($date); }

	/**
	 * (YYYY-MM-DD hh:ii:ss)形式の日付チェック (入力日付の形式はこれで統一する。)
	 *
	 * @param string $date
	 * @return bool true/false
	 */
	static function isDateTime(string $date) : bool{  return isThis::datetime($date); }

	/**
	* 指定した日が属する週の指定した曜日の日付（Y-m-d形式）を取得する。
	* $weekday  0 日曜日 1 月曜日 … 6 土曜日
	*
	* @param int $timestamp 指定日のタイムスタンプ
	* @param int $weekday  0 日曜日 1 月曜日 … 6 土曜日
	* @return string 'Y-m-d'
	*/
	static function getTheWeekDay(int $timestamp, int $weekday) : string {
		$w = date('w', $timestamp);
		$sa = $w - $weekday;
		return date('Y-m-d', $timestamp - ($sa * 86400) ); // 86400 = 24 * 60 *60
	}

	/**
	 * 日付の差分（日数）を求める abs($dateB - $dateA)
	 *
	 * @param string $dateA YYYY-MM-DD or YYYY-MM-DD hh:ii:ss (hh:ii:ssは無視される)
	 * @param string $dateB YYYY-MM-DD or YYYY-MM-DD hh:ii:ss (hh:ii:ssは無視される)
	 * @return int 日数
	 */
	function dateDiffAbs(string $dateA, string $dateB) : int { return floor(abs(self::strToDate($dateB) - self::strToDate($dateA)) / 86400) + 1; }

}

/**
 * フォーマットチェック用
 */
class isThis {

	/**
	 * (YYYY-MM-DD)形式の日付チェック (入力日付の形式はこれで統一する。)
	 *
	 * @param string $date
	 * @return bool true/false
	 */
	static function date(string $date) : bool{
		if (strlen($date) != 10 || !checkdate(substr($date,5,2), substr($date,8,2), substr($date,0,4))){ return false; }
		return true;
	}

	/**
	 * (YYYY-MM-DD hh:ii:ss)形式の日付チェック (入力日付の形式はこれで統一する。)
	 *
	 * @param string $date
	 * @return bool true/false
	 */
	static function datetime(string $date) : bool{
		return $date === date('Y-m-d H:i:s', myDate::strToDatetime($date));
	}
	/**
	 * 整数かどうかをチェックする。$bPositive = true で正値のみかチェック
	 *
	 * @param string $val
	 * @param boolean $bPositive 正値のみ?
	 * @return boolean true(整数) / false(整数外)
	 */
	static function int(string $val, bool $bPositive=false) : bool{
		if ($bPositive){
			return preg_match("/^[0-9]+$/",$val);
		}else{
			return preg_match("/^-?[0-9]+$/",$val);
		}
	}
	/**
	 * 電話番号かどうか（許可は数字と"-"と"("と")"と"+"） 海外の電話番号を考慮し、幅広く取る。
	 *
	 * @param string $val
	 * @return boolean true:OK false : 形式エラー
	 */
	static function phone(string $val) : bool { return preg_match("/^[0-9-\+() ]{3,20}$/",$val); }

	/**
	 * emailかどうかチェックする。 ドメインの有効性チェックまで行う。
	 *
	 * @param string $val
	 * @return boolean true:OK false : 形式エラー
	 */
	static function email(string $val) : bool{
		if (preg_match("/^([a-zA-Z0-9])+([a-zA-Z0-9\._-])*@([a-zA-Z0-9_-])+([a-zA-Z0-9\._-]+)+$/",$val)){
			$emailArray = explode('@', $val);
			if (checkdnsrr(array_pop($emailArray), 'MX')){
				return true;
			}
		}
		return false;
	}

	/**
	 * クレジットカードチェック（Luhnアルゴリズムのみ）
	 *
	 * @param string $number (数字のみとすること)
	 * @return boolean
	 */
	static function creditCard(string $number) : bool {

		if (!ctype_digit($number)){ return false; }

		// Set the string length and parity
		$number_length=strlen($number);
		if ($number_length === 0){ return false; }

		$parity=$number_length % 2;

		// Loop through each digit and do the maths
		$total=0;
		for ($i=0; $i<$number_length; $i++) {
			$digit=$number[$i];
			// Multiply alternate digits by two
			if ($i % 2 == $parity) {
				$digit*=2;
				// If the sum is two digits, add them together (in effect)
				if ($digit > 9) { $digit-=9; }
			}
			// Total up the digits
			$total+=$digit;
		}

		// If the total mod 10 equals 0, the number is valid
		return ($total % 10 == 0) ? true : false;
	}

	/**
	 * 文字列がASCII文字のみかどうか調べる。
	 * return true ASCII文字のみ false ASCII文字以外を含む
	 *
	 * @param string $str
	 * @return bool
	 */
	static function ascii(string $str) : bool{
		// 改行は除去
		$str = str_replace(array("\r\n", "\r", "\n"), '', $str);
		if ($str == ''){ return true; } // 何もないのはOK
		return (preg_match("/^[\x20-\x7E]+$/", $str));
	}

	/**
	 * パスワードチェック
	 *
	 * @param string $pass チェックするパスワード
	 * @param integer $minLen パスワードの最小の長さ
	 * @param integer $maxLen パスワードの最長の長さ 0で制限なし
	 * @param integer $mode 0 どんな文字でもOK 1 数字と小文字アルファベット 2 数字と大文字・小文字 3 数字と大文字・小文字と記号()
	 * @return integer 0 正常 1 短すぎる 2 長すぎる 3 数字のみエラー 4 数字なし 5 小文字なし 6 大文字なし 7 記号無し
	 */
	static function password(string $pass, int $minLen, int $maxLen, int $mode) : int{

		$passLen = mb_strlen($pass);
		if ($passLen < $minLen){ return 1; }
		if ($maxLen != 0 && $passLen > $maxLen){ return 2; }

		// なんでもOK
		if ($mode == 0){ return 0; }

		if (preg_match('/^[0-9]+$/', $pass)){ return 3; } // 全部数字はNG
		if (!preg_match('/[0-9]/', $pass)){ return 4; } // 数字を含まない
		if (!preg_match('/[a-z]/', $pass)){ return 5; } // 小文字を含まない

		// 数字とアルファベットを含んでいたらOK
		if ($mode == 1){ return 0; }

		if (!preg_match('/[A-Z]/', $pass)){ return 6; } // 大文字を含まない

		// 数字と小文字大文字を含んでいたらOK
		if ($mode == 2){ return 0; }

		if (!ctype_alnum($pass)){ return 7; } // 記号を含まない

		return 0;
	}
}

/*****************************************************************************/
/*** 料金計算 ***/
/*****************************************************************************/

class priceCalc {

	/**
	 * 内税の場合の税金計算（税込み額から税額を計算 小数点以下四捨五入）
	 *
	 * @param int $price 税込み金額
	 * @param string $baseDate 'yyyy-mm-dd' or 'yyyy-mm-dd hh:mm:ss' 計算の基準日 税率の変更があった場合に使用。
	 * @return int 税額
	 */
	static function getIncTaxAmount($price, $baseDate){
		//
		// $ts = myDate::strToDate($baseDate);
		// if ($ts < $GLOBALS['taxChangeTS']){
		// 	return round($price/108 * 8);
		// }else{
		// 	return round($price/108 * 8);
		// }
		return round($price/110 * 10);
	}

	/**
	 * 外税の場合の税金計算（税抜き額から税額を計算 小数点以下四捨五入）
	 *
	 * @param int $price 税抜き金額
	 * @param string $baseDate 'yyyy-mm-dd' or 'yyyy-mm-dd hh:mm:ss' 計算の基準日 税率の変更があった場合に使用。
	 * @return int 税額
	 */
	static function getTaxAmount($price, $baseDate){
		//
		// $ts = myDate::strToDate($baseDate);
		// if ($ts < $GLOBALS['taxChangeTS']){
		// 	return round($price*0.1);
		// }else{
		// 	return round($price*0.08);
		// }
		return round($price*0.1);
	}

	/**
	 * ％値引き後の金額を求める。(小数点以下四捨五入)
	 *  例:1500円の10%引き getDiscountPrice(1500,10)
	 *
	 * @param int $originalPrice
	 * @param int $dicountPercet
	 * @return int
	 */
	static function getDiscountPrice(int $originalPrice, int $dicountPercet){
		if ($dicountPercet == 0 || $dicountPercet >= 100){ return 0; }
		return round(($originalPrice/100) * (100-$dicountPercet));
	}

}

/*****************************************************************************/
/*** 他 ***/
/*****************************************************************************/

class util{

	/**
	 * 指定フォルダ($folder)以下の$days日より古いファイルを削除 (再帰処理無し)
	 * 非同期なので注意のこと。
	 *
	 * @param string $folder
	 * @param int $days
	 * @return void
	 */
	static function deleteOldFiles(string $folder, int $days) : void{
		exec('(find ' . $folder . ' -mtime +' . $days . ' -exec rm -f {} \;) &');

		// $expire = time() - (86400 * $days); //削除期限
		// $list = scandir($folder);
		// foreach($list as $value){
		// 	$file = $folder . $value;
		// 	if(!is_file($file)){ continue; }
		// 	$mod = filemtime($file);
		// 	if($mod < $expire){ unlink($file); }
		// }
	}

	/**
	 * 二次元配列のソート用
	 * $array = array(
	 *		array('id'=>xx, 'some'=>xxxx),
	*		array('id'=>xx, 'some'=>xxxx),
	* );
	* のような二次元配列について、二次元目の要素名（idやsome）でソートする。
	*  例：sortTDArrayByKey($array, 'id');
	*
	* @param array $array
	* @param string $sortKey
	* @param int $sortType SORT_ASC / SORT_DESC
	* @return void
	*/
	static function sortTDArrayByKey(array &$array, string $sortKey, int $sortType = SORT_ASC ) : void {
		$tmpArray = array();
		foreach ( $array as $key => $row ) {
			$tmpArray[$key] = $row[$sortKey];
		}
		array_multisort( $tmpArray, $sortType, $array );
		unset( $tmpArray );
	}

	/**
	 * 二次元配列のソート要（要素番号）
	 *
	 * $array = array(
	 *		array(xx, 101),
	*		array(xx, 200),
	*		array(xx, 203),
	* );
	* のような二次元配列について、二次元目の要素番号でソートする。
	*   例）sortTDArrayByENumber($array, 1); <-こうすると 101->200->203の順にソートされる。
	*
	* @param array $array
	* @param int $sortNum
	* @param int $sortType  SORT_ASC / SORT_DESC
	* @return void
	*/
	static function sortTDArrayByENumber(array &$array, int $sortNum, int $sortType = SORT_ASC) : void{
		$len = count($array);
		for ($i=0;$i < $len;$i++){
			for ($k=0;$k < ($len-($i+1)) ;$k++){
				if ($sortType == SORT_ASC){
					if ($array[$k][$sortNum] > $array[$k + 1][$sortNum]){
						$temp = $array[$k];
						$array[$k] = $array[$k + 1];
						$array[$k + 1] = $temp;
					}
				}else{
					if ($array[$k][$sortNum] < $array[$k + 1][$sortNum]){
						$temp = $array[$k];
						$array[$k] = $array[$k + 1];
						$array[$k + 1] = $temp;
					}
				}
			}

		}
	}

	/**
	 * ランダム文字列生成 (英数字)
	 *
	 * @param int $length: 生成する文字数
	 * @return string 生成文字列
	 */
	static function makeRandStr(int $length) : string {
		$str = array_merge(range('a', 'z'), range('0', '9'), range('A', 'Z'));
		$r_str = null;
		for ($i = 0; $i < $length; $i++){ $r_str .= $str[rand(0, count($str) - 1)]; }
		return $r_str;
	}

	/**
	 * str_replaceのマルチバイト版
	 *
	 * @param mixed $search  array|string　検索文字列 (配列可)
	 * @param mixed $replace  array|string　置換文字列 (配列可)
	 * @param mixed $haystack  array|string　置換対象文字列 (配列可)
	 * @param string $encoding
	 * @return string 置換後の文字列
	 */
	static function mb_str_replace(mixed $search, mixed $replace, mixed $haystack, $encoding='UTF-8') : string{
		// 検索先は配列か？
		$notArray = !is_array($haystack) ? TRUE : FALSE;
		// コンバート
		$haystack = $notArray ? array($haystack) : $haystack;
		// 検索文字列の文字数取得
		$search_len = mb_strlen($search, $encoding);
		// 置換文字列の文字数取得
		$replace_len = mb_strlen($replace, $encoding);

		foreach ($haystack as $i => $hay){
			// マッチング
			$offset = mb_strpos($hay, $search);
			// 一致した場合
			while ($offset !== FALSE){
				// 差替え処理
				$hay = mb_substr($hay, 0, $offset).$replace.mb_substr($hay, $offset + $search_len);
				$offset = mb_strpos($hay, $search, $offset + $replace_len);
			}
			$haystack[$i] = $hay;
		}
		return $notArray ? $haystack[0] : $haystack;
	}

	/*****************************************************************************/
	/*** 暗号／復号 ***/
	/*****************************************************************************/

	/**
	 * symple XORエンコード keyの長さは$plaintextより長いこと
	 *
	 * @param string $plaintext 日本語もOK
	 * @param string $key
	 * @return string base64_encodeされた暗号文
	 */
	static function xorEncrypt(string $plaintext, string $key) : string {
		$len = strlen($plaintext);
		$enc = '';
		for($i = 0; $i < $len; $i++){
			$enc .= chr(ord($plaintext[$i]) ^ ord($key[$i]));
		}
		return base64_encode($enc);
	}

	// symple XORデコード 上記のデコード版
	static function xorDecrypt(string $encryptedText, string $key) : string{
		$enc = base64_decode($encryptedText);
		$plaintext = "";
		$len = strlen($enc);
		for($i = 0; $i < $len; $i++){
				$plaintext .= chr(ord($enc[$i]) ^ ord($key[$i]));
		}
		return $plaintext;
	}

	/**
	 * ログ出力関数
	 * ハンドルできないエラーでのみ最後のログ出力として使用。$level = 1 debug 2 warn 3 error
	 * いちいちファイル開いて閉じていて、重たいので基本使用しない。ちなみに、実行時に90日以前のログファイルを削除している。
	 *
	 * @param int $level 1 debug 2 warn 3 error
	 * @param string $message
	 * @param string $file 出力先のフォルダー 最後はスラッシュで終わること。
	 * @return void
	 */
	static function vitalLogOut(int $level, string $message, string $outFolder = ''){

		if ($outFolder == ''){
			if (defined(TLIB_LOG)){
				$outFolder = TLIB_LOG;
			}else{
				$outFolder = __DIR__ . '/logs/';
			}
		}
		$outFolder = rtrim( $outFolder, '/' ) . '/';
		$file = $outFolder . date('Ymd') . '.log';

		//*** ファイル書き込み
		$fp = fopen($file, 'c');
		if ($fp===FALSE){ return ; }

		fseek($fp, 0, SEEK_END);

		$level_str = 'ERR';
			if ($level == 1){ $level_str = 'DBG'; }
		elseif ($level == 2){ $level_str = 'WRN'; }

		$contents = '[' . date('Y/m/d H:i:s') . '] [' . $level_str . '] ' . $message . "\r\n";
		fwrite($fp, $contents);

		fclose($fp);

		//*** ログフォルダー以下の古いファイルを削除
		self::deleteOldFiles($outFolder, 90);
	}
}

