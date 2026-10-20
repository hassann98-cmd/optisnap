<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * اعمال واترمارک لوگو روی تصویر (GD)
 */
class OSN_Watermark {

	/**
	 * اعمال واترمارک روی یک منبع تصویر GD
	 *
	 * @param resource|GdImage $image    تصویر مقصد (truecolor)
	 * @param array            $settings تنظیمات افزونه
	 * @return bool آیا واترمارک اعمال شد؟
	 */
	public static function apply( $image, $settings, $role = 'other' ) {
		// قوانین بر اساس نقش تصویر (v3.1): featured / gallery / other
		$role = in_array( $role, array( 'featured', 'gallery', 'other' ), true ) ? $role : 'other';
		if ( isset( $settings[ 'wm_on_' . $role ] ) && empty( $settings[ 'wm_on_' . $role ] ) ) {
			OSN_Logger::debug( 'واترمارک: برای نقش ' . $role . ' غیرفعال است.' );
			return false;
		}
		$logo_id = absint( $settings['wm_logo_id'] );
		if ( ! $logo_id ) {
			OSN_Logger::debug( 'واترمارک: لوگویی در تنظیمات انتخاب نشده است.' );
			return false;
		}

		$logo_path = get_attached_file( $logo_id );
		if ( ! $logo_path || ! file_exists( $logo_path ) ) {
			OSN_Logger::log( 'واترمارک: فایل لوگو یافت نشد (ID=' . $logo_id . ')' );
			return false;
		}

		$img_w = imagesx( $image );
		$img_h = imagesy( $image );

		// تصاویر خیلی کوچک (آیکون و...) واترمارک نمی‌خورند
		$min_width = absint( $settings['wm_min_width'] );
		if ( $min_width > 0 && $img_w < $min_width ) {
			OSN_Logger::debug( 'واترمارک: تصویر کوچکتر از حد مجاز است، رد شد.' );
			return false;
		}

		$logo = self::load_logo( $logo_path );
		if ( ! $logo ) {
			OSN_Logger::log( 'واترمارک: خواندن فایل لوگو ناموفق بود: ' . basename( $logo_path ) );
			return false;
		}

		// --- تغییر اندازه لوگو بر اساس درصدی از عرض تصویر (تنظیم نقش‌محور) ---
		$size_key     = 'wm_size_' . $role;
		$size_val     = isset( $settings[ $size_key ] ) && $settings[ $size_key ] ? $settings[ $size_key ] : $settings['wm_size'];
		$size_percent = max( 3, min( 60, absint( $size_val ) ) );
		$target_w     = (int) round( $img_w * $size_percent / 100 );

		// --- Smart Logo Scaling (v3.2) ---
		// اندازه لوگو بر اساس ابعاد تصویر محدود می‌شود:
		//  - سقف مطلق قابل تنظیم (پیش‌فرض 250px برای تصاویر بزرگ 2000px)
		//  - سقف نسبی 27% عرض تصویر (تصویر 300px → حداکثر ~81px)
		$abs_cap  = isset( $settings['wm_logo_max_px'] ) ? max( 40, absint( $settings['wm_logo_max_px'] ) ) : 250;
		$rel_cap  = (int) floor( $img_w * 0.27 );
		$target_w = min( $target_w, $abs_cap, $rel_cap );
		$target_w = max( 24, $target_w );
		$ratio        = imagesy( $logo ) / imagesx( $logo );
		$target_h     = (int) round( $target_w * $ratio );

		$scaled = imagecreatetruecolor( $target_w, $target_h );
		imagealphablending( $scaled, false );
		imagesavealpha( $scaled, true );
		$transparent = imagecolorallocatealpha( $scaled, 0, 0, 0, 127 );
		imagefill( $scaled, 0, 0, $transparent );
		imagecopyresampled( $scaled, $logo, 0, 0, 0, 0, $target_w, $target_h, imagesx( $logo ), imagesy( $logo ) );
		imagedestroy( $logo );

		// --- اعمال شفافیت کلی روی لوگو (تنظیم نقش‌محور) ---
		// opacity در تنظیمات = میزان «شفافیت» → عدد بیشتر یعنی محوتر
		$op_key          = 'wm_opacity_' . $role;
		$op_val          = isset( $settings[ $op_key ] ) && '' !== $settings[ $op_key ] ? $settings[ $op_key ] : $settings['wm_opacity'];
		$opacity_percent = max( 0, min( 100, absint( $op_val ) ) );
		$alpha_add       = (int) round( 127 * $opacity_percent / 100 );
		if ( $alpha_add > 0 ) {
			imagealphablending( $scaled, false );
			imagesavealpha( $scaled, true );
			imagefilter( $scaled, IMG_FILTER_COLORIZE, 0, 0, 0, $alpha_add );
		}

		// --- محاسبه موقعیت (Padding بر حسب پیکسل - v3.1) ---
		$margin = isset( $settings['wm_padding'] ) ? absint( $settings['wm_padding'] ) : (int) round( $img_w * 0.02 );
		$margin = min( $margin, (int) floor( $img_w / 4 ) ); // ایمنی: حداکثر یک‌چهارم عرض
		switch ( $settings['wm_position'] ) {
			case 'top-left':
				$x = $margin;
				$y = $margin;
				break;
			case 'top-right':
				$x = $img_w - $target_w - $margin;
				$y = $margin;
				break;
			case 'bottom-left':
				$x = $margin;
				$y = $img_h - $target_h - $margin;
				break;
			case 'center':
				$x = (int) round( ( $img_w - $target_w ) / 2 );
				$y = (int) round( ( $img_h - $target_h ) / 2 );
				break;
			case 'bottom-right':
			default:
				$x = $img_w - $target_w - $margin;
				$y = $img_h - $target_h - $margin;
				break;
		}

		// --- ترکیب لوگو با تصویر (با حفظ کانال آلفا) ---
		imagealphablending( $image, true );
		imagecopy( $image, $scaled, max( 0, $x ), max( 0, $y ), 0, 0, $target_w, $target_h );
		imagedestroy( $scaled );

		return true;
	}

	/**
	 * Hash فایل لوگوی فعلی (برای تشخیص واترمارک با لوگوی قدیمی - v3.2)
	 */
	public static function current_logo_hash( $settings = null ) {
		if ( null === $settings ) {
			$settings = osn_img_get_settings();
		}
		$logo_id = absint( $settings['wm_logo_id'] );
		if ( ! $logo_id ) {
			return '';
		}
		$path = get_attached_file( $logo_id );
		return ( $path && file_exists( $path ) ) ? (string) @md5_file( $path ) : '';
	}

	/**
	 * بارگذاری لوگو با پشتیبانی PNG / WebP / JPG
	 */
	private static function load_logo( $path ) {
		$info = @getimagesize( $path );
		if ( ! $info ) {
			return false;
		}

		switch ( $info['mime'] ) {
			case 'image/png':
				$logo = @imagecreatefrompng( $path );
				break;
			case 'image/webp':
				$logo = function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $path ) : false;
				break;
			case 'image/jpeg':
				$logo = @imagecreatefromjpeg( $path );
				break;
			default:
				return false;
		}

		if ( ! $logo ) {
			return false;
		}

		// تبدیل پالت به truecolor برای حفظ آلفا
		if ( ! imageistruecolor( $logo ) ) {
			imagepalettetotruecolor( $logo );
		}
		imagealphablending( $logo, false );
		imagesavealpha( $logo, true );

		return $logo;
	}
}
