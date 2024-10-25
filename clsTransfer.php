<?php

namespace tlib;

/**
 * Class clsTransfer
 *
 * This class handles the transfer functionality.
 *
 * [How to use]
 *
 * class clsSample {
 *   private clsTransfer $msg; // Declare the object with "private" access modifier. you can inherit the class.
 *   function __construct() {
 *    $this->msg = new clsTransfer(pathinfo(__FILE__, PATHINFO_FILENAME), __DIR__ . '/locale/');
 *  }
 *
 * function sampleFunction() {
 * 		$translatedText = $this->msg->_('Text to be translated');
 * }
 *
 * [How to make translation file]
 *
 * See the file clsTranslationFileGenerator.php
 *
 */
class clsTransfer {
	const DEFAULT_LANG = 'en'; // ISO639 2 letter code
	const DEFAULT_LANG_FILE_PATH = __DIR__ . '/locale/';
	protected array $translations = [];

	public function __construct(string $fileName, ?string $folderPath=null) {

		if ($folderPath === null) {
			if (defined(TLIB_LANG_FILE_PATH)){
				$folderPath = TLIB_LANG_FILE_PATH;
			}else{
				$folderPath = self::DEFAULT_LANG_FILE_PATH;
			}
		}

		$lang = $GLOBALS['LANG'] ?? self::DEFAULT_LANG;
		$file = $folderPath . '/' . $fileName . '_' . $lang . '.php';

		if (file_exists($file)) {
			$this->translations = include($file);
		}
	}

	public function _($text): string {
		if (isset($this->translations[$text])) {
			return $this->translations[$text];
		}

		return $text;
	}

	public static function getLang($defaultLang = null): string {
		$defaultLang = $defaultLang ?? self::DEFAULT_LANG;

		if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
			return substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
		}

		return $defaultLang;
	}
}