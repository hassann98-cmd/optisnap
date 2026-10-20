<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * هسته پردازش تصویر:
 * چرخش EXIF → کراپ مربعی 1:1 → Resize → واترمارک → WebP
 */
class OSN_Image_Processor {

	/**
	 * نگه‌داری موقت مسیر فایل‌های اصلی برای ثبت متا بعد از ساخت Attachment
	 * @var array path_new => path_original
	 */
	public static $pending_originals = array();

	/**
	 * گارد تعلیق (v3.5): وقتی true است، لایه ایمنی خودکار غیرفعال می‌شود.
	 * حین Restore / Rebuild Sizes / Watermark فعال می‌شود تا بازسازی متادیتا
	 * باعث پردازش ناخواسته نشود.
	 */
	public static $suspend_auto = false;

	/** دلیل آخرین Skip (برای گزارش) */
	public static $last_skip_reason = '';

	/** فرمت‌های قابل پردازش */
	private static $supported_mimes = array( 'image/jpeg', 'image/png', 'image/webp' );

	/**
	 * آیا سرور توان تولید WebP دارد؟
	 */
	public static function server_supports_webp() {
		if ( function_exists( 'imagewebp' ) ) {
			return true;
		}
		if ( class_exists( 'Imagick' ) ) {
			$formats = Imagick::queryFormats( 'WEBP*' );
			return ! empty( $formats );
		}
		return false;
	}

	/**
	 * آیا این فایل قبلاً توسط افزونه پردازش شده؟
	 * (لایه اول محافظت: پسوند -jlx در نام فایل)
	 */
	public static function is_already_processed_file( $path ) {
		return ( false !== strpos( wp_basename( $path ), OSN_IMG_SUFFIX . '.webp' ) );
	}

	/**
	 * پردازش یک فایل روی دیسک.
	 *
	 * @param string $file_path مسیر کامل فایل
	 * @param array  $args      آرگومان‌های اختیاری:
	 *                          'quality'   => int (10-200؛ بالای 100 = Lossless)
	 *                          'watermark' => bool (نادیده‌گرفتن تنظیمات)
	 * @return array|false|WP_Error
	 *         آرایه ['path','original_kept'] در موفقیت،
	 *         false یعنی «نیازی به پردازش نبود / رد شد»،
	 *         WP_Error در خطا.
	 */
	public static function process_file( $file_path, $args = array() ) {
		$settings = osn_img_get_settings();

		if ( empty( $settings['webp_enabled'] ) ) {
			return false;
		}

		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'osn_no_file', 'فایل وجود ندارد: ' . $file_path );
		}

		// جلوگیری از پردازش مجدد - اگر قبلاً با همین افزونه پردازش شده، دست نزن
		// (به‌جز وقتی $args['force'] صریحاً خواسته شده - رفع باگ: قبلاً حتی «Force
		// Optimize» هم روی تصویر WebP از قبل پردازش‌شده بی‌صدا کاری انجام نمی‌داد)
		if ( self::is_already_processed_file( $file_path ) && empty( $args['force'] ) ) {
			OSN_Logger::debug( 'رد شد (قبلاً پردازش شده): ' . wp_basename( $file_path ) );
			return false;
		}

		// استثناء با نام فایل: بنر و اسلایدری که نباید پردازش شود
		// کافی است "-noopt" در نام فایل باشد (مثلاً banner-noopt.jpg)
		if ( false !== strpos( wp_basename( $file_path ), '-noopt' ) ) {
			OSN_Logger::debug( 'رد شد (noopt در نام فایل): ' . wp_basename( $file_path ) );
			return false;
		}

		$info = @getimagesize( $file_path );
		if ( ! $info || empty( $info['mime'] ) ) {
			return false; // فایل تصویری معتبر نیست (مثلاً PDF) - کاری نکن
		}

		$mime = $info['mime'];
		if ( ! in_array( $mime, self::$supported_mimes, true ) ) {
			return false; // GIF / SVG / ICO دست‌نخورده بمانند
		}

		if ( ! self::server_supports_webp() ) {
			return new WP_Error( 'osn_no_webp', 'سرور از تولید WebP پشتیبانی نمی‌کند.' );
		}

		// --- بارگذاری تصویر (با محافظت حافظه + Imagick fallback - v3.6.2) ---
		$image = self::load_image_safe( $file_path, $mime );
		if ( is_wp_error( $image ) ) {
			return $image;
		}
		if ( ! $image ) {
			return new WP_Error( 'osn_load_failed', 'خواندن تصویر ناموفق: ' . wp_basename( $file_path ) );
		}

		// --- اصلاح چرخش EXIF (عکس‌های موبایل) ---
		if ( 'image/jpeg' === $mime ) {
			$image = self::fix_exif_orientation( $image, $file_path );
		}

		// --- کراپ مربعی 1:1 از مرکز ---
		if ( ! empty( $settings['square_crop'] ) ) {
			$image = self::square_crop_center( $image );
		}

		// --- محدودکردن اندازه (نقش‌محور از v3.6: قابل Override با args) ---
		$max = ( isset( $args['max_size'] ) && $args['max_size'] )
			? max( 100, absint( $args['max_size'] ) )
			: max( 100, absint( $settings['max_size'] ) );
		$image = self::constrain_size( $image, $max );

		// --- واترمارک ---
		// نسخه ۲: واترمارک به‌صورت پیش‌فرض دستی است (تب OptiSnap Watermark)
		// مگر اینکه در تنظیمات «واترمارک خودکار هنگام آپلود» روشن باشد
		// یا صراحتاً در $args خواسته شده باشد.
		$role = isset( $args['role'] ) ? $args['role'] : 'other';

		// v3.5: واترمارک هرگز خودکار اجرا نمی‌شود - فقط با درخواست صریح ($args['watermark'] === true)
		// که تنها از مسیر تاییدشده Watermark Manager می‌آید.
		// تنظیم قدیمی watermark_enabled عمداً نادیده گرفته می‌شود.
		if ( isset( $args['watermark'] ) && true === $args['watermark'] ) {
			OSN_Watermark::apply( $image, $settings, $role );
		}

		// --- تعیین کیفیت ---
		// Smart Mode: بهترین کیفیت با کمترین حجم بر اساس ابعاد، جزئیات، نقش و حجم اولیه
		if ( 'smart' === $settings['opt_mode'] && empty( $args['quality_forced'] ) ) {
			$quality = self::smart_quality( $image, (int) @filesize( $file_path ), $role, $settings );
		} else {
			$quality = isset( $args['quality'] ) ? absint( $args['quality'] ) : absint( $settings['quality'] );
		}
		// کیفیت 10 تا 200: مقادیر بالای 100 = Lossless (بدون افت کیفیت)
		$quality = max( 10, min( 200, $quality ) );
		if ( $quality > 100 ) {
			$webp_q = defined( 'IMG_WEBP_LOSSLESS' ) ? IMG_WEBP_LOSSLESS : 100;
		} else {
			$webp_q = $quality;
		}
		self::$last_quality = $quality;

		$pathinfo = pathinfo( $file_path );
		// v3.9.1: جلوگیری از انباشت پسوند هنگام Force Optimize
		// (قبلاً image-jlx.webp → image-jlx-jlx.webp → image-jlx-jlx-jlx.webp می‌شد)
		$new_path = $pathinfo['dirname'] . '/' . self::processed_base_name( $file_path ) . OSN_IMG_SUFFIX . '.webp';

		// جلوگیری از تداخل نام
		$counter = 1;
		while ( file_exists( $new_path ) ) {
			$new_path = $pathinfo['dirname'] . '/' . $pathinfo['filename'] . '-' . $counter . OSN_IMG_SUFFIX . '.webp';
			$counter++;
		}

		imagealphablending( $image, false );
		imagesavealpha( $image, true );
		// v3.7: OSN_Encoder ابتدا سعی می‌کند از cwebp (libwebp، روش فشرده‌سازی حداکثری)
		// استفاده کند؛ اگر روی هاست نصب نبود، بی‌صدا به GD برمی‌گردد.
		$saved = OSN_Encoder::save_webp( $image, $new_path, $webp_q, ( $quality > 100 ) );

		// --- تولید AVIF در کنار WebP (در صورت پشتیبانی سرور) ---
		$avif_path = '';
		if ( $saved && ! empty( $settings['avif_enabled'] ) && ( function_exists( 'imageavif' ) || OSN_Encoder::has_avifenc() ) ) {
			$try_avif = preg_replace( '/\.webp$/', '.avif', $new_path );
			if ( OSN_Encoder::save_avif( $image, $try_avif, min( 100, $quality ) ) && file_exists( $try_avif ) ) {
				$avif_path = $try_avif;
			}
		}

		imagedestroy( $image );

		if ( ! $saved || ! file_exists( $new_path ) ) {
			return new WP_Error( 'osn_save_failed', 'ذخیره WebP ناموفق: ' . wp_basename( $file_path ) );
		}

		// --- Size Guard (v3.5) ---
		// اگر خروجی از فایل اصلی بزرگتر (یا مساوی) شد، پردازش بی‌فایده است:
		// خروجی دور انداخته می‌شود و فایل اصلی دست‌نخورده می‌ماند.
		$orig_size  = (int) filesize( $file_path );
		$new_size   = (int) filesize( $new_path );
		$tolerance  = isset( $settings['size_guard_tolerance'] ) ? max( 0, min( 50, absint( $settings['size_guard_tolerance'] ) ) ) : 0;
		$allowed_up = $orig_size + (int) round( $orig_size * $tolerance / 100 );
		if ( $orig_size > 0 && $new_size > $allowed_up ) {
			@unlink( $new_path );
			if ( ! empty( $avif_path ) && file_exists( $avif_path ) ) {
				@unlink( $avif_path );
			}
			self::$last_skip_reason = 'خروجی (' . size_format( $new_size ) . ') از اصل (' . size_format( $orig_size ) . ') بزرگتر شد - پردازش لغو';
			OSN_Logger::debug( 'Size Guard: ' . wp_basename( $file_path ) . ' - ' . self::$last_skip_reason );
			return false;
		}

		// --- مدیریت فایل اصلی ---
		// قانون نسخه ۳: هیچ فایل اصلی بدون Backup تغییر نمی‌کند.
		// نسخه اصلی به uploads/osn-backup/ منتقل می‌شود (خارج از دسترس مستقیم وب).
		$original_kept = false;
		$original_path = '';
		if ( ! empty( $settings['keep_original'] ) ) {
			$backup_dir  = osn_img_backup_dir();
			$backup_name = wp_unique_filename( $backup_dir, wp_basename( $file_path ) );
			$backup_path = trailingslashit( $backup_dir ) . $backup_name;
			if ( @rename( $file_path, $backup_path ) || @copy( $file_path, $backup_path ) ) {
				$original_kept = true;
				$original_path = $backup_path;
				if ( file_exists( $file_path ) && file_exists( $backup_path ) ) {
					@unlink( $file_path ); // اگر rename نشد و copy شد
				}
			}
		} else {
			@unlink( $file_path );
		}

		OSN_Logger::debug( sprintf(
			'پردازش موفق: %s → %s (%s → %s)',
			wp_basename( $file_path ),
			wp_basename( $new_path ),
			size_format( $orig_size ),
			size_format( (int) filesize( $new_path ) )
		) );

		return array(
			'path'          => $new_path,
			'original_kept' => $original_kept,
			'original_path' => $original_path,
			'avif'          => $avif_path,
			'quality'       => $quality,
		);
	}

	/** آخرین کیفیت استفاده‌شده (برای گزارش) */
	public static $last_quality = 0;

	/**
	 * Smart Optimization Engine:
	 * انتخاب خودکار بهترین کیفیت بر اساس ابعاد، میزان جزئیات، نقش تصویر و حجم اولیه.
	 * هدف: بیشترین کیفیت بصری با کمترین حجم.
	 */
	private static function smart_quality( $image, $before_size, $role, $settings ) {
		$w = imagesx( $image );
		$h = imagesy( $image );

		$q = 82; // پایه

		// --- میزان جزئیات: نمونه‌برداری 24x24 و انحراف معیار روشنایی ---
		$samples = array();
		$step_x  = max( 1, (int) floor( $w / 24 ) );
		$step_y  = max( 1, (int) floor( $h / 24 ) );
		for ( $y = 0; $y < $h; $y += $step_y ) {
			for ( $x = 0; $x < $w; $x += $step_x ) {
				$rgb       = imagecolorat( $image, $x, $y );
				$samples[] = ( ( ( $rgb >> 16 ) & 0xFF ) * 0.3 ) + ( ( ( $rgb >> 8 ) & 0xFF ) * 0.59 ) + ( ( $rgb & 0xFF ) * 0.11 );
			}
		}
		$n = count( $samples );
		if ( $n > 10 ) {
			$mean = array_sum( $samples ) / $n;
			$var  = 0;
			foreach ( $samples as $v ) {
				$var += ( $v - $mean ) * ( $v - $mean );
			}
			$std = sqrt( $var / $n );

			if ( $std > 60 ) {
				$q += 6;  // جزئیات زیاد (بافت، طرح) → کیفیت بالاتر تا خراب نشود
			} elseif ( $std < 20 ) {
				$q -= 8;  // تصویر ساده با پس‌زمینه صاف (عکس استودیویی محصول) → فشرده‌تر
			}
		}

		// --- حجم اولیه ---
		if ( $before_size > 2 * MB_IN_BYTES ) {
			$q -= 4;
		} elseif ( $before_size > 0 && $before_size < 200 * KB_IN_BYTES ) {
			$q += 3;
		}

		// --- ابعاد ---
		if ( max( $w, $h ) <= 600 ) {
			$q += 4; // تصویر کوچک، آسیب‌پذیرتر به فشرده‌سازی
		}

		// --- نقش ووکامرس + بازه‌های استاندارد v3.2 ---
		// Featured: 88-90 | Gallery: 75-82 | تصاویر کوچک: 70-75 | سایر: 70-85
		if ( 'featured' === $role ) {
			$q += 7;
			return max( 84, min( 90, (int) round( $q ) ) );
		}
		if ( 'gallery' === $role ) {
			$q -= 4;
			return max( 75, min( 82, (int) round( $q ) ) );
		}
		if ( max( $w, $h ) <= 600 ) {
			return max( 70, min( 75, (int) round( $q - 8 ) ) );
		}
		return max( 70, min( 85, (int) round( $q ) ) );
	}

	/**
	 * تشخیص «از قبل بهینه» (v3.4):
	 * بررسی فرمت + ابعاد + حجم + کیفیت تقریبی (بایت بر پیکسل)
	 *
	 * @return string|false دلیل Skip یا false (یعنی پردازش لازم است)
	 */
	public static function should_auto_skip( $file_path, $settings = null, $max_override = 0 ) {
		if ( null === $settings ) {
			$settings = osn_img_get_settings();
		}
		$info = @getimagesize( $file_path );
		if ( ! $info || empty( $info[0] ) || empty( $info[1] ) ) {
			return false;
		}

		$size = (int) filesize( $file_path );
		$max  = $max_override ? max( 100, absint( $max_override ) ) : max( 100, absint( $settings['max_size'] ) );
		$w    = (int) $info[0];
		$h    = (int) $info[1];
		$bpp  = $size / max( 1, $w * $h ); // بایت بر پیکسل ≈ کیفیت تقریبی فشرده‌سازی

		// ۱) WebP خوش‌فشرده با ابعاد مناسب → دوباره فشرده نشود (افت کیفیت بی‌دلیل)
		if ( 'image/webp' === $info['mime'] && max( $w, $h ) <= ( $max + 50 ) && $bpp < 0.9 ) {
			return 'WebP بهینه (' . round( $bpp, 2 ) . ' bpp)';
		}

		// ۲) فایل خیلی کوچک با ابعاد کوچک (آیکون و بج) → پردازش بی‌فایده است
		if ( $size < 30 * KB_IN_BYTES && max( $w, $h ) <= 600 ) {
			return 'حجم از قبل مناسب (' . size_format( $size ) . ')';
		}

		return false;
	}

	/**
	 * بازسازی سایزهای ریسپانسیو و متادیتا (بدون پردازش مجدد تصویر - v3.2)
	 * برای تصاویر قدیمی که قبل از فعال‌شدن سایزهای 480/768/1200 پردازش شده‌اند.
	 */
	public static function rebuild_sizes( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'osn_no_file', 'فایل یافت نشد (ID=' . $attachment_id . ')' );
		}

		self::$suspend_auto = true;
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$meta = wp_generate_attachment_metadata( $attachment_id, $file );
		wp_update_attachment_metadata( $attachment_id, $meta );
		self::$suspend_auto = false;

		OSN_Logger::db( $attachment_id, 'Rebuild Sizes (srcset)', 0, (int) filesize( $file ), 'SUCCESS' );
		return 'done';
	}

	/**
	 * پردازش یک Attachment موجود (برای ابزار Batch و REST)
	 * شناسه Attachment حفظ می‌شود؛ فقط فایل و متادیتا جایگزین می‌گردد.
	 *
	 * @param int  $attachment_id
	 * @param bool $force اجرای مجدد حتی اگر متای پردازش داشته باشد
	 * @return string|WP_Error 'done' | 'skipped'
	 */
	public static function process_attachment( $attachment_id, $force = false ) {
		$attachment_id = absint( $attachment_id );

		// رفع باگ v3.6.2: پیش‌تر هر مقدار truthy در این متا (از جمله 'error') باعث
		// می‌شد تصویر برای همیشه رد شود و هیچ‌وقت دوباره امتحان نشود - یعنی یک خطای
		// موقتی (مثلاً کمبود لحظه‌ای حافظه) آن تصویر را تا ابد «گیر» می‌انداخت.
		// فقط '1' (موفق) و 'skipped' (تشخیص هوشمند) واقعاً «کار تمام است»؛
		// 'error' باید در تلاش بعدی (دستی یا Cron) دوباره امتحان شود.
		$cur_flag = get_post_meta( $attachment_id, OSN_IMG_META_FLAG, true );
		if ( ! $force && in_array( $cur_flag, array( '1', 'skipped' ), true ) ) {
			return 'skipped';
		}

		// استثناء شده؟ (بنر، اسلایدر و...)
		if ( get_post_meta( $attachment_id, OSN_IMG_META_EXCLUDED, true ) ) {
			return 'skipped';
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'osn_no_file', 'فایل Attachment یافت نشد (ID=' . $attachment_id . ')' );
		}

		// لایه اول: فایل از قبل پردازش شده → فقط متا را ثبت کن و رد شو
		if ( self::is_already_processed_file( $file ) && ! $force ) {
			update_post_meta( $attachment_id, OSN_IMG_META_FLAG, 1 );
			return 'skipped';
		}

		// لوگوی واترمارک را هرگز پردازش نکن!
		$settings = osn_img_get_settings();
		if ( absint( $settings['wm_logo_id'] ) === $attachment_id ) {
			update_post_meta( $attachment_id, OSN_IMG_META_FLAG, 1 );
			return 'skipped';
		}

		self::$last_skip_reason = '';

		$before      = (int) filesize( $file );
		$before_info = @getimagesize( $file );
		$started     = microtime( true );

		// --- کیفیت بر اساس نقش تصویر در ووکامرس (در حالت Manual) ---
		// در حالت Smart، موتور هوشمند خودش با درنظرگرفتن نقش تصمیم می‌گیرد.
		$role = self::get_image_role( $attachment_id );
		switch ( $role ) {
			case 'featured':
				$quality  = absint( $settings['quality_featured'] );
				$max_role = absint( isset( $settings['max_size_featured'] ) ? $settings['max_size_featured'] : 1200 );
				break;
			case 'gallery':
				$quality  = absint( $settings['quality_gallery'] );
				$max_role = absint( isset( $settings['max_size_gallery'] ) ? $settings['max_size_gallery'] : 1000 );
				break;
			default:
				$quality  = absint( $settings['quality'] );
				$max_role = absint( $settings['max_size'] );
		}

		// --- تشخیص هوشمند «از قبل بهینه» (v3.4) - با حداکثر ابعاد نقش‌محور (v3.6) ---
		if ( ! $force ) {
			$skip_reason = self::should_auto_skip( $file, $settings, $max_role );
			if ( $skip_reason ) {
				update_post_meta( $attachment_id, OSN_IMG_META_FLAG, 'skipped' );
				update_post_meta( $attachment_id, '_osn_skip_reason', $skip_reason );
				OSN_Logger::db( $attachment_id, 'Optimize', $before, $before, 'SKIPPED', $skip_reason );
				return 'auto_skipped';
			}
		}

		// واترمارک در پردازش انبوه اعمال نمی‌شود (تب جداگانه دارد)
		$result = self::process_file( $file, array(
			'quality'   => $quality,
			'role'      => $role,
			'max_size'  => $max_role,
			'watermark' => false,
			'force'     => $force,
		) );

		if ( is_wp_error( $result ) ) {
			OSN_Logger::log( 'خطای پردازش (ID=' . $attachment_id . '): ' . $result->get_error_message() );
			OSN_Logger::db( $attachment_id, 'Optimize', $before, 0, 'ERROR', $result->get_error_message() );
			update_post_meta( $attachment_id, OSN_IMG_META_FLAG, 'error' );
			update_post_meta( $attachment_id, '_osn_error_message', $result->get_error_message() );
			return $result;
		}
		delete_post_meta( $attachment_id, '_osn_error_message' ); // موفقیت: خطای قبلی پاک شود

		if ( false === $result ) {
			// Size Guard یا شرایط دیگر Skip با دلیل
			if ( self::$last_skip_reason ) {
				update_post_meta( $attachment_id, OSN_IMG_META_FLAG, 'skipped' );
				update_post_meta( $attachment_id, '_osn_skip_reason', self::$last_skip_reason );
				OSN_Logger::db( $attachment_id, 'Optimize', $before, $before, 'SKIPPED', self::$last_skip_reason );
				return 'auto_skipped';
			}
			// فرمت پشتیبانی‌نشده یا غیرفعال - علامت بزن که دوباره شمرده نشود
			update_post_meta( $attachment_id, OSN_IMG_META_FLAG, 1 );
			return 'skipped';
		}

		$new_path = $result['path'];

		// --- حذف بندانگشتی‌های قدیمی (فایل اصلی طبق تنظیمات باقی می‌ماند) ---
		$old_meta   = wp_get_attachment_metadata( $attachment_id );
		$upload_dir = wp_get_upload_dir();
		if ( ! empty( $old_meta['sizes'] ) && ! empty( $old_meta['file'] ) ) {
			$base_dir = trailingslashit( $upload_dir['basedir'] ) . trailingslashit( dirname( $old_meta['file'] ) );
			foreach ( $old_meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) && file_exists( $base_dir . $size['file'] ) ) {
					@unlink( $base_dir . $size['file'] );
				}
			}
		}

		// --- جایگزینی فایل روی همان Attachment ID ---
		$old_url = wp_get_attachment_url( $attachment_id ); // قبل از تغییر (برای به‌روزرسانی محتوا)
		update_attached_file( $attachment_id, $new_path );
		wp_update_post( array(
			'ID'             => $attachment_id,
			'post_mime_type' => 'image/webp',
		) );

		// ساخت مجدد متادیتا و بندانگشتی‌ها از روی نسخه نهایی
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$meta = wp_generate_attachment_metadata( $attachment_id, $new_path );
		wp_update_attachment_metadata( $attachment_id, $meta );

		// v3.10.0: به‌روزرسانی لینک‌های قدیمی در محتوا (رفع B9)
		if ( ! empty( $settings['update_content_urls'] ) && ! empty( $old_url ) ) {
			$new_url   = wp_get_attachment_url( $attachment_id );
			$replaced  = self::update_content_urls( $old_url, $new_url );
			if ( $replaced > 0 ) {
				OSN_Logger::debug( 'به‌روزرسانی URL در محتوا: ' . $replaced . ' سطر (ID=' . $attachment_id . ')' );
			}
		}

		$after    = (int) filesize( $new_path );
		$duration = round( microtime( true ) - $started, 2 );
		$used_q   = ! empty( $result['quality'] ) ? $result['quality'] : $quality;

		update_post_meta( $attachment_id, OSN_IMG_META_FLAG, 1 );
		update_post_meta( $attachment_id, OSN_IMG_META_FILESIZE, $after );
		update_post_meta( $attachment_id, OSN_IMG_META_REPORT, array(
			'before'      => $before,
			'after'       => $after,
			'percent'     => $before > 0 ? round( ( 1 - $after / $before ) * 100 ) : 0,
			'role'        => $role,
			'quality'     => $used_q,
			'duration'    => $duration,
			'avif'        => ! empty( $result['avif'] ) ? (int) filesize( $result['avif'] ) : 0,
			'before_dims' => $before_info ? $before_info[0] . '×' . $before_info[1] : '',
			'after_dims'  => ( ! empty( $meta['width'] ) ) ? $meta['width'] . '×' . $meta['height'] : '',
			'time'        => time(),
		) );
		if ( ! empty( $result['original_kept'] ) && ! empty( $result['original_path'] ) ) {
			update_post_meta( $attachment_id, OSN_IMG_META_ORIGINAL, $result['original_path'] );
		}
		if ( ! empty( $result['avif'] ) ) {
			update_post_meta( $attachment_id, '_osn_avif', $result['avif'] );
		}

		$mode_label = ( 'smart' === $settings['opt_mode'] ) ? 'Smart' : 'Manual';
		$src_mime   = ( $before_info && ! empty( $before_info['mime'] ) ) ? $before_info['mime'] : '';
		$op_label   = in_array( $src_mime, array( 'image/jpeg', 'image/png' ), true ) ? 'Convert WebP' : 'Optimize';
		OSN_Logger::db( $attachment_id, $op_label . ' (' . $role . ', ' . $mode_label . ')', $before, $after, 'SUCCESS', '', 'Q' . $used_q );

		return 'done';
	}

	/* =====================================================================
	 * به‌روزرسانی URLهای هاردکدشده در محتوا (v3.10.0 - رفع مشکل B9)
	 * بعد از تبدیل photo.jpg → photo-jlx.webp، لینک‌های قدیمی داخل مطالب/محصولات
	 * (و متاهای دارای URL) به‌صورت خودکار به URL جدید منتقل می‌شوند.
	 * =================================================================== */

	/**
	 * جفت‌های جایگزینی URL (قابل تست - بدون وابستگی به DB)
	 *
	 * سه حالت رایج محتوا پوشش داده می‌شود:
	 *  1) URL کامل:        https://site.com/wp-content/uploads/2025/01/photo.jpg
	 *  2) نسبی سایت:      /wp-content/uploads/2025/01/photo.jpg
	 *  3) بدون اسلش اول:  wp-content/uploads/2025/01/photo.jpg
	 *
	 * @return array[] لیست [search, replace]
	 */
	public static function content_replace_pairs( $old_url, $new_url ) {
		$old_url = (string) $old_url;
		$new_url = (string) $new_url;
		if ( '' === $old_url || '' === $new_url || $old_url === $new_url ) {
			return array();
		}
		$pairs = array( array( $old_url, $new_url ) );

		$strip = function ( $url ) {
			$u = wp_parse_url( $url, PHP_URL_PATH );
			return $u ? $u : '';
		};
		$old_path = $strip( $old_url );
		$new_path = $strip( $new_url );
		if ( $old_path && $new_path && $old_path !== $new_path ) {
			$pairs[] = array( $old_path, $new_path ); // /wp-content/...
			$pairs[] = array( ltrim( $old_path, '/' ), ltrim( $new_path, '/' ) ); // wp-content/...
		}
		return $pairs;
	}

	/**
	 * اعمال جفت‌های جایگزینی روی یک رشته محتوا (قابل تست)
	 */
	public static function apply_url_pairs( $content, $pairs ) {
		foreach ( (array) $pairs as $p ) {
			if ( ! empty( $p[0] ) && $p[0] !== $p[1] ) {
				$content = str_replace( $p[0], $p[1], $content );
			}
		}
		return $content;
	}

	/**
	 * جایگزینی URL قدیمی → جدید در مطالب (posts.postmeta) سایت
	 *
	 * @return int تعداد سطرهای به‌روزرسانی‌شده
	 */
	public static function update_content_urls( $old_url, $new_url ) {
		global $wpdb;
		$pairs = self::content_replace_pairs( $old_url, $new_url );
		if ( empty( $pairs ) ) {
			return 0;
		}

		$updated = 0;
		$targets = array(
			array( $wpdb->posts, 'ID', 'post_content' ),
			array( $wpdb->postmeta, 'meta_id', 'meta_value' ),
		);

		foreach ( $targets as $t ) {
			list( $table, $id_col, $col ) = $t;
			// فقط سطرهایی که واقعاً شامل URL قدیمی هستند (کوئری ارزان با LIKE)
			$like  = '%' . $wpdb->esc_like( $pairs[0][0] ) . '%';
			$found = $wpdb->get_col( $wpdb->prepare(
				"SELECT {$id_col} FROM {$table} WHERE {$col} LIKE %s LIMIT 500",
				$like
			) );
			foreach ( (array) $found as $rid ) {
				$row = $wpdb->get_var( $wpdb->prepare(
					"SELECT {$col} FROM {$table} WHERE {$id_col} = %d",
					$rid
				) );
				$new_val = self::apply_url_pairs( $row, $pairs );
				if ( $new_val !== $row ) {
					$wpdb->query( $wpdb->prepare(
						"UPDATE {$table} SET {$col} = %s WHERE {$id_col} = %d",
						$new_val,
						$rid
					) );
					$updated++;
				}
			}
		}
		return $updated;
	}

	/**
	 * نقش تصویر در ووکامرس:
	 * 'featured' = تصویر اصلی محصول | 'gallery' = گالری محصول | 'other'
	 */
	public static function get_image_role( $attachment_id ) {
		global $wpdb;
		$attachment_id = absint( $attachment_id );

		// v3.9.1: کش درخواستی - این تابع برای هر تصویر صفحه (و هر payload فرانت/REST)
		// چند کوئری SQL می‌زد؛ در لیست محصولات ده‌ها کوئری اضافه تولید می‌شد.
		static $role_cache = array();
		if ( isset( $role_cache[ $attachment_id ] ) ) {
			return $role_cache[ $attachment_id ];
		}

		// تصویر اصلی (Featured) کدام محصول است؟
		$featured_parent = $wpdb->get_var( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'product'
			 WHERE pm.meta_key = '_thumbnail_id' AND pm.meta_value = %d LIMIT 1",
			$attachment_id
		) );
		if ( $featured_parent ) {
			return $role_cache[ $attachment_id ] = 'featured';
		}

		// در گالری کدام محصول است؟ (_product_image_gallery = لیست جداشده با کاما)
		$gallery_parent = $wpdb->get_var( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'product'
			 WHERE pm.meta_key = '_product_image_gallery' AND FIND_IN_SET(%d, pm.meta_value) LIMIT 1",
			$attachment_id
		) );
		if ( $gallery_parent ) {
			return $role_cache[ $attachment_id ] = 'gallery';
		}

		// تشخیص ترکیبی تکمیلی: Attachment مستقیم به محصول وصل است
		$parent = wp_get_post_parent_id( $attachment_id );
		if ( $parent && 'product' === get_post_type( $parent ) ) {
			// اگر محصول تصویر اصلی ندارد و این اولین تصویر آن است → تصویر اصلی
			$thumb = get_post_thumbnail_id( $parent );
			if ( ! $thumb ) {
				$first = get_children( array(
					'post_parent'    => $parent,
					'post_type'      => 'attachment',
					'post_mime_type' => 'image',
					'orderby'        => 'menu_order ID',
					'order'          => 'ASC',
					'numberposts'    => 1,
					'fields'         => 'ids',
				) );
				if ( $first && (int) reset( $first ) === $attachment_id ) {
					return $role_cache[ $attachment_id ] = 'featured';
				}
			}
			return $role_cache[ $attachment_id ] = 'gallery';
		}

		return $role_cache[ $attachment_id ] = 'other';
	}

	/**
	 * نام محصول مرتبط با تصویر (برای جدول و لاگ)
	 */
	public static function get_related_product_name( $attachment_id ) {
		$id = self::get_related_product_id( $attachment_id );
		return $id ? get_the_title( $id ) : '';
	}

	/**
	 * شناسه محصول مرتبط با تصویر
	 */
	public static function get_related_product_id( $attachment_id ) {
		global $wpdb;
		$attachment_id = absint( $attachment_id );

		// v3.9.1: کش درخواستی (همان دلیل get_image_role)
		static $pid_cache = array();
		if ( isset( $pid_cache[ $attachment_id ] ) ) {
			return $pid_cache[ $attachment_id ];
		}

		$pid = $wpdb->get_var( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'product'
			 WHERE ( pm.meta_key = '_thumbnail_id' AND pm.meta_value = %d )
			    OR ( pm.meta_key = '_product_image_gallery' AND FIND_IN_SET(%d, pm.meta_value) )
			 LIMIT 1",
			$attachment_id,
			$attachment_id
		) );

		if ( ! $pid ) {
			// شاید مستقیم به محصول Attach شده باشد
			$parent = wp_get_post_parent_id( $attachment_id );
			if ( $parent && 'product' === get_post_type( $parent ) ) {
				$pid = $parent;
			}
		}

		return $pid_cache[ $attachment_id ] = ( $pid ? (int) $pid : 0 );
	}

	/**
	 * اعمال واترمارک روی یک Attachment موجود (از تب OptiSnap Watermark)
	 * فایل فعلی (WebP یا هر فرمت) بارگذاری، واترمارک و دوباره ذخیره می‌شود.
	 *
	 * @param int $attachment_id
	 * @return string|WP_Error 'done' | 'skipped'
	 */
	public static function watermark_attachment( $attachment_id, $confirmed_rerun = false ) {
		$attachment_id = absint( $attachment_id );
		$settings      = osn_img_get_settings();

		// --- جلوگیری از واترمارک دوباره (بدون تایید ویژه اجازه داده نمی‌شود) ---
		if ( get_post_meta( $attachment_id, OSN_IMG_META_WATERMARKED, true ) && ! $confirmed_rerun ) {
			return 'already';
		}

		// --- استثناءها: تصویر / محصول / دسته ---
		if ( get_post_meta( $attachment_id, OSN_IMG_META_EXCLUDED, true ) ) {
			return 'skipped';
		}
		$prod_id = self::get_related_product_id( $attachment_id );
		if ( $prod_id ) {
			if ( get_post_meta( $prod_id, '_osn_wm_exclude_product', true ) ) {
				return 'skipped'; // Exclude Product
			}
			$ex_cats = isset( $settings['wm_excluded_cats'] ) ? (array) $settings['wm_excluded_cats'] : array();
			if ( ! empty( $ex_cats ) && has_term( $ex_cats, 'product_cat', $prod_id ) ) {
				return 'skipped'; // Exclude Category
			}
		}
		if ( absint( $settings['wm_logo_id'] ) === $attachment_id ) {
			return 'skipped'; // لوگو خودش
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'osn_no_file', 'فایل یافت نشد (ID=' . $attachment_id . ')' );
		}

		$info = @getimagesize( $file );
		if ( ! $info || ! in_array( $info['mime'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			return 'skipped';
		}

		$before = (int) filesize( $file );
		$role   = self::get_image_role( $attachment_id );

		// --- ۱) Backup قبل از هر تغییر: uploads/osn-backup/watermark/ ---
		// قانون: هیچ فایل اصلی بدون Backup تغییر نمی‌کند.
		if ( ! get_post_meta( $attachment_id, '_osn_wm_original', true ) ) {
			$backup_dir = trailingslashit( osn_img_backup_dir() ) . 'watermark';
			if ( ! file_exists( $backup_dir ) ) {
				wp_mkdir_p( $backup_dir );
				@file_put_contents( $backup_dir . '/index.php', '<?php // Silence is golden.' );
			}
			$backup_name = wp_unique_filename( $backup_dir, wp_basename( $file ) );
			$backup_path = trailingslashit( $backup_dir ) . $backup_name;
			if ( ! @copy( $file, $backup_path ) ) {
				OSN_Logger::db( $attachment_id, 'Watermark', $before, 0, 'ERROR', 'Backup ناموفق - عملیات لغو شد' );
				return new WP_Error( 'osn_backup_failed', 'Backup ناموفق بود؛ برای ایمنی، واترمارک اجرا نشد (ID=' . $attachment_id . ')' );
			}
			update_post_meta( $attachment_id, '_osn_wm_original', $backup_path );
		}

		// --- ۲) Watermark ---
		$image = self::load_image_safe( $file, $info['mime'] );
		if ( is_wp_error( $image ) ) {
			return $image;
		}
		if ( ! $image ) {
			return new WP_Error( 'osn_load_failed', 'خواندن تصویر ناموفق (ID=' . $attachment_id . ')' );
		}

		$applied = OSN_Watermark::apply( $image, $settings, $role );
		if ( ! $applied ) {
			imagedestroy( $image );
			return 'skipped'; // نقش غیرفعال / لوگو نامعتبر / تصویر کوچک
		}

		imagealphablending( $image, false );
		imagesavealpha( $image, true );

		$quality = max( 10, min( 100, absint( $settings['quality'] ) ) );
		// v3.9.1: نوشتن اتمیک - ابتدا فایل موقت، سپس جایگزینی با rename.
		// اگر Encode شکست بخورد، فایل فعلی (که Backup واترمارک از آن گرفته شده)
		// دست‌نخورده و سالم می‌ماند؛ قبلاً شکست Encode می‌توانست فایل را خراب/خالی کند.
		$tmp = dirname( $file ) . '/' . wp_basename( $file ) . '.osntmp';
		switch ( $info['mime'] ) {
			case 'image/webp':
				$saved = OSN_Encoder::save_webp( $image, $tmp, $quality, false );
				break;
			case 'image/jpeg':
				$saved = @imagejpeg( $image, $tmp, 90 );
				break;
			case 'image/png':
				$saved = @imagepng( $image, $tmp, 6 );
				break;
			default:
				$saved = false;
		}
		imagedestroy( $image );
		if ( $saved ) {
			$saved = @rename( $tmp, $file ) || ( @copy( $tmp, $file ) && @unlink( $tmp ) );
		}
		if ( ! $saved ) {
			@unlink( $tmp );
		}

		if ( ! $saved ) {
			OSN_Logger::db( $attachment_id, 'Watermark', $before, 0, 'ERROR', 'ذخیره ناموفق' );
			return new WP_Error( 'osn_save_failed', 'ذخیره فایل واترمارک‌دار ناموفق (ID=' . $attachment_id . ')' );
		}

		// --- ۳) بازسازی بندانگشتی‌ها از نسخه واترمارک‌دار ---
		// گارد تعلیق: بازسازی متادیتا نباید لایه ایمنی خودکار را فعال کند (v3.5)
		self::$suspend_auto = true;
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$meta = wp_generate_attachment_metadata( $attachment_id, $file );
		wp_update_attachment_metadata( $attachment_id, $meta );
		self::$suspend_auto = false;

		$after = (int) filesize( $file );
		update_post_meta( $attachment_id, OSN_IMG_META_WATERMARKED, 'yes' );
		update_post_meta( $attachment_id, '_osn_watermark_date', current_time( 'mysql' ) );
		update_post_meta( $attachment_id, '_osn_watermark_version', OSN_IMG_VERSION );
		update_post_meta( $attachment_id, '_osn_watermark_logohash', OSN_Watermark::current_logo_hash( $settings ) );
		update_post_meta( $attachment_id, OSN_IMG_META_FILESIZE, $after );

		// نقش‌محور: opacity واقعی استفاده‌شده در لاگ
		$op_used = isset( $settings[ 'wm_opacity_' . $role ] ) ? $settings[ 'wm_opacity_' . $role ] : $settings['wm_opacity'];
		OSN_Logger::db(
			$attachment_id,
			'Watermark (' . $role . ')',
			$before,
			$after,
			'SUCCESS',
			'',
			$settings['wm_position'] . ' / ' . $op_used . '%'
		);

		return 'done';
	}

	/**
	 * بازگردانی یک Attachment به فایل اصلی قبل از هر پردازش
	 * (v3.5: بازسازی‌شده - این تابع از v3.1 به‌اشتباه حذف شده بود و Restore خطای Fatal می‌داد)
	 *
	 * - فایل اصلی از Backup برمی‌گردد
	 * - فایل WebP و AVIF پردازش‌شده حذف می‌شوند
	 * - متادیتا و سایزهای Responsive و srcset بازسازی می‌شوند
	 * - همه متاهای پردازش/گزارش/واترمارک پاک می‌شوند
	 */
	public static function restore_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		$original = get_post_meta( $attachment_id, OSN_IMG_META_ORIGINAL, true );
		if ( ! $original || ! file_exists( $original ) ) {
			return new WP_Error( 'osn_no_backup', 'فایل Backup برای این تصویر وجود ندارد (ID=' . $attachment_id . ')' );
		}

		$current = get_attached_file( $attachment_id );

		// حذف بندانگشتی‌های نسخه پردازش‌شده
		$old_meta   = wp_get_attachment_metadata( $attachment_id );
		$upload_dir = wp_get_upload_dir();
		if ( ! empty( $old_meta['sizes'] ) && ! empty( $old_meta['file'] ) ) {
			$base_dir = trailingslashit( $upload_dir['basedir'] ) . trailingslashit( dirname( $old_meta['file'] ) );
			foreach ( $old_meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) && file_exists( $base_dir . $size['file'] ) ) {
					@unlink( $base_dir . $size['file'] );
				}
			}
		}

		// بازگرداندن فایل اصلی به محل استاندارد آپلود (کنار نسخه فعلی)
		$dest_dir  = $current ? dirname( $current ) : trailingslashit( $upload_dir['basedir'] );
		$dest_name = wp_unique_filename( $dest_dir, wp_basename( $original ) );
		$dest      = trailingslashit( $dest_dir ) . $dest_name;
		if ( ! @copy( $original, $dest ) ) {
			return new WP_Error( 'osn_restore_failed', 'کپی فایل Backup ناموفق (ID=' . $attachment_id . ')' );
		}

		// حذف فایل WebP پردازش‌شده و AVIF
		if ( $current && $current !== $dest && file_exists( $current ) ) {
			@unlink( $current );
		}
		$avif = get_post_meta( $attachment_id, '_osn_avif', true );
		if ( $avif && file_exists( $avif ) ) {
			@unlink( $avif );
		}

		// تشخیص mime فایل اصلی
		$info = @getimagesize( $dest );
		$mime = ( $info && ! empty( $info['mime'] ) ) ? $info['mime'] : 'image/jpeg';

		$restore_old_url = wp_get_attachment_url( $attachment_id ); // URL فایل پردازش‌شده فعلی
		update_attached_file( $attachment_id, $dest );
		wp_update_post( array(
			'ID'             => $attachment_id,
			'post_mime_type' => $mime,
		) );

		// v3.10.0: بازگرداندن لینک‌های محتوا به URL اصلی (متقارن با پردازش)
		$restore_settings = osn_img_get_settings();
		if ( ! empty( $restore_settings['update_content_urls'] ) && ! empty( $restore_old_url ) ) {
			self::update_content_urls( $restore_old_url, wp_get_attachment_url( $attachment_id ) );
		}

		// بازسازی متادیتا و سایزهای Responsive - با گارد تعلیق
		self::$suspend_auto = true;
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$meta = wp_generate_attachment_metadata( $attachment_id, $dest );
		wp_update_attachment_metadata( $attachment_id, $meta );
		self::$suspend_auto = false;

		// پاک‌سازی کامل ردپاهای پردازش (Backup نور دستی هم چون برای نسخه قبلی فایل بود، بی‌فایده شده)
		$brightness_backup = get_post_meta( $attachment_id, '_osn_brightness_original', true );
		if ( $brightness_backup && file_exists( $brightness_backup ) ) {
			@unlink( $brightness_backup );
		}
		delete_post_meta( $attachment_id, OSN_IMG_META_FLAG );
		delete_post_meta( $attachment_id, OSN_IMG_META_ORIGINAL );
		delete_post_meta( $attachment_id, OSN_IMG_META_REPORT );
		delete_post_meta( $attachment_id, '_osn_skip_reason' );
		delete_post_meta( $attachment_id, '_osn_avif' );
		delete_post_meta( $attachment_id, OSN_IMG_META_WATERMARKED );
		delete_post_meta( $attachment_id, '_osn_watermark_date' );
		delete_post_meta( $attachment_id, '_osn_watermark_version' );
		delete_post_meta( $attachment_id, '_osn_watermark_logohash' );
		delete_post_meta( $attachment_id, '_osn_brightness' );
		delete_post_meta( $attachment_id, '_osn_brightness_original' );
		delete_post_meta( $attachment_id, '_osn_brightness_applied_hash' );
		update_post_meta( $attachment_id, OSN_IMG_META_FILESIZE, (int) filesize( $dest ) );

		OSN_Logger::db( $attachment_id, 'Restore Original', 0, (int) filesize( $dest ), 'SUCCESS' );

		return 'restored';
	}

	/**
	 * Restore جامع: اول Backup بهینه‌سازی (قدیمی‌ترین نسخه)، وگرنه Backup واترمارک
	 */
	public static function restore_any( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( get_post_meta( $attachment_id, OSN_IMG_META_ORIGINAL, true ) ) {
			return self::restore_attachment( $attachment_id );
		}
		if ( get_post_meta( $attachment_id, '_osn_wm_original', true ) ) {
			return self::restore_watermark( $attachment_id );
		}
		return new WP_Error( 'osn_no_backup', 'هیچ Backup برای این تصویر وجود ندارد (ID=' . $attachment_id . ')' );
	}

	/**
	 * بازگردانی نسخه قبل از واترمارک (Restore Original - v3.1)
	 */
	public static function restore_watermark( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		$backup = get_post_meta( $attachment_id, '_osn_wm_original', true );
		if ( ! $backup || ! file_exists( $backup ) ) {
			return new WP_Error( 'osn_no_wm_backup', 'Backup واترمارک برای این تصویر وجود ندارد (ID=' . $attachment_id . ')' );
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file ) {
			return new WP_Error( 'osn_no_file', 'فایل Attachment یافت نشد' );
		}

		if ( ! @copy( $backup, $file ) ) {
			return new WP_Error( 'osn_restore_failed', 'بازگردانی ناموفق (ID=' . $attachment_id . ')' );
		}

		// گارد تعلیق (v3.5): فایل بازگردانده‌شده نباید همان لحظه دوباره پردازش شود!
		self::$suspend_auto = true;
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$meta = wp_generate_attachment_metadata( $attachment_id, $file );
		wp_update_attachment_metadata( $attachment_id, $meta );
		self::$suspend_auto = false;

		delete_post_meta( $attachment_id, OSN_IMG_META_WATERMARKED );
		delete_post_meta( $attachment_id, '_osn_watermark_date' );
		delete_post_meta( $attachment_id, '_osn_watermark_version' );
		delete_post_meta( $attachment_id, '_osn_watermark_logohash' );
		update_post_meta( $attachment_id, OSN_IMG_META_FILESIZE, (int) filesize( $file ) );

		OSN_Logger::db( $attachment_id, 'Watermark Restore', 0, (int) filesize( $file ), 'SUCCESS' );

		return 'restored';
	}

	/**
	 * تنظیم دستی نور (روشنایی) یک تصویر - از تب Media Manager (v3.8)
	 *
	 * هر بار از روی همان نسخه Backup اولیه (قبل از هرگونه تغییر نور) دوباره
	 * محاسبه می‌شود - نه روی نسخه فعلی - تا تغییرات پیاپی (مثلاً اول ۱۰+ بعد ۲۰+)
	 * باعث افت کیفیت تجمعی نشوند و همیشه بشود به مقدار دلخواه برگشت.
	 *
	 * رفع باگ: اگر بین دو بار تنظیم نور، عملیات دیگری (واترمارک، بهینه‌سازی مجدد)
	 * فایل را عوض کرده باشد، استفاده کورکورانه از همان Backup قدیمی آن تغییرات را
	 * پاک می‌کرد (مثلاً واترمارک بعد از تنظیم نور محو می‌شد). حالا با مقایسه Hash
	 * فایل زنده با آخرین Hashـی که خودمان نوشتیم، تشخیص داده می‌شود که آیا Backup
	 * هنوز معتبر است یا باید از وضعیت فعلی (که شامل تغییرات دیگران هم هست) دوباره گرفته شود.
	 *
	 * @param int $attachment_id
	 * @param int $level          from -100 (تیره‌تر) to 100 (روشن‌تر)؛ 0 یعنی بازگردانی به حالت اصلی
	 * @return string|WP_Error 'done'
	 */
	public static function adjust_brightness( $attachment_id, $level ) {
		$attachment_id = absint( $attachment_id );
		$level          = max( -100, min( 100, (int) $level ) );

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'osn_no_file', 'فایل یافت نشد (ID=' . $attachment_id . ')' );
		}

		// --- Backup پیش از اولین تغییر نور (یا دوباره‌گیری اگر فایل از بیرون عوض شده) ---
		$backup       = get_post_meta( $attachment_id, '_osn_brightness_original', true );
		$applied_hash = get_post_meta( $attachment_id, '_osn_brightness_applied_hash', true );
		$current_hash = @md5_file( $file );
		$backup_valid = $backup && file_exists( $backup ) && $applied_hash && $current_hash && hash_equals( (string) $applied_hash, (string) $current_hash );

		if ( ! $backup_valid ) {
			$backup_dir = trailingslashit( osn_img_backup_dir() ) . 'brightness';
			if ( ! file_exists( $backup_dir ) ) {
				wp_mkdir_p( $backup_dir );
				@file_put_contents( $backup_dir . '/index.php', '<?php // Silence is golden.' );
			}
			$backup_name = wp_unique_filename( $backup_dir, wp_basename( $file ) );
			$backup_path = trailingslashit( $backup_dir ) . $backup_name;
			if ( ! @copy( $file, $backup_path ) ) {
				return new WP_Error( 'osn_backup_failed', 'Backup ناموفق بود؛ برای ایمنی، تغییر نور اجرا نشد (ID=' . $attachment_id . ')' );
			}
			if ( $backup && $backup !== $backup_path && file_exists( $backup ) ) {
				@unlink( $backup ); // Backup قدیمی و منسوخ - دیگر لازم نیست
			}
			update_post_meta( $attachment_id, '_osn_brightness_original', $backup_path );
			$backup = $backup_path;
		}

		// level=0 یعنی بازگردانی کامل به حالت اصلی - کپی مستقیم، بدون افت کیفیت اضافه
		if ( 0 === $level ) {
			if ( ! @copy( $backup, $file ) ) {
				return new WP_Error( 'osn_restore_failed', 'بازگردانی نور ناموفق (ID=' . $attachment_id . ')' );
			}
		} else {
			$info = @getimagesize( $backup );
			if ( ! $info || empty( $info['mime'] ) || ! in_array( $info['mime'], self::$supported_mimes, true ) ) {
				return new WP_Error( 'osn_bad_image', 'فرمت تصویر پشتیبانی نمی‌شود (ID=' . $attachment_id . ')' );
			}

			$image = self::load_image_safe( $backup, $info['mime'] );
			if ( is_wp_error( $image ) ) {
				return $image;
			}
			if ( ! $image ) {
				return new WP_Error( 'osn_load_failed', 'خواندن تصویر ناموفق (ID=' . $attachment_id . ')' );
			}

			// نگاشت 100- تا 100+ به 255- تا 255+ (بازه واقعی IMG_FILTER_BRIGHTNESS در GD)
			imagefilter( $image, IMG_FILTER_BRIGHTNESS, (int) round( $level * 2.55 ) );
			imagealphablending( $image, false );
			imagesavealpha( $image, true );

			$settings = osn_img_get_settings();
			$quality  = max( 10, min( 100, absint( $settings['quality'] ) ) );

			// v3.9.1: نوشتن اتمیک (مانند watermark_attachment)
			$tmp = dirname( $file ) . '/' . wp_basename( $file ) . '.osntmp';
			switch ( $info['mime'] ) {
				case 'image/webp':
					$saved = OSN_Encoder::save_webp( $image, $tmp, $quality, false );
					break;
				case 'image/jpeg':
					$saved = @imagejpeg( $image, $tmp, 90 );
					break;
				case 'image/png':
					$saved = @imagepng( $image, $tmp, 6 );
					break;
				default:
					$saved = false;
			}
			imagedestroy( $image );
			if ( $saved ) {
				$saved = @rename( $tmp, $file ) || ( @copy( $tmp, $file ) && @unlink( $tmp ) );
			}
			if ( ! $saved ) {
				@unlink( $tmp );
			}

			if ( ! $saved ) {
				return new WP_Error( 'osn_save_failed', 'ذخیره تصویر با نور جدید ناموفق (ID=' . $attachment_id . ')' );
			}
		}

		// بازسازی بندانگشتی‌های ریسپانسیو از نسخه جدید - با گارد تعلیق
		self::$suspend_auto = true;
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$meta = wp_generate_attachment_metadata( $attachment_id, $file );
		wp_update_attachment_metadata( $attachment_id, $meta );
		self::$suspend_auto = false;

		if ( 0 === $level ) {
			delete_post_meta( $attachment_id, '_osn_brightness' );
		} else {
			update_post_meta( $attachment_id, '_osn_brightness', $level );
		}
		update_post_meta( $attachment_id, OSN_IMG_META_FILESIZE, (int) filesize( $file ) );
		// Hash فایلی که خودمان همین الان نوشتیم - دفعه بعد برای تشخیص معتبر بودن Backup استفاده می‌شود
		update_post_meta( $attachment_id, '_osn_brightness_applied_hash', (string) @md5_file( $file ) );

		OSN_Logger::db( $attachment_id, 'Brightness (' . ( $level > 0 ? '+' : '' ) . $level . ')', 0, (int) filesize( $file ), 'SUCCESS' );

		return 'done';
	}


	/* ---------------------------------------------------------------------
	 * توابع کمکی GD
	 * ------------------------------------------------------------------- */

	/**
	 * تخمین حافظه لازم برای بارگذاری تصویر در GD (v3.6.2)
	 * فرمول مشابه هسته وردپرس: عرض × ارتفاع × کانال‌ها × ضریب ایمنی + سربار پایه
	 */
	private static function estimate_memory_needed( $width, $height, $channels = 4 ) {
		return (int) round( $width * $height * $channels * 2.5 ) + ( 2 * 1024 * 1024 );
	}

	/** بایت قابل استفاده فعلی PHP بر اساس memory_limit */
	private static function available_memory_bytes() {
		$limit = ini_get( 'memory_limit' );
		if ( ! $limit || '-1' === trim( $limit ) ) {
			return PHP_INT_MAX; // بدون محدودیت
		}
		return wp_convert_hr_to_bytes( $limit );
	}

	/**
	 * بارگذاری تصویر - با افزایش خودکار memory_limit، بررسی کفایت حافظه
	 * قبل از عملیات سنگین (جلوگیری از کرش خاموش روی تصاویر بزرگ)،
	 * و تلاش با Imagick برای فایل‌های CMYK/خراب که GD نمی‌خواند (v3.6.2).
	 *
	 * @return array|WP_Error|false آرایه GD image resource در 'image'، یا خطا
	 */
	private static function load_image_safe( $path, $mime ) {
		// مثل هسته وردپرس هنگام ساخت بندانگشتی: سقف حافظه تصویر را بالا ببر
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		$info = @getimagesize( $path );
		if ( $info && ! empty( $info[0] ) && ! empty( $info[1] ) ) {
			$channels = isset( $info['channels'] ) ? max( 3, (int) $info['channels'] ) : 4;
			$needed   = self::estimate_memory_needed( $info[0], $info[1], $channels );
			$avail    = self::available_memory_bytes() - memory_get_usage( true );

			if ( $avail > 0 && $needed > $avail ) {
				return new WP_Error(
					'osn_low_memory',
					sprintf(
						'حافظه سرور کافی نیست (نیاز تقریبی %s، موجود %s). با هاست خود تماس بگیرید تا memory_limit افزایش یابد، یا این تصویر را قبل از آپلود کوچک‌تر کنید.',
						size_format( $needed ),
						size_format( max( 0, $avail ) )
					)
				);
			}
		}

		$img       = self::load_image( $path, $mime );
		$gd_error  = ! $img ? error_get_last() : null;

		// فایل خراب یا CMYK JPEG که GD نمی‌تواند بخواند → تلاش با Imagick (اگر موجود باشد)
		if ( ! $img && class_exists( 'Imagick' ) ) {
			try {
				$im = new Imagick( $path );
				if ( 'CMYK' === (string) $im->getImageColorspace() || Imagick::COLORSPACE_CMYK === $im->getImageColorspace() ) {
					$im->transformImageColorspace( Imagick::COLORSPACE_SRGB );
				}
				$im->setImageFormat( 'png32' ); // با کانال آلفا، بدون افت کیفیت، برای انتقال امن به GD
				$blob = $im->getImageBlob();
				$im->clear();
				$im->destroy();
				$img = @imagecreatefromstring( $blob );
				if ( $img ) {
					OSN_Logger::debug( 'بارگذاری با Imagick (fallback CMYK/خراب) موفق: ' . wp_basename( $path ) );
				}
			} catch ( Exception $e ) {
				OSN_Logger::debug( 'Imagick fallback ناموفق برای ' . wp_basename( $path ) . ': ' . $e->getMessage() );
			}
		}

		if ( ! $img ) {
			$reason = 'فرمت پشتیبانی‌نشده یا فایل خراب است';
			if ( $gd_error && ! empty( $gd_error['message'] ) ) {
				$reason .= ' (' . $gd_error['message'] . ')';
			} elseif ( 'image/jpeg' === $mime ) {
				$reason .= ' - احتمالاً JPEG با فضای رنگی CMYK است (رایج در فایل‌های فتوشاپ)';
			}
			return new WP_Error( 'osn_load_failed', 'خواندن تصویر ناموفق: ' . wp_basename( $path ) . ' - ' . $reason );
		}

		return $img;
	}

	private static function load_image( $path, $mime ) {
		switch ( $mime ) {
			case 'image/jpeg':
				$img = @imagecreatefromjpeg( $path );
				break;
			case 'image/png':
				$img = @imagecreatefrompng( $path );
				break;
			case 'image/webp':
				$img = function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $path ) : false;
				break;
			default:
				$img = false;
		}

		if ( ! $img ) {
			return false;
		}

		if ( ! imageistruecolor( $img ) ) {
			imagepalettetotruecolor( $img );
		}
		imagealphablending( $img, false );
		imagesavealpha( $img, true );

		return $img;
	}

	/**
	 * نام پایه فایل بدون پسوندهای پردازش قبلی (v3.9.2 - قابل تست)
	 * photo.jpg → photo | photo-jlx.webp → photo | photo-1-jlx.webp → photo
	 */
	public static function processed_base_name( $file_path ) {
		$base = pathinfo( $file_path, PATHINFO_FILENAME );
		if ( false !== strpos( $base, OSN_IMG_SUFFIX ) ) {
			$base = preg_replace( '/(?:-\d+)?' . preg_quote( OSN_IMG_SUFFIX, '/' ) . '$/', '', $base );
		}
		return $base;
	}

	/**
	 * اصلاح جهت تصویر بر اساس EXIF (عکس‌های دوربین موبایل)
	 */
	private static function fix_exif_orientation( $image, $path ) {
		if ( ! function_exists( 'exif_read_data' ) ) {
			return $image;
		}

		$exif = @exif_read_data( $path );
		if ( empty( $exif['Orientation'] ) ) {
			return $image;
		}

		return self::apply_exif_transform( $image, (int) $exif['Orientation'] );
	}

	/**
	 * اعمال تبدیل نمایش بر اساس Orientation استاندارد EXIF (v3.9.2)
	 *
	 * قبلاً فقط حالت‌های 3/6/8 (چرخش) پشتیبانی می‌شد؛ حالت‌های 2/4/5/7 (Flip)
	 * که در عکس‌های بعضی دوربین‌ها/نرم‌افزارها رایج است هم اضافه شد:
	 *   2 = Mirror horizontal | 3 = Rotate 180 | 4 = Mirror vertical
	 *   5 = Mirror horizontal + Rotate 270 CW (Transpose)
	 *   6 = Rotate 90 CW | 7 = Mirror horizontal + Rotate 90 CW (Transverse)
	 *   8 = Rotate 270 CW
	 * نکته: زاویه مثبت در imagerotate یعنی چرخش پادساعت‌گرد (CCW).
	 */
	public static function apply_exif_transform( $image, $orientation ) {
		switch ( $orientation ) {
			case 2:
				imageflip( $image, IMG_FLIP_HORIZONTAL );
				return $image;

			case 3:
				$rotated = imagerotate( $image, 180, 0 );
				break;

			case 4:
				imageflip( $image, IMG_FLIP_VERTICAL );
				return $image;

			case 5:
				// Transpose: آینه افقی + چرخش 270 ساعت‌گرد (= 90 CCW)
				imageflip( $image, IMG_FLIP_HORIZONTAL );
				$rotated = imagerotate( $image, 90, 0 );
				break;

			case 6:
				$rotated = imagerotate( $image, -90, 0 );
				break;

			case 7:
				// Transverse: آینه افقی + چرخش 90 ساعت‌گرد (= 90 CW)
				imageflip( $image, IMG_FLIP_HORIZONTAL );
				$rotated = imagerotate( $image, -90, 0 );
				break;

			case 8:
				$rotated = imagerotate( $image, 90, 0 );
				break;

			default:
				return $image;
		}

		if ( $rotated ) {
			imagedestroy( $image );
			imagealphablending( $rotated, false );
			imagesavealpha( $rotated, true );
			return $rotated;
		}
		return $image;
	}

	/**
	 * کراپ مربعی 1:1 از مرکز
	 * افقی: برش از مرکز | عمودی: محصول در مرکز حفظ می‌شود
	 */
	private static function square_crop_center( $image ) {
		$w = imagesx( $image );
		$h = imagesy( $image );

		if ( $w === $h ) {
			return $image; // از قبل مربع است
		}

		$side = min( $w, $h );
		$x    = (int) floor( ( $w - $side ) / 2 );
		$y    = (int) floor( ( $h - $side ) / 2 );

		$dst = imagecreatetruecolor( $side, $side );
		imagealphablending( $dst, false );
		imagesavealpha( $dst, true );
		$transparent = imagecolorallocatealpha( $dst, 0, 0, 0, 127 );
		imagefill( $dst, 0, 0, $transparent );

		imagecopy( $dst, $image, 0, 0, $x, $y, $side, $side );
		imagedestroy( $image );

		return $dst;
	}

	/**
	 * محدودکردن اندازه به حداکثر ضلع مشخص (با حفظ نسبت)
	 */
	private static function constrain_size( $image, $max ) {
		$w = imagesx( $image );
		$h = imagesy( $image );

		if ( $w <= $max && $h <= $max ) {
			return $image;
		}

		$ratio = min( $max / $w, $max / $h );
		$new_w = (int) round( $w * $ratio );
		$new_h = (int) round( $h * $ratio );

		$dst = imagecreatetruecolor( $new_w, $new_h );
		imagealphablending( $dst, false );
		imagesavealpha( $dst, true );
		$transparent = imagecolorallocatealpha( $dst, 0, 0, 0, 127 );
		imagefill( $dst, 0, 0, $transparent );

		imagecopyresampled( $dst, $image, 0, 0, 0, 0, $new_w, $new_h, $w, $h );
		imagedestroy( $image );

		return $dst;
	}
}
