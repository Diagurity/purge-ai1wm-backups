<?php
/**
 * Plugin Name: All-in-One WP Migration 拡張：バックアップ自動削除
 * Description: All-in-One WP Migration の拡張です。エクスポートで作られたバックアップ（.wpress）を、指定した時間がたったら自動で削除します。削除までの時間は「設定 → AIOWM バックアップ自動削除」で変えられます。
 * Version:     1.2.0
 * Author:      Diagurity
 * License:     GPL-2.0-or-later
 * Text Domain: purge-ai1wm-backups
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
define( 'WPRESS_PURGE_LOADED', '1.2.0' );

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
		$parts[] = $days . '日';
	}
	if ( $hours ) {
		$parts[] = $hours . '時間';
	}
	if ( $minutes || ! $parts ) {
		$parts[] = $minutes . '分';
	}
	return implode( 'と', $parts );
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

	if ( $seconds < WPRESS_PURGE_TTL_MIN || $seconds > WPRESS_PURGE_TTL_MAX ) {
		add_settings_error(
			WPRESS_PURGE_TTL_OPTION,
			'wpress_purge_ttl_range',
			sprintf(
				'削除までの時間は %s〜%s の間で指定してください。範囲に収まる値に直して保存しました。',
				wpress_purge_format_duration( WPRESS_PURGE_TTL_MIN ),
				wpress_purge_format_duration( WPRESS_PURGE_TTL_MAX )
			)
		);
		$seconds = min( WPRESS_PURGE_TTL_MAX, max( WPRESS_PURGE_TTL_MIN, $seconds ) );
	}

	return $seconds;
}

add_action( 'admin_menu', function () {
	add_options_page(
		'All-in-One WP Migration 拡張：バックアップ自動削除',
		'AIOWM バックアップ自動削除',
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
		'設定'
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
		<h1>All-in-One WP Migration 拡張：バックアップ自動削除</h1>

		<div class="notice notice-info inline">
			<p>
				<strong>このプラグインは All-in-One WP Migration（AIOWM）の拡張です。</strong><br>
				AIOWM のエクスポートで作られたバックアップ（.wpress）は、サイトの公開フォルダに置かれたままになり、
				URL が分かれば誰でもダウンロードできます。中身はサイト全体（データベースと wp-config.php を含む）なので、
				ダウンロードが済んだら残しておかないよう、指定した時間がたったら自動で削除します。
			</p>
		</div>

		<?php if ( ! wpress_purge_ai1wm_active() ) : ?>
			<div class="notice notice-warning inline">
				<p>All-in-One WP Migration が有効になっていません。このプラグインは AIOWM のバックアップを消すためのものです。
				残っている .wpress がある場合は、AIOWM が無くても削除は動きます。</p>
			</div>
		<?php endif; ?>

		<?php settings_errors( WPRESS_PURGE_TTL_OPTION ); ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'wpress_purge' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wpress_purge_ttl">削除までの時間</label></th>
					<td>
						<input type="number" id="wpress_purge_ttl" name="<?php echo esc_attr( WPRESS_PURGE_TTL_OPTION ); ?>"
							value="<?php echo esc_attr( $amount ); ?>" min="1" step="1" class="small-text" <?php disabled( $locked ); ?>>
						<select name="wpress_purge_ttl_unit" aria-label="単位" <?php disabled( $locked ); ?>>
							<option value="minute" <?php selected( $unit, 'minute' ); ?>>分</option>
							<option value="hour" <?php selected( $unit, 'hour' ); ?>>時間</option>
							<option value="day" <?php selected( $unit, 'day' ); ?>>日</option>
						</select>
						<p class="description">
							バックアップが最後に書き込まれてから、この時間がたつと削除します。
							<?php echo esc_html( wpress_purge_format_duration( WPRESS_PURGE_TTL_MIN ) ); ?>〜<?php echo esc_html( wpress_purge_format_duration( WPRESS_PURGE_TTL_MAX ) ); ?>の間で指定できます（既定は1時間）。<br>
							確認は10分ごとなので、実際に消えるのは最大で10分ほど遅れます。エクスポートの途中で消えることはありません。
						</p>
						<?php if ( $locked ) : ?>
							<p class="description"><strong>wp-config.php の <code>WPRESS_PURGE_TTL</code> で
							<?php echo esc_html( wpress_purge_format_duration( $ttl ) ); ?> に固定されているため、ここでは変えられません。</strong></p>
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

		<h2>いま残っているバックアップ</h2>
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
		echo '<p>ありません。</p>';
		return;
	}

	$now = time();
	echo '<table class="widefat striped" style="max-width:800px"><thead><tr>'
		. '<th>ファイル</th><th>大きさ</th><th>最後の書き込み</th><th>削除の目安</th>'
		. '</tr></thead><tbody>';

	foreach ( $files as $file ) {
		$mtime  = (int) filemtime( $file );
		$remain = $mtime + $ttl - $now;
		printf(
			'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_html( basename( $file ) ),
			esc_html( size_format( (int) filesize( $file ) ) ),
			esc_html( wp_date( 'Y-m-d H:i', $mtime ) ),
			esc_html( $remain > 0 ? 'あと約' . wpress_purge_format_duration( $remain ) : '次の確認で削除' )
		);
	}
	echo '</tbody></table>';

	if ( wpress_purge_ai1wm_active() ) {
		printf(
			'<p><a href="%s">AIOWM のバックアップ画面を開く</a></p>',
			esc_url( admin_url( 'admin.php?page=ai1wm_backups' ) )
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
		. '<strong>All-in-One WP Migration のバックアップ（.wpress）の自動削除に失敗しました。</strong><br>'
		. 'ai1wm-backups ディレクトリの書き込み権限をご確認ください（FTP 等で手動削除も可）。<br>'
		. '対象: ' . $list
		. '</p></div>';
} );
