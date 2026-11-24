# Purge AI1WM Backups

All-in-One WP Migration（AI1WM）の拡張（ServMask 社の公式品ではない）。AI1WM が作る `.wpress` を、最後の書き込みから指定した時間がたったら削除する。

## なぜ必要か

`.wpress` はサイト全体（DB + ファイル一式）のアーカイブ。DB にはユーザーとパスワードハッシュ、ファイル側には `wp-config.php`（DB 認証情報・認証ソルト）が含まれる。AI1WM は管理画面のダウンロードを **ファイル URL の直リンク**で提供するため、バックアップは公開ディレクトリに置かれたままになる。アクセス遮断すると管理画面から落とせなくなるので、代わりに「置かれている時間」を短くする。

無料版の手動エクスポートには保持期間による自動削除が無い（保持機能は有料 Backup 拡張のスケジュールバックアップ向け）。手動運用ではこのプラグインで補う。

## 設置

1 ファイルでどちらでも動く。

- **絶対に止めたくない（推奨・セキュリティ用途）**
  `wp-content/mu-plugins/purge-ai1wm-backups.php` に置く。有効化操作は不要・無効化不可。
  （`mu-plugins` ディレクトリが無ければ作る）
- **管理画面で管理したい**
  `wp-content/plugins/purge-ai1wm-backups/` ごと置いて有効化する。

## 言語

管理画面の文言は英語で書いてあり、日本語は `languages/purge-ai1wm-backups-ja.po`（`.mo` は `msgfmt` で作る）。
サイトの言語（ユーザーの言語）が日本語なら日本語で、それ以外は英語で出る。

- `plugins/` に置いたときは、フォルダごと置けば `languages/` も一緒に入る
- `mu-plugins/` に1ファイルで置いたときは、日本語にしたければ `wp-content/mu-plugins/purge-ai1wm-backups/languages/` に `.mo` を置く。置かなければ英語で出る
- 文言を変えたら `.po` を直して `msgfmt -o languages/purge-ai1wm-backups-ja.mo languages/purge-ai1wm-backups-ja.po`

## 設定

管理画面の **設定 → バックアップ自動削除**（英語の画面では Settings → Purge AI1WM Backups） で、削除までの時間を分・時間・日で指定する（10分〜30日、既定1時間）。
プラグイン一覧の「設定」リンクからも開ける（mu-plugins に置いたときは一覧にリンクが出ないので、設定メニューから）。
画面には、いま残っている `.wpress` の件数・合計サイズ・次の自動削除までの時間と、ファイルごとの消える目安が出る。
そこから1件ずつ、またはまとめて手で削除もできる（確認ダイアログあり。消せるのはバックアップの置き場所の直下にある `*.wpress` だけ）。

`wp-config.php` で決めた値は管理画面より優先され、画面では変えられなくなる。

```php
define( 'WPRESS_PURGE_TTL', 7200 ); // 2 時間（秒）
```

削除先は `AI1WM_BACKUPS_PATH`（未定義なら `wp-content/ai1wm-backups`）。
プラグインを削除すると、設定（`wpress_purge_ttl`）と記録も消える。

## 動作

- WP-Cron（10 分ごと）と管理画面読み込み（5 分に 1 回まで）の両方で走る。cron が止まっても管理画面側で動く。
- 削除対象は `ai1wm-backups` 内の `*.wpress` のみ。
- 書き込み中のファイルは mtime が進むので、エクスポート途中では消えない。
- 削除に失敗（パーミッション等）した場合は管理画面に通知し、`error_log` にも記録する。問題が直れば通知は自動で消える。

## 配布用の zip

`sh bin/zip.sh` で `dist/purge-ai1wm-backups-<版>.zip` ができる。中身はコミット済みのファイルだけ（`.git`・`.gitignore`・`bin/` は入らない）。管理画面の「プラグインを追加 → プラグインのアップロード」にそのまま渡せる。

## ライセンス

Copyright (C) 2026 Diagurity (https://diagurity.com/)

GNU General Public License version 2、またはそれ以降の版（GPL-2.0-or-later）で配布する。全文は `LICENSE`。
