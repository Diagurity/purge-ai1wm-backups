<?php
/**
 * Plugin Name: All-in-One WP Migration Add-on: Auto-delete Backups
 * Description: An add-on for All-in-One WP Migration. Deletes the backups (.wpress) made by an export once the time you set has passed. Change the time under Settings → AIOWM Backup Auto-delete.
 * Version:     1.3.0
 * Author:      Diagurity
 * License:     GPL-2.0-or-later
 * Text Domain: purge-ai1wm-backups
 * Domain Path: /languages
 *
 * 置き場所（どちらでも動く）:
 *   - 絶対に止めたくない場合（推奨・セキュリティ用途）:
 *       wp-content/mu-plugins/purge-ai1wm-backups.php
 *       mu-plugins は有効化の操作が不要で、テーマやプラグインの切り替えでも外れない。
 *       管理画面から無効化もできない。（プラグイン一覧の「設定」リンクは出ないので、
 *       設定画面へは「設定 → AIOWM バックアップ自動削除」から入る）
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
define( 'WPRESS_PURGE_LOADED', '1.3.0' );

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

	$ttl     = wpress_purge_ttl();
	$now     = time();
	$deleted = array();
	$failed  = array();

	foreach ( $files as $file ) {
		if ( ! is_file( $file ) ) {
			continue;
		}

		$mtime = filemtime( $file );
		if ( ! $mtime || ( $now - $mtime ) < $ttl ) {
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
register_activation_hook( __FILE__, 'wpress_purge_schedule' );
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'wpress_purge_event' );
} );
register_uninstall_hook( __FILE__, 'wpress_purge_uninstall' );

/** 削除したときに設定と記録を消す */
function wpress_purge_uninstall() {
	delete_option( WPRESS_PURGE_TTL_OPTION );
	delete_option( WPRESS_PURGE_FAIL_OPTION );
	delete_transient( 'wpress_purge_ran' );
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
   設定画面（設定 → AIOWM バックアップ自動削除）
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
		__( 'All-in-One WP Migration Add-on: Auto-delete Backups', 'purge-ai1wm-backups' ),
		__( 'AIOWM Backup Auto-delete', 'purge-ai1wm-backups' ),
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

/** 設定画面を出す */
function wpress_purge_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$ttl    = wpress_purge_ttl();
	$locked = defined( 'WPRESS_PURGE_TTL' );

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
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'All-in-One WP Migration Add-on: Auto-delete Backups', 'purge-ai1wm-backups' ); ?></h1>

		<div class="notice notice-info inline">
			<p>
				<strong><?php esc_html_e( 'This plugin is an add-on for All-in-One WP Migration (AIOWM).', 'purge-ai1wm-backups' ); ?></strong><br>
				<?php esc_html_e( 'Backups (.wpress) made by an AIOWM export stay in a public folder of the site, and anyone who knows the URL can download them. They contain the whole site, including the database and wp-config.php, so this plugin deletes them automatically once the time you set has passed.', 'purge-ai1wm-backups' ); ?>
			</p>
		</div>

		<?php if ( ! wpress_purge_ai1wm_active() ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'All-in-One WP Migration is not active. This plugin is meant to delete AIOWM backups. Any .wpress files left on the server are still deleted without AIOWM.', 'purge-ai1wm-backups' ); ?></p>
			</div>
		<?php endif; ?>

		<?php /* 保存結果の知らせは「設定」メニューの画面なら WordPress が出す（ここで出すと2つ並ぶ） */ ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'wpress_purge' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wpress_purge_ttl"><?php esc_html_e( 'Time until deletion', 'purge-ai1wm-backups' ); ?></label></th>
					<td>
						<input type="number" id="wpress_purge_ttl" name="<?php echo esc_attr( WPRESS_PURGE_TTL_OPTION ); ?>"
							value="<?php echo esc_attr( $amount ); ?>" min="1" step="1" class="small-text" <?php disabled( $locked ); ?>>
						<select name="wpress_purge_ttl_unit" aria-label="<?php esc_attr_e( 'Unit', 'purge-ai1wm-backups' ); ?>" <?php disabled( $locked ); ?>>
							<option value="minute" <?php selected( $unit, 'minute' ); ?>><?php esc_html_e( 'minutes', 'purge-ai1wm-backups' ); ?></option>
							<option value="hour" <?php selected( $unit, 'hour' ); ?>><?php esc_html_e( 'hours', 'purge-ai1wm-backups' ); ?></option>
							<option value="day" <?php selected( $unit, 'day' ); ?>><?php esc_html_e( 'days', 'purge-ai1wm-backups' ); ?></option>
						</select>
						<p class="description">
							<?php
							printf(
								/* translators: 1: shortest time, 2: longest time, 3: default time */
								esc_html__( 'A backup is deleted once this much time has passed since it was last written. You can set %1$s to %2$s (default: %3$s).', 'purge-ai1wm-backups' ),
								esc_html( wpress_purge_format_duration( WPRESS_PURGE_TTL_MIN ) ),
								esc_html( wpress_purge_format_duration( WPRESS_PURGE_TTL_MAX ) ),
								esc_html( wpress_purge_format_duration( WPRESS_PURGE_TTL_DEFAULT ) )
							);
							?>
							<br>
							<?php esc_html_e( 'Checks run every 10 minutes, so a file may be deleted up to about 10 minutes late. Files are never deleted in the middle of an export.', 'purge-ai1wm-backups' ); ?>
						</p>
						<?php if ( $locked ) : ?>
							<p class="description"><strong>
							<?php
							printf(
								/* translators: 1: time, 2: constant name */
								esc_html__( 'Fixed to %1$s by %2$s in wp-config.php, so it cannot be changed here.', 'purge-ai1wm-backups' ),
								esc_html( wpress_purge_format_duration( $ttl ) ),
								'<code>WPRESS_PURGE_TTL</code>'
							);
							?>
							</strong></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<?php
			if ( ! $locked ) {
				submit_button();
			}
			?>
		</form>

		<h2><?php esc_html_e( 'Backups on the server now', 'purge-ai1wm-backups' ); ?></h2>
		<?php wpress_purge_render_files( $ttl ); ?>
	</div>
	<?php
}

/**
 * 残っている .wpress と、消える目安の時刻を出す
 *
 * @param int $ttl 削除までの時間（秒）。
 */
function wpress_purge_render_files( $ttl ) {
	$dir   = wpress_purge_dir();
	$files = is_dir( $dir ) ? glob( trailingslashit( $dir ) . '*.wpress' ) : array();
	$files = is_array( $files ) ? array_filter( $files, 'is_file' ) : array();

	if ( ! $files ) {
		echo '<p>' . esc_html__( 'None.', 'purge-ai1wm-backups' ) . '</p>';
		return;
	}

	$now = time();
	echo '<table class="widefat striped" style="max-width:800px"><thead><tr>'
		. '<th>' . esc_html__( 'File', 'purge-ai1wm-backups' ) . '</th>'
		. '<th>' . esc_html__( 'Size', 'purge-ai1wm-backups' ) . '</th>'
		. '<th>' . esc_html__( 'Last written', 'purge-ai1wm-backups' ) . '</th>'
		. '<th>' . esc_html__( 'Deleted', 'purge-ai1wm-backups' ) . '</th>'
		. '</tr></thead><tbody>';

	foreach ( $files as $file ) {
		$mtime  = (int) filemtime( $file );
		$remain = $mtime + $ttl - $now;
		printf(
			'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_html( basename( $file ) ),
			esc_html( size_format( (int) filesize( $file ) ) ),
			esc_html( wp_date( 'Y-m-d H:i', $mtime ) ),
			esc_html(
				$remain > 0
					/* translators: %s: remaining time */
					? sprintf( __( 'in about %s', 'purge-ai1wm-backups' ), wpress_purge_format_duration( $remain ) )
					: __( 'at the next check', 'purge-ai1wm-backups' )
			)
		);
	}
	echo '</tbody></table>';

	if ( wpress_purge_ai1wm_active() ) {
		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=ai1wm_backups' ) ),
			esc_html__( 'Open the AIOWM backups screen', 'purge-ai1wm-backups' )
		);
	}
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
