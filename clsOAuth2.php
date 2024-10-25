<?php
namespace tlib;

class clsOAuth2 {

	protected const TOKEN_EXPIRES = 90; // 90 days to delte expired records
	protected const HASH_SOLT = 'tlib_oAuth2'; // change this code when you use this class

	protected clsdb $db;
	protected int $tokenExpires;

	public function __construct($db, $tokenExpires = 0) {
		$this->db = $db;
		if ($tokenExpires == 0){
			$this->tokenExpires = self::TOKEN_EXPIRES;
		}else{
			$this->tokenExpires = $tokenExpires;
		}
	}

	///////////////////////////////////////////////////////////////////////////
	// adding client

	/**
	 * Adds a new client to the OAuth2 server.
	 *
	 * @param string $redirect_uri The redirect URI for the client.
	 * @param array $info Additional information about the client like home url and icon image. (optional).
	 * @return string Returns an empty string on success, or an error message on failure.
	 */
	public function addClient(string $redirect_uri, array $info = []): string {

		$client_id_chars = '';
		for ($i = 0; ; $i++) {
			if ($i >= TLIB_MAX_INDEX_GENERATION) { return 'Could not generate a unique client_id after ' . TLIB_MAX_INDEX_GENERATION . ' attempts.'; }

			$client_id_chars = bin2hex(random_bytes(10)); // 20 characters long
			$count = $this->db->getFirstOne('SELECT COUNT(*) FROM OA2_CLIENTS WHERE CLIENT_ID_CHARS = "' . $client_id_chars . '"');
			if ($count === null){ return 'System error : ' . __CLASS__ . ' : ' . __METHOD__ . ' : 01'; }
			if ($count == 0) {
				break;
			}
		}


		$client_secret = '';
		for ($i = 0;; $i++) {
			if ($i >= TLIB_MAX_INDEX_GENERATION) { return 'Could not generate a unique client secret after ' . TLIB_MAX_INDEX_GENERATION . ' attempts.'; }

			$client_secret = util::getRandamCode128(Date('YmdHis'));
			$count = $this->db->getFirstOne('SELECT COUNT(*) FROM OA2_CLIENTS WHERE CLIENT_SECRET = "' . $client_secret . '"');
			if ($count === null){ return 'System error : ' . __CLASS__ . ' : ' . __METHOD__ . ' : 01'; }
			if ($count == 0) {
				break;
			}
		}


		$additional_info = json_encode($info);

		$stmt = $this->db->prepare('INSERT INTO OA2_CLIENTS (CLIENT_ID_CHARS, CLIENT_SECRET, REDIRECT_URI, ADDITIONAL_INFO) VALUES (?, ?, ?, ?)');
		$stmt->bind_param('ssss', $client_id_chars, $client_secret, $redirect_uri, $additional_info);
		$ret = $stmt->execute();
		if (!$ret){
			return 'System Error : ' . __CLASS__ . ' : ' . __METHOD__ . ' : 02';
		}

		return '';
	}

	/**
	 * Modifies the additional information of a client.
	 *
	 * @param string $client_id_chars The client ID.
	 * @param string $client_secret The client secret.
	 * @param array $info The additional information to be modified.
	 * @return string The modified additional information.
	 */
	public function modClientAdditionalInfo(string $client_id_chars, string $client_secret, array $info): string {

		$sql = 'SELECT ADDITIONAL_INFO FROM OA2_CLIENTS WHERE CLIENT_ID_CHARS = "' . $this->db->real_escape_string($client_id_chars) .  '" AND CLIENT_SECRET = "' . $this->db->real_escape_string($client_secret) . '"';
		$ret = $this->db->getFirstOne($sql);
		if ($ret === null) {
			return 'No client found with the provided client_id and client_secret.';
		}

		$additional_info = json_decode($ret, true);
		$additional_info = array_merge($additional_info, $info);

		$additional_info_json = json_encode($additional_info);

		$sql = 'UPDATE OA2_CLIENTS SET ADDITIONAL_INFO = "' . $additional_info_json . '" WHERE CLIENT_ID_CHARS = "' . $this->db->real_escape_string($client_id_chars) . '" AND CLIENT_SECRET = "' . $this->db->real_escape_string($client_secret) . '"';
		$ret = $this->db->query($sql);
		if (!$ret){
			return 'System Error : ' . __CLASS__ . ' : ' . __METHOD__ . ' : 01';
		}

		return '';
	}

	///////////////////////////////////////////////////////////////////////////
	// get authorization code

	/**
	 * Authorizes the client and generates an authorization code.
	 *
	 * @param string $response_type The response type.
	 * @param string $client_id_chars The client ID.
	 * @param string $redirect_uri The redirect URI.
	 * @param string $scope The requested scope.
	 * @param string $state The state parameter. not used in this version.
	 * @param int $user_id The user ID.
	 * @param string &$response_url URL to ridirect with the generated authorization code.
	 * @return string Returns an empty string on success, or an error message on failure.
	 */
	public function authorize(string $response_type, string $client_id_chars, string $redirect_uri, string $scope, string $state, int $user_id, string &$response_url) : string{
		// パラメータの検証
		if ($response_type !== 'code') { return 'Invalid response_type.'; }
		$client_id = $this->isValidClientIdChars($client_id_chars);
		if ($client_id === null) { return 'Invalid client_id.';}
		if (!filter_var($redirect_uri, FILTER_VALIDATE_URL)) { return 'Invalid redirect_uri.'; }

		// Authorization codeの生成
		$authorization_code = $this->generateAuthorizationCode();
		if ($authorization_code == ''){ return 'Failed to generate authorization code.'; }

		// dbに保存
		$stmt = $this->db->prepare('INSERT INTO `OA2_AUTHORIZATION_CODES` (`AUTHORIZATION_CODE`, `CLIENT_ID`, `USER_ID`, `SCOPE`, `EXPIRES`, `UPDATE_TIME`) VALUES (?, ?, ?, ?, DATE_ADD(now(), INTERVAL 1 HOUR)), now()');// 1時間後に有効期限が切れるように設定
        $ret = $stmt->execute([$authorization_code, $client_id, $user_id, $scope]);
		if (!$ret){ return 'System Error : ' . __CLASS__ . ' : ' . __METHOD__ . ' : failed to insert authorization code.'; }

		// リダイレクトするURLを生成
		$params = array(
			'code' => $authorization_code,
			'state' => $state
		);

		$response_url = $redirect_uri . '?' . http_build_query($params);

		return '';
	}

	/**
	 * Checks if the given CLIENT_ID_CHARS is valid. If valid, return the CLIENT_ID.
	 *
	 * @param string $CLIENT_ID_CHARS The client ID characters to validate.
	 * @return null|int Return CLIENT_ID of the $CLIENT_ID_CHARS, otherwise return null if the client doesnt exist.
	 */
	private function isValidClientIdChars(string $CLIENT_ID_CHARS) : null | int {
		return $this->db->getFirstOne('SELECT CLIENT_ID FROM `OA2_CLIENTS` WHERE `CLIENT_ID_CHARS` = "' . $this->db->real_escape_string($CLIENT_ID_CHARS) . '"');
	}

	private function generateAuthorizationCode() : string{

		$authorization_code = '';
		for ($i=0;;$i++) {
			if ($i > TLIB_MAX_INDEX_GENERATION) {
				$authorization_code = '';
				break;
			}

			$authorization_code = util::getRandamCode128();
			$cnt = $this->db->getFirstOne('SELECT COUNT(*) FROM `OA2_AUTHORIZATION_CODES` WHERE `AUTHORIZATION_CODE` = "' . $authorization_code . '"');
			if ($cnt == 0) {
				break;
			}
		}
		return $authorization_code;
	}

	///////////////////////////////////////////////////////////////////////////
	// get access token

	/**
	 * Retrieves an access token.
	 *
	 * @param string $grant_type The grant type.
	 * @param string $client_id The client ID.
	 * @param string $client_secret The client secret.
	 * @param string $redirect_uri The redirect URI.
	 * @param string $auth_code The authorization code.
	 * @param array $retunValue Additional return values.
	 * @return string The access token.
	 */
	public function getAccessToken(string $grant_type, string $client_id, string $client_secret, string $redirect_uri, string $auth_code, array $retunValue) : string{

		// $grant_typeがauthorization_codeでない場合はエラー
		if ($grant_type !== 'authorization_code') { return 'Invalid grant_type'; }

		// まず、クライアントの認証を行います
		if (!$this->authenticateClient($client_id, $client_secret, $redirect_uri)){ return 'Invalid client'; }

		// 次に、認可コードを検証します
		$userInfo = $this->validateAuthorizationCode($auth_code, $client_id);
		if ($userInfo === false) { return 'Invalid authorization code'; }

		// アクセストークンを生成します
		$accessToken = $this->generateAccessToken();
		if ($accessToken === ''){ return 'Failed to generate access token'; }

		// アクセストークンをDBに保存します
		$ret = $this->storeAccessToken($accessToken, $client_id, $userInfo['user_id'], $userInfo['scope']);
		if (!$ret){ return 'Failed to store access token in DB'; }

		$retunValue = array(
			'access_token' => $accessToken,
			'token_type' => 'Bearer',
			'expires_in' => $this->tokenExpires * 24 * 60 * 60,
			'scope' => $userInfo['scope'],
		);

		return '';
	}

	/**
	 * Check the client using the valid client ID, client secret.
	 *
	 * @param string $client_id The client ID.
	 * @param string $client_secret The client secret.
	 * @param string $redirect_uri The optional redirect URI. Not used in this version.
	 * @return bool Returns true if the client is authenticated, false otherwise.
	 */
	private function authenticateClient(string $CLIENT_ID_CHARS, string $client_secret, string $redirect_uri = '') : bool{
		// DBでチェック
		$sql = 'SELECT COUNT(*) FROM `OA2_CLIENTS` WHERE `CLIENT_ID_CHARS` = "' . $this->db->real_escape_string($CLIENT_ID_CHARS) . '" AND `CLIENT_SECRET` = "' . $this->db->real_escape_string($client_secret) . '"';
		$cnt = $this->db->getFirstOne($sql);
		if ($cnt === null){ return false; } // error

		return ($cnt > 0);
	}

	/**
	 * Validates the authorization code against the client ID.
	 *
	 * @param string $auth_code The authorization code to validate.
	 * @param string $client_id The client ID to validate against.
	 * @return array|false Returns an array containing the user_id and scope if successful, or false otherwise.
	 */
	private function validateAuthorizationCode(string $auth_code, string $client_id_chars) : array|false{
		// DBから認可コード情報を取得
		$sql = 'SELECT OAC.USER_ID, OAC.SCOPE FROM `OA2_AUTHORIZATION_CODES` AS OAC
			LEFT JOIN OA2_CLIENTS AS OC ON OC.CLIENT_ID = OAC.CLIENT_ID
			WHERE OAC.AUTHORIZATION_CODE = "' . $this->db->real_escape_string($auth_code) . '" AND OC.CLIENT_ID_CHARS = "' . $this->db->real_escape_string($client_id_chars) . '" AND EXPIRES > now()';
		$authorizationCodeInfo = $this->db->getFirstRow($sql);
		if ($authorizationCodeInfo === null){ return false; } // no data or  error

		// user_idとscopeを返す
		return array(
			'user_id' => $authorizationCodeInfo[0],
			'scope' => $authorizationCodeInfo[1]
		);
	}

	/**
	 * Generates an access token.
	 *
	 * @return string Returns the generated access token. An empty string is returned on failure.
	 */
	private function generateAccessToken() : string {
		$accessToken = '';
		for ($i = 0;;$i++) {
			if ($i > TLIB_MAX_INDEX_GENERATION) { return ''; } // failed to generate access token

			// ランダムなアクセストークンを生成
			$accessToken = util::getRandamCode128();

			// DBから同一のclient_idで同じアクセストークンが存在するかチェック
			$count = $this->db->getFirstOne('SELECT COUNT(*) as count FROM `OA2_ACCESS_TOKENS` WHERE ACCESS_TOKEN = "' . $accessToken . '"');
			if ($count === null){ return ''; } // error

			// 重複するアクセストークンが存在しなければループを抜ける
			if ($count == 0) { break; }

		}

		return $accessToken;
	}

	/**
	 * Stores the access token in the database.
	 *
	 * @param string $accessToken The access token to store.
	 * @param string $client_id The client ID.
	 * @param int $user_id The user ID.
	 * @param string $scope The scope of the access token.
	 * @return bool Returns true if the access token was stored successfully, false otherwise.
	 */
	private function storeAccessToken($accessToken, $client_id, $user_id, $scope) : bool {
		// アクセストークンをDBに保存
		$result = $this->db->query('INSERT INTO `OA2_ACCESS_TOKENS` (ACCESS_TOKEN, CLIENT_ID, USER_ID, SCOPE, EXPIRES)
			VALUES
			("' . $this->db->real_escape_string($accessToken) . '", "' . $this->db->real_escape_string($client_id) . '", "' . $this->db->real_escape_string($user_id) . '", "' . $scope . '", DATE_ADD(now(), INTERVAL ' . intval($this->tokenExpires) . ' DAY))');

		// DBへの保存が成功したかどうかを返す
		return ($result !== false);
	}

	///////////////////////////////////////////////////////////////////////////
	// authenticate user

	/**
	 * Authenticates the user using the provided access token.
	 *
	 * @param array|null $userInfo An array to store the user information if the user is authenticated.
	 * @param bool $bAutoExtend If true, the access token expiration time is extended.
	 * @return bool Returns true if the user is authenticated, false otherwise. when false, http_response_code(401) is called.
	 */
	public function authenticateUser(?array &$userInfo, $bAutoExtend = false) : bool {

		// Authorizationヘッダを取得
		$headers = apache_request_headers();
		if (!isset($headers['Authorization'])){
			http_response_code(401);
			return false;
		}

		$authorization = $headers['Authorization'] ?? '';

		// Bearerトークンを取得
		if (strpos(strtolower($authorization), 'bearer ') === 0) {
			$accessToken = substr($authorization, 7);
		} else {
			http_response_code(401);
			return false;
		}

		// アクセストークンがDBに存在し、有効であることを確認
		$userInfo = $this->db->getFirstRowAssoc('SELECT CLIENT_ID, USER_ID, SCOPE FROM `OA2_ACCESS_TOKENS` WHERE `ACCESS_TOKEN` = "' . $this->db->real_escape_string($accessToken) . '" AND `EXPIRES` > now()');
		if ($userInfo === null) {
			// アクセストークンが存在しないか、有効期限が切れている場合はエラー
			http_response_code(401);
			return false;
		}

		// アクセストークンの延長
		if ($bAutoExtend){
			$this->db->query('UPDATE `OA2_ACCESS_TOKENS` SET `EXPIRES` = DATE_ADD(now(), INTERVAL ' . intval($this->tokenExpires) . ' DAY) WHERE `ACCESS_TOKEN` = "' . $this->db->real_escape_string($accessToken) . '"');
		}
		// エラーでも良い

		// ユーザーが認証された
		return true;
	}

	///////////////////////////////////////////////////////////////////////////
	// 有効期限が切れたレコードの削除

	/**
	 * Deletes expired records. Intended to be executed in a batch or similar process.
	 *
	 * @return bool Returns true if the records are successfully deleted, false otherwise.
	 */
	public function deleteExpiredRecords(): bool {

		// USERS 登録中で1日以上たったら削除
		$sql = 'DELETE FROM USERS WHERE VALID = 0 AND INSERT_TIME < DATE_ADD(now(), INTERVAL -1 DAY)';
		if ($this->db->query($sql) === false){ return false; }

		// USER_VERIFICATIONS
		$sql = 'DELETE FROM USER_VERIFICATIONS_SESSION WHERE EXPIRATION_TIME < NOW() ';
		if ($this->db->query($sql) === false){ return false; }

		// OA2_AUTHORIZATION_CODES
		$sql = 'DELETE FROM OA2_AUTHORIZATION_CODES WHERE EXPIRES < NOW()';
		if ($this->db->query($sql) === false){ return false; }

		// OA2_ACCESS_TOKENS
		$sql = 'DELETE FROM OA2_ACCESS_TOKENS WHERE EXPIRES < NOW()';
		if ($this->db->query($sql) === false){ return false; }

		return true;
	}

}