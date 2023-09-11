-- アカウント認証に関する情報以外は含まない 顧客情報については、アプリ側で管理のこと
-- 複数言語対応する箇所については、カラムにJSON形式で{"ja":"日本","en":"Japan"}のように指定する。
-- 言語コードはISO 639-1 (http Content-Languageに同じ)

-------------------------------------------------------------------------------
--- ユーザアカウント ---
-------------------------------------------------------------------------------

-- アカウント情報
DROP TABLE IF EXISTS ACCOUNT;
CREATE TABLE IF NOT EXISTS ACCOUNT(
	ACCOUNT_ID BIGINT unsigned AUTO_INCREMENT comment 'ユーザ識別',
	ACCOUNT_ID_CHARS CHAR(64) UNIQUE comment 'hash("sha3-256", datetime+randam()) ユーザID識別子 必ず英数混在（OAUTHなどで推測される数値を外に出したくない場合用）',
	PASS CHAR(64) NOT NULL comment 'hash("sha3-256", "password")',
	TFA TINYINT unsigned DEFAULT 0 comment '2段階認証を行うか 1 行う 0 行わない',
	TFA_AUTH_ID INT unsigned comment 'ACCOUNT_AUTH::AUTH_ID 2段認証時に使うAUTH_ID TFAが0の場合は0',
	LOGIN_NOTICE TINYINT unsigned DEFAULT 0 comment 'ログイン時に通知するか 1 する 0 しない',
	LOGIN_NOTICE_AUTH_ID TINYINT unsigned DEFAULT 0 comment 'ACCOUNT_AUTH::AUTH_ID ログイン通知に使用するAUTH_ID',
	LAST_LOGIN_TIME DATETIME comment '最後のログイン時間',
	VALID TINYINT DEFAULT 0 comment '有効なアカウントの場合1 退会などの場合は0',
	INSERT_TIME DATETIME NOT NULL comment '登録時間',
	UPDATE_TIME DATETIME NOT NULL comment '更新時間',
	PRIMARY KEY (ACCOUNT_ID),
	INDEX INDEX_ACCOUNT_ID_CHARS (ACCOUNT_ID_CHARS)
)engine=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1;

-- 認証に使用する情報 将来的にはFidoなどにも対応したいが保管情報がわからないので特に何もしない・・
DROP TABLE IF EXISTS ACCOUNT_AUTH;
CREATE TABLE NOT EXISTS ACCOUNT_AUTH(
	ACCOUNT_ID BIGINT unsigned comment 'ACCOUNT::ACCOUNT_ID',
	AUTH_ID INT unsigned comment 'Index用 1～',
	ID_TYPE TINYINT unsigned comment '1 e-mail 2 SMS 99 任意文字列(英字必須 & 特殊文字NG)',
	ID_INFO VARCHAR(128) NOT NULL comment 'ID_TYPE=1の場合 e-mailアドレス 2の場合 電話番号 99の場合任意文字列 ',
	INSERT_TIME DATETIME NOT NULL comment '登録時間',
	VALID TINYINT unsigned comment '1 有効 0 無効',
	PRIMARY KEY (ACCOUNT_ID, AUTH_ID),
	INDEX INDEX_ACCOUNT_ID (ACCOUNT_ID),
	INDEX ID_TYPE_INFO(ID_TYPE, ID_INFO)
)engine=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1;

-- 認証用 e-mailやsmsに認証コードを送り、システム上に入力されたら認証 認証が終わったら削除
DROP TABLE IF EXISTS AUTH_VERIFY;
CREATE TABLE IF NOT EXISTS AUTH_VERIFY(
	ACCOUNT_ID BIGINT unsigned comment 'ACCOUNT::ACCOUNT_ID アカウントがない場合0',
	AUTH_ID INT unsigned comment 'ACCOUNT_AUTH::AUTH_ID 認証を送ったemail/SMSの参考用',
	VERIFY_CODE VARCHAR(12) NOT NULL comment '認証コード',
	TEMP_SESSION_KEY CHAR(64) NOT NULL UNIQUE comment 'hash("sha3-256", ID_INFO+random_bytes(32))',
	PASS CHAR(64) DEFAULT NULL comment 'アカウントがない初期登録時に使用 hash("sha3-256", "password") アカウント作成時にコピー',
	EXPIRE_TIME DATETIME NOT NULL comment '登録期限 30分',
	INSERT_TIME DATETIME NOT NULL comment '登録時間',
	INDEX INDEX_ID_INFO (ID_INFO),
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
	CODE CHAR(64) comment 'bin2hex(random_bytes(32)) ユーザログイン後にAPPに返す CODE 削除後は""',
	CODE_EXPIRE_TIME DATETIME DEFAULT NOT NULL '登録から5分',
	PRIMARY KEY (CODE)
)engine=InnoDB DEFAULT CHARSET=utf8mb4;

-- OAUTH用 ユーザログイン CODEと交換用 ACCESS_TOKENをSESSIONとして利用も可能
DROP TABLE IF EXISTS OAUTH_SESSION;
CREATE TABLE IF NOT EXISTS OAUTH_SESSION(
	APP_ID BIGINT unsigned AUTO_INCREMENT comment 'D_APPLICATION::APP_ID',
	ACCOUNT_ID BIGINT unsigned comment 'ACCOUNT::ACCOUNT_ID',
	ACCESS_TOKEN CHAR(64) comment 'hash("sha3-256", APP_ID + ACCOUNT_ID + time+random_bytes(32)) APPから求められた時に返すACCESS_TOKEN',
	USER_INFO JSON comment 'ログイン時のユーザの情報{IP:"", COUNTRY:"", etc..} 現状IPのみ',
	LAST_CHECK_TIME DATETIME NOT NULL comment '最後にSESSIONをチェックした時間',
	EXPIRE_TIME DATETIME DEFAULT NULL comment '1年ぐらい NULLの場合は期限なし',
	INSERT_TIME DATETIME NOT NULL comment 'セッション登録時',
	PRIMARY KEY (ACCESS_TOKEN),
	INDEX INDEX_ACCOUNT_ID (ACCOUNT_ID),
	INDEX INDEX_APP_ID_ACCESS_TOKEN (APP_ID, ACCESS_TOKEN)
)engine=InnoDB DEFAULT CHARSET=utf8mb4;

-------------------------------------------------------------------------------
--- 他 ---
-------------------------------------------------------------------------------

DROP TABLE IF EXISTS ATTACK_CHECK;
CREATE TABLE IF NOT EXISTS ATTACK_CHECK(
	IP_ADDRESS VARBINARY(16) NOT NULL COMMENT "Store IPv4 and IPv6 both. INET6_ATON()で返された値",
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
	ACCOUNT_ID BIGINT unsigned NOT NULL comment 'ACCOUNT::ACCOUNT_ID',
	APP_ID BIGINT unsigned NOT NULL comment 'OAUTH_APPLICATION::APP_ID',
	ACTIVITY INT unsigned NOT NULL comment '1 login 2 logout 3 auth start 4 auth checking 5 auth end / 1001 REGISTER 1002 UNREGISTER',
	IP_ADDRESS VARBINARY(16) NOT NULL COMMENT "Store IPv4 and IPv6 both. INET6_ATON / INET_ATON",
	USER_INFO JSON default '' COMMENT 'ブラウザ識別など 下記コメント',
	INSERT_TIME DATETIME NOT NULL comment '記録時間',
	INDEX INDEX_ACCOUNT_ID (ACCOUNT_ID),
	INDEX INDEX_APP_ID (APP_ID)
)engine=InnoDB DEFAULT CHARSET=utf8mb4;

-- USER_INFO JSON information
-- { "USER_AGENT" : "", "COUNTRY" : "", CITY : "", NOTE : "" }

-- ログのパーティション
ALTER TABLE ACCOUNT_ACTIVITY PARTITION BY RANGE (YEAR(INSERT_TIME)) (
	PARTITION y2022 VALUES LESS THAN (2022),
	PARTITION y2023 VALUES LESS THAN (2023),
	PARTITION y2024 VALUES LESS THAN (2024)
);

-- 追加するとき
-- ALTER TABLE ACCOUNT_ACTIVITY ADD PARTITION BY RANGE (YEAR(INSERT_TIME)) (
-- 	PARTITION p2025 VALUES LESS THAN (2025)
-- );

