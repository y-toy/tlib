<?php

require_once('/tlib/config_sample.php'); // tlibの設定ファイルを読み込む 環境で書き換えのこと

class clsControllerBase {

	const STATUS_OK = 200;
	const STATUS_BAD_REQUEST = 400;
	const STATUS_UNAUTHORIZED = 401;
	const STATUS_FORBIDDEN = 403;
	const STATUS_NOT_FOUND = 404;
	const STATUS_METHOD_NOT_ALLOWED = 405;
	const STATUS_INTERNAL_SERVER_ERROR = 500;
	const STATUS_SERVICE_UNAVAILABLE = 503;

	private ?clsDB $db = null;
	private int $ver = 0;
	private int $resCode = 200;
	private int $clientId = 0; // 環境で書き換えのこと。OA2_ACCESS_TOKENSテーブルのclient_id。
	private string $returnSessionCode = '';

	function __construct(int $ver, ?array $dbInfo = null){
		$this->ver = $ver;
		if ($dbInfo !== null){
			$this->db = new clsDB($dbInfo);
		}
	}


	/**
	 * 各種必要な整形をしてjsonを返す。
	 *
	 * @param integer $resCode HTTPステータスコード
	 * @param array $json 返却するjson
	 * @return array 整形後のjson
	 */
	function getReturnJson(int $resCode, array $json) : array{
		$this->resCode = $resCode;
		return $this->addSessionCode($json);
	}

	function getResCode() : int { return $this->resCode; }
	function setResCode(int $resCode) : void { $this->resCode = $resCode; }


	/**
	 * ログインしているか（認証済みか）をチェックする。
	 * http request headerのsessionにセッションコードが入っていることを前提とする。
	 *
	 * @return integer 認証済みの場合、ユーザIDを返す。認証されていない場合0を返す。
	 */
	function isLogin() : int {
		$this->returnSessionCode = '';

		if ($this->db == null){ return 0; }
		$sessionCode = isset($_SERVER['session'])?$_SERVER['session']:'';
		if ($sessionCode == ''){ return 0; }

		//$ret = core::isLoginOAUTH2($this->db, $this->clientId, $sessionCode, $userType);
		$ret = core::isLogin($this->db, $sessionCode);

		$this->returnSessionCode = $sessionCode;

		return $ret;
	}

	// 返却のjsonにセッションコードを追加する。isLoginが呼ばれていること。
	function addSessionCode(array $json){
		$json['session'] = $this->returnSessionCode;
		return $json;
	}

	// 継承側で実装すること
	// function get(...$paras){ }
	// function post(...$paras){ }
	// function put(...$paras){ }
	// function delete(...$paras){ }

}
