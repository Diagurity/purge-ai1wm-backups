# Diagurity WP Plugins

[Diagurity](https://diagurity.com) で使う、WordPress のセキュリティ強化・検証用プラグイン集。
1 リポジトリに複数プラグインを収録し、複数サイトで版を揃えやすくする。

## 収録プラグイン

| プラグイン | 概要 |
|------------|------|
| [`purge-ai1wm-backups`](purge-ai1wm-backups/) | All-in-One WP Migration が作る `.wpress` を一定時間で自動削除。公開ディレクトリに機密バックアップが残り続けるのを防ぐ。 |

> 既存の検証用プラグインも順次ここへ集約予定。

## 運用

- 各プラグインはフォルダ単位で独立。デプロイは対象ファイルをサイトへコピー（FTP 等）。
- セキュリティ用途で「絶対に止めたくない」ものは `mu-plugins/` に置く（無効化不可）。
- 各プラグインの詳細は、それぞれのフォルダの `README.md` を参照。

## ライセンス

GPL-2.0-or-later
