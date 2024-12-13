/*****************************************************************************/
/*** ユーザ情報 多数の会社に提供するクラウドシステム ***/
/*****************************************************************************/

CREATE TABLE `COMPANIES` (
    `COMPANY_ID` BIGINT UNSIGNED NOT NULL PRIMARY KEY comment '企業ID 1はスーパーユーザ 1000から一般企業ID',
	`COMPANY_ID_CHARS` VARCHAR(128) UNIQUE comment 'hash("sha3-512", datetime+fixed-solt+randam(32)) 企業ID識別子 必ず英数混在（USER_IDの数値を外に出したくない場合用）',
	`COMPANY_ID_CHARS_SHORT` varchar(12) UNIQUE comment '企業ID識別子 短いバージョン',
    `COMPANY_NAME` VARCHAR(255) NOT NULL UNIQUE comment '企業名',
	`MULTI_LANG` TINYINT UNSIGNED DEFAULT 0 comment '多言語対応の場合1',
    `STATUS` TINYINT DEFAULT 0 comment '0 登録中 1 通常 9 削除',
    `INSERT_TIME` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP comment '登録時間',
    `UPDATE_TIME` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP comment '更新時間'
) comment '企業情報';

INSERT INTO COMPANIES (COMPANY_ID, COMPANY_ID_CHARS, COMPANY_NAME, VALID, INSERT_TIME, UPDATE_TIME) VALUES (1, "SUPER_ADMIN_USERS", 'Super Users', 1, now(), now());

/*****************************************************************************/
/*** 企業内ユーザ情報 ***/
/*****************************************************************************/

CREATE TABLE `USERS` (
	`USER_ID` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `COMPANY_ID` BIGINT UNSIGNED NOT NULL comment 'COMPANIES::COMPANY_ID 0はスーパーユーザ',
	'USER_ID_CHARS' VARCHAR(128) UNIQUE comment 'hash("sha3-512", datetime+fixed-solt+randam(32)) ユーザID識別子 必ず英数混在（USER_IDの数値を外に出したくない場合用）',
	'USER_TYPE' TINYINT UNSIGNED DEFAULT 0 comment 'ユーザの種類 0: 一般ユーザ 1: システム管理ユーザ',
	`USER_NAME` VARCHAR(255) NOT NULL comment 'ユーザ名 基本はメールアドレス / 電話番号',
	`PASS` VARCHAR(128) NOT NULL comment 'パスワード hash("sha3-512", "password" + fixed-solt)',
	`VERIFICATION_ID` INT UNSIGNED DEFAULT 0 comment 'USER_VERIFICATIONS::VERIFICATION_ID メインで使用するメール／SMS 0の場合は未設定',
	`LOCKED` TINYINT DEFAULT 0 comment '不正利用などでロックする場合1',
	`VALID` TINYINT DEFAULT 0 comment '有効なアカウントの場合1 登録中は0',
	`INSERT_TIME` TIMESTAMP NOT NULL comment '登録時間',
	`UPDATE_TIME` TIMESTAMP NOT NULL CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP comment '更新時間',
	FOREIGN KEY (`COMPANY_ID`) REFERENCES `COMPANIES`(`COMPANY_ID`) ON DELETE CASCADE,
	INDEX INDEX_USER_ID_CHARS (USER_ID_CHARS),
	INDEX INDEX_COMPANY_ID (COMPANY_ID),
	INDEX INDEX_COMPANY_ID_USER_ID (COMPANY_ID, USER_ID),
	INDEX INDEX_USER_NAME (USER_NAME)
) comment 'ユーザ認証情報';

-- same as USER_VERIFICATIONS in account.sql
CREATE TABLE `USER_VERIFICATIONS` (
    `VERIFICATION_ID` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `USER_ID` BIGINT UNSIGNED NOT NULL comment 'USERS::USER_ID',
    `CONTACT` VARCHAR(255) NOT NULL comment 'メールアドレスまたは電話番号',
    `CONTACT_TYPE` TINYINT UNSIGNED NOT NULL comment '連絡先の種類 1: EMAIL 2: SMS',
	`FLG_MAIN` TINYINT UNSIGNED DEFAULT 0 comment 'メインで使用するメール／SMS 1の場合はメイン',
	`INSERT_TIME` TIMESTAMP NOT NULL comment '登録時間' ON INSERT CURRENT_TIMESTAMP,
    FOREIGN KEY (`USER_ID`) REFERENCES `USERS`(`USER_ID`) ON DELETE CASCADE,
    INDEX INDEX_USER_ID (USER_ID)
) comment 'ユーザの登録しているメールアドレス／SMS';

-- same as USER_VERIFICATIONS_SESSION in account.sql
CREATE TABLE `USER_VERIFICATIONS_SESSION` (
	`USER_ID` BIGINT UNSIGNED NOT NULL comment 'USERS::USER_ID',
	`SESSION_CODE` VARCHAR(128) UNIQUE comment 'hash("sha3-512", datetime+fixed-solt+randam(32))',
	`CONTACT` VARCHAR(255) NOT NULL comment 'メールアドレスまたは電話番号',
	`CONTACT_TYPE` TINYINT UNSIGNED NOT NULL comment '連絡先の種類 1: EMAIL 2: SMS',
	`VERIFICATION_CODE` CHAR(16) comment '認証コード',
	`ADDITIONAL_DATA` JSON DEFAULT JSON_OBJECT() comment 'セッション間でやり取りするオプションのデータ',
	`EXPIRATION_TIME` TIMESTAMP comment '認証コードの有効期限',
	FOREIGN KEY (`USER_ID`) REFERENCES `USERS`(`USER_ID`) ON DELETE CASCADE,
	CHECK (JSON_VALID(ADDITIONAL_DATA)),
	INDEX INDEX_USER_ID (USER_ID),
	INDEX INDEX_SESSION_CODE (SESSION_CODE)
) comment 'ユーザの認証中のセッション管理用';

/*****************************************************************************/
/*** 所属グループ情報 ***/
/*****************************************************************************/

CREATE TABLE `GROUPS` (
	`GROUP_ID` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `COMPANY_ID` BIGINT UNSIGNED NOT NULL comment 'COMPANIES::COMPANY_ID',
	`GROUP_NAMES` JSON NOT NULL comment 'グループ名を多言語で管理 {"en": "English Name", "ja": "日本語名"} ISO639 2 letter code',
	'USER_TYPE' TINYINT UNSIGNED DEFAULT 0 comment 'このグループを使うユーザの種類 0: 一般ユーザ 1: システム管理ユーザ',
	`INSERT_TIME` TIMESTAMP NOT NULL comment '登録時間',
	`UPDATE_TIME` TIMESTAMP NOT NULL comment '更新時間',
	FOREIGN KEY (`COMPANY_ID`) REFERENCES `COMPANIES`(`COMPANY_ID`) ON DELETE CASCADE,
	INDEX INDEX_GROUP_ID (GROUP_ID) comment '組織IDにインデックスを設定',
	INDEX INDEX_COMPANY_ID (COMPANY_ID) comment '企業IDにインデックスを設定',
	INDEX INDEX_COMPANY_ID_GROUP_ID (COMPANY_ID, GROUP_ID) comment '企業IDと組織IDにインデックスを設定'
) comment '組織を定義するテーブル';

CREATE TABLE `ROLES` (
	`ROLE_ID` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY comment '役割ID 0は管理サイトではなくユーザーサイト側の登録ユーザ',
	`COMPANY_ID` BIGINT UNSIGNED NOT NULL comment 'COMPANIES::COMPANY_ID 0は全組織共通の役割',
	`ROLE_NAMES` JSON NOT NULL comment '役割名を多言語で管理 {"en": "English Name", "ja": "日本語名"} ISO639 2 letter code',
	`ROLE_INFOS` JSON DEFAULT JSON_OBJECT() comment '役割の詳細情報 閲覧可能ページ情報など',
	`INSERT_TIME` TIMESTAMP NOT NULL comment '登録時間',
	`UPDATE_TIME` TIMESTAMP NOT NULL comment '更新時間',
	FOREIGN KEY (`COMPANY_ID`) REFERENCES `COMPANIES`(`COMPANY_ID`) ON DELETE CASCADE,
	INDEX INDEX_ROLE_ID (ROLE_ID) comment '役割IDにインデックスを設定'
	INDEX INDEX_COMPANY_ID (COMPANY_ID) comment '企業IDにインデックスを設定',
	INDEX INDEX_COMPANY_ID_ROLE_ID (COMPANY_ID, ROLE_ID) comment '役割IDにインデックスを設定'
) comment '管理サイトでの役割を定義するテーブル';

-- 例えば グループに「請求グループ」で「請求グループ」の出来ることをロールで定義し、ここに入れる。
-- 人単位ではなくグループ単位とする。
CREATE TABLE `GROUP_ROLES` (
	`GROUP_ID` BIGINT UNSIGNED NOT NULL comment 'GROUPS::GROUP_ID',
	`ROLE_ID` BIGINT UNSIGNED NOT NULL comment 'ROLES::ROLE_ID',
	`INSERT_TIME` TIMESTAMP NOT NULL comment '登録時間',
	`UPDATE_TIME` TIMESTAMP NOT NULL comment '更新時間',
	PRIMARY KEY (`GROUP_ID`, `ROLE_ID`),
	FOREIGN KEY (`GROUP_ID`) REFERENCES `GROUPS`(`GROUP_ID`) ON DELETE CASCADE,
	FOREIGN KEY (`ROLE_ID`) REFERENCES `ROLES`(`ROLE_ID`) ON DELETE CASCADE,
	INDEX INDEX_GROUP_ID (GROUP_ID) comment '組織IDにインデックスを設定',
	INDEX INDEX_GROUP_ID_ROLE_ID (GROUP_ID, ROLE_ID) comment '役割IDにインデックスを設定'
) comment '管理ユーザがどの組織にどの役割で所属しているかを定義するテーブル';

CREATE TABLE `GROUP_USERS` (
	`GROUP_ID` BIGINT UNSIGNED NOT NULL comment 'GROUPS::GROUP_ID',
	`USER_ID` BIGINT UNSIGNED NOT NULL comment 'USERS::USER_ID',
	PRIMARY KEY (`GROUP_ID`, `USER_ID`),
	FOREIGN KEY (`GROUP_ID`) REFERENCES `GROUPS`(`GROUP_ID`) ON DELETE CASCADE,
	FOREIGN KEY (`USER_ID`) REFERENCES `USERS`(`USER_ID`) ON DELETE CASCADE,
	INDEX INDEX_GROUP_ID (GROUP_ID) comment '組織IDにインデックスを設定',
	INDEX INDEX_USER_ID (USER_ID) comment 'ユーザIDにインデックスを設定'
) comment 'ユーザがどの組織に所属しているかを定義するテーブル';

/*****************************************************************************/
/*** セッション情報 ***/
/*****************************************************************************/

CREATE TABLE `USER_SESSIONS` (
    `USER_ID` BIGINT UNSIGNED NOT NULL comment 'USERS::USER_ID',
    `SESSION_TOKEN` VARCHAR(255) NOT NULL UNIQUE comment 'セッショントークン sha3-512',
	`SESSION_INFO` JSON DEFAULT JSON_OBJECT() comment 'セッション情報 ログイン時の情報など',
    `EXPIRATION_TIME` TIMESTAMP NOT NULL comment 'セッションの有効期限',
    `INSERT_TIME` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP comment '登録時間',
    FOREIGN KEY (`USER_ID`) REFERENCES `USERS`(`USER_ID`) ON DELETE CASCADE,
    INDEX INDEX_USER_ID (USER_ID),
    INDEX INDEX_SESSION_TOKEN (SESSION_TOKEN)
) comment 'ユーザのセッション情報';

