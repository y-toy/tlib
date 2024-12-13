<?php
namespace tlib;

class clsUserSessionManager {
    private clsDB $db;
	private int $COMPANY_ID;
    private const DEFAULT_EXPIRE_DAYS = 30;

    public function __construct(clsDB $db, int $COMPANY_ID=0) {
        $this->db = $db;
		$this->COMPANY_ID = $COMPANY_ID; // 0はaccount.sqlのテーブルを利用 0>はaccount_b2b_service.sql
    }

    /**
     * ユーザ名とパスワードをチェックし、妥当なユーザか判別する
     *
     * @param string $userName ユーザ名
     * @param string $password パスワード
     * @return int|null 妥当なユーザの場合はユーザID、そうでない場合はnull
     */
    public function validateUser(string $userName, string $password): ?int {
        $sql = 'SELECT USER_ID FROM USERS WHERE USER_NAME = "' . $this->db->real_escape_string($userName) . '" AND PASS = "' . $this->db->real_escape_string($password) . '"';
		if ($this->COMPANY_ID > 0){ $sql.= ' AND COMPANY_ID = ' . $this->COMPANY_ID; }
        $userId = $this->db->getFirstOne($sql);

        return $userId !== null ? (int)$userId : null;
    }

    /**
     * ユーザのログインを処理する
     *
     * @param int $userId ユーザID
     * @return string|null ログイン成功時はセッションコード、失敗時はnull
     */
    public function login(int $userId, ?array $sessionInfo): ?string {
        $sessionCode = $this->generateSessionToken();
		if ($sessionCode === ''){ return null; }

        $escapedSessionCode = $this->db->real_escape_string($sessionCode);
        $expireDays = defined('TLIB_LOGIN_EXPIRE_DAYS') ? TLIB_LOGIN_EXPIRE_DAYS : self::DEFAULT_EXPIRE_DAYS;
        $expirationTime = date('Y-m-d H:i:s', strtotime("+$expireDays days"));
        $sessionInfoJson = $sessionInfo !== null ? json_encode($sessionInfo) : json_encode([]);
        $escapedSessionInfo = $this->db->real_escape_string($sessionInfoJson);

        $sql = 'INSERT INTO USER_SESSIONS (USER_ID, SESSION_TOKEN, SESSION_INFO, EXPIRATION_TIME) VALUES (' . $userId . ', "' . $escapedSessionCode . '", \'' . $escapedSessionInfo . '\', "' . $expirationTime . '")';
        if ($this->db->query($sql)) {
            return $sessionCode;
        }
        return null;
    }

    /**
     * ユーザのログアウトを処理する
     *
     * @param int $userId ユーザID
     * @param string $sessionCode セッションコード
     * @return bool ログアウト成功時はtrue、失敗時はfalse
     */
    public function logout(int $userId, string $sessionCode): bool {
        $escapedSessionCode = $this->db->real_escape_string($sessionCode);
        $sql = 'DELETE FROM USER_SESSIONS WHERE USER_ID = ' . $userId . ' AND SESSION_TOKEN = "' . $escapedSessionCode . '"';
        return (bool)$this->db->query($sql);
    }

    /**
     * ユーザがログインしているかを確認する
     *
     * @param string $sessionCode セッションコード　書き換える場合があるので注意
     * @return int ログインしているuserのUSER_ID ログインしていない場合は0
     */
    public function isLoggedIn(string &$sessionCode): int {
		return core::isLogin($this->db, $sessionCode);
    }

}
?>