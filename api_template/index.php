<?php

/**
 * api ルーティング用
 *
 * このルーティングファイル index.phpがhttps://example.com/api/index.phpとする場合の例
 *
 * 以下のURLが呼ばれた場合
 *  https://example.com/api/0001/sample/method/params1/params2
 *
 * api以降がパラメーターになる。
 *  - パラメータ1 : バージョン情報 例では 0001 なので clsSampleのコンストラクタに1を指定して呼ばれる。0はバージョン未指定で現在のバージョンとなる。
 *  - パラメータ2 : 呼び出すクラス名 例では sample なので controllers/clsSample.php が呼ばれ、clsSampleが生成される。
 *                 呼び出し方は new clsSample($version) となる。
 *  - パラメータ3 : メソッド名 例では method なので clsSample->method() が呼ばれる。省略された場合、$_SERVER["REQUEST_METHOD"]がメソッド名として呼ばれる。
 *  - パラメータ4以降 : メソッドに渡すパラメーター
 *
 * 認証
 *  - http rewuest headerにセッションコード格納用の"session"を指定する。
 *  - session-codeはclsLogin->login()で取得したセッションコード
 *  - コントローラーの継承元クラスにclsControllerBaseに認証チェックのメソッドあり。(isLogin()で認証チェック)
 *  - session-codeは時々書き換わる。書き換わった場合は、戻りのjsonに{'session-code': 'new session code'}が設定される。設定されていない場合は、セッションコードは変更されていない。
 *
 */

 // $_SERVER['SCRIPT_NAME'] ドメインからのこのindex.phpまでのパス
 // $_SERVER['REQUEST_URI'] ドメインを除くURL
$scriptPath = dirname($_SERVER['SCRIPT_NAME']);
if ($scriptPath[0] !== '/') { $scriptPath = '/' . $scriptPath; }

$reqURI = $_SERVER['REQUEST_URI'];
if ($reqURI[0] !== '/') { $reqURI = '/' . $reqURI; }

$uriParas =  str_replace($scriptPath, '', $reqURI);

$paras = explode('/',$uriParas);
$paras = array_filter($paras, function($value) { return (trim($value) !== ''); }); // 空白のパラメーターは削除

try{

	// 最低でもバージョンとコントローラーは必要
	$cntPara = count($paras);
	if ($cntPara < 2){ throw new Exception('No controller or version'); }

	$version = array_shift($paras); // 1つ目のパラメーターはバージョン
	$file = array_shift($paras); // 2つ目のパラメーターは実行クラス名（ファイル名）
	$fileName = 'cls' . ucfirst(strtolower($file));
	$filePath = './controllers/'.$fileName.'.php';

	if(!file_exists($filePath)){ throw new Exception('No controller file'); }

	include($filePath);

	$className = $fileName;
    $methodName = strtolower((($cntPara > 2)?array_shift($paras):$_SERVER["REQUEST_METHOD"]));

	if (!method_exists($className, $methodName)){ throw new Exception('No method'); }

	$obj = new $className($version);
	$res = array();
	if (count($paras) > 0){
		$res = json_encode($obj->$methodName(...$paras)); // 分解して渡す
	}else{
		$res = json_encode($obj->$methodName());
	}

	// header("Access-Control-Allow-Origin: *");
	header('Content-Type: application/json; charset=utf-8', true, $obj->getResCode());
	echo $res;

}catch(Exception $e){
    header("HTTP/1.1 404 Not Found");
	exit;
}

