-- アカウント認証に関する情報以外は含まない 顧客情報については、アプリ側で管理のこと
-- 複数言語対応する箇所については、カラムにJSON形式で{"ja":"日本","en":"Japan"}のように指定する。
-- 言語コードはISO 639-1 (http Content-Languageに同じ)
-- indexを貼るのであれば、charとvarcharの検索速度はほぼ変わらないので、hash系もvarcharを使っている。

-------------------------------------------------------------------------------
--- ユーザアカウント ---
-------------------------------------------------------------------------------

-- アカウントメイン情報 迷ったが全アプリで一意とする。
DROP TABLE IF EXISTS ACCOUNT;
CREATE TABLE IF NOT EXISTS ACCOUNT(
	ACCOUNT_ID BIGINT UNSIGNED AUTO_INCREMENT comment 'ユーザ識別',
	ACCOUNT_ID_CHARS VARCHAR(128) UNIQUE comment 'hash("see your config file", datetime+fixed-solt+randam(32)) ユーザID識別子 必ず英数混在（OAUTHなどでACCOUNT_IDの数値を外に出したくない場合用）',
	USER_NAME VARCHAR(255) NOT NULL comment 'ユーザ名 基本はメールアドレスの予定',
	PASS VARCHAR(128) NOT NULL comment 'パスワード hash("see your config file", "password" + fixed-solt)',
	USER_NAME_VERIFIED_ID INT UNSIGNED DEFAULT 0 comment 'ACCOUNT_INFO_VERIFIED::VERIFIED_ID USER_NAMEがメールアドレスや電話番号の場合に指定。0ならメールでもSMSでもない',
	TFA_VERIFIED_ID INT UNSIGNED NOT NULL comment 'ACCOUNT_INFO_VERIFIED::VERIFIED_ID 2FAで使う認証済みの情報。TFAを使用しない場合は0。',
	LOGIN_NOTICE_VERIFIED_ID INT UNSIGNED NOT NULL comment 'ACCOUNT_INFO_VERIFIED::VERIFIED_ID ログイン通知で使う認証済みの情報。ログイン通知を使用しない場合は0。',
	LOCKED TINYINT DEFAULT 0 comment '不正利用などでロックする場合1',
	VALID TINYINT DEFAULT 0 comment '有効なアカウントの場合1 退会時の基本は削除なのでここが0になることはあまり考えていない。',
	INSERT_TIME DATETIME NOT NULL comment '登録時間',
	UPDATE_TIME DATETIME NOT NULL comment '更新時間',
	PRIMARY KEY (ACCOUNT_ID),
	INDEX INDEX_ACCOUNT_ID_CHARS (ACCOUNT_ID_CHARS),
	INDEX INDEX_USER_NAME (USER_NAME)
)engine=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1;

-- アカウントでユーザ保持情報として確認されたメールアドレスや電話番号等。
DROP TABLE IF EXISTS ACCOUNT_INFO_VERIFIED;
CREATE TABLE IF NOT EXISTS ACCOUNT_INFO_VERIFIED(
	ACCOUNT_ID BIGINT UNSIGNED NOT NULL comment 'ACCOUNT::ACCOUNT_ID',
	VERIFIED_ID INT UNSIGNED NOT NULL comment 'ACCOUNT_IDの中で一意の情報 1～。 0は他のテーブルからJOIN時に「設定なし」の意味で使用。',
	VERIFIED_INFO_TYPE INT UNSIGNED NOT NULL comment '確認情報 1 メール認証 2 SMS認証',
	VERIFIED_INFO  VARCHAR(255) DEFAULT '' comment 'メールアドレスやSMS電話番号など、VERIFIED_INFO_TYPEによって入れる宛先情報',
	VALID TINYINT DEFAULT 0 comment '有効な認証情報の場合1 使うのをやめた認証情報は0',
	LAST_VALID_TIME DATETIME NOT NULL comment '有効性確認時間',
	INSERT_TIME DATETIME NOT NULL comment '登録時間',
	PRIMARY KEY (ACCOUNT_ID, VERIFIED_ID),
	INDEX INDEX_ACCOUNT_ID (ACCOUNT_ID)
)engine=InnoDB DEFAULT CHARSET=utf8mb4;

-------------------------------------------------------------------------------
--- メール認証／SMS認証 ---
-------------------------------------------------------------------------------

-- 認証用 e-mailやsmsに認証コードを送り、認証させるときに使用 認証済み（データをACCOUNT_INFO_VERIFIEDに移動済み）や期限が過ぎたら削除
DROP TABLE IF EXISTS AUTH_VERIFY;
CREATE TABLE IF NOT EXISTS AUTH_VERIFY(
	ACCOUNT_ID BIGINT UNSIGNED comment 'ACCOUNT::ACCOUNT_ID アカウントがない場合0',
	AUTH_TYPE INT UNSIGNED NOT NULL comment '確認情報 1 メール認証 2 SMS認証',
	AUTH_INFO VARCHAR(255) DEFAULT '' comment 'メールアドレスやSMS電話番号など、AUTH_TYPEによって入れる認証情報',
	VERIFY_CODE CHAR(8) NOT NULL comment '認証コード 8桁の数字とする',
	TEMP_SESSION_KEY CHAR(128) NOT NULL UNIQUE comment 'hash(TLIB_HASH_ALGO_SESS, datetime+fixed-solt+random_bytes(32))',
	FAILED_CNT INT UNSIGNED default 0 COMMENT "認証にトライした数 攻撃チェック 5回失敗で終了",
	EXPIRE_TIME DATETIME NOT NULL comment '有効期限 登録期限 + 30分 認証後この情報を流用する場合に備え、認証後は12時間延長',
	INSERT_TIME DATETIME NOT NULL comment '登録時間',
	INDEX INDEX_TEMP_SESSION_KEY (TEMP_SESSION_KEY)
)engine=InnoDB DEFAULT CHARSET=utf8mb4;

-------------------------------------------------------------------------------
--- アプリケーション登録用 ---
-------------------------------------------------------------------------------

-- OAUTH用 登録されたアプリケーション
DROP TABLE IF EXISTS OAUTH_APPLICATION;
CREATE TABLE IF NOT EXISTS OAUTH_APPLICATION(
	APP_ID BIGINT unsigned AUTO_INCREMENT comment '対象となるOAUTH2でいうところのアプリケーション',
	APP_ID_CHARS CHAR(40) comment 'HASH(tiger160,4) ログインID',
	APP_ID_SECRET CHAR(64) comment 'bin2hex(random_bytes(32)) ログイン パスワード',
	SITE_URL TEXT default '' comment 'アプリのURL あれば',
	APP_INFO JSON default '' comment '{"APP_NAME" : {"ja":xxxx, "en":xxxxx}, "APP_LOGIN_TITLE" : {"ja":xxxx, "en":xxxxx}}',
	SCOPE JSON comment 'SCOPE対象の名前の羅列 ["EMAIL","NAME",等] 使わない',
	PRIMARY KEY (APP_ID),
	INDEX INDEX_APP_ID_CHARS (APP_ID_CHARS),
	CHECK (JSON_VALID(SCOPE))
)engine=InnoDB DEFAULT CHARSET=utf8mb4;

-- OAUTH用 アプリケーション使用承諾ユーザ 「このAPP_IDで許可しているACCOUNT_IDは以下ですよ」的な感じ。本来のOAUTHでは使わない。
DROP TABLE IF NOT EXISTS OAUTH_USER_PERMIT;
CREATE TABLE IF NOT EXISTS OAUTH_USER_PERMIT(
	APP_ID BIGINT unsigned NOT NULL comment 'OAUTH_APPLICATION::APP_ID',
	ACCOUNT_ID BIGINT unsigned NOT NULL comment 'ACCOUNT::ACCOUNT_ID',
	SCOPE JSON comment 'SCOPE対象の名前の羅列 ["EMAIL","NAME",等] 通常はOAUTH_APPLICATION::SCOPEと同じになるはず',
	INDEX INDEX_APP_IACCOUNT_ID (APP_ID, ACCOUNT_ID),
	INDEX INDEX_APP_ID (APP_ID),
	INDEX INDEX_ACCOUNT_ID (ACCOUNT_ID)
)engine=InnoDB DEFAULT CHARSET=utf8mb4;

-- OAUTH用 ユーザログイン ログイン後のCODE受け渡し用
DROP TABLE IF EXISTS OAUTH_CODE_SESSION;
CREATE TABLE IF NOT EXISTS OAUTH_CODE_SESSION(
	APP_ID BIGINT unsigned AUTO_INCREMENT comment 'D_APPLICATION::APP_ID',
	ACCOUNT_ID BIGINT unsigned comment 'ACCOUNT::ACCOUNT_ID',
	CODE VARCHAR(64) comment 'bin2hex(random_bytes(32)) ユーザログイン後にAPPに返す CODE 削除後は""',
	CODE_EXPIRE_TIME DATETIME DEFAULT NOT NULL '登録から5分',
	PRIMARY KEY (CODE)
)engine=InnoDB DEFAULT CHARSET=utf8mb4;

-- OAUTH用 ユーザログイン CODEと交換用 ACCESS_TOKENをSESSIONとして利用も可能
DROP TABLE IF EXISTS OAUTH_SESSION;
CREATE TABLE IF NOT EXISTS OAUTH_SESSION(
	APP_ID BIGINT unsigned AUTO_INCREMENT comment 'D_APPLICATION::APP_ID',
	ACCOUNT_ID BIGINT unsigned comment 'ACCOUNT::ACCOUNT_ID',
	ACCESS_TOKEN VARCHAR(128) UNIQUE comment 'hash("TLIB_HASH_ALGO_SESS", APP_ID + ACCOUNT_ID + time + fixed-solt + random_bytes(32)) APPから求められた時に返すACCESS_TOKEN',
	USER_INFO JSON comment 'ログイン時のユーザの情報{IP:"", COUNTRY:"", etc..} 現状IPのみ',
	EXPIRE_TIME DATETIME DEFAULT NULL comment '1年ぐらい NULLの場合は期限なし',
	INSERT_TIME DATETIME NOT NULL comment 'セッション登録時',
	PRIMARY KEY (ACCESS_TOKEN),
	INDEX INDEX_ACCOUNT_ID (ACCOUNT_ID),
	INDEX INDEX_APP_ID_ACCESS_TOKEN (APP_ID, ACCESS_TOKEN)
)engine=InnoDB DEFAULT CHARSET=utf8mb4;

-------------------------------------------------------------------------------
--- セッション管理 各アプリ側でやるのもOK ---
-------------------------------------------------------------------------------

DROP TABLE IF EXISTS ACCOUNT_SESSION;
CREATE TABLE IF NOT EXISTS ACCOUNT_SESSION(
	APP_ID BIGINT unsigned AUTO_INCREMENT comment 'D_APPLICATION::APP_ID',
	ACCOUNT_ID BIGINT unsigned comment 'ACCOUNT::ACCOUNT_ID',
	SESSION_KEY VARCHAR(128) NOT NULL UNIQUE comment 'hash("TLIB_HASH_ALGO_SESS", APP_ID + ACCOUNT_ID + time + fixed-solt + random_bytes(32))',
	EXPIRE_TIME DATETIME DEFAULT NULL comment '1年ぐらい NULLの場合は期限なし',
	INSERT_TIME DATETIME NOT NULL comment 'セッション登録時',
	INDEX INDEX_APP_ID_ACCOUNT_ID (APP_ID, ACCOUNT_ID),
	INDEX INDEX_APP_ID (APP_ID),
	INDEX INDEX_SESSION_KEY (SESSION_KEY)
) engine=InnoDB DEFAULT CHARSET=utf8mb4;

-------------------------------------------------------------------------------
--- 他 ---
-------------------------------------------------------------------------------

DROP TABLE IF EXISTS ATTACK_CHECK;
CREATE TABLE IF NOT EXISTS ATTACK_CHECK(
	IP_ADDRESS VARBINARY(16) NOT NULL COMMENT "Store IPv4 and IPv6 both. VALUE return from INET6_ATON()",
	ATTACK_CNT INT NOT NULL COMMENT "Count of Suspected Attacks from the IP address.",
	EXPIRED_TIME datetime NOT NULL COMMENT 'This row will be deleted after this time.',
	INSERT_TIME datetime NOT NULL COMMENT 'Insert time',
	INDEX INDEX_IP_ADDRESS (IP_ADDRESS)
) engine=InnoDB DEFAULT CHARSET=utf8mb4;

-------------------------------------------------------------------------------
--- ログ ---
-------------------------------------------------------------------------------

-- ログ
DROP TABLE IF EXISTS ACCOUNT_ACTIVITY;
CREATE TABLE IF NOT EXISTS ACCOUNT_ACTIVITY(
	APP_ID BIGINT unsigned NOT NULL comment 'OAUTH_APPLICATION::APP_ID',
	ACCOUNT_ID BIGINT unsigned NOT NULL comment 'ACCOUNT::ACCOUNT_ID',
	ACTIVITY INT unsigned NOT NULL comment '1 login 2 logout 3 auth start 4 auth checking 5 auth end 6 auth info changed 7 sent login mail / 1001 REGISTER 1002 UNREGISTER',
	IP_ADDRESS VARBINARY(16) NOT NULL COMMENT "Store IPv4 and IPv6 both. INET6_ATON / INET_ATON",
	USER_INFO JSON default '' COMMENT 'ブラウザ識別など 下記コメント',
	NOTE TEXT default '' COMMENT 'ログ内容',
	INSERT_TIME DATETIME NOT NULL comment '記録時間',
	INDEX INDEX_ACCOUNT_ID (ACCOUNT_ID),
	INDEX INDEX_APP_ID (APP_ID)
)engine=InnoDB DEFAULT CHARSET=utf8mb4;

-- USER_INFO JSON information
-- { "USER_AGENT" : "", "COUNTRY" : "", CITY : "", NOTE : "" }

-- ログのパーティション
ALTER TABLE ACCOUNT_ACTIVITY PARTITION BY RANGE (YEAR(INSERT_TIME)) (
	PARTITION y2024 VALUES LESS THAN (2024),
	PARTITION y2025 VALUES LESS THAN (2025),
	PARTITION y2026 VALUES LESS THAN (2026),
	PARTITION y2027 VALUES LESS THAN (2027),
	PARTITION y2028 VALUES LESS THAN (2028),
	PARTITION y2029 VALUES LESS THAN (2029),
	PARTITION y2030 VALUES LESS THAN (2030),
	PARTITION y2031 VALUES LESS THAN (2031),
	PARTITION y2032 VALUES LESS THAN (2032),
	PARTITION y2033 VALUES LESS THAN (2033),
);

-- 追加するとき
-- ALTER TABLE ACCOUNT_ACTIVITY ADD PARTITION BY RANGE (YEAR(INSERT_TIME)) (
-- 	PARTITION p2025 VALUES LESS THAN (2025)
-- );

