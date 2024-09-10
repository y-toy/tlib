<?php

/**
 * テンプレートファイルの内容を読み込み、内部変数を反映した結果を返す。
 * 変数:
 *   $this->param['hoge'] = value;
 * テンプレートファイル：
 *   1234 <?= $v['hoge'] ?> 5678
 * 結果：
 *   1234 value 5678
 */
class clsTemplate {

	public array $param;

	function set(string $key, $value) : void { $this->param[$key] = $value; }
	function clear(){ unset($this->param); }

	function getTemplateResult(string $tplFile) : string{
		if (!file_exists($tplFile)){ return ''; }

		// 標準出力をバッファに取り込み
		ob_start();

		$v = $this->param;
		include($tplFile);

		$templateResult = ob_get_contents();
		ob_end_clean();

		return $templateResult;
	}

}