<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ثبت خطا و رویداد در wp-content/debug.log
 */
class OSN_Logger {

	/**
	 * @param string $message پیام
	 * @param bool   $force   ثبت حتی اگر گزینه debug خاموش باشد (برای خطاهای مهم)
	 */
	public static function log( $message, $force = true ) {
		$settings = function_exists( 'osn_img_get_settings' ) ? osn_img_get_settings() : array( 'debug_log' => 1 );

		if ( empty( $settings['debug_log'] ) && ! $force ) {
			return;
		}

		$line = '[' . gmdate( 'Y-m-d H:i:s' ) . ' UTC] [OptiSnap] ' . $message;

		// v3.9.1: نوشتن در wp-content/debug.log فقط وقتی صریحاً debug در تنظیمات روشن
		// باشد (یا WP_DEBUG_LOG فعال باشد). قبلاً همه پیام‌های force بدون توجه به
		// تنظیم debug در وب‌روت نوشته می‌شدند - فایل قابل دانلود عمومی + پرشدن دیسک.
		// پیام‌های مهم اکنون به error_log سرور (لاگ خارج از وب‌روت) می‌روند.
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( $line );
		} elseif ( ! empty( $settings['debug_log'] ) ) {
			$log_file = WP_CONTENT_DIR . '/debug.log';
			// نوشتن امن؛ در صورت نبود دسترسی، بی‌صدا رد می‌شویم
			@file_put_contents( $log_file, $line . PHP_EOL, FILE_APPEND | LOCK_EX );
		} else {
			@error_log( $line );
		}
	}

	/** ثبت رویداد عادی - فقط وقتی debug در تنظیمات روشن است */
	public static function debug( $message ) {
		self::log( $message, false );
	}

	/* =====================================================================
	 * لاگ دیتابیس (تب OptiSnap Logs)
	 * =================================================================== */

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'osn_image_logs';
	}

	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table_name();
		dbDelta( "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			image_name VARCHAR(255) NOT NULL DEFAULT '',
			product VARCHAR(255) NOT NULL DEFAULT '',
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			operation VARCHAR(60) NOT NULL DEFAULT '',
			quality VARCHAR(20) NOT NULL DEFAULT '',
			size_before BIGINT UNSIGNED NOT NULL DEFAULT 0,
			size_after BIGINT UNSIGNED NOT NULL DEFAULT 0,
			result VARCHAR(20) NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY attachment_id (attachment_id)
		) {$charset};" );
	}

	/**
	 * ثبت یک عملیات در جدول لاگ
	 *
	 * @param int    $attachment_id شناسه تصویر
	 * @param string $operation     نوع عملیات (Optimize / Watermark / Restore / ...)
	 * @param int    $before        حجم قبل (بایت)
	 * @param int    $after         حجم بعد (بایت)
	 * @param string $result        SUCCESS | ERROR | SKIPPED
	 * @param string $error_note    توضیح خطا (اختیاری)
	 */
	public static function db( $attachment_id, $operation, $before = 0, $after = 0, $result = 'SUCCESS', $error_note = '', $quality = '' ) {
		global $wpdb;
		$table = self::table_name();

		// اگر جدول هنوز ساخته نشده (بروزرسانی از نسخه قدیمی) بساز
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			self::create_table();
		}

		$file = get_attached_file( $attachment_id );
		$name = $file ? wp_basename( $file ) : ( 'ID ' . $attachment_id );
		if ( $error_note ) {
			$name .= ' | ' . mb_substr( $error_note, 0, 120 );
		}

		$product    = '';
		$product_id = 0;
		if ( class_exists( 'OSN_Image_Processor' ) ) {
			$product_id = OSN_Image_Processor::get_related_product_id( $attachment_id );
			$product    = $product_id ? get_the_title( $product_id ) : '';
		}

		$wpdb->insert( $table, array(
			'created_at'    => current_time( 'mysql' ),
			'attachment_id' => absint( $attachment_id ),
			'image_name'    => $name,
			'product'       => $product,
			'product_id'    => absint( $product_id ),
			'operation'     => $operation,
			'quality'       => (string) $quality,
			'size_before'   => max( 0, (int) $before ),
			'size_after'    => max( 0, (int) $after ),
			'result'        => $result,
		) );

		// نگه‌داشتن حداکثر ۱۰۰۰ رکورد آخر
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $count > 1100 ) {
			$wpdb->query( "DELETE FROM {$table} ORDER BY id ASC LIMIT 100" );
		}
	}

	/** دریافت آخرین لاگ‌ها */
	public static function get_logs( $limit = 200 ) {
		global $wpdb;
		$table = self::table_name();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) );
	}

	/** پاک‌کردن همه لاگ‌ها */
	public static function clear_logs() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . self::table_name() );
	}
}
