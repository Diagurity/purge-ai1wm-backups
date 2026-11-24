<?php
/**
 * Plugin Name: Purge AI1WM Backups
 * Description: Automatically deletes All-in-One WP Migration backups (.wpress) once the time you set has passed. Set the time under Settings → Purge AI1WM Backups. Not an official ServMask extension.
 * Version:     1.5.0
 * Author:      Diagurity
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: purge-ai1wm-backups
 * Domain Path: /languages
 *
 * 置き場所（どちらでも動く）:
 *   - 絶対に止めたくない場合（推奨・セキュリティ用途）:
 *       wp-content/mu-plugins/purge-ai1wm-backups.php
 *       mu-plugins は有効化の操作が不要で、テーマやプラグインの切り替えでも外れない。
 *       管理画面から無効化もできない。（プラグイン一覧の「設定」リンクは出ないので、
 *       設定画面へは「設定 → AI1WM バックアップ自動削除」から入る）
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
 * 削除までの時間の決まり方（上が優先）:
 *   1. wp-config.php の define( 'WPRESS_PURGE_TTL', 秒数 );（設定画面では変えられなくなる）
 *   2. 管理画面の設定（オプション wpress_purge_ttl、秒）
 *   3. 既定の 1 時間
 *
 * 時間を数え始める時刻:
 *   ファイルの最後の書き込み時刻。ただし、このプラグインが動き始める前から
 *   あったファイルは、動き始めた時刻から数える（有効化した瞬間に古いバックアップが
 *   まとめて消えないように）。通常プラグインは有効化するたびに、mu-plugins は
 *   初めて動いたときに、その時刻をオプション wpress_purge_since に記録する。
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
define( 'WPRESS_PURGE_LOADED', '1.5.0' );

/*
 * 画面の文言は英語で書き、日本語は languages/ の翻訳ファイルに置く。
 * mu-plugins に1ファイルで置いたときは、隣の purge-ai1wm-backups/languages/ も探す。
 * translate.wordpress.org などの翻訳（WP_LANG_DIR）があれば WordPress がそちらを先に読む。
 */
add_action( 'init', function () {
	$mo = 'purge-ai1wm-backups-' . determine_locale() . '.mo';
	foreach ( array( __DIR__ . '/languages/', __DIR__ . '/purge-ai1wm-backups/languages/' ) as $dir ) {
		if ( is_readable( $dir . $mo ) ) {
			load_textdomain( 'purge-ai1wm-backups', $dir . $mo );
			return;
		}
	}
}, 0 );

/* 削除に失敗したファイルを覚えておくオプション名（管理画面通知に使う） */
define( 'WPRESS_PURGE_FAIL_OPTION', 'wpress_purge_failures' );

/* 削除までの時間（秒）を入れておくオプション名 */
define( 'WPRESS_PURGE_TTL_OPTION', 'wpress_purge_ttl' );

/* プラグインが動き始めた時刻（UNIX 秒）を入れておくオプション名 */
define( 'WPRESS_PURGE_SINCE_OPTION', 'wpress_purge_since' );

/* 設定画面のスラッグ */
define( 'WPRESS_PURGE_PAGE', 'purge-ai1wm-backups' );

/* 削除までの時間の既定・下限・上限（秒） */
define( 'WPRESS_PURGE_TTL_DEFAULT', 3600 );     // 1 時間
define( 'WPRESS_PURGE_TTL_MIN', 600 );          // 10 分（cron の間隔より短くしても意味がない）
define( 'WPRESS_PURGE_TTL_MAX', 30 * 86400 );   // 30 日

/**
 * 削除までの時間（秒）
 *
 * @return int
 */
function wpress_purge_ttl() {
	if ( defined( 'WPRESS_PURGE_TTL' ) ) {
		return max( 60, (int) WPRESS_PURGE_TTL );
	}

	$ttl = (int) get_option( WPRESS_PURGE_TTL_OPTION, WPRESS_PURGE_TTL_DEFAULT );
	if ( $ttl < WPRESS_PURGE_TTL_MIN || $ttl > WPRESS_PURGE_TTL_MAX ) {
		$ttl = WPRESS_PURGE_TTL_DEFAULT;
	}
	return $ttl;
}

/**
 * プラグインが動き始めた時刻（UNIX 秒）
 *
 * まだ記録が無ければ（mu-plugins に置いて初めて動いたとき、1.4.0 以前から
 * 更新したときなど）、いまの時刻を記録する。
 *
 * @return int
 */
function wpress_purge_since() {
	$since = (int) get_option( WPRESS_PURGE_SINCE_OPTION, 0 );
	if ( $since <= 0 ) {
		$since = time();
		update_option( WPRESS_PURGE_SINCE_OPTION, $since );
	}
	return $since;
}

/**
 * ファイルが自動削除される時刻（UNIX 秒）
 *
 * 最後の書き込みから削除までの時間がたったとき。ただしプラグインが動き始める前から
 * あったファイルは、動き始めた時刻から数える。
 *
 * @param int $mtime ファイルの最後の書き込み時刻。
 * @return int
 */
function wpress_purge_due( $mtime ) {
	return max( (int) $mtime, wpress_purge_since() ) + wpress_purge_ttl();
}

/**
 * バックアップの置き場所
 *
 * @return string
 */
function wpress_purge_dir() {
	return defined( 'AI1WM_BACKUPS_PATH' )
		? AI1WM_BACKUPS_PATH
		: WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'ai1wm-backups';
}

/**
 * All-in-One WP Migration が有効か
 *
 * @return bool
 */
function wpress_purge_ai1wm_active() {
	return defined( 'AI1WM_PLUGIN_NAME' ) || class_exists( 'Ai1wm_Main_Controller' );
}

/**
 * 秒数を「1時間」「1日と2時間」「30分」のような表記にする
 *
 * @param int $seconds 秒数。
 * @return string
 */
function wpress_purge_format_duration( $seconds ) {
	$seconds = max( 0, (int) $seconds );
	$days    = intdiv( $seconds, 86400 );
	$hours   = intdiv( $seconds % 86400, 3600 );
	$minutes = intdiv( $seconds % 3600, 60 );

	$parts = array();
	if ( $days ) {
		/* translators: %d: number of days */
		$parts[] = sprintf( _n( '%d day', '%d days', $days, 'purge-ai1wm-backups' ), $days );
	}
	if ( $hours ) {
		/* translators: %d: number of hours */
		$parts[] = sprintf( _n( '%d hour', '%d hours', $hours, 'purge-ai1wm-backups' ), $hours );
	}
	if ( $minutes || ! $parts ) {
		/* translators: %d: number of minutes */
		$parts[] = sprintf( _n( '%d minute', '%d minutes', $minutes, 'purge-ai1wm-backups' ), $minutes );
	}
	return implode( _x( ', ', 'between parts of a duration', 'purge-ai1wm-backups' ), $parts );
}

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
	$dir = wpress_purge_dir();

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
		if ( ! $mtime || $now < wpress_purge_due( $mtime ) ) {
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
			'[wpress-purge] Could not delete .wpress files (%d): %s',
			count( $failed ),
			implode( ', ', $failed )
		) );
	} else {
		delete_option( WPRESS_PURGE_FAIL_OPTION );
	}

	if ( $deleted ) {
		error_log( sprintf(
			'[wpress-purge] Deleted .wpress files (%d): %s',
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
			'display'  => 'Every 10 minutes (.wpress auto-delete)',
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
register_activation_hook( __FILE__, 'wpress_purge_activate' );

/**
 * 有効化したとき: cron を予約し、時間を数え始める時刻をいまにする
 *
 * 無効にしていた間に置かれたバックアップも、有効化した瞬間には消さない。
 */
function wpress_purge_activate() {
	update_option( WPRESS_PURGE_SINCE_OPTION, time() );
	wpress_purge_schedule();
}
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'wpress_purge_event' );
} );
register_uninstall_hook( __FILE__, 'wpress_purge_uninstall' );

/** 削除したときに設定と記録を消す */
function wpress_purge_uninstall() {
	delete_option( WPRESS_PURGE_TTL_OPTION );
	delete_option( WPRESS_PURGE_SINCE_OPTION );
	delete_option( WPRESS_PURGE_FAIL_OPTION );
	delete_transient( 'wpress_purge_ran' );
	delete_transient( 'purge_ai1wm_backups_update_info' );
	wp_clear_scheduled_hook( 'wpress_purge_event' );
}

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
   設定画面（設定 → AI1WM バックアップ自動削除）
------------------------------------------------------------------ */

add_action( 'admin_init', function () {
	register_setting(
		'wpress_purge',
		WPRESS_PURGE_TTL_OPTION,
		array(
			'type'              => 'integer',
			'sanitize_callback' => 'wpress_purge_sanitize_ttl',
			'default'           => WPRESS_PURGE_TTL_DEFAULT,
		)
	);
} );

/**
 * 設定画面から来た「数値＋単位」を秒にして、範囲に収める
 *
 * @param mixed $value 送られてきた値（数値の欄。単位は別の欄から読む）。
 * @return int 秒
 */
function wpress_purge_sanitize_ttl( $value ) {
	/* 設定画面以外（update_option の直呼び）から秒で来たときは、そのまま範囲だけ見る */
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- options.php が nonce を確かめたあとに呼ばれる
	if ( ! isset( $_POST['wpress_purge_ttl_unit'] ) ) {
		$seconds = (int) $value;
	} else {
		$units   = array(
			'minute' => 60,
			'hour'   => 3600,
			'day'    => 86400,
		);
		$unit    = sanitize_key( wp_unslash( $_POST['wpress_purge_ttl_unit'] ) );
		$unit    = isset( $units[ $unit ] ) ? $unit : 'hour';
		/*
		 * 数値は $value ではなく送信そのものから読む。オプションがまだ無いとき、
		 * WordPress はこの関数を2回呼ぶ（update_option → add_option）。$value を使うと
		 * 2回目は秒にした値にもう一度単位を掛けてしまう。
		 */
		$amount  = isset( $_POST[ WPRESS_PURGE_TTL_OPTION ] ) ? wp_unslash( $_POST[ WPRESS_PURGE_TTL_OPTION ] ) : $value;
		$seconds = (int) round( (float) $amount * $units[ $unit ] );
	}

	/*
	 * 初回の保存ではこの関数が2回呼ばれるので、同じ知らせを2つ出さないよう、
	 * すでに積んであれば足さない。
	 */
	$already = wp_list_filter( get_settings_errors( WPRESS_PURGE_TTL_OPTION ), array( 'code' => 'wpress_purge_ttl_range' ) );

	if ( ( $seconds < WPRESS_PURGE_TTL_MIN || $seconds > WPRESS_PURGE_TTL_MAX ) && ! $already ) {
		add_settings_error(
			WPRESS_PURGE_TTL_OPTION,
			'wpress_purge_ttl_range',
			sprintf(
				/* translators: 1: shortest time, 2: longest time */
				__( 'Set a time between %1$s and %2$s. The value was changed to fit that range and saved.', 'purge-ai1wm-backups' ),
				wpress_purge_format_duration( WPRESS_PURGE_TTL_MIN ),
				wpress_purge_format_duration( WPRESS_PURGE_TTL_MAX )
			)
		);
	}

	return min( WPRESS_PURGE_TTL_MAX, max( WPRESS_PURGE_TTL_MIN, $seconds ) );
}

add_action( 'admin_menu', function () {
	add_options_page(
		'Purge AI1WM Backups',
		_x( 'Purge AI1WM Backups', 'settings menu', 'purge-ai1wm-backups' ),
		'manage_options',
		WPRESS_PURGE_PAGE,
		'wpress_purge_render_page'
	);
} );

/* プラグイン一覧の「無効化／削除」の横に「設定」を出す */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	$links[] = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'options-general.php?page=' . WPRESS_PURGE_PAGE ) ),
		esc_html__( 'Settings', 'purge-ai1wm-backups' )
	);
	return $links;
} );

/* ------------------------------------------------------------------
   設定画面の見た目
   1ファイルで mu-plugins にも置けるよう、CSS はこのファイルの中に持ち、
   この画面を開いたときだけ読み込む。
------------------------------------------------------------------ */

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'settings_page_' . WPRESS_PURGE_PAGE !== $hook ) {
		return;
	}
	wp_register_style( 'wpress-purge-admin', false, array(), WPRESS_PURGE_LOADED );
	wp_enqueue_style( 'wpress-purge-admin' );
	wp_add_inline_style( 'wpress-purge-admin', wpress_purge_admin_css() );
} );

/** 設定画面の CSS */
function wpress_purge_admin_css() {
	return <<<'CSS'
.wpp {
	--wpp-accent: var(--wp-admin-theme-color, #2271b1);
	--wpp-line: #dcdcde;
	--wpp-muted: #646970;
	--wpp-ok: #00a32a;
	--wpp-ok-soft: #edfaef;
	--wpp-warn: #996800;
	--wpp-warn-soft: #fcf9e8;
	--wpp-danger: #b32d2e;
	--wpp-danger-soft: #fcf0f1;
	max-width: 900px;
	font-size: 13px;
}
.wpp-hero {
	display: flex;
	gap: 16px;
	align-items: center;
	margin: 16px 0 20px;
	padding: 24px 28px;
	border-radius: 12px;
	color: #fff;
	background: linear-gradient(135deg, #0e7490, #1d4ed8);
	box-shadow: 0 6px 20px rgba(14, 116, 144, 0.25);
}
.wpp-hero > .dashicons {
	flex: none;
	width: 52px;
	height: 52px;
	font-size: 30px;
	line-height: 52px;
	text-align: center;
	border-radius: 14px;
	background: rgba(255, 255, 255, 0.18);
}
.wpp-hero h1 {
	margin: 0 0 4px;
	padding: 0;
	color: #fff;
	font-size: 22px;
	font-weight: 700;
}
.wpp-hero p {
	margin: 0;
	color: rgba(255, 255, 255, 0.88);
	font-size: 14px;
}
.wpp-hero .wpp-badge {
	display: inline-block;
	margin-top: 8px;
	padding: 3px 10px;
	border-radius: 999px;
	background: rgba(255, 255, 255, 0.18);
	color: #fff;
	font-size: 12px;
}
.wpp-stats {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 12px;
	margin-bottom: 12px;
}
.wpp-card {
	margin-bottom: 12px;
	padding: 20px 22px;
	border: 1px solid var(--wpp-line);
	border-radius: 10px;
	background: #fff;
	box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}
.wpp-stat-label {
	display: flex;
	align-items: center;
	gap: 6px;
	color: var(--wpp-muted);
}
.wpp-stat-label .dashicons {
	color: var(--wpp-accent);
	font-size: 18px;
	width: 18px;
	height: 18px;
}
.wpp-stat-value {
	margin-top: 6px;
	font-size: 22px;
	font-weight: 700;
	line-height: 1.3;
}
.wpp-card-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 6px;
}
.wpp-card-title {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0;
	font-size: 15px;
}
.wpp-card-title .dashicons {
	color: var(--wpp-accent);
}
.wpp-note {
	margin: 0;
	color: var(--wpp-muted);
}
.wpp-ttl {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 10px;
	margin-top: 14px;
}
.wpp-ttl input[type="number"] {
	width: 90px;
	font-size: 18px !important;
	font-weight: 600;
	text-align: right;
}
.wpp-chips {
	display: flex;
	gap: 6px;
}
.wpp-chip input {
	position: absolute;
	opacity: 0;
}
.wpp-chip span {
	display: inline-block;
	padding: 7px 16px;
	border: 1px solid var(--wpp-line);
	border-radius: 999px;
	background: #f6f7f7;
	cursor: pointer;
	transition: background 0.15s, border-color 0.15s;
}
.wpp-chip input:checked + span {
	border-color: var(--wpp-accent);
	background: var(--wpp-accent);
	color: #fff;
}
.wpp-chip input:focus-visible + span {
	outline: 2px solid var(--wpp-accent);
	outline-offset: 2px;
}
.wpp-chip input:disabled + span {
	cursor: not-allowed;
	opacity: 0.6;
}
.wpp-lock {
	margin: 12px 0 0;
	padding: 8px 12px;
	border-radius: 6px;
	background: var(--wpp-warn-soft);
	color: var(--wpp-warn);
}
.wpp .submit {
	margin: 4px 0 20px;
	padding: 0;
}
.wpp-flash {
	display: flex;
	align-items: center;
	gap: 6px;
	margin: 0 0 12px;
	padding: 10px 14px;
	border-radius: 8px;
	font-weight: 600;
}
.wpp-flash.is-ok {
	background: var(--wpp-ok-soft);
	color: #00661b;
}
.wpp-flash.is-error {
	background: var(--wpp-danger-soft);
	color: #8a2424;
}
.wpp-flash.is-warn {
	background: var(--wpp-warn-soft);
	color: var(--wpp-warn);
	font-weight: 400;
}
.wpp-files {
	margin: 14px 0 0;
	padding: 0;
	list-style: none;
}
.wpp-file {
	display: flex;
	align-items: center;
	gap: 12px;
	margin: 0;
	padding: 12px 0;
	border-top: 1px solid #f0f0f1;
}
.wpp-file:first-child {
	border-top: 0;
}
.wpp-file > .dashicons {
	flex: none;
	color: var(--wpp-muted);
}
.wpp-file-main {
	flex: 1;
	min-width: 0;
}
.wpp-file-name {
	display: block;
	overflow: hidden;
	font-weight: 600;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.wpp-file-meta {
	color: var(--wpp-muted);
}
.wpp-pill {
	flex: none;
	padding: 3px 10px;
	border-radius: 999px;
	background: #f0f6fc;
	color: var(--wpp-accent);
	font-size: 12px;
	white-space: nowrap;
}
.wpp-pill.is-due {
	background: var(--wpp-warn-soft);
	color: var(--wpp-warn);
}
.wpp .button.wpp-delete {
	display: inline-flex;
	flex: none;
	align-items: center;
	gap: 3px;
	min-height: 26px;
	padding: 0 8px;
	border-color: var(--wpp-danger);
	font-size: 12px;
	line-height: 1;
	color: var(--wpp-danger);
}
.wpp .button.wpp-delete:hover,
.wpp .button.wpp-delete:focus {
	border-color: var(--wpp-danger);
	background: var(--wpp-danger-soft);
	color: var(--wpp-danger);
}
.wpp .button.wpp-delete .dashicons,
.wpp .button.wpp-delete .dashicons::before {
	display: block;
	width: 14px;
	height: 14px;
	margin: 0;
	font-size: 14px;
	line-height: 14px;
}
.wpp-empty {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 14px 0 0;
	padding: 14px;
	border: 1px dashed var(--wpp-line);
	border-radius: 8px;
	color: var(--wpp-muted);
}
.wpp-empty .dashicons {
	color: var(--wpp-ok);
}
.wpp-foot {
	margin: 12px 0 0;
}
@media (max-width: 782px) {
	.wpp-stats {
		grid-template-columns: 1fr;
	}
	.wpp-file {
		flex-wrap: wrap;
	}
}
CSS;
}

/* ------------------------------------------------------------------
   一覧からの手動削除（1件・すべて）
------------------------------------------------------------------ */

add_action( 'admin_post_wpress_purge_delete', 'wpress_purge_handle_delete' );

/**
 * 一覧の「削除」「すべて削除」を受けて消す
 *
 * 消せるのはバックアップの置き場所の直下にある *.wpress だけ。送られてきた
 * ファイル名は basename に落とし、realpath で置き場所の中にあることを確かめる。
 */
function wpress_purge_handle_delete() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to delete backups.', 'purge-ai1wm-backups' ), 403 );
	}
	check_admin_referer( 'wpress_purge_delete' );

	$dir     = wpress_purge_dir();
	$realdir = is_dir( $dir ) ? realpath( $dir ) : false;
	$targets = array();

	if ( $realdir ) {
		if ( ! empty( $_POST['all'] ) ) {
			$targets = glob( trailingslashit( $realdir ) . '*.wpress' );
			$targets = is_array( $targets ) ? $targets : array();
		} elseif ( isset( $_POST['file'] ) ) {
			$name = basename( (string) wp_unslash( $_POST['file'] ) );
			if ( preg_match( '/\.wpress$/i', $name ) ) {
				$targets[] = trailingslashit( $realdir ) . $name;
			}
		}
	}

	$deleted = array();
	$failed  = array();

	foreach ( $targets as $target ) {
		$real = realpath( $target );
		if ( ! $real || dirname( $real ) !== $realdir || ! is_file( $real ) ) {
			continue;
		}
		if ( @unlink( $real ) ) {
			$deleted[] = basename( $real );
		} else {
			$failed[] = basename( $real );
		}
	}

	if ( $deleted ) {
		error_log( sprintf(
			'[wpress-purge] Deleted %d .wpress file(s) from the settings screen: %s',
			count( $deleted ),
			implode( ', ', $deleted )
		) );
	}

	wp_safe_redirect( add_query_arg(
		array(
			'page'           => WPRESS_PURGE_PAGE,
			'wpress_deleted' => count( $deleted ),
			'wpress_failed'  => count( $failed ),
		),
		admin_url( 'options-general.php' )
	) );
	exit;
}

/* ------------------------------------------------------------------
   設定画面
------------------------------------------------------------------ */

/**
 * 残っている .wpress（新しい順）
 *
 * @return array[] name, size, mtime
 */
function wpress_purge_list_files() {
	$dir   = wpress_purge_dir();
	$paths = is_dir( $dir ) ? glob( trailingslashit( $dir ) . '*.wpress' ) : array();
	$files = array();

	foreach ( is_array( $paths ) ? $paths : array() as $path ) {
		if ( ! is_file( $path ) ) {
			continue;
		}
		$files[] = array(
			'name'  => basename( $path ),
			'size'  => (int) filesize( $path ),
			'mtime' => (int) filemtime( $path ),
		);
	}

	usort( $files, function ( $a, $b ) {
		return $b['mtime'] - $a['mtime'];
	} );

	return $files;
}

/** 設定画面を出す */
function wpress_purge_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$ttl    = wpress_purge_ttl();
	$locked = defined( 'WPRESS_PURGE_TTL' );
	$files  = wpress_purge_list_files();
	$now    = time();

	/* 欄に出す値は、割り切れるいちばん大きい単位で */
	if ( 0 === $ttl % 86400 ) {
		$amount = $ttl / 86400;
		$unit   = 'day';
	} elseif ( 0 === $ttl % 3600 ) {
		$amount = $ttl / 3600;
		$unit   = 'hour';
	} else {
		$amount = (int) round( $ttl / 60 );
		$unit   = 'minute';
	}

	$total = 0;
	$next  = null;
	foreach ( $files as $file ) {
		$total += $file['size'];
		$due    = wpress_purge_due( $file['mtime'] );
		$next   = null === $next ? $due : min( $next, $due );
	}

	if ( null === $next ) {
		$next_label = __( 'Nothing to delete', 'purge-ai1wm-backups' );
	} elseif ( $next > $now ) {
		/* translators: %s: remaining time */
		$next_label = sprintf( __( 'in about %s', 'purge-ai1wm-backups' ), wpress_purge_format_duration( $next - $now ) );
	} else {
		$next_label = __( 'at the next check', 'purge-ai1wm-backups' );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 削除の結果を表示するだけ
	$deleted_count = isset( $_GET['wpress_deleted'] ) ? absint( $_GET['wpress_deleted'] ) : 0;
	$failed_count  = isset( $_GET['wpress_failed'] ) ? absint( $_GET['wpress_failed'] ) : 0;
	// phpcs:enable

	$units = array(
		'minute' => __( 'minutes', 'purge-ai1wm-backups' ),
		'hour'   => __( 'hours', 'purge-ai1wm-backups' ),
		'day'    => __( 'days', 'purge-ai1wm-backups' ),
	);
	?>
	<div class="wrap wpp">
		<div class="wpp-hero">
			<span class="dashicons dashicons-trash" aria-hidden="true"></span>
			<div>
				<h1>Purge AI1WM Backups</h1>
				<p><?php esc_html_e( 'Backups (.wpress) made by an AI1WM export stay in a public folder of the site, and anyone who knows the URL can download them. They contain the whole site, including the database and wp-config.php, so this plugin deletes them automatically once the time you set has passed.', 'purge-ai1wm-backups' ); ?></p>
				<span class="wpp-badge"><?php esc_html_e( 'This plugin is an add-on for All-in-One WP Migration (AI1WM). It is not an official ServMask extension.', 'purge-ai1wm-backups' ); ?></span>
			</div>
		</div>

		<?php if ( $deleted_count ) : ?>
			<p class="wpp-flash is-ok"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
				<?php
				/* translators: %d: number of backups */
				echo esc_html( sprintf( _n( 'Deleted %d backup.', 'Deleted %d backups.', $deleted_count, 'purge-ai1wm-backups' ), $deleted_count ) );
				?>
			</p>
		<?php endif; ?>
		<?php if ( $failed_count ) : ?>
			<p class="wpp-flash is-error"><span class="dashicons dashicons-warning" aria-hidden="true"></span>
				<?php
				/* translators: %d: number of backups */
				echo esc_html( sprintf( _n( 'Could not delete %d backup. Check that the ai1wm-backups folder is writable.', 'Could not delete %d backups. Check that the ai1wm-backups folder is writable.', $failed_count, 'purge-ai1wm-backups' ), $failed_count ) );
				?>
			</p>
		<?php endif; ?>
		<?php if ( ! wpress_purge_ai1wm_active() ) : ?>
			<p class="wpp-flash is-warn"><span class="dashicons dashicons-info" aria-hidden="true"></span>
				<?php esc_html_e( 'All-in-One WP Migration is not active. This plugin is meant to delete AI1WM backups. Any .wpress files left on the server are still deleted without AI1WM.', 'purge-ai1wm-backups' ); ?>
			</p>
		<?php endif; ?>

		<div class="wpp-stats">
			<div class="wpp-card">
				<span class="wpp-stat-label"><span class="dashicons dashicons-archive" aria-hidden="true"></span><?php esc_html_e( 'Backups on the server', 'purge-ai1wm-backups' ); ?></span>
				<div class="wpp-stat-value">
					<?php
					/* translators: %d: number of files */
					echo esc_html( sprintf( _n( '%d file', '%d files', count( $files ), 'purge-ai1wm-backups' ), count( $files ) ) );
					?>
				</div>
			</div>
			<div class="wpp-card">
				<span class="wpp-stat-label"><span class="dashicons dashicons-database" aria-hidden="true"></span><?php esc_html_e( 'Total size', 'purge-ai1wm-backups' ); ?></span>
				<div class="wpp-stat-value"><?php echo esc_html( $files ? size_format( $total, 1 ) : '0 B' ); ?></div>
			</div>
			<div class="wpp-card">
				<span class="wpp-stat-label"><span class="dashicons dashicons-clock" aria-hidden="true"></span><?php esc_html_e( 'Next auto-delete', 'purge-ai1wm-backups' ); ?></span>
				<div class="wpp-stat-value"><?php echo esc_html( $next_label ); ?></div>
			</div>
		</div>

		<?php /* 保存結果の知らせは「設定」メニューの画面なら WordPress が出す（ここで出すと2つ並ぶ） */ ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'wpress_purge' ); ?>
			<div class="wpp-card">
				<h2 class="wpp-card-title"><span class="dashicons dashicons-backup" aria-hidden="true"></span><label for="wpress_purge_ttl"><?php esc_html_e( 'Time until deletion', 'purge-ai1wm-backups' ); ?></label></h2>
				<p class="wpp-note">
					<?php
					printf(
						/* translators: 1: shortest time, 2: longest time, 3: default time */
						esc_html__( 'A backup is deleted once this much time has passed since it was last written. You can set %1$s to %2$s (default: %3$s).', 'purge-ai1wm-backups' ),
						esc_html( wpress_purge_format_duration( WPRESS_PURGE_TTL_MIN ) ),
						esc_html( wpress_purge_format_duration( WPRESS_PURGE_TTL_MAX ) ),
						esc_html( wpress_purge_format_duration( WPRESS_PURGE_TTL_DEFAULT ) )
					);
					?>
					<?php esc_html_e( 'Backups that were already on the server when this plugin was activated are counted from the time it was activated.', 'purge-ai1wm-backups' ); ?>
					<?php esc_html_e( 'Checks run every 10 minutes, so a file may be deleted up to about 10 minutes late. Files are never deleted in the middle of an export.', 'purge-ai1wm-backups' ); ?>
				</p>
				<div class="wpp-ttl">
					<input type="number" id="wpress_purge_ttl" name="<?php echo esc_attr( WPRESS_PURGE_TTL_OPTION ); ?>"
						value="<?php echo esc_attr( $amount ); ?>" min="1" step="1" <?php disabled( $locked ); ?>>
					<div class="wpp-chips" role="radiogroup" aria-label="<?php esc_attr_e( 'Unit', 'purge-ai1wm-backups' ); ?>">
						<?php foreach ( $units as $value => $label ) : ?>
							<label class="wpp-chip">
								<input type="radio" name="wpress_purge_ttl_unit" value="<?php echo esc_attr( $value ); ?>" <?php checked( $unit, $value ); ?> <?php disabled( $locked ); ?>>
								<span><?php echo esc_html( $label ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
				<?php if ( $locked ) : ?>
					<p class="wpp-lock">
						<?php
						printf(
							/* translators: 1: time, 2: constant name */
							esc_html__( 'Fixed to %1$s by %2$s in wp-config.php, so it cannot be changed here.', 'purge-ai1wm-backups' ),
							esc_html( wpress_purge_format_duration( $ttl ) ),
							'<code>WPRESS_PURGE_TTL</code>'
						);
						?>
					</p>
				<?php endif; ?>
			</div>
			<?php
			if ( ! $locked ) {
				submit_button( null, 'primary large' );
			}
			?>
		</form>

		<div class="wpp-card">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wpress_purge_delete">
				<?php wp_nonce_field( 'wpress_purge_delete' ); ?>

				<div class="wpp-card-head">
					<h2 class="wpp-card-title"><span class="dashicons dashicons-media-archive" aria-hidden="true"></span><?php esc_html_e( 'Backups on the server now', 'purge-ai1wm-backups' ); ?></h2>
					<?php if ( count( $files ) > 1 ) : ?>
						<?php
						/* translators: %d: number of backups */
						$confirm_all = sprintf( _n( 'Delete %d backup? This cannot be undone.', 'Delete all %d backups? This cannot be undone.', count( $files ), 'purge-ai1wm-backups' ), count( $files ) );
						?>
						<button type="submit" name="all" value="1" class="button wpp-delete" onclick="return confirm('<?php echo esc_js( $confirm_all ); ?>');">
							<span class="dashicons dashicons-trash" aria-hidden="true"></span><?php esc_html_e( 'Delete all', 'purge-ai1wm-backups' ); ?>
						</button>
					<?php endif; ?>
				</div>

				<?php if ( ! $files ) : ?>
					<p class="wpp-empty"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php esc_html_e( 'No backups right now.', 'purge-ai1wm-backups' ); ?></p>
				<?php else : ?>
					<ul class="wpp-files">
						<?php foreach ( $files as $file ) : ?>
							<?php
							$remain = wpress_purge_due( $file['mtime'] ) - $now;
							/* translators: %s: file name */
							$confirm = sprintf( __( 'Delete %s? This cannot be undone.', 'purge-ai1wm-backups' ), $file['name'] );
							?>
							<li class="wpp-file">
								<span class="dashicons dashicons-media-archive" aria-hidden="true"></span>
								<div class="wpp-file-main">
									<span class="wpp-file-name" title="<?php echo esc_attr( $file['name'] ); ?>"><?php echo esc_html( $file['name'] ); ?></span>
									<span class="wpp-file-meta">
										<?php
										echo esc_html( size_format( $file['size'], 1 ) );
										echo ' · ';
										/* translators: %s: date and time */
										echo esc_html( sprintf( __( 'Last written %s', 'purge-ai1wm-backups' ), wp_date( 'Y-m-d H:i', $file['mtime'] ) ) );
										?>
									</span>
								</div>
								<span class="wpp-pill<?php echo $remain > 0 ? '' : ' is-due'; ?>">
									<?php
									echo esc_html(
										$remain > 0
											/* translators: %s: remaining time */
											? sprintf( __( 'Auto-deletes in about %s', 'purge-ai1wm-backups' ), wpress_purge_format_duration( $remain ) )
											: __( 'Auto-deletes at the next check', 'purge-ai1wm-backups' )
									);
									?>
								</span>
								<button type="submit" name="file" value="<?php echo esc_attr( $file['name'] ); ?>" class="button wpp-delete" onclick="return confirm('<?php echo esc_js( $confirm ); ?>');">
									<span class="dashicons dashicons-trash" aria-hidden="true"></span><?php esc_html_e( 'Delete', 'purge-ai1wm-backups' ); ?>
								</button>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</form>

			<?php if ( wpress_purge_ai1wm_active() ) : ?>
				<p class="wpp-foot"><a href="<?php echo esc_url( admin_url( 'admin.php?page=ai1wm_backups' ) ); ?>"><?php esc_html_e( 'Open the AI1WM backups screen', 'purge-ai1wm-backups' ); ?></a></p>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

/* ------------------------------------------------------------------
   更新の知らせ（diagurity.com/wp-plugins/ からの自己配布）
   仕様は Diagurity のリポジトリの docs/wp-plugins-update-json.md。
   外へ送るのは更新 JSON への GET 1本だけで、サイトの URL などは付けない
   （User-Agent を上書きする。WordPress の既定にはサイトの URL が入る）。
   mu-plugins に置いたときは WordPress の更新の仕組みの外なので、知らせは出ない。
------------------------------------------------------------------ */

/* diagurity.com は CoreServer 上の WordPress から HTTPS で直接つながらないので、Cloudflare の Worker を読む */
define( 'WPRESS_PURGE_UPDATE_URL', 'https://seccheck-fetcher.odd-brook-2273.workers.dev/wp-plugins/updates/purge-ai1wm-backups.json' );
define( 'WPRESS_PURGE_UPDATE_CACHE', 'purge_ai1wm_backups_update_info' );

/**
 * 更新 JSON を読む（1日おぼえておく。失敗したときも空で1日おぼえ、管理画面を遅くしない）
 *
 * @return array|null version と download_url があるときだけ配列
 */
function wpress_purge_update_info() {
	$cached = get_transient( WPRESS_PURGE_UPDATE_CACHE );
	if ( false !== $cached ) {
		return is_array( $cached ) && $cached ? $cached : null;
	}

	$info     = null;
	$response = wp_remote_get(
		WPRESS_PURGE_UPDATE_URL,
		array(
			'timeout'    => 5,
			'user-agent' => 'purge-ai1wm-backups/' . WPRESS_PURGE_LOADED,
		)
	);
	if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( is_array( $data ) && ! empty( $data['version'] ) && ! empty( $data['download_url'] ) ) {
			$info = $data;
		}
	}

	set_transient( WPRESS_PURGE_UPDATE_CACHE, $info ? $info : array(), DAY_IN_SECONDS );
	return $info;
}

/* 新しい版があれば、プラグイン一覧と更新の画面に出す */
add_filter( 'pre_set_site_transient_update_plugins', function ( $transient ) {
	if ( empty( $transient ) || ! is_object( $transient ) ) {
		return $transient;
	}

	$basename = plugin_basename( __FILE__ );
	if ( empty( $transient->checked[ $basename ] ) ) {
		return $transient; // mu-plugins など、更新の対象に入っていないとき
	}

	$info = wpress_purge_update_info();
	if ( ! $info ) {
		return $transient;
	}

	$item = (object) array(
		'id'          => $basename,
		'slug'        => 'purge-ai1wm-backups',
		'plugin'      => $basename,
		'new_version' => (string) $info['version'],
		'package'     => (string) $info['download_url'],
		'url'         => (string) ( isset( $info['homepage'] ) ? $info['homepage'] : 'https://diagurity.com/wp-plugins/purge-ai1wm-backups/' ),
		'requires'    => isset( $info['requires'] ) ? (string) $info['requires'] : '',
		'requires_php' => isset( $info['requires_php'] ) ? (string) $info['requires_php'] : '',
		'tested'      => isset( $info['tested'] ) ? (string) $info['tested'] : '',
	);

	if ( version_compare( (string) $info['version'], (string) $transient->checked[ $basename ], '>' ) ) {
		$transient->response[ $basename ] = $item;
		unset( $transient->no_update[ $basename ] );
	} else {
		/* 新しくないときも載せる（一覧に自動更新の切り替えが出る） */
		$transient->no_update[ $basename ] = $item;
		unset( $transient->response[ $basename ] );
	}

	return $transient;
} );

/* 「詳細を表示」の中身 */
add_filter( 'plugins_api', function ( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || empty( $args->slug ) || 'purge-ai1wm-backups' !== $args->slug ) {
		return $result;
	}

	$info = wpress_purge_update_info();
	if ( ! $info ) {
		return $result;
	}

	$lang     = 0 === strpos( get_user_locale(), 'ja' ) ? 'ja' : 'en';
	$sections = array();
	foreach ( array( 'description', 'changelog' ) as $key ) {
		if ( isset( $info['sections'][ $key ][ $lang ] ) ) {
			$sections[ $key ] = (string) $info['sections'][ $key ][ $lang ];
		} elseif ( isset( $info['sections'][ $key ]['en'] ) ) {
			$sections[ $key ] = (string) $info['sections'][ $key ]['en'];
		}
	}

	return (object) array(
		'name'          => isset( $info['name'] ) ? (string) $info['name'] : 'Purge AI1WM Backups',
		'slug'          => 'purge-ai1wm-backups',
		'version'       => (string) $info['version'],
		'author'        => '<a href="https://diagurity.com/">Diagurity</a>',
		'requires'      => isset( $info['requires'] ) ? (string) $info['requires'] : '',
		'requires_php'  => isset( $info['requires_php'] ) ? (string) $info['requires_php'] : '',
		'tested'        => isset( $info['tested'] ) ? (string) $info['tested'] : '',
		'last_updated'  => isset( $info['last_updated'] ) ? (string) $info['last_updated'] : '',
		'homepage'      => isset( $info['homepage'] ) ? (string) $info['homepage'] : 'https://diagurity.com/wp-plugins/purge-ai1wm-backups/',
		'download_link' => (string) $info['download_url'],
		'sections'      => $sections,
	);
}, 20, 3 );

/* 「更新を確認」を押したときと、更新が終わったときは取り直す */
add_action( 'load-update-core.php', 'wpress_purge_flush_update_cache' );
add_action( 'upgrader_process_complete', 'wpress_purge_flush_update_cache' );

/** おぼえている更新 JSON を捨てる */
function wpress_purge_flush_update_cache() {
	delete_transient( WPRESS_PURGE_UPDATE_CACHE );
}

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
		. '<strong>' . esc_html__( 'Could not auto-delete All-in-One WP Migration backups (.wpress).', 'purge-ai1wm-backups' ) . '</strong><br>'
		. esc_html__( 'Check that the ai1wm-backups folder is writable. You can also delete the files by FTP.', 'purge-ai1wm-backups' ) . '<br>'
		/* translators: %s: list of file names */
		. sprintf( esc_html__( 'Files: %s', 'purge-ai1wm-backups' ), $list )
		. '</p></div>';
} );

/* ------------------------------------------------------------------
   有効化する前からあったバックアップの予告
   有効化した直後に、いつ消えるかを知らせる（必要なら先に落とせるように）
------------------------------------------------------------------ */

add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	/* 設定画面には一覧と残り時間が出ているので出さない */
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && 'settings_page_' . WPRESS_PURGE_PAGE === $screen->id ) {
		return;
	}

	$since = wpress_purge_since();
	$now   = time();
	$count = 0;
	$next  = null;

	foreach ( wpress_purge_list_files() as $file ) {
		if ( $file['mtime'] >= $since ) {
			continue;
		}
		$due = wpress_purge_due( $file['mtime'] );
		if ( $due <= $now ) {
			continue;
		}
		$count++;
		$next = null === $next ? $due : min( $next, $due );
	}

	if ( ! $count ) {
		return;
	}

	echo '<div class="notice notice-warning"><p>'
		. esc_html(
			sprintf(
				/* translators: 1: number of backups, 2: remaining time */
				_n(
					'%1$d backup (.wpress) that was already on the server will be deleted automatically in about %2$s. Download it before then if you need it.',
					'%1$d backups (.wpress) that were already on the server will be deleted automatically in about %2$s. Download them before then if you need them.',
					$count,
					'purge-ai1wm-backups'
				),
				$count,
				wpress_purge_format_duration( $next - $now )
			)
		)
		. ' <a href="' . esc_url( admin_url( 'options-general.php?page=' . WPRESS_PURGE_PAGE ) ) . '">'
		. esc_html__( 'See the backups', 'purge-ai1wm-backups' )
		. '</a></p></div>';
} );
