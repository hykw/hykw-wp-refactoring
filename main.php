<?php
  /**
   * @package HYKW Refactoring Plugin
   * @use hykwWPData_url
   * @version 1.2.0
   */
  /*
    Plugin Name: HYKW Refactoring Plugin
    Plugin URI: https://github.com/hykw/hykw-wp-refactoring
    Description: リファクタリング支援プラグイン
    Author: Hitoshi Hayakawa
  */

class hykwRefact
{
  # コマンド(例：http://example.jp/?TEST=save)
  const CMD_SAVE = 'save';
  const CMD_CLEAR = 'clear';
  const CMD_ASSERT = 'assert';

  # enable/disable メソッドで有効/無効にする機能の名前
  const FUNC_LOGGING = 'log';   # syslogへの出力

  private $dir_data;
  private $queryStringKey;
  private $enabled_all;
  private $enabled_funcs;
  private $accessCheck_functionName;

  /**
   * __construct 
   * 
   例）
   <pre>
     $obj = new hykwRefact('/tmp/refact', 'TEST');
       → http://example.jp/archives/1234?TEST=save みたいな引数でアクセス
   </pre>
   * 
   * @param string $dir_data データを保存するディレクトリ
   * @param string $queryStringKey 保存やテストを指定する時のキー
   */
  function __construct($dir_data, $queryStringKey = 'TEST')
  {
    $this->dir_data = $dir_data;
    $this->queryStringKey = $queryStringKey;

    if (!file_exists($dir_data)) {
      mkdir($dir_data, 0755, TRUE);
    }

    $this->enabled_all = TRUE;
    $this->enabled_funcs = Array(
      self::FUNC_LOGGING => 1,
    );
    $this->accessCheck_functionName = FALSE;
  }

  /**
   * doTest テスト関連の機能を呼び出し
   *
   *  http://example.com/?TEST=save のようにキー+コマンドで呼び出す
   *
   * save/loadするファイル名はFQDN+URIをsha1したものだけど、functions.phpの中の値や
   * PC/SPなど同一UAだけど条件によって返ってくる値が違う時に名前がぶつかってしまう。
   * $key_appendedに任意の文字列を指定することで、URLに文字列を付加してsha1を作る
   * 例）$obj->doTest($args, 'pcsite');
   * 
   * @param mixed $args 保存/assertする値
   * @param string $key_appended URLに追加するキー
   * @return boolean TRUE:正常終了,FALSE:何か異常あるいは値の不一致などが発生
   */
  public function doTest($args, $key_appended = '')
  {
    if ($this->enabled_all == FALSE)
      return TRUE;

    if ($this->accessCheck_functionName != FALSE) {
      if (call_user_func($this->accessCheck_functionName) == FALSE)
        return TRUE;
    }

    $fqdn = get_site_url();
    $path = hykwWPData_url::get_requestURL(FALSE);

    # バッチ処理など、これが無いケースもあり得る
    if (!isset($_SERVER['QUERY_STRING']))
      return TRUE;

    parse_str($_SERVER['QUERY_STRING'], $qs);
    if (!isset($qs[$this->queryStringKey]))
      return TRUE;

    $cmd = $qs[$this->queryStringKey];

    # TEST部分を除いたQueryStringを付与
    $url = sprintf('%s%s%s', $fqdn, $path, 
      $this->getQueryStringExcluded($qs, $this->queryStringKey));

    $saveLoadFilename = $this->getReadWriteFileName($url, $key_appended);
    switch($cmd) {
    case self::CMD_SAVE:
      $ret = $this->saveData($saveLoadFilename, $args);
      $this->writeLog($cmd, $url, $key_appended, '');
      return $ret;

    case self::CMD_CLEAR:
      $this->clearDataDir();
      echo "clear";
      $this->writeLog($cmd, '', '', 'all logs are removed');
      exit;

    case self::CMD_ASSERT:
      $ret = $this->loadAndAssert($saveLoadFilename, $args);
      $result = ($ret == TRUE) ? 'OK' : 'fail';
      $this->writeLog($cmd, $url, $key_appended, $result);
      return $ret;

    default:
      return FALSE;
    }

    # just in case
    return FALSE;
  }

  /**
   * getQueryStringExcluded 指定キーを除外したQueryStringを返す
   * 
   * @param array $qs querystring(['KEY1'=>value1, 'KEY2'=>value2, ...])
   * @param string $excludedKey 除外するキー
   * @return string 除外後のQueryString, 空じゃなければ先頭に'?'を付与する(例：'?code=123&type=abc')
   */
  private function getQueryStringExcluded($qs, $excludedKey)
  {
    $queryString = '';
    foreach ($qs as $key => $value) {
      if ($key == $excludedKey)
        continue;

      if ($queryString == '')
        $queryString = '?';
      else
        $queryString .= '&';

      $queryString .= sprintf('%s=%s', $key, $value);
    }

    return $queryString;
  }

  /**
   * clearDataDir データディレクトリの中身をクリアする
   * 
   * @return boolean 実行結果:TRUE=正常終了、FALSE=異常終了
   */
  private function clearDataDir()
  {
    foreach (new DirectoryIterator($this->dir_data) as $fileInfo) {
      if($fileInfo->isDot())
        continue;

      unlink($fileInfo->getPathname());
    }

    return TRUE;
  }

  /**
   * getReadWriteFileName save/loadするファイル名を返す
   * 
   * @param string $url URL
   * @param string $key_appended URLに追加するキー
   * @return string ファイル名
   */
  private function getReadWriteFileName($url, $key_appended)
  {
    $filename_ingredient = $url . $key_appended;

    $ret = sprintf('%s/%s', $this->dir_data, sha1($filename_ingredient));
    return $ret;
  }

  /**
   * saveData データディレクトリに引数をシリアライズして保存
   * 
   * @param string $saveFilename 保存するファイル名
   * @param mixed $args 保存する値
   * @return boolean TRUE:正常終了、FALSE:異常終了
   */
  private function saveData($saveFilename, $args)
  {
    $fp = fopen($saveFilename, 'w');
    if ($fp == FALSE)
      return FALSE;

    $str = serialize($args);
    for ($written = 0; $written < strlen($str); $written += $fwrite) {
      $fwrite = fwrite($fp, substr($str, $written));
      if ($fwrite === FALSE)
        return FALSE;
    }

    fclose($fp);
    return TRUE;
  }


  /**
   * LoadAndassert 引数とファイルに保存されていた値を比較
   * 
   * @param string $loadFilename 読み込むファイル
   * @param mixed $argsCompared 比較対象の値
   * @return boolean TRUE:一致、FALSE: 不一致もしくはエラー
   */
  private function LoadAndAssert($loadFilename, $argsCompared)
  {
    if (!file_exists($loadFilename)) {
      echo sprintf('File not found: %s', $loadFilename);
      return FALSE;
    }

    $loadValue = file_get_contents($loadFilename);
    if ($loadValue == FALSE)
      return FALSE;

    $savedArgs = unserialize($loadValue);

    # === じゃなくて == で比較すること
    $compareResult = ($argsCompared == $savedArgs);
    if ($compareResult == TRUE)
      return TRUE;

    # 一致しない。キーごとに比較
    echo <<<EOL
<!DOCTYPE html>
<html lang="ja" dir="ltr">
<head>
<meta charset="UTF-8">
</head>
<body>
<pre>

EOL;

    # array以外は単に出力して終わりにしちゃう
    if (gettype($argsCompared) != 'array') {
      echo "expected:\n";
      echo htmlspecialchars($argsCompared) . "\n\n";

      echo "saved value:\n";
      echo htmlspecialchars($savedArgs);

      return FALSE;
    }

    $retFlag = TRUE;
    foreach ($argsCompared as $key => $value) {
      // 連想配列じゃなくて、単なる配列の場合。いったん、単に出力するだけにする
      if ($key == '') {
        echo "expected:\n";
        echo print_r($argsCompared) . "\n\n";

        echo "saved value:\n";
        echo print_r($savedArgs);

        return FALSE;
      }

      if ($value != $savedArgs[$key]) {
        $retFlag = FALSE;

        echo sprintf("[%s]\n", $key);

        # xdiffが入ってない環境のため、直接diffを呼び出す
        $diff = $this->getDiffString(htmlspecialchars($value), htmlspecialchars($savedArgs[$key]));
        echo $diff;
      }
    }

    return $retFlag;
  }

  /**
   * getDiffString diff(1)で文字列を比較した結果を返す
   * 
   * @param string $from 比較元
   * @param string $to 比較先
   * @return string diff結果
   */
  private function getDiffString($from, $to)
  {
    $tmp_from = tempnam($this->dir_data, 'EXPECTED_');
    $fp_from = fopen($tmp_from, 'w');
    fputs($fp_from, $from);
    fclose($fp_from);

    $tmp_to = tempnam($this->dir_data, 'SAVED_');
    $fp_to = fopen($tmp_to, 'w');
    fputs($fp_to, $to);
    fclose($fp_to);

    $diff = shell_exec(sprintf('diff -u %s %s', $tmp_from, $tmp_to));

    unlink($tmp_from);
    unlink($tmp_to);

    return $diff;
  }

  /**
   * writeLog 引数をsyslogに出力する
   * 
   * @param string $cmd コマンド名
   * @param string $url URL
   * @param string $key_appended URLに追加するキー
   * @param string $msg 追加メッセージ
   */
  private function writeLog($cmd, $url = '', $key_appended = '', $msg = '')
  {
    if ($this->enabled_funcs[self::FUNC_LOGGING] == 0)
      return;

    $log = sprintf('[hykwRefact] %s: ', $cmd);
    switch ($cmd)
    {
    case self::CMD_CLEAR:
      $log .= $msg;
      break;
    case self::CMD_SAVE:
    case self::CMD_ASSERT:
      $log .= sprintf('%s%s %s', $url, $key_appended, $msg);
      break;
    }
     
    syslog(LOG_INFO, $log);
  }

  /**
   * enable 機能の有効/無効設定
   * 
   * @param mixed $funcName 有効/無効にする機能の名前（空なら全機能が対象）
   */
  public function enable($funcName = '')
  {
    if ($funcName == '') {
      $this->enabled_all = TRUE;
      return;
    }

    $this->enabled_funcs[$funcName] = 1;
  }

  public function disable($funcName = '')
  {
    if ($funcName == '') {
      $this->enabled_all = FALSE;
      return;
    }

    $this->enabled_funcs[$funcName] = 0;
  }

  public function registerAccessCheck($funcName)
  {
    $this->accessCheck_functionName = $funcName;
  }


}


