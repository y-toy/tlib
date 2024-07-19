<?php

namespace tlib;

/**
 * メールアドレスやSMS認証の認証で使う関数群をまとめる。現在はメールのみ
 * AUTH_VERIFYテーブルが中心。AUTH_VERIFYテーブルからACCOUNT_INFO_VERIFIEDテーブルへの移動も行う。
 */
class clsVerifyContact{

	protected ?clsDB $db = null;

	function __construct(clsDB &$db){
		$this->db = $db;
	}

	// 8桁のランダムな数字コードを生成する。
	function getVerifyCode() : int{ return random_int(10000000, 99999999); }

	// 認証を開始する。
	function startVerifying(int $AUTH_TYPE, string $AUTH_INFO,int &$verifyCode) : bool{

		return true;
	}

}