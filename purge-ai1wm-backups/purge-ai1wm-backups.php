<?php
/**
 * Plugin Name: .wpress 自動削除（All-in-One WP Migration バックアップ）
 * Description: All-in-One WP Migration が作る .wpress を、最後の書き込みから一定時間後に削除する。公開ディレクトリに置かれたままになる時間を短くするため。mu-plugins/ でも plugins/ でも動作し、削除に失敗したときは管理画面に通知する。
 * Version:     1.1.0
 * Author:      Diagurity
 * License:     GPL-2.0-or-later
 *
 * 置き場所（どちらでも動く）:
 *   - 絶対に止めたくない場合（推奨・セキュリティ用途）:
 *       wp-content/mu-plugins/purge-ai1wm-backups.php
 *       mu-plugins は有効化の操作が不要で、テーマやプラグインの切り替えでも外れない。
 *       管理画面から無効化もできない。
 *   - 管理画面で管理したい場合:
 *       wp-content/plugins/purge-ai1wm-backups/ ごと置いて有効化。
 *
 * なぜ必要か:
 *   .wpress はサイト全体（DB + ファイル一式）で、DB にはユーザーとパスワード
 *   ハッシュ、ファイル側には wp-config.php（DB認証情報と認証ソルト）が入る。
 *   All-in-One WP Migration は管理画面のダウンロードボタンをファイルURLの直リンクで
 *   作っているため、バックアップは公開されたままになる。アクセスを遮断すると
 *   管理画面から落とせなくなるので、代わりに「置かれている時間」を短くする。
 *
 *   無料版の手動エクスポートには保持期間による自動削除は無い（保持機能は有料の
 *   Backup 拡張のスケジュールバックアップ向け）。手動運用ではこのプラグインで補う。
 *
 * 実行のきっかけは2つ:
 *   1. WP-Cron（10分ごと）
 *   2. 管理画面の読み込み（5分に1回まで）
 *   過去に wp-cron.php 自体が停止したサイトがあるため、cron だけに頼らず
 *   管理画面側からも動くようにしている。
 */

defined( 'ABSPATH' ) || exit;

/* mu-plugins と plugins の両方に置かれても二重実行しないための保険 */
if ( defined( 'WPRESS_PURGE_LOADED' ) ) {
	return;
}
define( 'WPRESS_PURGE_LOADED', '1.1.0' );

/**
 * 削除までの猶予（秒）。既定は 1 時間。
 * 変えたいときは wp-config.php で define( 'WPRESS_PURGE_TTL', 7200 ); のように書く。
 */
if ( ! defined( 'WPRESS_PURGE_TTL' ) ) {
	define( 'WPRESS_PURGE_TTL', 3600 );
}

/* 削除に失敗したファイルを覚えておくオプション名（管理画面通知に使う） */
define( 'WPRESS_PURGE_FAIL_OPTION', 'wpress_purge_failures' );

/**
 * .wpress を消す本体
 *
 * 書き込み中のファイルは更新時刻が進み続けるので、エクスポートの途中で
 * 消されることはない。削除に失敗したファイルは覚えておき、管理画面に通知する
 * （黙って溜まり続ける事故を防ぐため）。
 *
 * @return int 削除した件数
 */
function wpress_purge_now() {
	$dir = defined( 'AI1WM_BACKUPS_PATH' )
		? AI1WM_BACKUPS_PATH
		: WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'ai1wm-backups';

	if ( ! is_dir( $dir ) ) {
		delete_option( WPRESS_PURGE_FAIL_OPTION );
		return 0;
	}

	$files = glob( trailingslashit( $dir ) . '*.wpress' );
	if ( ! is_array( $files ) ) {
		$files = array();
	}

	$now     = time();
	$deleted = array();
	$failed  = array();

	foreach ( $files as $file ) {
		if ( ! is_file( $file ) ) {
			continue;
		}

		$mtime = filemtime( $file );
		if ( ! $mtime || ( $now - $mtime ) < WPRESS_PURGE_TTL ) {
			continue;
		}

		if ( @unlink( $file ) ) {
			$deleted[] = basename( $file );
		} else {
			$failed[] = basename( $file );
		}
	}

	/* 削除失敗の記録は毎回上書き（権限が直れば次回の実行で自動的に消える） */
	if ( $failed ) {
		update_option(
			WPRESS_PURGE_FAIL_OPTION,
			array(
				'files' => $failed,
				'time'  => $now,
			),
			false
		);
		error_log( sprintf(
			'[wpress-purge] .wpress の削除に失敗しました（%d 件）: %s',
			count( $failed ),
			implode( ', ', $failed )
		) );
	} else {
		delete_option( WPRESS_PURGE_FAIL_OPTION );
	}

	if ( $deleted ) {
		error_log( sprintf(
			'[wpress-purge] .wpress を削除しました（%d 件）: %s',
			count( $deleted ),
			implode( ', ', $deleted )
		) );
	}

	return count( $deleted );
}

/* ------------------------------------------------------------------
   きっかけ 1: WP-Cron（10分ごと）
------------------------------------------------------------------ */

add_filter( 'cron_schedules', function ( $schedules ) {
	if ( ! isset( $schedules['wpress_purge_ten_minutes'] ) ) {
		$schedules['wpress_purge_ten_minutes'] = array(
			'interval' => 600,
			'display'  => '10分ごと（.wpress 自動削除）',
		);
	}
	return $schedules;
} );

add_action( 'init', 'wpress_purge_schedule' );

/** cron を予約する（未予約のときだけ） */
function wpress_purge_schedule() {
	if ( ! wp_next_scheduled( 'wpress_purge_event' ) ) {
		wp_schedule_event( time() + 600, 'wpress_purge_ten_minutes', 'wpress_purge_event' );
	}
}

add_action( 'wpress_purge_event', 'wpress_purge_now' );

/* 通常プラグインとして使うとき用（mu-plugins では発火しないので無害） */
register_activation_hook( __FILE__, 'wpress_purge_schedule' );
register_deactivation_hook( __FILE__, function () {
	$timestamp = wp_next_scheduled( 'wpress_purge_event' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'wpress_purge_event' );
	}
	wp_clear_scheduled_hook( 'wpress_purge_event' );
} );

/* ------------------------------------------------------------------
   きっかけ 2: 管理画面の読み込み（5分に1回まで）
   wp-cron が止まっていても動くようにするための保険
------------------------------------------------------------------ */

add_action( 'admin_init', function () {
	if ( get_transient( 'wpress_purge_ran' ) ) {
		return;
	}
	set_transient( 'wpress_purge_ran', 1, 300 );

	wpress_purge_now();
} );

/* ------------------------------------------------------------------
   削除に失敗したときの管理画面通知
   パーミッション等で消せず、黙って溜まり続けるのを防ぐ
------------------------------------------------------------------ */

add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$record = get_option( WPRESS_PURGE_FAIL_OPTION );
	if ( empty( $record['files'] ) || ! is_array( $record['files'] ) ) {
		return;
	}

	$list = implode( ', ', array_map( 'esc_html', $record['files'] ) );

	echo '<div class="notice notice-error"><p>'
		. '<strong>.wpress（バックアップ）の自動削除に失敗しました。</strong><br>'
		. 'ai1wm-backups ディレクトリの書き込み権限をご確認ください（FTP 等で手動削除も可）。<br>'
		. '対象: ' . $list
		. '</p></div>';
} );
