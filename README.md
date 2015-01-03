# WordPress リファクタリング支援プラグイン

URLに?TEST={コマンド名}を付与してアクセスすることで、下記を行うことができます。

- 引数で指定したオブジェクトをファイルに保存
- 引数で指定したオブジェクトと、保存したオブジェクトを比較して差分を表示
- 保存したオブジェクトをクリア

リファクタリング前後の値を比較することで、入力に対して出力が変化していないことを確認することが出来ます。

## 【ロジックの埋め込み方法】

### functions.phpでインスタンスを作成
引数はオブジェクトを保存するディレクトリです。無ければ作ります。
第二引数は各機能を呼び出すときのキーです。省略するとTESTになります(例：http://example.jp/?TEST=clear)


```php
$gRefactoring = new hykwRefact('/tmp/refactoring');

# 呼び出し引数を FQDN/?REFACT=save のように、REFACTに変更
$gRefactoring = new hykwRefact('/tmp/refactoring', 'REFACT');
```

### 引数を保存・確認したい場所で下記を呼び出す

引数は保存・確認する値です（保存なのか確認なのかは、URLの引数により決まります）。

保存・比較対象の値は、URLを元に生成されます。同一URL = 同一ファイル名となるため、例えばPC/SPとで返す値などが異なるなど、URLだけで判断すると都合がわるい時には第二引数（省略可能）を指定してください。

```php
$GLOBALS['gRefactoring']->doTest($args);

# キーを追加する場合
$GLOBALS['gRefactoring']->doTest($args, 'PC');
```

## 【呼び出し方法】
URLの末尾にTEST={コマンド}を付与してアクセスするだけです。

### コマンド
- save: 引数をファイルに保存します
- clear: ファイルに保存したデータをクリアします
- assert: 引数と、ファイルに保存した値を比較します

### 例
```html
http://example.jp/?TEST=clear
http://example.jp/archives/12345?TEST=save
http://example.jp/category/01/03?TEST=assert
```

## 【依存プラグイン】
内部で hykw-wpdata(https://github.com/hykw/hykw-wpdata)を利用しています。

## その他機能
### 個別/全機能のenable/disable

enable/disable メソッドを呼び出すことで、機能のon/offが可能です（引数省略で全機能が対象）

```php
$gobj->enable();
$gobj->disable();         // 全機能を無効にする（= 何もしない）
$gobj->disable('log');    // syslogに実行結果を出力しない(default: enable)
```

