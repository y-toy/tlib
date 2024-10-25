CREATE TABLE `OA2_CLIENTS` (
  `CLIENT_ID` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY comment 'クライアントID > 0. 0 means public client',
  `CLIENT_ID_CHARS` VARCHAR(80) NOT NULL,
  `CLIENT_SECRET` VARCHAR(128) NOT NULL,
  `REDIRECT_URI` VARCHAR(2000) NOT NULL,
  `ADDITIONAL_INFO` JSON,
  INDEX `INDEX_CLIENT_ID_CHARS` (`CLIENT_ID_CHARS`)
) comment 'アプリケーション登録用';

CREATE TABLE `OA2_AUTHORIZATION_CODES` (
  `ID` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `AUTHORIZATION_CODE` VARCHAR(128) NOT NULL comment '認可コード sha3-512',
  `CLIENT_ID` INT UNSIGNED NOT NULL comment 'OA2_CLIENTS::CLIENT_ID',
  `USER_ID` INT UNSIGNED NOT NULL,
  `SCOPE` VARCHAR(256) default '',
  `EXPIRES` TIMESTAMP NOT NULL,
  `UPDATE_TIME` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `AUTHORIZATION_CODE` (`AUTHORIZATION_CODE`),
  INDEX `CLIENT_ID` (`CLIENT_ID`),
  FOREIGN KEY (`CLIENT_ID`) REFERENCES `OA2_CLIENTS`(`ID`),
  FOREIGN KEY (`USER_ID`) REFERENCES `USERS`(`USER_ID`) ON DELETE CASCADE
) comment '認可コード発行用';

CREATE TABLE `OA2_ACCESS_TOKENS` (
  `ID` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ACCESS_TOKEN` VARCHAR(128) NOT NULL comment 'アクセストークン sha3-512',
  `CLIENT_ID` INT UNSIGNED NOT NULL comment 'OA2_CLIENTS::CLIENT_ID',
  `USER_ID` INT UNSIGNED NOT NULL,
  `SCOPE` VARCHAR(256) DEFAULT '',
  `EXPIRES` TIMESTAMP NOT NULL,
  INDEX `ACCESS_TOKEN` (`ACCESS_TOKEN`),
  INDEX `CLIENT_ID` (`CLIENT_ID`),
  FOREIGN KEY (`CLIENT_ID`) REFERENCES `OA2_CLIENTS`(`ID`),
  FOREIGN KEY (`USER_ID`) REFERENCES `USERS`(`USER_ID`) ON DELETE CASCADE
) COMMENT 'アクセストークン発行用';

-- CREATE TABLE `REFRESH_TOKENS` (
--   `ID` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
--   `REFRESH_TOKEN` VARCHAR(128) NOT NULL,
--   `CLIENT_ID` INT UNSIGNED NOT NULL,
--   `USER_ID` INT UNSIGNED NOT NULL,
--   `SCOPE` VARCHAR(256) DEFAULT '',
--   `EXPIRES` TIMESTAMP NOT NULL,
--   INDEX `REFRESH_TOKEN` (`REFRESH_TOKEN`),
--   INDEX `CLIENT_ID` (`CLIENT_ID`),
--   FOREIGN KEY (`CLIENT_ID`) REFERENCES `OA2_CLIENTS`(`ID`),
--   FOREIGN KEY (`USER_ID`) REFERENCES `USERS`(`USER_ID`) ON DELETE CASCADE
-- ) COMMENT 'リフレッシュトークン発行用';

