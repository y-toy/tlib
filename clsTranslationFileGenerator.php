<?php

namespace tlib;

/**
 * Class clsTranslationFileGenerator
 *
 * This class is responsible for generating translation files.
 *
 * [How to use]
 * $generator = new TranslationFileGenerator(['en', 'ja']);
 * $generator->generate('/path/to/php/files');
 *
 * Files with "locale" in the folder name will be skipped.
 *
 */
class clsTranslationFileGenerator
{

	protected array $langCodes;
	protected array $excludedFolders;

	public function __construct(array $langCodes, array $excludedFolders = []) {
		$this->langCodes = $langCodes;
		$this->excludedFolders = array_map(function($folder) {
			return rtrim($folder, '/') . '/';
		}, $excludedFolders);
	}

	public function generate($inputFolder, ?string $outputFolder = null): bool {

		$inputFolder = rtrim($inputFolder, '/') . '/';
		if (!file_exists($inputFolder)){ return false; }
		if (in_array($inputFolder, $this->excludedFolders)) { return true; }

		$paraOutputFolder = $outputFolder; // Reassign during recursion.
		if ($outputFolder === null) { $outputFolder = $inputFolder . 'locale/'; }

		$files = glob($inputFolder . '*');

		foreach ($files as $file) {
			// Skip if the file is in the output folder
			if (strpos($file, $outputFolder) !== false) {
				continue;
			}
			// skip if the file path contains 'locale'
			if (strpos(dirname($file), 'locale') !== false) {
				continue;
			}

			// If it's a directory, process recursively
			if (is_dir($file)) {
				$this->generate($file, $paraOutputFolder);
				continue;
			}

			// if it's not a php file, skip
			if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
				continue;
			}

			$baseName = pathinfo($file, PATHINFO_FILENAME);
			$content = $this->removeCommentsFromPhpFile($file);
			preg_match_all("/->\s*_\s*\(\s*(\"(?:\\\\\"|[^\"])*\"|'(?:\\\\'|[^'])*')\s*\)/U", $content, $matches);

			foreach ($this->langCodes as $langCode) {
				$translationFile = "{$this->outputFolder}/{$baseName}_{$langCode}.php";
				$translations = file_exists($translationFile) ? include($translationFile) : [];

				foreach ($matches[1] as $match) {
					// Remove the surrounding quotes and unescape internal quotes
					$match = substr($match, 1, -1);
					$match = str_replace(["\\'", '\\"'], ["'", '"'], $match);

					if (!isset($translations[$match])) {
						$translations[$match] = '';
					}
				}

				// Don't output the file if there are no translations
				if (empty($translations)) {
					continue;
				}

				// The folder will be created after the output is determined
				if (!file_exists($outputFolder)) { mkdir($outputFolder); }

				$output = "<?php\n\nreturn " . var_export($translations, true) . ";\n";
				// Remove extra backslashes
				$output = str_replace('\\\\', '\\', $output);
				file_put_contents($translationFile, $output);
			}
		}
	}

	private function removeCommentsFromPhpFile($filePath) {
		$code = file_get_contents($filePath);
		$tokens = token_get_all($code);
		$output = '';

		foreach ($tokens as $token) {
			if (is_array($token)) {
				// Ignore comments and doc comments
				if ($token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
					$output .= $token[1];
				}
			} else {
				$output .= $token;
			}
		}

		return $output;
	}
}
