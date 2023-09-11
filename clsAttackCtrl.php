<?php
namespace tlib;

/**
 * 攻撃管理
 * 攻撃と思われるIPを登録し一定期間アクセス不可にする。
 *
 * DBに以下のテーブルがあること。BlackListに登録すると、ATTACK_CNTに1000が設定される。
 *
 * CREATE TABLE IF NOT EXISTS ATTACK_CHECK(
 * 	IP_ADDRESS VARBINARY(16) NOT NULL COMMENT "Store IPv4 and IPv6 both. INET6_ATON()で返された値",
 * 	ATTACK_CNT INT NOT NULL COMMENT "Count of Suspected Attacks from the IP address.",
 * 	EXPIRED_TIME datetime NOT NULL COMMENT 'This row will be deleted after this time.',
 * 	INSERT_TIME datetime NOT NULL COMMENT 'Insert time',
 * 	INDEX INDEX_IP_ADDRESS (IP_ADDRESS)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;
 *
 */

class clsAttackCtrl{

	const MAX_ATTACK_CNT = 5; // 攻撃の閾値 ここを超えたらアクセス拒否 (5回までOK)
	const WARNING_ATTACK_CNT = 3; // 攻撃の閾値 ここを超えたら警告 (3回で警告)
	const BLACK_LIST_ATTACK_CNT = 1000; // ブラックリスト登録のIPの場合この数を返す。
	const DEFAULT_EXPIRED_TIME = 3600; // 登録から消去までのタイミング 秒
	const DEFAULT_BLACK_LIST_EXPIRED_TIME = 259200; // ブラックリスト登録から消去までのタイミング 3日

	private ?clsDB $db = null;
	function __construct(clsDB &$db){ $this->db = $db; }

	/**
	 * アクセス不可のIPかチェックする。
	 *
	 * @param int $cntAttack
	 * @param int $cntWarning
	 * @return integer
	 */
	function isAttack(int $cntAttack = self::MAX_ATTACK_CNT, int $cntWarning = self::WARNING_ATTACK_CNT) : int{
		$cnt = $this->getNowAttackCnt();
			 if ($cnt >  $cntAttack ){ return 2; }
		else if ($cnt >= $cntWarning){ return 1; }

		return 0;
	}

	/**
	 * 現在の攻撃数を取得
	 *
	 * @return integer 攻撃数
	 */
	function getNowAttackCnt() : int {
		$sql = 'SELECT ATTACK_CNT FROM ATTACK_CHECK WHERE IP_ADDRESS=INET6_ATON("' . $_SERVER['REMOTE_ADDR'] . '") AND EXPIRED_TIME < now()';
		$cnt = $this->db->getFirstOne($sql);
		if ($cnt === null){ return 0; }

		return (int)$cnt;
	}

	/**
	 * 攻撃数を１追加
	 *
	 * @param int $expireTime 解除までの追加時間（秒）この秒数経過したら攻撃していないとみなされる。
	 * @return boolean
	 */
	function addAttackCnt(int $expireTime = self::DEFAULT_EXPIRED_TIME) : bool{
		$cnt = $this->getNowAttackCnt();
		if ($cnt == 0){
			$sql='INSERT INTO ATTACK_CHECK (IP_ADDRESS, TYPE_OF_JUDGE, ATTACK_CNT, EXPIRED_TIME, INSERT_TIME)
			VALUES (INET6_ATON("' .  $_SERVER['REMOTE_ADDR'] . '"), 1, NOW() + INTERVAL ' . $expireTime . ' SECOND, now())';
		}else if ($cnt < self::BLACK_LIST_ATTACK_CNT){
			$sql='UPDATE ATTACK_CHECK SET ATTACK_CNT=ATTACK_CNT+1, EXPIRED_TIME= NOW() + INTERVAL ' . $expireTime . ', INSERT_TIME = now()
				WHERE IP_ADDRESS=INET6_ATON("' . $_SERVER['REMOTE_ADDR'] . '")';
		}else{
			// Black listの場合は何もしない。
			return true;
		}
		return (bool)$this->db->query($sql);
	}

	/**
	 * Black listに追加する。
	 *
	 * @param int $expireTime 解除までの追加時間（秒）この秒数経過したらBlack List解除
	 * @return boolean
	 */
	function addBlackList(int $expireTime = self::DEFAULT_BLACK_LIST_EXPIRED_TIME) : bool {
		$this->delAttackCnt($_SERVER['REMOTE_ADDR']); // 既に追加済みの場合は一旦削除
		$sql='INSERT INTO ATTACK_CHECK (IP_ADDRESS, TYPE_OF_JUDGE, ATTACK_CNT, EXPIRED_TIME, INSERT_TIME)
			VALUES (INET6_ATON("' .  $_SERVER['REMOTE_ADDR'] . '"), ' . self::BLACK_LIST_ATTACK_CNT . ', NOW() + INTERVAL ' . $expireTime . ' SECOND, now())';

		return (bool)$this->db->query($sql);
	}

	/**
	 * 攻撃カウントを削除
	 *
	 * @return boolean
	 */
	function delAttackCnt() : bool{
		$sql='DELETE FROM ATTACK_CHECK WHERE IP_ADDRESS=INET6_ATON("' . $_SERVER['REMOTE_ADDR'] . '")';
		return (bool)$this->db->query($sql);
	}

	/**
	 * すでに期限切れの攻撃カウントの削除
	 *
	 * @return boolean
	 */
	function delOldAttackCnt() : bool{
		$sql = 'DELETE FROM ATTACK_CHECK WHERE EXPIRED_TIME < now()';
		return (bool)$this->db->query($sql);
	}

};
