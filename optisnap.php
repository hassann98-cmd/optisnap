<?php
/**
 * Plugin Name: OptiSnap – بهینه‌ساز و واترمارک تصویر
 * Plugin URI:  https://jluxe.ir
 * Description: تبدیل خودکار تصاویر به WebP، کراپ مربعی 1:1، فشرده‌سازی و واترمارک لوگو - سازگار با WooCommerce و اپ JLuxe Admin
 * Version:     3.12.0
 * Author:      OptiSnap
 * Text Domain: optisnap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // جلوگیری از اجرای مستقیم فایل
}

define( 'OSN_IMG_VERSION', '3.12.0' );
define( 'OSN_IMG_DIR', plugin_dir_path( __FILE__ ) );
define( 'OSN_IMG_URL', plugin_dir_url( __FILE__ ) );
define( 'OSN_IMG_OPTION', 'osn_img_settings' );
define( 'OSN_IMG_META_FLAG', '_osn_optimized' );
define( 'OSN_IMG_META_ORIGINAL', '_osn_original_file' );
// عمداً همچنان '-jlx' است (نه '-osn'): این پسوند از قبل داخل نام فایل هزاران
// تصویر واقعی از پردازش‌های قبلی روی سایت زنده نوشته شده. تغییرش یعنی یا باید
// آن فایل‌ها را فیزیکی Rename کرد (پرخطر برای URLهای منتشرشده) یا افزونه دیگر
// آن‌ها را «قبلاً پردازش‌شده» تشخیص نمی‌دهد و دوباره (و شاید با واترمارک دوبل)
// پردازش‌شان می‌کند. برای تصاویر تازه هم همین پسوند استفاده می‌شود تا یکدست بماند.
define( 'OSN_IMG_SUFFIX', '-jlx' ); // پسوند فایل‌های پردازش‌شده
define( 'OSN_IMG_META_EXCLUDED', '_osn_excluded' );     // استثناء از پردازش
define( 'OSN_IMG_META_WATERMARKED', '_osn_watermarked' ); // واترمارک خورده
define( 'OSN_IMG_META_FILESIZE', '_osn_filesize' );     // حجم فایل (برای مرتب‌سازی)
define( 'OSN_IMG_META_REPORT', '_osn_report' );         // گزارش قبل/بعد
define( 'OSN_IMG_META_PROFILE', '_osn_profile' );       // پروفایل پردازش انتخابی هر Attachment/درصد

require_once OSN_IMG_DIR . 'includes/class-logger.php';
require_once OSN_IMG_DIR . 'includes/class-watermark.php';
require_once OSN_IMG_DIR . 'includes/class-encoder.php';
require_once OSN_IMG_DIR . 'includes/class-image-processor.php';
require_once OSN_IMG_DIR . 'includes/class-settings.php';
require_once OSN_IMG_DIR . 'includes/class-rest-handler.php';
require_once OSN_IMG_DIR . 'includes/class-batch-tool.php';
require_once OSN_IMG_DIR . 'includes/class-manager-page.php';
require_once OSN_IMG_DIR . 'includes/class-watermark-manager.php';
require_once OSN_IMG_DIR . 'includes/class-frontend.php';
require_once OSN_IMG_DIR . 'includes/class-product-editor.php';

/**
 * تنظیمات پیش‌فرض
 */
function osn_img_default_settings() {
	return array(
		'webp_enabled'      => 1,    // تبدیل به WebP
		'opt_mode'          => 'manual', // manual | smart (موتور هوشمند)
		'opt_policy'        => 'all',    // سیاست پردازش: upload_only | heavy_only | all | products_only | manual
		'avif_enabled'      => 0,    // تولید AVIF در کنار WebP (نیاز به PHP 8.1+ با libavif)
		'responsive_sizes'  => 1,    // ساخت سایزهای 480/768/1200 برای srcset
		'quality'           => 80,   // کیفیت WebP پیش‌فرض (10-200؛ بالای 100 = Lossless)
		'quality_featured'  => 88,   // کیفیت تصویر اصلی محصول (بهینه‌سازی ملایم)
		'quality_gallery'   => 75,   // کیفیت تصاویر گالری (فشرده‌تر)
		'max_size'          => 1000, // حداکثر ضلع سایر تصاویر (پیکسل)
		'max_size_featured' => 1200, // حداکثر ضلع تصویر اصلی محصول (v3.6)
		'max_size_gallery'  => 1000, // حداکثر ضلع تصاویر گالری (v3.6)
		'square_crop'       => 1,    // کراپ مربعی 1:1
		'keep_original'     => 1,    // نگه‌داشتن فایل اصلی (Backup)
		'update_content_urls' => 1,  // v3.10.0: به‌روزرسانی خودکار لینک‌های قدیمی در محتوا (رفع B9)
		'watermark_enabled' => 0,    // واترمارک خودکار هنگام آپلود (طبق نسخه ۲: پیش‌فرض خاموش - از تب واترمارک دستی اجرا شود)
		'wm_logo_id'        => 0,    // شناسه Attachment لوگو
		'wm_position'       => 'bottom-right',
		'wm_opacity'        => 40,   // درصد شفافیت
		'wm_size'           => 22,   // درصد از عرض تصویر
		'wm_min_width'      => 400,  // تصاویر کوچکتر از این عرض واترمارک نمی‌خورند
		'wm_padding'        => 20,   // فاصله از لبه (پیکسل)
		'wm_logo_max_px'    => 250,  // Smart Logo Scaling: سقف عرض لوگو (پیکسل)
		// قوانین واترمارک بر اساس نقش تصویر (v3.1)
		'wm_on_featured'      => 1,
		'wm_on_gallery'       => 1,
		'wm_on_other'         => 1,
		'wm_opacity_featured' => 25,
		'wm_opacity_gallery'  => 40,
		'wm_opacity_other'    => 40,
		'wm_size_featured'    => 20,
		'wm_size_gallery'     => 22,
		'wm_size_other'       => 22,
		'wm_excluded_cats'    => array(), // دسته‌های مستثنا از واترمارک
		'heavy_kb'          => 500,  // آستانه «تصویر حجیم» در اسکن (کیلوبایت)
		'size_guard_tolerance' => 0, // v3.6.2: درصد تحمل Size Guard؛ 0 = خروجی باید حتماً کوچکتر باشد
		'auto_cron'         => 0,    // پردازش خودکار پس‌زمینه با WP-Cron
		'cron_batch'        => 5,    // تعداد تصویر در هر اجرای Cron
		'debug_log'         => 0,
	);
}

/**
 * دریافت تنظیمات (با ادغام پیش‌فرض‌ها)
 */
function osn_img_get_settings() {
	$saved = get_option( OSN_IMG_OPTION, array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), osn_img_default_settings() );
}

/**
 * پوشه Backup اختصاصی: uploads/osn-backup/
 * هیچ فایل اصلی بدون بکاپ تغییر نمی‌کند - نسخه اصلی به این پوشه منتقل می‌شود.
 */
function osn_img_backup_dir() {
	$uploads = wp_get_upload_dir();
	$dir     = trailingslashit( $uploads['basedir'] ) . 'osn-backup';
	if ( ! file_exists( $dir ) ) {
		wp_mkdir_p( $dir );
		@file_put_contents( $dir . '/index.php', '<?php // Silence is golden.' );
		// v3.9.1: مسدودسازی برای Apache 2.2 (Order/Deny) و 2.4 (Require) و IIS (web.config)
		@file_put_contents( $dir . '/.htaccess', "Order deny,allow\nDeny from all\nRequire all denied\n" );
		@file_put_contents( $dir . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n" );
	} else {
		// ارتقای محافظت نصب‌های قدیمی (فایل‌های کمکی ممکن است از نسخه‌های قبلی مانده باشند)
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', '<?php // Silence is golden.' );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Order deny,allow\nDeny from all\nRequire all denied\n" );
		}
		if ( ! file_exists( $dir . '/web.config' ) ) {
			@file_put_contents( $dir . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n" );
		}
	}
	return $dir;
}

/**
 * سایزهای ریسپانسیو برای srcset وردپرس (Mobile / Tablet / Desktop)
 * وردپرس خودکار این سایزها را در srcset تصاویر استفاده می‌کند.
 */
add_action( 'after_setup_theme', function () {
	$settings = osn_img_get_settings();
	if ( ! empty( $settings['responsive_sizes'] ) ) {
		add_image_size( 'osn-480', 480, 0, false );   // Mobile
		add_image_size( 'osn-768', 768, 0, false );   // Tablet
		add_image_size( 'osn-1200', 1200, 0, false ); // Desktop
	}
} );

/**
 * مهاجرت یک‌باره از نام‌های قدیمی مبتنی بر «jluxe» (نسخه‌های تا 3.8.x) به
 * نام‌های جدید مبتنی بر «osn» (OptiSnap، از نسخه 3.9.0). بدون این مهاجرت،
 * تصاویری که روی سایت زنده قبلاً بهینه‌سازی/واترمارک شده‌اند به اشتباه
 * «پردازش‌نشده» به نظر می‌رسند و ممکن است دوباره (و حتی با واترمارک دوبل)
 * پردازش شوند - چون متاهای وضعیت زیر کلید قدیمی ذخیره شده بودند.
 * با یک Option جداگانه تضمین می‌شود این کد فقط دقیقاً یک‌بار اجرا شود.
 */
function osn_img_migrate_from_jluxe_names() {
	if ( get_option( 'osn_img_migrated_v39', false ) ) {
		return;
	}
	global $wpdb;

	// ۱) تنظیمات ذخیره‌شده قبلی (کیفیت، لوگوی واترمارک، سیاست پردازش و...)
	$old_settings = get_option( 'jlx_img_settings', null );
	if ( null !== $old_settings && false === get_option( OSN_IMG_OPTION, false ) ) {
		add_option( OSN_IMG_OPTION, $old_settings );
	}

	// ۲) متاهای وضعیت روی تک‌تک تصاویر/محصولات: _jluxe_xxx → _osn_xxx
	// یک UPDATE برای همه سطرهای مرتبط - بدون نیاز به حلقه روی هر Attachment
	$like = $wpdb->esc_like( '_jluxe_' ) . '%';
	$wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->postmeta} SET meta_key = REPLACE(meta_key, %s, %s) WHERE meta_key LIKE %s",
		'_jluxe_',
		'_osn_',
		$like
	) );

	// ۳) جدول لاگ قدیمی → جدول جدید (تغییر نام، نه کپی - تمام تاریخچه لاگ حفظ می‌شود)
	$old_table  = $wpdb->prefix . 'jluxe_image_logs';
	$new_table  = $wpdb->prefix . 'osn_image_logs';
	$old_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) ) === $old_table;
	$new_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_table ) ) === $new_table;
	if ( $old_exists && ! $new_exists ) {
		$wpdb->query( "RENAME TABLE {$old_table} TO {$new_table}" );
	}

	update_option( 'osn_img_migrated_v39', 1, false );

	if ( class_exists( 'OSN_Logger' ) ) {
		OSN_Logger::log( 'مهاجرت به OptiSnap (v3.9.0) انجام شد: تنظیمات، متاهای وضعیت تصاویر و جدول لاگ به نام‌های جدید منتقل شدند - هیچ داده‌ای از دست نرفت.' );
	}
}
// اولویت 4: قبل از هوک ارتقای نسخه زیر (اولویت 5) تا جدول لاگ قبل از create_table() تغییر نام بدهد
add_action( 'plugins_loaded', 'osn_img_migrate_from_jluxe_names', 4 );

/**
 * بروزرسانی خودکار (ساخت/ارتقای جدول لاگ بعد از آپدیت افزونه)
 */
add_action( 'plugins_loaded', function () {
	if ( get_option( 'osn_img_version' ) !== OSN_IMG_VERSION ) {
		OSN_Logger::create_table();

		// مهاجرت v3.5: واترمارک خودکار برای همیشه حذف شد.
		// تنظیم قدیمی watermark_enabled (به‌جامانده از نسخه‌های 1.x) خاموش می‌شود
		// تا هیچ تصویری بدون اجازه صریح واترمارک نخورد.
		$saved = get_option( OSN_IMG_OPTION, array() );
		if ( is_array( $saved ) && ! empty( $saved['watermark_enabled'] ) ) {
			$saved['watermark_enabled'] = 0;
			update_option( OSN_IMG_OPTION, $saved );
			OSN_Logger::log( 'مهاجرت v3.5: واترمارک خودکار هنگام آپلود غیرفعال شد (واترمارک فقط دستی).' );
		}

		update_option( 'osn_img_version', OSN_IMG_VERSION );
	}
}, 5 );

/**
 * فعال‌سازی: ثبت تنظیمات پیش‌فرض
 */
register_activation_hook( __FILE__, function () {
	if ( false === get_option( OSN_IMG_OPTION, false ) ) {
		add_option( OSN_IMG_OPTION, osn_img_default_settings() );
	}
	// جدول لاگ عملیات
	OSN_Logger::create_table();
	// بررسی پشتیبانی سرور از WebP
	if ( ! OSN_Image_Processor::server_supports_webp() ) {
		OSN_Logger::log( 'هشدار: سرور از تولید WebP پشتیبانی نمی‌کند (GD/Imagick بدون WebP).' );
	}
} );

/**
 * هوک اصلی: پردازش فایل بلافاصله بعد از آپلود
 * این فیلتر هم آپلود مدیریت، هم ووکامرس و هم REST API را پوشش می‌دهد.
 */
function osn_img_filter_upload( $upload, $context = 'upload' ) {
	if ( empty( $upload['file'] ) || ! empty( $upload['error'] ) ) {
		return $upload;
	}

	// --- Optimization Policy (v3.4) ---
	if ( ! osn_img_policy_allows_upload( $upload['file'] ) ) {
		return $upload;
	}

	$result = OSN_Image_Processor::process_file( $upload['file'] );

	if ( is_wp_error( $result ) ) {
		OSN_Logger::log( 'خطای پردازش آپلود: ' . $result->get_error_message() . ' | فایل: ' . basename( $upload['file'] ) );
		return $upload; // در صورت خطا، آپلود اصلی دست‌نخورده برگردد
	}

	if ( is_array( $result ) && ! empty( $result['path'] ) && $result['path'] !== $upload['file'] ) {
		$old_basename = wp_basename( $upload['file'] );
		$new_basename = wp_basename( $result['path'] );

		// اگر فایل اصلی نگه داشته شده، مسیر Backup را برای ثبت متا نگه می‌داریم
		if ( ! empty( $result['original_kept'] ) && ! empty( $result['original_path'] ) ) {
			OSN_Image_Processor::$pending_originals[ $result['path'] ] = $result['original_path'];
		}

		$upload['file'] = $result['path'];
		$upload['url']  = str_replace( $old_basename, $new_basename, $upload['url'] );
		$upload['type'] = 'image/webp';
	}

	return $upload;
}
add_filter( 'wp_handle_upload', 'osn_img_filter_upload', 10, 2 );
add_filter( 'wp_handle_sideload', 'osn_img_filter_upload', 10, 2 );

/**
 * Optimization Policy (v3.4): آیا این آپلود باید خودکار پردازش شود؟
 *  upload_only / all  → بله
 *  heavy_only         → فقط اگر حجم فایل از آستانه «حجیم» بیشتر باشد
 *  products_only      → خیر (هنگام آپلود هنوز به محصولی وصل نیست؛ از Media Manager یا Cron پردازش می‌شود)
 *  manual             → خیر (فقط دستی)
 */
function osn_img_policy_allows_upload( $file_path ) {
	$settings = osn_img_get_settings();
	$policy   = isset( $settings['opt_policy'] ) ? $settings['opt_policy'] : 'all';

	switch ( $policy ) {
		case 'manual':
		case 'products_only':
			return false;
		case 'heavy_only':
			$min = max( 50, absint( $settings['heavy_kb'] ) ) * 1024;
			return ( file_exists( $file_path ) && filesize( $file_path ) >= $min );
		default: // all | upload_only
			return true;
	}
}

/**
 * بعد از ساخته‌شدن Attachment: ثبت متای «پردازش‌شده» و مسیر Backup
 */
add_action( 'add_attachment', function ( $attachment_id ) {
	$file = get_attached_file( $attachment_id );
	if ( ! $file ) {
		return;
	}
	if ( false !== strpos( wp_basename( $file ), OSN_IMG_SUFFIX . '.webp' ) ) {
		update_post_meta( $attachment_id, OSN_IMG_META_FLAG, 1 );

		$after  = file_exists( $file ) ? (int) filesize( $file ) : 0;
		$before = 0;

		if ( isset( OSN_Image_Processor::$pending_originals[ $file ] ) ) {
			$orig = OSN_Image_Processor::$pending_originals[ $file ];
			update_post_meta( $attachment_id, OSN_IMG_META_ORIGINAL, $orig );
			if ( file_exists( $orig ) ) {
				$before = (int) filesize( $orig );
			}
			unset( OSN_Image_Processor::$pending_originals[ $file ] );
		}

		update_post_meta( $attachment_id, OSN_IMG_META_FILESIZE, $after );

		// اگر نسخه AVIF هم ساخته شده، ثبت کن
		$avif = preg_replace( '/\.webp$/', '.avif', $file );
		if ( $avif !== $file && file_exists( $avif ) ) {
			update_post_meta( $attachment_id, '_osn_avif', $avif );
		}
		if ( $before > 0 ) {
			update_post_meta( $attachment_id, OSN_IMG_META_REPORT, array(
				'before'  => $before,
				'after'   => $after,
				'percent' => $before > 0 ? round( ( 1 - $after / $before ) * 100 ) : 0,
				'time'    => time(),
			) );
		}

		OSN_Logger::db( $attachment_id, 'Upload + Optimize', $before, $after, 'SUCCESS' );
	}
} );

/**
 * لایه سوم (شبکه ایمنی جهانی):
 * اگر تصویری از مسیری آپلود شود که فیلتر wp_handle_upload را رد کرده باشد
 * (مثلاً اندپوینت سفارشی /wp-json/jluxe-admin/v1/media با wp_insert_attachment مستقیم)،
 * همین‌جا - در لحظه ساخت متادیتا و قبل از نهایی‌شدن Attachment - پردازش می‌شود.
 * این هوک برای «هر» Attachment جدید اجرا می‌شود، از هر مسیری که آمده باشد.
 */
function osn_img_metadata_safety_net( $metadata, $attachment_id ) {
	static $in_progress = array();

	// جلوگیری از بازگشت (خودمان داخل همین تابع دوباره متادیتا می‌سازیم)
	if ( isset( $in_progress[ $attachment_id ] ) ) {
		return $metadata;
	}

	// --- رفع باگ v3.5: این لایه فقط برای «آپلود تازه» است ---
	// wp_generate_attachment_metadata در جاهای دیگری هم اجرا می‌شود
	// (بازسازی بندانگشتی ووکامرس در پس‌زمینه، Regenerate Thumbnails،
	// Restore و Rebuild Sizes خود افزونه) و نباید باعث پردازش ناخواسته
	// و بی‌نظم تصاویر قدیمی شود.
	if ( OSN_Image_Processor::$suspend_auto ) {
		return $metadata;
	}
	$att_post = get_post( $attachment_id );
	if ( ! $att_post ) {
		return $metadata;
	}
	// v3.9.1: اگر post_date_gmt خالی/نامعتبر بود ('0000-00-00 00:00:00')،
	// strtotime مقدار false برمی‌گرداند و قبلاً این آپلود «قدیمی» تفسیر و برای همیشه
	// از پردازش رد می‌شد. حالا از post_date محلی به‌عنوان fallback استفاده می‌شود.
	$ts = strtotime( $att_post->post_date_gmt . ' UTC' );
	if ( ! $ts ) {
		$ts = strtotime( $att_post->post_date );
	}
	if ( ! $ts || ( time() - $ts ) > 120 ) {
		return $metadata; // Attachment قدیمی است؛ این یک آپلود تازه نیست
	}

	// قبلاً پردازش شده؟
	if ( get_post_meta( $attachment_id, OSN_IMG_META_FLAG, true ) ) {
		return $metadata;
	}

	$file = get_attached_file( $attachment_id );
	if ( ! $file || ! file_exists( $file ) ) {
		return $metadata;
	}

	// فایل از قبل خروجی افزونه است (از مسیر wp_handle_upload آمده) → فقط علامت بزن
	if ( OSN_Image_Processor::is_already_processed_file( $file ) ) {
		update_post_meta( $attachment_id, OSN_IMG_META_FLAG, 1 );
		return $metadata;
	}

	$mime = get_post_mime_type( $attachment_id );
	if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
		return $metadata;
	}

	// Optimization Policy (v3.4)
	if ( ! osn_img_policy_allows_upload( $file ) ) {
		return $metadata;
	}

	// لوگوی واترمارک را پردازش نکن
	$settings = osn_img_get_settings();
	if ( absint( $settings['wm_logo_id'] ) === (int) $attachment_id ) {
		update_post_meta( $attachment_id, OSN_IMG_META_FLAG, 1 );
		return $metadata;
	}

	$result = OSN_Image_Processor::process_file( $file );

	if ( is_wp_error( $result ) ) {
		OSN_Logger::log( 'خطای لایه ایمنی (ID=' . $attachment_id . '): ' . $result->get_error_message() );
		return $metadata;
	}
	if ( false === $result ) {
		update_post_meta( $attachment_id, OSN_IMG_META_FLAG, 1 );
		return $metadata;
	}

	$in_progress[ $attachment_id ] = true;

	// حذف بندانگشتی‌های ساخته‌شده از فایل خام
	if ( ! empty( $metadata['sizes'] ) && ! empty( $metadata['file'] ) ) {
		$upload_dir = wp_get_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] ) . trailingslashit( dirname( $metadata['file'] ) );
		foreach ( $metadata['sizes'] as $size ) {
			if ( ! empty( $size['file'] ) && file_exists( $base_dir . $size['file'] ) ) {
				@unlink( $base_dir . $size['file'] );
			}
		}
	}

	// جایگزینی فایل روی همان Attachment ID و ساخت متادیتای جدید از نسخه نهایی
	update_attached_file( $attachment_id, $result['path'] );
	wp_update_post( array(
		'ID'             => $attachment_id,
		'post_mime_type' => 'image/webp',
	) );

	require_once ABSPATH . 'wp-admin/includes/image.php';
	$metadata = wp_generate_attachment_metadata( $attachment_id, $result['path'] );

	update_post_meta( $attachment_id, OSN_IMG_META_FLAG, 1 );
	if ( ! empty( $result['original_kept'] ) ) {
		update_post_meta( $attachment_id, OSN_IMG_META_ORIGINAL, $file );
	}

	OSN_Logger::debug( 'پردازش از لایه ایمنی انجام شد (ID=' . $attachment_id . ')' );

	unset( $in_progress[ $attachment_id ] );
	return $metadata;
}
add_filter( 'wp_generate_attachment_metadata', 'osn_img_metadata_safety_net', 9999, 2 );

/**
 * اجازه آپلود WebP (برای برخی نسخه‌ها/هاست‌ها)
 */
add_filter( 'upload_mimes', function ( $mimes ) {
	$mimes['webp'] = 'image/webp';
	return $mimes;
} );

/**
 * پردازش خودکار پس‌زمینه با WP-Cron
 * هر ۵ دقیقه تعداد محدودی تصویر پردازش می‌شود - بدون فشار به هاست
 * و بدون نیاز به باز بودن صفحه مدیریت.
 */
add_filter( 'cron_schedules', function ( $schedules ) {
	$schedules['osn_five_minutes'] = array(
		'interval' => 300,
		'display'  => 'هر ۵ دقیقه (OptiSnap)',
	);
	return $schedules;
} );

add_action( 'init', function () {
	$settings  = osn_img_get_settings();
	$scheduled = wp_next_scheduled( 'osn_img_cron_event' );

	if ( ! empty( $settings['auto_cron'] ) && ! $scheduled ) {
		wp_schedule_event( time() + 60, 'osn_five_minutes', 'osn_img_cron_event' );
	} elseif ( empty( $settings['auto_cron'] ) && $scheduled ) {
		wp_unschedule_event( $scheduled, 'osn_img_cron_event' );
	}
} );

add_action( 'osn_img_cron_event', function () {
	$settings = osn_img_get_settings();
	if ( empty( $settings['auto_cron'] ) ) {
		return;
	}

	// حالت Cron بر اساس Optimization Policy (v3.4)
	$policy = isset( $settings['opt_policy'] ) ? $settings['opt_policy'] : 'all';
	if ( in_array( $policy, array( 'manual', 'upload_only' ), true ) ) {
		return; // پردازش خودکار پس‌زمینه در این سیاست‌ها انجام نمی‌شود
	}
	$mode = 'all';
	if ( 'heavy_only' === $policy ) {
		$mode = 'heavy';
	} elseif ( 'products_only' === $policy ) {
		$mode = 'products';
	}

	$batch = max( 1, min( 20, absint( $settings['cron_batch'] ) ) );
	$ids   = OSN_Batch_Tool::get_ids_for_mode( $mode, $batch );

	if ( empty( $ids ) ) {
		return;
	}

	@set_time_limit( 120 );
	$done = 0;
	foreach ( $ids as $id ) {
		$result = OSN_Image_Processor::process_attachment( $id );
		if ( is_wp_error( $result ) ) {
			update_post_meta( $id, OSN_IMG_META_FLAG, 'error' );
			OSN_Logger::log( 'خطای Cron (ID=' . $id . '): ' . $result->get_error_message() );
		} elseif ( 'done' === $result ) {
			$done++;
		}
	}

	if ( $done > 0 ) {
		OSN_Logger::debug( 'Cron: ' . $done . ' تصویر در پس‌زمینه پردازش شد.' );
	}
} );

/**
 * غیرفعال‌سازی: پاک‌کردن زمان‌بندی Cron
 */
register_deactivation_hook( __FILE__, function () {
	$scheduled = wp_next_scheduled( 'osn_img_cron_event' );
	if ( $scheduled ) {
		wp_unschedule_event( $scheduled, 'osn_img_cron_event' );
	}
} );

/**
 * راه‌اندازی کلاس‌ها
 */
add_action( 'init', function () {
	load_plugin_textdomain( 'optisnap', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

add_action( 'plugins_loaded', function () {
	new OSN_Settings();
	new OSN_Rest_Handler();
	new OSN_Batch_Tool();
	new OSN_Manager_Page();
	new OSN_Watermark_Manager();
	new OSN_Frontend();
	new OSN_Product_Editor();
} );
