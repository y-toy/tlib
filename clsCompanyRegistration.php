<?php

namespace tlib;

/**
 * Class clsCompanyRegistration
 *
 * This class is responsible for registering a company. mainly for the DB table "COMPANIES".
 *
 */

class clsCompanyRegistration{
	protected clsDB $db;
	private clsTransfer $msg;

    public function __construct(myDB &$db) {
        $this->db = $db;
		$this->msg = new clsTransfer(pathinfo(__FILE__, PATHINFO_FILENAME), __DIR__ . '/locale/');
    }

	/**
	 * Initiates the company registration process.
	 *
	 * @param string $companyName The name of the company to be registered.
	 * @param int &$companyId A reference to the variable where the company ID will be stored.
	 * @param string &$companyIdChars A reference to the variable where the company ID characters will be stored.
	 * @return string A status message indicating the result of the registration process.
	 */
	public function registerCompanyStart(string $companyName, int &$companyId, string &$companyIdChars): string {
		// 企業名のチェック
		if (strlen($companyName) == 0) { return $this->msg->_('Company name cannot be empty.'); }

		// 企業名は被って良い。

		// COMPANY_ID_CHARSの生成
		$companyIdChars = '';
		for ($i = 0;; $i++) {
			if ($i >= TLIB_MAX_INDEX_GENERATION) {
				return $this->msg->_('Could not generate a unique COMPANY ID after ' . $i . ' attempts.');
			}

			$companyIdChars = $this->generateRandomString(128);
			$sql = 'SELECT count(*) FROM COMPANIES WHERE COMPANY_ID_CHARS = "' . $companyIdChars . '"';
			$ret = $this->db->getFirstOne($sql);
			if ($ret === null) {
				return $this->msg->_('System error occurred when generating COMPANY ID.');
			}
			if ($ret == 0) {
				break;
			}
		 }

		// COMPANY_ID_CHARS_SHORTの生成
		$companyIdCharsShort = '';
		for ($i = 0;; $i++) {
			if ($i >= TLIB_MAX_INDEX_GENERATION) {
				return $this->msg->_('Could not generate a unique short COMPANY ID after ' . $i . ' attempts.');
			}

			$companyIdCharsShort = util::makeRandStr(12);
			$sql = 'SELECT count(*) FROM COMPANIES WHERE COMPANY_ID_CHARS_SHORT = "' . $companyIdCharsShort . '"';
			$ret = $this->db->getFirstOne($sql);
			if ($ret === null) {
				return $this->msg->_('System error occurred when generating short COMPANY ID.');
			}
			if ($ret == 0) {
				break;
			}
		}

		 // COMPANY_IDの生成
		$sql = 'SELECT MAX(COMPANY_ID) FROM COMPANIES';
		$maxCompanyId = $this->db->getFirstOne($sql);
		if ($maxCompanyId === null) {
			return $this->msg->_('System error occurred when generating COMPANY ID.');
		}
		$companyId = max(1000, $maxCompanyId + 1);

		// 企業情報の登録
		$sql = 'INSERT INTO COMPANIES (COMPANY_ID, COMPANY_ID_CHARS, COMPANY_ID_CHARS_SHORT, COMPANY_NAME, STATUS, INSERT_TIME, UPDATE_TIME)
				VALUES (' . $companyId . ', "' . $companyIdChars . '", "' . $companyIdCharsShort . '", "' . $this->db->real_escape_string($companyName) . '", 0, NOW(), NOW())';
		$ret = $this->db->query($sql);
		if (!$ret) {
			return $this->msg->_('System error occurred when inserting company data. please try again.');
		}

		return '';
	}

	public function registerCompanyDone(int $companyId): string {
		// 企業情報の更新
		$sql = 'UPDATE COMPANIES SET STATUS = 1, UPDATE_TIME = NOW() WHERE COMPANY_ID = ' . $companyId;
		$ret = $this->db->query($sql);
		if (!$ret) {
			return $this->msg->_('System error occurred when updating company data.');
		}

		return '';
	}

	public function deleteCompany(int $companyId): string {
		// トランザクション開始
		$this->db->begin_transaction();

		try {
			// USERSテーブルのユーザを削除
			$sql = 'DELETE FROM USERS WHERE COMPANY_ID = ' . $companyId;
			$ret = $this->db->query($sql);
			if (!$ret) {
				throw new \Exception($this->msg->_('System error occurred when deleting users.'));
			}

			 // GROUPSテーブルのグループを削除
			$sql = 'DELETE FROM GROUPS WHERE COMPANY_ID = ' . $companyId;
			$ret = $this->db->query($sql);
			if (!$ret) {
				throw new \Exception($this->msg->_('System error occurred when deleting groups.'));
			}

			// ROLESテーブルの役割を削除
			$sql = 'DELETE FROM ROLES WHERE COMPANY_ID = ' . $companyId;
			$ret = $this->db->query($sql);
			if (!$ret) {
				throw new \Exception($this->msg->_('System error occurred when deleting roles.'));
			}

			// COMPANIESテーブルの企業を削除
			$sql = 'DELETE FROM COMPANIES WHERE COMPANY_ID = ' . $companyId;
			$ret = $this->db->query($sql);
			if (!$ret) {
				throw new \Exception($this->msg->_('System error occurred when deleting company.'));
			}

			// トランザクションコミット
			$this->db->commit();

		} catch (\Exception $e) {
			$this->db->rollback();
			return $e->getMessage();
		}

		return '';
	}
}

