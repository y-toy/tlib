<?php

require_once __DIR__ . '/clsControllerBase.php';

class clsSample extends clsControllerBase {

	private int $curVersion = 1;

	function __construct($ver = 0){
		if ($ver == 0 || $ver > $this->curVersion){ $ver = $this->curVersion; }
		parent::__construct($ver, $GLOBALS['db_info']);
	}

	/**
	 * ユーザIDを取得する
	 *
	 * @return array json
	 */
	function getUserId(...$paras){
		$userId = $this->isLogin();
		if ($userId == 0){ return $this->getReturnJson(self::STATUS_FORBIDDEN, []); }

		return $this->getReturnJson(200, ['result' => 'ok', 'userid' => $userId]);
	}

}

