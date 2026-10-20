<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OptiSnap Encoder (v3.7)
 *
 * فشرده‌سازی WebP/AVIF استاندارد GD (imagewebp/imageavif) از الگوریتم‌های ساده و
 * محدودی استفاده می‌کند. اگر باینری‌های متن‌باز libwebp (پروژه Google،
 * github.com/webmproject/libwebp) یا libavif (پروژه AOMediaCodec،
 * github.com/AOMediaCodec/libavif) روی هاست نصب باشند، این کلاس با تنظیمات
 * پیشرفته (روش فشرده‌سازی حداکثری، sharp-YUV، چندنخی) از آن‌ها استفاده می‌کند
 * که معمولاً ۵ تا ۱۵ درصد حجم کمتر با همان کیفیت بصری نتیجه می‌دهد.
 *
 * اگر shell_exec غیرفعال باشد یا باینری‌ها نصب نباشند (رایج در اکثر هاست‌های
 * اشتراکی)، همه‌چیز بی‌صدا به روش فعلی GD برمی‌گردد - هیچ رفتاری خراب نمی‌شود.
 */
class OSN_Encoder {

	/** کش نتیجه تشخیص باینری‌ها در طول یک درخواست */
	private static $bin_cache = array();

	/** آیا مسیر پیداشده برای این باینری، نسخه همراه خود افزونه (assets/bin/) بود؟ */
	private static $bin_is_bundled = array();

	/**
	 * فایل موقت برای Encode (v3.9.1)
	 * wp_tempnam خودش فایل را می‌سازد؛ قبلاً '.png' به مسیر اضافه می‌شد و فایل خالی
	 * ساخته‌شده در هر Encode در /tmp باقی می‌ماند (نشت فایل موقت). حالا همان مسیر
	 * بدون پسوند اضافه استفاده می‌شود و با پایان کار پاک می‌شود.
	 */
	private static function temp_path() {
		return wp_tempnam( 'osn-enc' );
	}

	/** لیست توابع غیرفعال‌شده PHP (disable_functions) */
	private static function disabled_functions() {
		return array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
	}

	/**
	 * آیا اجرای دستور شل روی این هاست ممکن است؟
	 * نکته: exec() هم اینجا چک می‌شود چون save_webp_via_cwebp/save_avif_via_avifenc
	 * برای اجرای واقعی باینری از exec() استفاده می‌کنند (نه فقط shell_exec).
	 */
	public static function shell_available() {
		if ( ! function_exists( 'shell_exec' ) || ! function_exists( 'exec' ) || ! function_exists( 'escapeshellarg' ) ) {
			return false;
		}
		$disabled = self::disabled_functions();
		if ( in_array( 'shell_exec', $disabled, true ) || in_array( 'exec', $disabled, true ) || in_array( 'proc_open', $disabled, true ) ) {
			return false;
		}
		// در برخی محیط‌ها safe_mode/open_basedir اجرای بیرونی را می‌بندد؛ یک تست ساده:
		return null !== @shell_exec( 'echo 1' );
	}

	/**
	 * پوشه باینری‌های همراه افزونه بر اساس سیستم‌عامل/معماری سرور (v3.7.2)
	 * فقط لینوکس پوشش داده می‌شود چون تقریباً همه هاست‌های وردپرس روی لینوکس هستند.
	 * باینری‌ها باید توسط مدیر افزونه از منابع رسمی دانلود و در assets/bin/<این-پوشه>/
	 * با نام دقیق «cwebp» و «avifenc» قرار داده شوند (این کلاس چیزی دانلود نمی‌کند).
	 *
	 * @return string|false
	 */
	private static function bundled_platform_dir() {
		$os = function_exists( 'php_uname' ) ? strtolower( (string) php_uname( 's' ) ) : '';
		if ( false === strpos( $os, 'linux' ) ) {
			return false; // مک/ویندوز هدف این قابلیت نیست (هاست وردپرس معمولاً لینوکس است)
		}
		$machine = function_exists( 'php_uname' ) ? strtolower( (string) php_uname( 'm' ) ) : '';
		if ( in_array( $machine, array( 'x86_64', 'amd64' ), true ) ) {
			return 'linux-x86_64';
		}
		if ( in_array( $machine, array( 'aarch64', 'arm64' ), true ) ) {
			return 'linux-aarch64';
		}
		return false; // معماری پشتیبانی‌نشده - فقط از GD استفاده می‌شود
	}

	/**
	 * مسیر یک باینری را پیدا می‌کند (کش‌شده):
	 *  ۱) اول نسخه همراه افزونه در assets/bin/<platform>/<name> (نیازی به نصب روی هاست نیست)
	 *  ۲) وگرنه، اگر روی PATH سرور نصب است (مثلاً با apt/yum توسط پشتیبانی هاست)
	 */
	private static function find_binary( $name ) {
		if ( array_key_exists( $name, self::$bin_cache ) ) {
			return self::$bin_cache[ $name ];
		}
		if ( ! function_exists( 'exec' ) || in_array( 'exec', self::disabled_functions(), true ) ) {
			return self::$bin_cache[ $name ] = false; // بدون exec() اجرای هیچ باینری‌ای ممکن نیست
		}

		$platform_dir = self::bundled_platform_dir();
		if ( $platform_dir ) {
			$bundled = OSN_IMG_DIR . 'assets/bin/' . $platform_dir . '/' . $name;
			if ( file_exists( $bundled ) ) {
				// آپلود Zip/FTP معمولاً بیت اجرایی را نگه نمی‌دارد؛ اینجا دوباره تنظیم می‌شود.
				@chmod( $bundled, 0755 );
				if ( @is_executable( $bundled ) ) {
					self::$bin_is_bundled[ $name ] = true;
					return self::$bin_cache[ $name ] = $bundled;
				}
			}
		}

		if ( self::shell_available() ) {
			$path = @shell_exec( 'command -v ' . escapeshellarg( $name ) . ' 2>/dev/null' );
			$path = $path ? trim( $path ) : '';
			if ( $path && @is_executable( $path ) ) {
				self::$bin_is_bundled[ $name ] = false;
				return self::$bin_cache[ $name ] = $path;
			}
		}

		return self::$bin_cache[ $name ] = false;
	}

	public static function has_cwebp() {
		return false !== self::find_binary( 'cwebp' );
	}

	public static function has_avifenc() {
		return false !== self::find_binary( 'avifenc' );
	}

	/** کش نتیجه تشخیص پشتیبانی Imagick از هر فرمت */
	private static $imagick_cache = array();

	/**
	 * آیا افزونه Imagick (کتابخانه PHP، نه برنامه خارجی) نصب است و از WebP
	 * پشتیبانی می‌کند؟ چون Imagick یک Extension داخل خود PHP است (مثل GD)،
	 * برخلاف cwebp/avifenc هیچ نیازی به exec()/shell_exec() ندارد - روی
	 * هاست‌هایی که این توابع را بسته‌اند (رایج در هاست‌های اشتراکی cPanel)
	 * همچنان کار می‌کند و معمولاً کیفیت فشرده‌سازی بهتری از GD ساده دارد.
	 */
	public static function has_imagick_webp() {
		return self::imagick_supports( 'webp', 'WEBP*' );
	}

	public static function has_imagick_avif() {
		return self::imagick_supports( 'avif', 'AVIF*' );
	}

	private static function imagick_supports( $key, $query ) {
		if ( array_key_exists( $key, self::$imagick_cache ) ) {
			return self::$imagick_cache[ $key ];
		}
		if ( ! class_exists( 'Imagick' ) ) {
			return self::$imagick_cache[ $key ] = false;
		}
		try {
			$formats = Imagick::queryFormats( $query );
			return self::$imagick_cache[ $key ] = ! empty( $formats );
		} catch ( Exception $e ) {
			return self::$imagick_cache[ $key ] = false;
		}
	}

	/**
	 * تشخیص مستقل از exec() برای داشبورد: آیا فایل باینری همراه افزونه اصلاً
	 * روی دیسک هست؟ این جدا از find_binary() است چون find_binary() اگر exec()
	 * غیرفعال باشد اصلاً به سراغ چک‌کردن فایل نمی‌رود - و بدون این تشخیص جدا،
	 * نمی‌شود «فایل آپلود نشده» را از «فایل هست ولی exec() بسته است» تشخیص داد
	 * (این ابهام دقیقاً چیزی است که در پشتیبانی هاست‌های cPanel پیش می‌آید).
	 */
	private static function bundled_file_status( $name ) {
		$platform_dir = self::bundled_platform_dir();
		if ( ! $platform_dir ) {
			return array( 'platform_dir' => false, 'exists' => false, 'path' => '' );
		}
		$path = OSN_IMG_DIR . 'assets/bin/' . $platform_dir . '/' . $name;
		return array(
			'platform_dir' => $platform_dir,
			'exists'       => file_exists( $path ),
			'path'         => $path,
		);
	}

	/**
	 * وضعیت موتورهای فعال - برای نمایش در داشبورد سلامت.
	 * ترتیب اولویت واقعی موتور استفاده‌شده در save_webp()/save_avif():
	 * ۱) cwebp/avifenc (نیاز به exec)  ۲) Imagick (بدون نیاز به exec)  ۳) GD
	 */
	public static function engine_status() {
		$cwebp        = self::find_binary( 'cwebp' );
		$avif         = self::find_binary( 'avifenc' );
		$imagick_webp = self::has_imagick_webp();
		$imagick_avif = self::has_imagick_avif();
		$gd_webp      = function_exists( 'imagewebp' );
		$gd_avif      = function_exists( 'imageavif' );

		$webp_engine = $cwebp ? 'shell' : ( $imagick_webp ? 'imagick' : ( $gd_webp ? 'gd' : 'none' ) );
		$avif_engine = $avif ? 'shell' : ( $imagick_avif ? 'imagick' : ( $gd_avif ? 'gd' : 'none' ) );

		return array(
			'cwebp_path'           => $cwebp ?: '',
			'cwebp_bundled'        => ! empty( self::$bin_is_bundled['cwebp'] ),
			'cwebp_bundled_file'   => self::bundled_file_status( 'cwebp' ),
			'avifenc_path'         => $avif ?: '',
			'avifenc_bundled'      => ! empty( self::$bin_is_bundled['avifenc'] ),
			'avifenc_bundled_file' => self::bundled_file_status( 'avifenc' ),
			'imagick_webp'         => $imagick_webp,
			'imagick_avif'         => $imagick_avif,
			'webp_engine'          => $webp_engine, // shell | imagick | gd | none
			'avif_engine'          => $avif_engine,
			'gd_webp'              => $gd_webp,
			'gd_avif'              => $gd_avif,
			'shell'                => self::shell_available(),
			'exec_disabled'        => ! function_exists( 'exec' ) || in_array( 'exec', self::disabled_functions(), true ),
		);
	}

	/**
	 * ذخیره تصویر GD به‌صورت WebP با بهترین موتور در دسترس.
	 * ابتدا cwebp (libwebp، روش فشرده‌سازی 6 = حداکثری) و در صورت نبود/شکست، GD.
	 *
	 * @param resource|GdImage $image
	 * @param string           $dest_path
	 * @param int              $quality  0-100 (Lossless جدا مدیریت می‌شود)
	 * @param bool             $lossless
	 * @return bool موفقیت
	 */
	public static function save_webp( $image, $dest_path, $quality, $lossless = false ) {
		if ( self::has_cwebp() ) {
			$ok = self::save_webp_via_cwebp( $image, $dest_path, $quality, $lossless );
			if ( $ok ) {
				return true;
			}
			OSN_Logger::debug( 'cwebp ناموفق بود، بازگشت به مرحله بعد برای ' . wp_basename( $dest_path ) );
		}

		if ( self::has_imagick_webp() ) {
			$ok = self::save_webp_via_imagick( $image, $dest_path, $quality, $lossless );
			if ( $ok ) {
				return true;
			}
			OSN_Logger::debug( 'Imagick WebP ناموفق بود، بازگشت به GD برای ' . wp_basename( $dest_path ) );
		}

		// Fallback: روش فعلی GD
		if ( $lossless ) {
			$q = defined( 'IMG_WEBP_LOSSLESS' ) ? IMG_WEBP_LOSSLESS : 100;
		} else {
			$q = $quality;
		}
		return (bool) @imagewebp( $image, $dest_path, $q );
	}

	/**
	 * ذخیره WebP با Imagick - بدون نیاز به exec()/shell_exec()، مناسب هاست‌هایی
	 * که این توابع را برای امنیت بسته‌اند (رایج در هاست‌های اشتراکی cPanel).
	 */
	private static function save_webp_via_imagick( $image, $dest_path, $quality, $lossless ) {
		$tmp_png = self::temp_path();
		imagealphablending( $image, false );
		imagesavealpha( $image, true );
		if ( ! @imagepng( $image, $tmp_png, 1 ) ) {
			@unlink( $tmp_png );
			return false;
		}

		$ok = false;
		try {
			$im = new Imagick( $tmp_png );
			$im->setImageFormat( 'webp' );
			if ( $lossless ) {
				$im->setOption( 'webp:lossless', 'true' );
			} else {
				$im->setImageCompressionQuality( max( 1, min( 100, (int) $quality ) ) );
				$im->setOption( 'webp:method', '6' ); // بیشترین فشرده‌سازی
			}
			$ok = $im->writeImage( $dest_path );
			$im->clear();
			$im->destroy();
		} catch ( Exception $e ) {
			OSN_Logger::debug( 'Imagick WebP خطا: ' . $e->getMessage() );
			$ok = false;
		}

		@unlink( $tmp_png );
		return $ok && file_exists( $dest_path ) && filesize( $dest_path ) > 0;
	}

	/**
	 * فشرده‌سازی با cwebp: عبور از یک PNG بی‌افت میانی (چون cwebp ورودی فایل می‌خواهد نه resource)
	 * تنظیمات: -m 6 (قوی‌ترین روش)، -mt (چندنخی)، -sharp_yuv (کیفیت رنگ بهتر لبه‌ها)، -af (فیلتر تطبیقی)
	 */
	private static function save_webp_via_cwebp( $image, $dest_path, $quality, $lossless ) {
		$tmp_png = self::temp_path();
		imagealphablending( $image, false );
		imagesavealpha( $image, true );
		if ( ! @imagepng( $image, $tmp_png, 1 ) ) { // فشرده‌سازی سریع PNG سطح 1؛ کیفیت بی‌افت است، سرعت مهم‌تر است
			@unlink( $tmp_png );
			return false;
		}

		$cwebp = self::find_binary( 'cwebp' );
		$args  = $lossless
			? '-lossless -z 9 -m 6'
			: '-q ' . (int) max( 1, min( 100, $quality ) ) . ' -m 6 -af -sharp_yuv';

		// escapeshellarg (نه escapeshellcmd) چون مسیر باینری همراه افزونه یک آرگومان
		// کامل و مطلق است (ممکن است در پوشه‌ای با نام غیرمعمول باشد)، نه بخشی از دستور شل.
		$cmd = escapeshellarg( $cwebp ) . ' ' . $args . ' -mt -quiet '
			. escapeshellarg( $tmp_png ) . ' -o ' . escapeshellarg( $dest_path ) . ' 2>&1';

		@exec( $cmd, $out, $exit_code );
		@unlink( $tmp_png );

		return ( 0 === (int) $exit_code && file_exists( $dest_path ) && filesize( $dest_path ) > 0 );
	}

	/**
	 * ذخیره AVIF با بهترین موتور در دسترس: avifenc (libavif) یا GD
	 */
	public static function save_avif( $image, $dest_path, $quality ) {
		if ( self::has_avifenc() ) {
			$ok = self::save_avif_via_avifenc( $image, $dest_path, $quality );
			if ( $ok ) {
				return true;
			}
			OSN_Logger::debug( 'avifenc ناموفق بود، بازگشت به مرحله بعد برای ' . wp_basename( $dest_path ) );
		}

		if ( self::has_imagick_avif() ) {
			$ok = self::save_avif_via_imagick( $image, $dest_path, $quality );
			if ( $ok ) {
				return true;
			}
			OSN_Logger::debug( 'Imagick AVIF ناموفق بود، بازگشت به GD برای ' . wp_basename( $dest_path ) );
		}

		if ( ! function_exists( 'imageavif' ) ) {
			return false;
		}
		return (bool) @imageavif( $image, $dest_path, min( 100, $quality ) );
	}

	/** ذخیره AVIF با Imagick - بدون نیاز به exec()/shell_exec() */
	private static function save_avif_via_imagick( $image, $dest_path, $quality ) {
		$tmp_png = self::temp_path();
		imagealphablending( $image, false );
		imagesavealpha( $image, true );
		if ( ! @imagepng( $image, $tmp_png, 1 ) ) {
			@unlink( $tmp_png );
			return false;
		}

		$ok = false;
		try {
			$im = new Imagick( $tmp_png );
			$im->setImageFormat( 'avif' );
			$im->setImageCompressionQuality( max( 1, min( 100, (int) $quality ) ) );
			$ok = $im->writeImage( $dest_path );
			$im->clear();
			$im->destroy();
		} catch ( Exception $e ) {
			OSN_Logger::debug( 'Imagick AVIF خطا: ' . $e->getMessage() );
			$ok = false;
		}

		@unlink( $tmp_png );
		return $ok && file_exists( $dest_path ) && filesize( $dest_path ) > 0;
	}

	/**
	 * فشرده‌سازی با avifenc: سرعت 6 (تعادل سرعت/کیفیت)، cq-level متناسب با کیفیت درخواستی
	 */
	private static function save_avif_via_avifenc( $image, $dest_path, $quality ) {
		$tmp_png = self::temp_path();
		imagealphablending( $image, false );
		imagesavealpha( $image, true );
		if ( ! @imagepng( $image, $tmp_png, 1 ) ) {
			@unlink( $tmp_png );
			return false;
		}

		// نگاشت کیفیت 0-100 (بالاتر=بهتر) به cq-level libaom (0-63، پایین‌تر=بهتر)
		$cq   = (int) round( 63 - ( max( 1, min( 100, $quality ) ) / 100 * 63 ) );
		$avif = self::find_binary( 'avifenc' );
		$cmd  = escapeshellarg( $avif ) . ' -a end-usage=q -a cq-level=' . $cq . ' -s 6 --min 0 --max 63 '
			. escapeshellarg( $tmp_png ) . ' ' . escapeshellarg( $dest_path ) . ' 2>&1';

		@exec( $cmd, $out, $exit_code );
		@unlink( $tmp_png );

		return ( 0 === (int) $exit_code && file_exists( $dest_path ) && filesize( $dest_path ) > 0 );
	}
}
