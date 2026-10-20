<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * منوی اصلی: OptiSnap (نسخه ۳)
 *
 * تب‌ها:
 *  Health    - داشبورد سلامت تصاویر
 *  Images    - Media Manager (پردازش گروهی با توقف/ادامه پایدار در دیتابیس)
 *  Watermark - واترمارک دستی با Preview
 *  Cleaner   - تصاویر تکراری / بدون محصول / خیلی بزرگ
 *  SEO       - تصاویر بدون Alt + ساخت Alt از نام محصول
 *  Logs      - لاگ کامل عملیات
 */
class OSN_Manager_Page {

	const BULK_STATE = 'osn_img_bulk_state'; // وضعیت صف در دیتابیس (Stop/Resume پایدار)

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'wp_ajax_osn_toggle_exclude', array( $this, 'ajax_toggle_exclude' ) );
		add_action( 'wp_ajax_osn_watermark_one', array( $this, 'ajax_watermark_one' ) );
		add_action( 'wp_ajax_osn_bulk_start', array( $this, 'ajax_bulk_start' ) );
		add_action( 'wp_ajax_osn_bulk_step', array( $this, 'ajax_bulk_step' ) );
		add_action( 'wp_ajax_osn_bulk_stop', array( $this, 'ajax_bulk_stop' ) );
		add_action( 'wp_ajax_osn_delete_attachment', array( $this, 'ajax_delete_attachment' ) );
		add_action( 'wp_ajax_osn_seo_alt', array( $this, 'ajax_seo_alt' ) );
		add_action( 'wp_ajax_osn_view_backup', array( $this, 'ajax_view_backup' ) );
		add_action( 'wp_ajax_osn_adjust_brightness', array( $this, 'ajax_adjust_brightness' ) );
		add_action( 'admin_post_osn_clear_logs', array( $this, 'handle_clear_logs' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_product_metabox' ) );
	}

	public function add_menu() {
		// v3.6.1: منوی واحد - همه بخش‌ها (داشبورد، مدیریت، واترمارک، ابزار، تنظیمات) زیر یک منو
		add_menu_page(
			'OptiSnap',
			'OptiSnap',
			'manage_options',
			'osn-image-manager',
			array( $this, 'render_page' ),
			'dashicons-format-gallery',
			58
		);
		add_submenu_page(
			'osn-image-manager',
			'داشبورد و مدیریت تصاویر',
			'🏥 داشبورد و مدیریت',
			'manage_options',
			'osn-image-manager',
			array( $this, 'render_page' )
		);
		// زیرمنوی «💧 OptiSnap Watermark» را کلاس OSN_Watermark_Manager با اولویت بعدی اضافه می‌کند
		add_submenu_page(
			'osn-image-manager',
			'ابزارها (اسکن / Backup / Cron)',
			'🛠 ابزارها',
			'manage_options',
			'tools.php?page=osn-image-batch'
		);
		add_submenu_page(
			'osn-image-manager',
			'تنظیمات یکپارچه',
			'⚙️ تنظیمات',
			'manage_options',
			'options-general.php?page=osn-image-settings'
		);
	}

	/* =====================================================================
	 * نشان نقش تصویر (سیستم تشخیص نقش)
	 * =================================================================== */

	public static function role_badge( $role, $excluded = false ) {
		if ( $excluded ) {
			return '<span class="osn-badge osn-b-gray">⛔ استثناء</span>';
		}
		switch ( $role ) {
			case 'featured':
				return '<span class="osn-badge osn-b-gold">⭐ Featured Product Image</span>';
			case 'gallery':
				return '<span class="osn-badge osn-b-blue">🖼 Product Gallery</span>';
			default:
				return '<span class="osn-badge osn-b-gray">📄 Other Media</span>';
		}
	}

	/**
	 * توضیح دقیق وضعیت یک موتور فشرده‌سازی (WebP/AVIF) برای داشبورد سلامت.
	 * سه سطح واقعی که save_webp()/save_avif() امتحان می‌کنند را منعکس می‌کند:
	 * ۱) cwebp/avifenc (نیاز به exec)  ۲) Imagick (بدون نیاز به exec)  ۳) GD
	 * به‌جای فقط «نصب نیست»، دلیل واقعی را نشان می‌دهد تا کاربر بداند مشکل
	 * از فایل آپلودنشده است، exec() هاست بسته است، یا اصلاً چیزی لازم نیست
	 * چون Imagick از قبل جواب داده (رایج‌ترین ابهام در هاست‌های cPanel).
	 *
	 * @param array  $enc    خروجی OSN_Encoder::engine_status()
	 * @param string $format 'webp' یا 'avif'
	 */
	private static function engine_diag_line( $enc, $format ) {
		$name        = ( 'avif' === $format ) ? 'avifenc' : 'cwebp';
		$engine      = ( 'avif' === $format ) ? $enc['avif_engine'] : $enc['webp_engine'];
		$active_path = ( 'avif' === $format ) ? $enc['avifenc_path'] : $enc['cwebp_path'];
		$is_bundled  = ( 'avif' === $format ) ? $enc['avifenc_bundled'] : $enc['cwebp_bundled'];
		$file_status = ( 'avif' === $format ) ? $enc['avifenc_bundled_file'] : $enc['cwebp_bundled_file'];

		if ( 'shell' === $engine ) {
			$src = $is_bundled ? ' (همراه افزونه)' : ' (نصب‌شده روی هاست)';
			return '<span style="color:#00a32a;">✅ فعال - ' . esc_html( $name ) . $src . '</span>';
		}

		if ( 'imagick' === $engine ) {
			return '<span style="color:#00a32a;">✅ فعال از طریق Imagick (بدون نیاز به exec)</span>';
		}

		// از اینجا به بعد یعنی نه cwebp/avifenc فعاله نه Imagick - یا GD یا هیچ‌کدام
		$gd_fallback = ( 'gd' === $engine );
		$tail        = $gd_fallback ? '؛ از GD استفاده می‌شود' : '؛ هیچ موتوری برای این فرمت در دسترس نیست';
		$imagick_hint = class_exists( 'Imagick' ) ? '' : ' یا افزونه <code>imagick</code> را از «Select PHP Extensions» در cPanel فعال کنید (بدون نیاز به exec)';

		if ( ! empty( $enc['exec_disabled'] ) ) {
			if ( ! empty( $file_status['exists'] ) ) {
				return '<span style="color:#dba617;">⚠️ فایل در <code>assets/bin/' . esc_html( $file_status['platform_dir'] ) . '/' . esc_html( $name ) . '</code> هست، اما اجرای برنامه خارجی (<code>exec</code>) در PHP این هاست غیرفعال است' . $tail . '.' . $imagick_hint . '</span>';
			}
			return '<span style="color:#646970;">— exec() در PHP این هاست غیرفعال است' . $tail . '.' . $imagick_hint . '</span>';
		}

		if ( false === $file_status['platform_dir'] ) {
			return '<span style="color:#646970;">— سیستم‌عامل/معماری سرور خودکار شناسایی نشد' . $tail . '.</span>';
		}

		if ( ! empty( $file_status['exists'] ) ) {
			return '<span style="color:#dba617;">⚠️ فایل در <code>assets/bin/' . esc_html( $file_status['platform_dir'] ) . '/' . esc_html( $name ) . '</code> هست ولی قابل اجرا نشد (مجوز فایل یا محدودیت هاست)' . $tail . '.</span>';
		}

		return '<span style="color:#646970;">— نصب نیست' . $tail . '. مسیر مورد انتظار: <code>assets/bin/' . esc_html( $file_status['platform_dir'] ) . '/' . esc_html( $name ) . '</code></span>';
	}

	/* =====================================================================
	 * AJAX: صف پایدار (Pause / Resume / Stop با ذخیره در دیتابیس)
	 * =================================================================== */

	private function guard() {
		check_ajax_referer( 'osn_batch_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'دسترسی غیرمجاز', 403 );
		}
	}

	public function ajax_bulk_start() {
		$this->guard();
		$ids = isset( $_POST['ids'] ) ? array_filter( array_map( 'absint', (array) $_POST['ids'] ) ) : array();
		if ( empty( $ids ) ) {
			wp_send_json_error( 'تصویری انتخاب نشده' );
		}
		$allowed = array( 'optimize', 'watermark', 'sizes', 'wm_optimize', 'force', 'restore' );
		$op      = ( isset( $_POST['op'] ) && in_array( $_POST['op'], $allowed, true ) ) ? sanitize_key( $_POST['op'] ) : 'optimize';

		update_option( self::BULK_STATE, array(
			'op'    => $op,
			'queue' => array_values( $ids ),
			'total' => count( $ids ),
			'done'  => 0,
			'time'  => time(),
		), false );

		wp_send_json_success( array( 'total' => count( $ids ) ) );
	}

	public function ajax_bulk_step() {
		$this->guard();

		// v3.9.1: قفل هم‌زمانی - دو درخواست موازی (دابل‌کلیک/دو تب مرورگر) نباید
		// همزمان از صف pop کنند؛ وگرنه یک آیتم از دست می‌رود یا دوبار پردازش می‌شود.
		// GET_LOCK اتمیک است و با پایان درخواست خودکار آزاد می‌شود.
		global $wpdb;
		$lock = $wpdb->get_var( "SELECT GET_LOCK('osn_img_bulk_step', 0)" );
		if ( '0' === (string) $lock ) {
			wp_send_json_success( array( 'finished' => false, 'busy' => true ) );
		}

		$state = get_option( self::BULK_STATE, array() );
		if ( empty( $state['queue'] ) ) {
			if ( '1' === (string) $lock ) {
				$wpdb->query( "SELECT RELEASE_LOCK('osn_img_bulk_step')" );
			}
			delete_option( self::BULK_STATE );
			wp_send_json_success( array( 'finished' => true ) );
		}

		@set_time_limit( 90 );

		$id      = (int) array_shift( $state['queue'] );
		$started = microtime( true );

		switch ( $state['op'] ) {
			case 'watermark':
				$result = OSN_Image_Processor::watermark_attachment( $id );
				break;

			case 'sizes':
				// فقط بازسازی سایزهای srcset و متادیتا
				$result = OSN_Image_Processor::rebuild_sizes( $id );
				break;

			case 'wm_optimize':
				// Safe Processing (v3.2) به ترتیب استاندارد:
				// 1) Backup  2) Watermark  3) Optimize (WebP/AVIF)
				// 4) Responsive Sizes  5) Update Metadata
				// (Backup داخل هر دو مرحله انجام می‌شود؛ سایز و متادیتا داخل Optimize)
				$wm = OSN_Image_Processor::watermark_attachment( $id );
				if ( is_wp_error( $wm ) ) {
					$result = $wm; // بدون Backup موفق، ادامه نمی‌دهیم
					break;
				}
				$result = OSN_Image_Processor::process_attachment( $id );
				break;

			case 'force':
				// Force Optimize (v3.4): پردازش مجدد به دستور مدیر، بدون Skip هوشمند
				delete_post_meta( $id, OSN_IMG_META_FLAG );
				delete_post_meta( $id, '_osn_skip_reason' );
				$result = OSN_Image_Processor::process_attachment( $id, true );
				break;

			case 'restore':
				$result = OSN_Image_Processor::restore_any( $id );
				if ( 'restored' === $result ) {
					$result = 'done';
				}
				break;

			default:
				$result = OSN_Image_Processor::process_attachment( $id );
		}

		$state['done']++;
		if ( empty( $state['queue'] ) ) {
			delete_option( self::BULK_STATE );
		} else {
			update_option( self::BULK_STATE, $state, false );
		}

		$cur_file = get_attached_file( $id );
		$out = array(
			'finished' => empty( $state['queue'] ),
			'id'       => $id,
			'done'     => $state['done'],
			'total'    => $state['total'],
			'duration' => round( microtime( true ) - $started, 2 ),
			'file'     => $cur_file ? wp_basename( $cur_file ) : ( 'ID ' . $id ),
		);

		if ( is_wp_error( $result ) ) {
			$out['status'] = 'error';
			$out['error']  = $result->get_error_message();
			update_post_meta( $id, OSN_IMG_META_FLAG, 'error' );
		} else {
			$out['status'] = $result; // done | skipped | auto_skipped
			if ( 'auto_skipped' === $result ) {
				$out['reason'] = get_post_meta( $id, '_osn_skip_reason', true );
			}
			$report = get_post_meta( $id, OSN_IMG_META_REPORT, true );
			if ( is_array( $report ) && ! empty( $report['before'] ) ) {
				$out['report'] = array(
					'before_h'  => size_format( $report['before'], 1 ),
					'after_h'   => size_format( $report['after'], 1 ),
					'percent'   => (int) $report['percent'],
					'quality'   => isset( $report['quality'] ) ? $report['quality'] : '',
					'duration'  => isset( $report['duration'] ) ? $report['duration'] : $out['duration'],
					'avif_h'    => ! empty( $report['avif'] ) ? size_format( $report['avif'], 1 ) : '',
				);
			}
			$out['thumb'] = wp_get_attachment_image_url( $id, 'thumbnail' );
		}

		if ( '1' === (string) $lock ) {
			$wpdb->query( "SELECT RELEASE_LOCK('osn_img_bulk_step')" );
		}
		wp_send_json_success( $out );
	}

	public function ajax_bulk_stop() {
		$this->guard();
		delete_option( self::BULK_STATE );
		wp_send_json_success();
	}

	/* =====================================================================
	 * AJAX: سایر عملیات
	 * =================================================================== */

	public function ajax_toggle_exclude() {
		$this->guard();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( 'شناسه نامعتبر' );
		}
		if ( get_post_meta( $id, OSN_IMG_META_EXCLUDED, true ) ) {
			delete_post_meta( $id, OSN_IMG_META_EXCLUDED );
			wp_send_json_success( array( 'excluded' => false ) );
		}
		update_post_meta( $id, OSN_IMG_META_EXCLUDED, 1 );
		OSN_Logger::db( $id, 'Exclude', 0, 0, 'SUCCESS' );
		wp_send_json_success( array( 'excluded' => true ) );
	}

	public function ajax_watermark_one() {
		$this->guard();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		@set_time_limit( 60 );
		$result = OSN_Image_Processor::watermark_attachment( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( array(
			'status' => $result,
			'url'    => wp_get_attachment_image_url( $id, 'thumbnail' ),
		) );
	}

	/** تنظیم دستی نور یک تصویر (تب Media Manager) */
	public function ajax_adjust_brightness() {
		$this->guard();
		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$level = isset( $_POST['level'] ) ? (int) $_POST['level'] : 0;
		if ( ! $id ) {
			wp_send_json_error( 'شناسه نامعتبر' );
		}
		@set_time_limit( 60 );
		$result = OSN_Image_Processor::adjust_brightness( $id, $level );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( array(
			'level' => max( -100, min( 100, $level ) ),
			'url'   => wp_get_attachment_image_url( $id, 'medium' ),
		) );
	}

	/** حذف تصویر (تب Cleaner) - با احتیاط کامل */
	public function ajax_delete_attachment() {
		$this->guard();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			wp_send_json_error( 'شناسه نامعتبر' );
		}

		// ایمنی: تصویری که به محصول وصل است هرگز از Cleaner حذف نشود
		if ( OSN_Image_Processor::get_related_product_id( $id ) ) {
			wp_send_json_error( 'این تصویر به یک محصول متصل است و از این بخش قابل حذف نیست.' );
		}

		$file   = get_attached_file( $id );
		$before = ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0;

		$deleted = wp_delete_attachment( $id, true );
		if ( ! $deleted ) {
			wp_send_json_error( 'حذف ناموفق بود.' );
		}

		OSN_Logger::db( $id, 'Delete (Cleaner)', $before, 0, 'SUCCESS' );
		wp_send_json_success( array( 'freed' => size_format( $before, 1 ) ) );
	}

	/** ساخت Alt پیشنهادی (تب SEO) */
	public function ajax_seo_alt() {
		$this->guard();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( 'شناسه نامعتبر' );
		}

		$prod_id = OSN_Image_Processor::get_related_product_id( $id );
		if ( $prod_id ) {
			$alt = get_the_title( $prod_id );
		} else {
			// نام فایل انسانی‌شده: IMG_2026.jpg → IMG 2026
			$file = get_attached_file( $id );
			$alt  = $file ? preg_replace( '/[-_]+/', ' ', pathinfo( $file, PATHINFO_FILENAME ) ) : '';
			$alt  = trim( preg_replace( '/' . preg_quote( OSN_IMG_SUFFIX, '/' ) . '$/', '', $alt ) );
		}

		if ( '' === $alt ) {
			wp_send_json_error( 'متن Alt قابل ساخت نبود.' );
		}

		update_post_meta( $id, '_wp_attachment_image_alt', $alt );

		// TITLE: اگر خالی یا فقط نام فایل خام بود، همان پیشنهاد ثبت شود (v3.2)
		$att = get_post( $id );
		if ( $att && ( '' === trim( $att->post_title ) || preg_match( '/^(img|dsc|image)[-_ ]?\d+/i', $att->post_title ) ) ) {
			wp_update_post( array( 'ID' => $id, 'post_title' => $alt ) );
		}

		OSN_Logger::db( $id, 'SEO Alt+Title', 0, 0, 'SUCCESS', $alt );
		wp_send_json_success( array( 'alt' => $alt ) );
	}

	/**
	 * نمایش امن فایل Backup برای دکمه Compare (v3.2)
	 * پوشه osn-backup با .htaccess بسته است؛ این پراکسی فقط برای مدیر با nonce کار می‌کند.
	 */
	public function ajax_view_backup() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'osn_batch_nonce', 'nonce', false ) ) {
			wp_die( 'دسترسی غیرمجاز', 403 );
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		$path = get_post_meta( $id, OSN_IMG_META_ORIGINAL, true );
		if ( ! $path ) {
			$path = get_post_meta( $id, '_osn_wm_original', true );
		}
		if ( ! $path || ! file_exists( $path ) ) {
			wp_die( 'Backup یافت نشد', 404 );
		}

		// ایمنی: مسیر باید داخل پوشه Backup باشد (جلوگیری از Path Traversal)
		// v3.9.1: بررسی با پسوند جداکننده مسیر - قبلاً strpos بدون '/' پایانی،
		// پوشه‌هایی با پیشوند یکسان (مثل osn-backup-evil) را هم قبول می‌کرد.
		$real   = realpath( $path );
		$backup = realpath( osn_img_backup_dir() );
		if ( ! $real || ! $backup || 0 !== strpos( $real, $backup . DIRECTORY_SEPARATOR ) ) {
			wp_die( 'مسیر نامعتبر', 403 );
		}

		$info = @getimagesize( $real );
		$mime = ( $info && ! empty( $info['mime'] ) ) ? $info['mime'] : 'application/octet-stream';
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . filesize( $real ) );
		header( 'Cache-Control: private, max-age=300' );
		readfile( $real );
		exit;
	}

	public function handle_clear_logs() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'osn_clear_logs' ) ) {
			wp_die( 'دسترسی غیرمجاز' );
		}
		OSN_Logger::clear_logs();
		wp_safe_redirect( admin_url( 'admin.php?page=osn-image-manager&tab=logs&cleared=1' ) );
		exit;
	}

	/* =====================================================================
	 * Product Image Panel - متاباکس صفحه ویرایش محصول
	 * =================================================================== */

	public function add_product_metabox() {
		add_meta_box(
			'osn_product_images',
			'🖼 وضعیت تصاویر (OptiSnap)',
			array( $this, 'render_product_metabox' ),
			'product',
			'side',
			'low'
		);
	}

	public function render_product_metabox( $post ) {
		$ids = array();
		$thumb = get_post_thumbnail_id( $post->ID );
		if ( $thumb ) {
			$ids[ $thumb ] = 'تصویر اصلی';
		}
		$gallery = get_post_meta( $post->ID, '_product_image_gallery', true );
		if ( $gallery ) {
			foreach ( explode( ',', $gallery ) as $gid ) {
				$gid = absint( trim( $gid ) );
				if ( $gid && ! isset( $ids[ $gid ] ) ) {
					$ids[ $gid ] = 'گالری';
				}
			}
		}

		if ( empty( $ids ) ) {
			echo '<p>این محصول تصویری ندارد.</p>';
			return;
		}

		echo '<div style="direction:rtl; text-align:right; font-size:12px;">';
		foreach ( $ids as $id => $label ) {
			$file       = get_attached_file( $id );
			$size       = ( $file && file_exists( $file ) ) ? size_format( filesize( $file ), 1 ) : '—';
			$report     = get_post_meta( $id, OSN_IMG_META_REPORT, true );
			$flag       = get_post_meta( $id, OSN_IMG_META_FLAG, true );
			$excluded   = get_post_meta( $id, OSN_IMG_META_EXCLUDED, true );
			$wm         = get_post_meta( $id, OSN_IMG_META_WATERMARKED, true );
			$brightness = (int) get_post_meta( $id, '_osn_brightness', true );

			echo '<div style="border-bottom:1px solid #eee; padding:6px 0;">';
			echo '<strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $file ? wp_basename( $file ) : ( 'ID ' . $id ) ) . '<br>';

			// --- وضعیت بهینه‌سازی ---
			if ( $excluded ) {
				echo '<span style="color:#8a6116;">⛔ استثناء از پردازش</span>';
			} elseif ( is_array( $report ) && ! empty( $report['before'] ) ) {
				echo esc_html( size_format( $report['before'], 1 ) ) . ' ← <strong style="color:#00a32a;">' . esc_html( size_format( $report['after'], 1 ) ) . '</strong> (' . esc_html( $report['percent'] ) . '%- کاهش)';
			} elseif ( '1' === (string) $flag ) {
				echo '✅ بهینه‌شده - حجم: ' . esc_html( $size );
			} elseif ( 'skipped' === (string) $flag ) {
				echo '⏭ از قبل بهینه (رد شد) - حجم: ' . esc_html( $size );
			} elseif ( 'error' === (string) $flag ) {
				echo '<span style="color:#d63638;">❌ خطای پردازش</span>';
			} else {
				echo '<span style="color:#d63638;">بهینه‌نشده</span> - حجم فعلی: ' . esc_html( $size );
			}
			echo '<br>';

			// --- وضعیت واترمارک (پیش‌تر اینجا اصلاً نمایش داده نمی‌شد) ---
			echo '💧 واترمارک: ' . ( $wm ? '<span style="color:#00a32a;">دارد</span>' : '<span style="color:#646970;">ندارد</span>' );

			// --- نور دستی (در صورت اعمال) ---
			if ( 0 !== $brightness ) {
				echo ' · 🔆 نور: ' . esc_html( $brightness > 0 ? '+' . $brightness : $brightness );
			}

			echo '</div>';
		}
		echo '<p style="margin-top:8px;"><a href="' . esc_url( admin_url( 'admin.php?page=osn-image-manager&tab=images&s=' . $post->ID ) ) . '">مدیریت در OptiSnap ←</a></p>';
		echo '</div>';
	}

	/* =====================================================================
	 * کوئری لیست تصاویر (تب Images)
	 * =================================================================== */

	private function query_images() {
		$per_page = isset( $_GET['per_page'] ) && in_array( (int) $_GET['per_page'], array( 20, 50, 100 ), true ) ? (int) $_GET['per_page'] : 20;
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$orderby  = isset( $_GET['orderby'] ) && 'size' === $_GET['orderby'] ? 'size' : 'date';
		$status   = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
		$format   = isset( $_GET['format'] ) ? sanitize_key( $_GET['format'] ) : 'all';
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		// فیلتر فرمت (OptiSnap WebP Converter - v3.4)
		$mimes = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );
		$fmt_map = array(
			'jpg'   => array( 'image/jpeg' ),
			'png'   => array( 'image/png' ),
			'webp'  => array( 'image/webp' ),
			'other' => array( 'image/gif' ),
		);
		if ( isset( $fmt_map[ $format ] ) ) {
			$mimes = $fmt_map[ $format ];
		}

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => $mimes,
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'no_found_rows'  => false,
		);

		if ( 'size' === $orderby ) {
			$args['meta_key'] = OSN_IMG_META_FILESIZE;
			$args['orderby']  = 'meta_value_num';
			$args['order']    = 'DESC';
		} else {
			$args['orderby'] = 'date';
			$args['order']   = 'DESC';
		}

		switch ( $status ) {
			case 'unprocessed':
				$args['meta_query'][] = array( 'key' => OSN_IMG_META_FLAG, 'compare' => 'NOT EXISTS' );
				break;
			case 'processed':
				$args['meta_query'][] = array( 'key' => OSN_IMG_META_FLAG, 'value' => '1' );
				break;
			case 'excluded':
				$args['meta_query'][] = array( 'key' => OSN_IMG_META_EXCLUDED, 'compare' => 'EXISTS' );
				break;
			case 'errors':
				$args['meta_query'][] = array( 'key' => OSN_IMG_META_FLAG, 'value' => 'error' );
				break;
			case 'skipped':
				$args['meta_query'][] = array( 'key' => OSN_IMG_META_FLAG, 'value' => 'skipped' );
				break;
		}

		if ( '' !== $search ) {
			if ( is_numeric( $search ) ) {
				// شناسه تصویر یا شناسه محصول
				$sid = (int) $search;
				$ids = array( $sid );
				if ( 'product' === get_post_type( $sid ) ) {
					$ids = array();
					$t   = get_post_thumbnail_id( $sid );
					if ( $t ) { $ids[] = $t; }
					$g = get_post_meta( $sid, '_product_image_gallery', true );
					if ( $g ) {
						foreach ( explode( ',', $g ) as $gid ) { $ids[] = (int) trim( $gid ); }
					}
				}
				$args['post__in'] = ! empty( $ids ) ? $ids : array( 0 );
			} else {
				$ids = get_posts( array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					's'              => $search,
					'fields'         => 'ids',
					'posts_per_page' => 300,
				) );
				$products = get_posts( array(
					'post_type'      => 'product',
					'post_status'    => 'any',
					's'              => $search,
					'fields'         => 'ids',
					'posts_per_page' => 50,
				) );
				foreach ( $products as $pid ) {
					$t = get_post_thumbnail_id( $pid );
					if ( $t ) { $ids[] = (int) $t; }
					$g = get_post_meta( $pid, '_product_image_gallery', true );
					if ( $g ) {
						foreach ( explode( ',', $g ) as $gid ) { $ids[] = (int) trim( $gid ); }
					}
				}
				$ids = array_values( array_unique( array_filter( $ids ) ) );
				$args['post__in'] = ! empty( $ids ) ? $ids : array( 0 );
			}
		}

		return new WP_Query( $args );
	}

	/* =====================================================================
	 * رندر صفحه اصلی
	 * =================================================================== */

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی غیرمجاز' );
		}

		$tab   = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'health';
		$nonce = wp_create_nonce( 'osn_batch_nonce' );
		$base  = admin_url( 'admin.php?page=osn-image-manager' );
		// v3.6.1: تب واترمارک اینجا حذف شد؛ نسخه کامل زیرمنوی «💧 OptiSnap Watermark» همین منوست
		$tabs  = array(
			'health'  => '🏥 Image Health',
			'images'  => '🗂 Media Manager',
			'cleaner' => '🧹 Cleaner',
			'seo'     => '🔍 SEO Tools',
			'logs'    => '📋 Logs',
		);
		?>
		<div class="wrap" style="direction:rtl; text-align:right;">
			<h1>🖼️ OptiSnap</h1>

			<nav class="nav-tab-wrapper" style="margin-bottom:12px;">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( $base . '&tab=' . $key ); ?>" class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<style>
				.osn-tbl img{width:52px;height:52px;object-fit:cover;border-radius:6px;}
				.osn-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;margin:1px;white-space:nowrap;}
				.osn-b-green{background:#d1f0d9;color:#00591f;}
				.osn-b-red{background:#fcdcdc;color:#8a1f1f;}
				.osn-b-gray{background:#eee;color:#555;}
				.osn-b-gold{background:#fdf0d5;color:#8a6116;}
				.osn-b-blue{background:#d8e9f7;color:#1d4f78;}
				.osn-bar{background:#e5e5e5;border-radius:6px;overflow:hidden;height:22px;max-width:560px;}
				.osn-bar>div{background:linear-gradient(90deg,#2271b1,#72aee6);height:100%;width:0%;transition:width .3s;color:#fff;font-size:12px;line-height:22px;text-align:center;}
				.osn-report b{color:#00a32a;}
				.osn-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:14px 0;}
				.osn-hcard{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px;text-align:center;}
				.osn-hcard b{display:block;font-size:24px;color:#1d2327;}
				.osn-hcard span{color:#646970;font-size:12px;}
			</style>

			<?php
			switch ( $tab ) {
				case 'images':
					$this->render_images_tab( $nonce );
					break;
				case 'watermark':
					echo '<div class="notice notice-info"><p>بخش واترمارک به زیرمنوی <a href="' . esc_url( admin_url( 'admin.php?page=osn-watermark' ) ) . '"><strong>💧 OptiSnap Watermark</strong></a> منتقل شده است.</p></div>';
					$this->render_health_tab();
					break;
				case 'cleaner':
					$this->render_cleaner_tab( $nonce );
					break;
				case 'seo':
					$this->render_seo_tab( $nonce );
					break;
				case 'logs':
					$this->render_logs_tab();
					break;
				default:
					$this->render_health_tab();
			}
			?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * تب Health - داشبورد سلامت تصاویر
	 * ------------------------------------------------------------------- */

	private function render_health_tab() {
		$scan    = get_option( OSN_Batch_Tool::SCAN_OPTION, array() );
		$has     = ! empty( $scan['total'] );
		$settings = osn_img_get_settings();

		$unprocessed = OSN_Batch_Tool::count_for_mode( 'all' );

		// شمارش واترمارک (v3.2)
		$wm_count = function ( $exists ) {
			$q = new WP_Query( array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => array(
					array( 'key' => OSN_IMG_META_WATERMARKED, 'compare' => $exists ? 'EXISTS' : 'NOT EXISTS' ),
				),
			) );
			return (int) $q->found_posts;
		};
		$watermarked     = $wm_count( true );
		$not_watermarked = $wm_count( false );

		$freed = 0;
		if ( ! empty( $scan['saved_bytes'] ) ) {
			$freed = (int) $scan['saved_bytes'];
		}
		?>
		<h2>داشبورد سلامت تصاویر</h2>
		<?php if ( ! $has ) : ?>
			<div class="notice notice-info"><p>برای آمار دقیق، یک‌بار <a href="<?php echo esc_url( admin_url( 'tools.php?page=osn-image-batch' ) ); ?>">اسکن کامل</a> را اجرا کنید.</p></div>
		<?php endif; ?>

		<div class="osn-cards">
			<div class="osn-hcard"><b><?php echo $has ? esc_html( number_format_i18n( $scan['total'] ) ) : '—'; ?></b><span>تعداد کل تصاویر</span></div>
			<div class="osn-hcard"><b><?php echo $has ? esc_html( size_format( $scan['bytes'], 1 ) ) : '—'; ?></b><span>حجم کل</span></div>
			<div class="osn-hcard"><b style="color:#00a32a;"><?php echo esc_html( size_format( $freed, 1 ) ); ?></b><span>حجم آزادشده</span></div>
			<div class="osn-hcard"><b><?php echo $has && isset( $scan['webp_count'] ) ? esc_html( number_format_i18n( $scan['webp_count'] ) ) : '—'; ?></b><span>تعداد WebP</span></div>
			<div class="osn-hcard"><b><?php echo $has && isset( $scan['avif_count'] ) ? esc_html( number_format_i18n( $scan['avif_count'] ) ) : '—'; ?></b><span>تعداد AVIF</span></div>
			<div class="osn-hcard"><b style="color:#d63638;"><?php echo esc_html( number_format_i18n( $unprocessed ) ); ?></b><span>تصاویر بهینه‌نشده</span></div>
			<div class="osn-hcard"><b style="color:#dba617;"><?php echo $has && isset( $scan['no_alt'] ) ? esc_html( number_format_i18n( $scan['no_alt'] ) ) : '—'; ?></b><span>تصاویر بدون Alt</span></div>
			<div class="osn-hcard"><b style="color:#d63638;"><?php echo $has ? esc_html( number_format_i18n( $scan['non_webp'] ) ) : '—'; ?></b><span>بدون WebP</span></div>
			<div class="osn-hcard"><b><?php echo $has && isset( $scan['avif_count'] ) ? esc_html( number_format_i18n( max( 0, (int) $scan['total'] - (int) $scan['avif_count'] ) ) ) : '—'; ?></b><span>بدون AVIF</span></div>
			<div class="osn-hcard"><b style="color:#dba617;"><?php echo $has && ! empty( $scan['heavy'] ) ? esc_html( number_format_i18n( count( $scan['heavy'] ) ) ) : '0'; ?></b><span>Large Files</span></div>
			<div class="osn-hcard"><b style="color:#00a32a;"><?php echo esc_html( number_format_i18n( $watermarked ) ); ?></b><span>Watermarked 💧</span></div>
			<div class="osn-hcard"><b><?php echo esc_html( number_format_i18n( $not_watermarked ) ); ?></b><span>Not Watermarked</span></div>
		</div>

		<?php if ( ! empty( $settings['responsive_sizes'] ) && absint( $settings['max_size'] ) < 1200 ) : ?>
			<div class="notice notice-info"><p>💡 نکته srcset: «حداکثر اندازه» شما <?php echo esc_html( $settings['max_size'] ); ?>px است، بنابراین نسخه دسکتاپ 1200 ساخته نمی‌شود (نسخه‌های 480 و 768 فعال‌اند). اگر نسخه 1200 می‌خواهید، حداکثر اندازه را در تنظیمات به 1200 یا بیشتر تغییر دهید.</p></div>
		<?php endif; ?>

		<div class="osn-cards" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr));">
			<div class="osn-hcard" style="text-align:right;">
				<strong>⚙️ وضعیت موتور</strong>
				<p style="margin:6px 0 0;">
					حالت: <strong><?php echo 'smart' === $settings['opt_mode'] ? 'Smart 🧠' : 'Manual'; ?></strong><br>
					WebP سرور: <?php echo OSN_Image_Processor::server_supports_webp() ? '✅' : '❌'; ?><br>
					AVIF سرور: <?php echo function_exists( 'imageavif' ) ? '✅' : '❌ (PHP 8.1+ لازم است)'; ?><br>
					<?php
					// v3.9.1: هشدار nginx - .htaccess فقط روی Apache اجرا می‌شود
					$osn_sw = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( (string) $_SERVER['SERVER_SOFTWARE'] ) : '';
					if ( false !== strpos( $osn_sw, 'nginx' ) ) :
						?>
						<span style="color:#8a6116;">⚠️ سرور nginx: پوشه <code>uploads/osn-backup/</code> با .htaccess بسته نمی‌شود؛ لطفاً در تنظیمات سرور (یا هاست) دسترسی مستقیم به مسیر <code>/wp-content/uploads/osn-backup/</code> را مسدود کنید.</span><br>
					<?php endif; ?>
					سایزهای ریسپانسیو: <?php echo ! empty( $settings['responsive_sizes'] ) ? '✅ 480/768/1200' : '❌'; ?>
				</p>
				<?php $enc = OSN_Encoder::engine_status(); ?>
				<p style="margin:10px 0 0; padding-top:8px; border-top:1px solid #eee;">
					<strong>🚀 موتور فشرده‌سازی پیشرفته (v3.7)</strong><br>
					WebP: <?php echo wp_kses_post( self::engine_diag_line( $enc, 'webp' ) ); ?>
					<br>
					AVIF: <?php echo wp_kses_post( self::engine_diag_line( $enc, 'avif' ) ); ?>
					<?php if ( 'gd' === $enc['webp_engine'] && 'gd' === $enc['avif_engine'] ) : ?>
						<br><span class="description">راهنمای کامل رفع این وضعیت در <code>assets/bin/README.md</code> داخل افزونه هست.</span>
					<?php endif; ?>
				</p>
			</div>
			<div class="osn-hcard" style="text-align:right;">
				<strong>🔗 دسترسی سریع</strong>
				<p style="margin:6px 0 0;">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=osn-image-manager&tab=images&status=unprocessed&orderby=size' ) ); ?>">سنگین‌ترین تصاویر بهینه‌نشده ←</a><br>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=osn-image-manager&tab=seo' ) ); ?>">رفع تصاویر بدون Alt ←</a><br>
					<a href="<?php echo esc_url( admin_url( 'tools.php?page=osn-image-batch' ) ); ?>">اسکن / Backup / پردازش خودکار ←</a><br>
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=osn-image-settings' ) ); ?>">تنظیمات ←</a>
				</p>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * تب Images - Media Manager
	 * ------------------------------------------------------------------- */

	private function render_images_tab( $nonce ) {
		$q        = $this->query_images();
		$per_page = isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : 20;
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$orderby  = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'date';
		$status   = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		// وضعیت صف نیمه‌کاره از دیتابیس (Resume پایدار)
		$pending_state = get_option( self::BULK_STATE, array() );
		$has_pending   = ! empty( $pending_state['queue'] );
		?>
		<?php if ( $has_pending ) : ?>
			<div class="notice notice-warning">
				<p>
					⏸ یک عملیات نیمه‌کاره وجود دارد:
					<strong><?php echo esc_html( $pending_state['done'] . ' / ' . $pending_state['total'] ); ?></strong> انجام شده
					(<?php echo 'watermark' === $pending_state['op'] ? 'واترمارک' : 'بهینه‌سازی'; ?>)
					<button class="button button-primary" id="osn-resume-db">▶️ ادامه از همان‌جا</button>
					<button class="button" id="osn-discard-db">✖ انصراف از صف</button>
				</p>
			</div>
		<?php endif; ?>

		<form method="get" style="margin-bottom:10px;">
			<input type="hidden" name="page" value="osn-image-manager">
			<input type="hidden" name="tab" value="images">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="جستجو: نام تصویر / محصول / شناسه">
			<select name="status">
				<option value="all" <?php selected( $status, 'all' ); ?>>همه وضعیت‌ها</option>
				<option value="unprocessed" <?php selected( $status, 'unprocessed' ); ?>>بهینه‌نشده</option>
				<option value="processed" <?php selected( $status, 'processed' ); ?>>بهینه‌شده</option>
				<option value="excluded" <?php selected( $status, 'excluded' ); ?>>استثناء‌شده</option>
				<option value="errors" <?php selected( $status, 'errors' ); ?>>خطاخورده</option>
				<option value="skipped" <?php selected( $status, 'skipped' ); ?>>⏭ رد شده (از قبل بهینه)</option>
			</select>
			<select name="format">
				<option value="all" <?php selected( isset( $_GET['format'] ) ? sanitize_key( $_GET['format'] ) : 'all', 'all' ); ?>>Format: All</option>
				<option value="jpg" <?php selected( isset( $_GET['format'] ) ? sanitize_key( $_GET['format'] ) : '', 'jpg' ); ?>>JPG → WebP</option>
				<option value="png" <?php selected( isset( $_GET['format'] ) ? sanitize_key( $_GET['format'] ) : '', 'png' ); ?>>PNG → WebP</option>
				<option value="webp" <?php selected( isset( $_GET['format'] ) ? sanitize_key( $_GET['format'] ) : '', 'webp' ); ?>>WebP</option>
				<option value="other" <?php selected( isset( $_GET['format'] ) ? sanitize_key( $_GET['format'] ) : '', 'other' ); ?>>Other</option>
			</select>
			<select name="orderby">
				<option value="date" <?php selected( $orderby, 'date' ); ?>>جدیدترین</option>
				<option value="size" <?php selected( $orderby, 'size' ); ?>>حجم: بیشترین ← کمترین</option>
			</select>
			<select name="per_page">
				<option value="20" <?php selected( $per_page, 20 ); ?>>۲۰</option>
				<option value="50" <?php selected( $per_page, 50 ); ?>>۵۰</option>
				<option value="100" <?php selected( $per_page, 100 ); ?>>۱۰۰</option>
			</select>
			<button class="button">اعمال</button>
		</form>

		<div style="margin:10px 0;">
			<button class="button button-primary" id="osn-bulk-btn" disabled>🗜 Bulk Optimize (<span id="osn-sel-count">0</span>)</button>
			<button class="button" id="osn-bulk-wm-btn" disabled>💧 Bulk Watermark</button>
			<button class="button" id="osn-bulk-full-btn" disabled>🚀 Full Process (WM + Optimize)</button>
			<button class="button" id="osn-bulk-sizes-btn" disabled>🔁 Rebuild Sizes (srcset)</button>
			<button class="button" id="osn-bulk-convert-btn" disabled>🔄 Convert WebP Selected</button>
			<button class="button" id="osn-bulk-restore-btn" disabled>↩️ Restore Selected</button>
			<button class="button" id="osn-pause-btn" style="display:none;">⏸ Pause</button>
			<button class="button" id="osn-resume-btn" style="display:none;">▶️ Resume</button>
			<button class="button" id="osn-stop-btn2" style="display:none;">⏹ Stop</button>
		</div>

		<div id="osn-bulk-progress" style="display:none; margin-bottom:10px;">
			<div class="osn-bar"><div id="osn-bulk-bar"></div></div>
			<p id="osn-bulk-text" style="margin-top:4px;"></p>
		</div>

		<table class="widefat striped osn-tbl">
			<thead>
				<tr>
					<th style="width:28px;"><input type="checkbox" id="osn-check-all"></th>
					<th style="width:60px;">تصویر</th>
					<th>نام فایل</th>
					<th>حجم</th>
					<th>ابعاد</th>
					<th>نوع</th>
					<th>محصول مرتبط</th>
					<th>وضعیت</th>
					<th style="width:200px;">عملیات</th>
				</tr>
			</thead>
			<tbody>
			<?php if ( $q->have_posts() ) : ?>
				<?php foreach ( $q->posts as $post ) :
					$id   = $post->ID;
					$file = get_attached_file( $id );
					$size = ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0;
					if ( $size > 0 ) {
						update_post_meta( $id, OSN_IMG_META_FILESIZE, $size ); // ایندکس خودترمیم
					}
					$meta     = wp_get_attachment_metadata( $id );
					$dims     = ( ! empty( $meta['width'] ) ) ? $meta['width'] . '×' . $meta['height'] : '—';
					$mime     = str_replace( 'image/', '', get_post_mime_type( $id ) );
					$flag     = get_post_meta( $id, OSN_IMG_META_FLAG, true );
					$excluded = (bool) get_post_meta( $id, OSN_IMG_META_EXCLUDED, true );
					$wm       = (bool) get_post_meta( $id, OSN_IMG_META_WATERMARKED, true );
					$report   = get_post_meta( $id, OSN_IMG_META_REPORT, true );
					$avif     = get_post_meta( $id, '_osn_avif', true );
					$prod_id  = OSN_Image_Processor::get_related_product_id( $id );
					$role     = OSN_Image_Processor::get_image_role( $id );
					$thumb    = wp_get_attachment_image_url( $id, 'thumbnail' );
				?>
				<tr data-id="<?php echo esc_attr( $id ); ?>">
					<td><input type="checkbox" class="osn-check" value="<?php echo esc_attr( $id ); ?>" <?php disabled( $excluded ); ?>></td>
					<td><?php if ( $thumb ) : ?><img src="<?php echo esc_url( $thumb ); ?>" loading="lazy"><?php endif; ?></td>
					<td><?php echo esc_html( $file ? wp_basename( $file ) : '—' ); ?><br><span class="description">ID: <?php echo esc_html( $id ); ?></span></td>
					<td class="osn-size">
						<strong><?php echo esc_html( $size ? size_format( $size, 1 ) : '—' ); ?></strong>
						<?php if ( is_array( $report ) && ! empty( $report['before'] ) ) : ?>
							<div class="osn-report description">
								<?php echo esc_html( size_format( $report['before'], 1 ) ); ?> ← <?php echo esc_html( size_format( $report['after'], 1 ) ); ?>
								<b>(<?php echo esc_html( $report['percent'] ); ?>%-)</b>
								<?php if ( ! empty( $report['before_dims'] ) && ! empty( $report['after_dims'] ) ) : ?>
									<br><?php echo esc_html( $report['before_dims'] ); ?> ← <?php echo esc_html( $report['after_dims'] ); ?>
								<?php endif; ?>
								<?php if ( ! empty( $report['quality'] ) ) : ?> Q<?php echo esc_html( $report['quality'] ); ?><?php endif; ?>
								<?php if ( ! empty( $report['duration'] ) ) : ?> · <?php echo esc_html( $report['duration'] ); ?>s<?php endif; ?>
								<?php if ( ! empty( $report['avif'] ) ) : ?><br>AVIF: <?php echo esc_html( size_format( $report['avif'], 1 ) ); ?><?php endif; ?>
							</div>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $dims ); ?></td>
					<td><?php echo esc_html( strtoupper( $mime ) ); ?><?php if ( $avif ) : ?> <span class="osn-badge osn-b-blue">+AVIF</span><?php endif; ?></td>
					<td>
						<?php if ( $prod_id ) : ?>
							<a href="<?php echo esc_url( get_edit_post_link( $prod_id ) ); ?>" target="_blank"><?php echo esc_html( get_the_title( $prod_id ) ); ?></a><br>
						<?php endif; ?>
						<?php echo wp_kses_post( self::role_badge( $role, false ) ); ?>
					</td>
					<td class="osn-status">
						<?php $skip_reason = get_post_meta( $id, '_osn_skip_reason', true ); ?>
						<?php if ( $excluded ) : ?><span class="osn-badge osn-b-gray">⛔ استثناء</span><?php endif; ?>
						<?php if ( '1' === (string) $flag ) : ?><span class="osn-badge osn-b-green">✅ Optimized</span>
						<?php elseif ( 'skipped' === $flag ) : ?>
							<span class="osn-badge osn-b-blue" title="<?php echo esc_attr( $skip_reason ); ?>">⏭ Skipped - Already Optimized</span>
							<?php if ( $skip_reason ) : ?><br><span class="description"><?php echo esc_html( $skip_reason ); ?></span><?php endif; ?>
						<?php elseif ( 'error' === $flag ) :
							$err_msg = get_post_meta( $id, '_osn_error_message', true );
						?>
							<span class="osn-badge osn-b-red" title="<?php echo esc_attr( $err_msg ); ?>">❌ خطا</span>
							<?php if ( $err_msg ) : ?><br><span class="description" style="color:#d63638;"><?php echo esc_html( $err_msg ); ?></span><?php endif; ?>
						<?php elseif ( ! $excluded ) : ?><span class="osn-badge osn-b-red">قابل فشرده‌سازی</span><?php endif; ?>
						<?php if ( $wm ) : ?><span class="osn-badge osn-b-green">💧</span><?php endif; ?>
					</td>
					<td>
						<button class="button button-small osn-one-btn" data-id="<?php echo esc_attr( $id ); ?>" <?php disabled( $excluded || '1' === (string) $flag ); ?>>بهینه‌سازی</button>
						<button class="button button-small osn-exclude-btn" data-id="<?php echo esc_attr( $id ); ?>"><?php echo $excluded ? 'خروج از استثناء' : 'استثناء'; ?></button>
						<?php if ( ! $excluded && in_array( (string) $flag, array( '1', 'skipped', 'error' ), true ) ) : ?>
							<button class="button button-small osn-force-btn" data-id="<?php echo esc_attr( $id ); ?>">⚡ Force Optimize</button>
						<?php endif; ?>
						<?php
						$has_backup = get_post_meta( $id, OSN_IMG_META_ORIGINAL, true ) || get_post_meta( $id, '_osn_wm_original', true );
						if ( $has_backup ) :
							$cur_url = wp_get_attachment_image_url( $id, 'large' );
						?>
							<button class="button button-small osn-compare-btn" data-id="<?php echo esc_attr( $id ); ?>" data-cur="<?php echo esc_url( $cur_url ); ?>">🔍 Compare</button>
							<button class="button button-small osn-restore-btn" data-id="<?php echo esc_attr( $id ); ?>">↩️ Restore</button>
						<?php endif; ?>
						<br>
						<?php $brightness = (int) get_post_meta( $id, '_osn_brightness', true ); ?>
						<button class="button button-small osn-brightness-toggle" data-id="<?php echo esc_attr( $id ); ?>">🔆 نور<?php echo $brightness ? ' (' . esc_html( $brightness > 0 ? '+' . $brightness : $brightness ) . ')' : ''; ?></button>
						<div class="osn-brightness-panel" style="display:none; margin-top:4px;">
							<input type="range" class="osn-brightness-range" min="-100" max="100" step="5" value="<?php echo esc_attr( $brightness ); ?>" style="width:110px; vertical-align:middle;">
							<span class="osn-brightness-val"><?php echo esc_html( $brightness ); ?></span>
							<button class="button button-small osn-brightness-apply" data-id="<?php echo esc_attr( $id ); ?>">اعمال</button>
						</div>
					</td>
				</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr><td colspan="9">تصویری یافت نشد.</td></tr>
			<?php endif; ?>
			</tbody>
		</table>

		<?php
		$total_pages = (int) $q->max_num_pages;
		if ( $total_pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages" style="float:right;">' .
				'<span class="displaying-num">' . esc_html( number_format_i18n( $q->found_posts ) ) . ' تصویر</span> ' .
				wp_kses_post( paginate_links( array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'current'   => $paged,
					'total'     => $total_pages,
					'prev_text' => '‹ قبلی',
					'next_text' => 'بعدی ›',
				) ) ) . '</div></div>';
		}
		?>

		<!-- Compare Modal (v3.2) -->
		<div id="osn-compare-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.7); z-index:99999;">
			<div style="background:#fff; max-width:920px; margin:4vh auto; border-radius:12px; padding:18px; direction:rtl;">
				<h3 style="margin-top:0;">🔍 مقایسه قبل و بعد</h3>
				<div style="display:flex; gap:12px;">
					<div style="flex:1; text-align:center;">
						<p><strong>قبل (نسخه اصلی از Backup)</strong></p>
						<img id="osn-cmp-before" src="" style="max-width:100%; max-height:60vh; border-radius:8px; background:#f0f0f1;">
					</div>
					<div style="flex:1; text-align:center;">
						<p><strong>بعد (نسخه فعلی)</strong></p>
						<img id="osn-cmp-after" src="" style="max-width:100%; max-height:60vh; border-radius:8px; background:#f0f0f1;">
					</div>
				</div>
				<p style="text-align:left; margin-bottom:0;"><button class="button" id="osn-cmp-close">بستن</button></p>
			</div>
		</div>

		<script>
		jQuery(function($){
			var nonce = '<?php echo esc_js( $nonce ); ?>';
			var paused = false, active = false;

			// Escape امن برای درج داده‌های سرور در HTML (رفع XSS در ادمین - v3.9.1)
			function esc(s){
				return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
					return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
				});
			}

			// --- Compare ---
			$(document).on('click', '.osn-compare-btn', function(){
				var id = $(this).data('id');
				$('#osn-cmp-before').attr('src', ajaxurl + '?action=osn_view_backup&id=' + id + '&nonce=' + nonce);
				$('#osn-cmp-after').attr('src', $(this).data('cur'));
				$('#osn-compare-modal').fadeIn(150);
			});
			$('#osn-cmp-close, #osn-compare-modal').on('click', function(e){
				if (e.target === this) $('#osn-compare-modal').fadeOut(150);
			});
			$('#osn-cmp-close').on('click', function(){ $('#osn-compare-modal').fadeOut(150); });

			function updateSel(){
				var n = $('.osn-check:checked').length;
				$('#osn-sel-count').text(n);
				$('#osn-bulk-btn, #osn-bulk-wm-btn, #osn-bulk-full-btn, #osn-bulk-sizes-btn, #osn-bulk-convert-btn, #osn-bulk-restore-btn').prop('disabled', n === 0 || active);
			}
			$('#osn-check-all').on('change', function(){ $('.osn-check:not(:disabled)').prop('checked', this.checked); updateSel(); });
			$(document).on('change', '.osn-check', updateSel);

			function applyRow(d){
				var $row = $('tr[data-id="'+d.id+'"]');
				if (!$row.length) return;
				if (d.status === 'done' && d.report) {
					$row.find('.osn-size').html('<strong>'+d.report.after_h+'</strong>'+
						'<div class="osn-report description">'+d.report.before_h+' ← '+d.report.after_h+
						' <b>('+d.report.percent+'%-)</b>'+(d.report.quality?' Q'+esc(d.report.quality):'')+
						(d.report.duration?' · '+d.report.duration+'s':'')+
						(d.report.avif_h?'<br>AVIF: '+d.report.avif_h:'')+'</div>');
					$row.find('.osn-status').html('<span class="osn-badge osn-b-green">✅ بهینه‌شده</span>');
					$row.find('.osn-one-btn').prop('disabled', true);
				} else if (d.status === 'auto_skipped') {
					$row.find('.osn-status').html('<span class="osn-badge osn-b-blue">⏭ Skipped - Already Optimized</span>' +
						(d.reason ? '<br><span class="description">' + esc(d.reason) + '</span>' : ''));
				} else if (d.status === 'error') {
					$row.find('.osn-status').html('<span class="osn-badge osn-b-red" title="' + esc(d.error||'') + '">❌ خطا</span>' +
						(d.error ? '<br><span class="description" style="color:#d63638;">' + esc(d.error) + '</span>' : ''));
					$row.find('.osn-force-btn, .osn-one-btn').show().prop('disabled', false);
				}
				$row.find('.osn-check').prop('checked', false);
			}

			function ui(state){
				active = (state === 'running');
				$('#osn-pause-btn').toggle(state === 'running');
				$('#osn-resume-btn').toggle(state === 'paused');
				$('#osn-stop-btn2').toggle(state === 'running' || state === 'paused');
				updateSel();
			}

			function step(){
				if (paused || !active) return;
				$.post(ajaxurl, {action:'osn_bulk_step', nonce:nonce})
				.done(function(res){
					if (!res.success) { ui('idle'); alert('خطا در پردازش'); return; }
					var d = res.data;
					if (d.id) applyRow(d);
					if (d.total) {
						var pct = Math.round(d.done / d.total * 100);
						$('#osn-bulk-bar').css('width', pct+'%').text(pct+'%');
						$('#osn-bulk-text').text('در حال پردازش: ' + d.done + ' / ' + d.total + (d.duration ? ' · آخرین تصویر: ' + d.duration + 's' : ''));
					}
					if (d.error) $('#osn-bulk-text').append(' ⚠️ ' + esc(d.error));
					if (d.finished) {
						ui('idle');
						$('#osn-bulk-text').append(' - ✅ پایان عملیات.');
						$('.notice-warning').slideUp();
					} else {
						setTimeout(step, 350);
					}
				})
				.fail(function(){ ui('paused'); paused = true; $('#osn-bulk-text').append(' - قطع ارتباط؛ صف در دیتابیس ذخیره است، Resume بزنید.'); });
			}

			function startQueue(ids, op){
				$.post(ajaxurl, {action:'osn_bulk_start', nonce:nonce, ids:ids, op:op})
				.done(function(res){
					if (!res.success) { alert(res.data || 'خطا'); return; }
					paused = false;
					$('#osn-bulk-progress').show();
					ui('running');
					step();
				});
			}

			$('#osn-bulk-btn').on('click', function(){
				var ids = $('.osn-check:checked').map(function(){ return $(this).val(); }).get();
				if (!ids.length) return;
				if (!confirm(ids.length + ' تصویر بهینه‌سازی شود؟')) return;
				startQueue(ids, 'optimize');
			});
			$('#osn-bulk-wm-btn').on('click', function(){
				var ids = $('.osn-check:checked').map(function(){ return $(this).val(); }).get();
				if (!ids.length) return;
				if (!confirm('واترمارک روی ' + ids.length + ' تصویر اعمال شود؟ (تصاویر واترمارک‌خورده خودکار رد می‌شوند)')) return;
				startQueue(ids, 'watermark');
			});
			$('#osn-bulk-full-btn').on('click', function(){
				var ids = $('.osn-check:checked').map(function(){ return $(this).val(); }).get();
				if (!ids.length) return;
				if (!confirm('پردازش کامل روی ' + ids.length + ' تصویر؟\nترتیب: Backup ← Watermark ← Optimize ← Responsive Sizes ← Metadata')) return;
				startQueue(ids, 'wm_optimize');
			});
			$('#osn-bulk-convert-btn').on('click', function(){
				var ids = $('.osn-check:checked').map(function(){ return $(this).val(); }).get();
				if (!ids.length) return;
				if (!confirm(ids.length + ' تصویر به WebP تبدیل شود؟ (فایل اصلی در Backup حفظ می‌شود، Attachment ID تغییر نمی‌کند)')) return;
				startQueue(ids, 'optimize');
			});
			$('#osn-bulk-restore-btn').on('click', function(){
				var ids = $('.osn-check:checked').map(function(){ return $(this).val(); }).get();
				if (!ids.length) return;
				if (!confirm('آیا مطمئن هستید ' + ids.length + ' تصویر به نسخه قبل از پردازش بازگردند؟')) return;
				if (!confirm('تایید نهایی: فایل فعلی با نسخه Backup جایگزین و سایزها/متادیتا بازسازی می‌شوند.')) return;
				startQueue(ids, 'restore');
			});
			$(document).on('click', '.osn-force-btn', function(){
				var id = $(this).data('id');
				if (!confirm('Force Optimize: این تصویر به دستور شما دوباره پردازش شود؟ (Skip هوشمند نادیده گرفته می‌شود)')) return;
				startQueue([id], 'force');
			});
			$(document).on('click', '.osn-restore-btn', function(){
				var id = $(this).data('id');
				if (!confirm('آیا مطمئن هستید تصویر ID ' + id + ' به نسخه قبل از پردازش بازگردد؟')) return;
				startQueue([id], 'restore');
			});
			$('#osn-bulk-sizes-btn').on('click', function(){
				var ids = $('.osn-check:checked').map(function(){ return $(this).val(); }).get();
				if (!ids.length) return;
				if (!confirm('سایزهای ریسپانسیو (480/768/1200) برای ' + ids.length + ' تصویر بازسازی شود؟ (تصویر اصلی تغییر نمی‌کند)')) return;
				startQueue(ids, 'sizes');
			});
			$('#osn-pause-btn').on('click', function(){
				paused = true; ui('paused');
				$('#osn-bulk-text').append(' - ⏸ متوقف شد (صف در دیتابیس ذخیره است؛ حتی با بستن صفحه از بین نمی‌رود).');
			});
			$('#osn-resume-btn').on('click', function(){ paused = false; ui('running'); step(); });
			$('#osn-stop-btn2').on('click', function(){
				if (!confirm('عملیات کاملاً متوقف و صف حذف شود؟')) return;
				paused = true;
				$.post(ajaxurl, {action:'osn_bulk_stop', nonce:nonce}).always(function(){ ui('idle'); $('#osn-bulk-text').append(' - ⏹ عملیات لغو شد.'); });
			});

			// ادامه صف نیمه‌کاره از دیتابیس
			$('#osn-resume-db').on('click', function(){
				paused = false;
				$('#osn-bulk-progress').show();
				ui('running');
				$(this).closest('.notice').slideUp();
				step();
			});
			$('#osn-discard-db').on('click', function(){
				var $n = $(this).closest('.notice');
				$.post(ajaxurl, {action:'osn_bulk_stop', nonce:nonce}).always(function(){ $n.slideUp(); });
			});

			// پردازش تکی
			$(document).on('click', '.osn-one-btn', function(){
				var $btn = $(this), id = $btn.data('id');
				$btn.prop('disabled', true).text('...');
				startQueue([id], 'optimize');
				$btn.text('بهینه‌سازی');
			});

			// استثناء
			$(document).on('click', '.osn-exclude-btn', function(){
				var $btn = $(this), id = $btn.data('id'), $row = $btn.closest('tr');
				$.post(ajaxurl, {action:'osn_toggle_exclude', nonce:nonce, id:id})
				.done(function(res){
					if (!res.success) { alert('خطا'); return; }
					location.reload();
				});
			});

			// تنظیم دستی نور
			$(document).on('click', '.osn-brightness-toggle', function(){
				$(this).closest('td').find('.osn-brightness-panel').slideToggle(120);
			});
			$(document).on('input', '.osn-brightness-range', function(){
				$(this).closest('.osn-brightness-panel').find('.osn-brightness-val').text($(this).val());
			});
			$(document).on('click', '.osn-brightness-apply', function(){
				var $btn = $(this), id = $btn.data('id');
				var $panel = $btn.closest('.osn-brightness-panel');
				var level = parseInt($panel.find('.osn-brightness-range').val(), 10) || 0;
				var $toggle = $panel.siblings('.osn-brightness-toggle');
				$btn.prop('disabled', true).text('...');
				$.post(ajaxurl, {action:'osn_adjust_brightness', nonce:nonce, id:id, level:level})
				.done(function(res){
					$btn.prop('disabled', false).text('اعمال');
					if (!res.success) { alert('خطا: ' + res.data); return; }
					var label = '🔆 نور' + (level ? ' (' + (level > 0 ? '+' + level : level) + ')' : '');
					$toggle.text(label);
					if (res.data.url) {
						$panel.closest('tr').find('img').attr('src', res.data.url + '?t=' + Date.now());
					}
					$panel.slideUp(120);
				})
				.fail(function(){ $btn.prop('disabled', false).text('اعمال'); alert('خطای ارتباط با سرور'); });
			});
		});
		</script>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * تب Watermark - با Preview
	 * ------------------------------------------------------------------- */

	private function render_watermark_tab( $nonce ) {
		$settings = osn_img_get_settings();
		$per_page = isset( $_GET['per_page'] ) && in_array( (int) $_GET['per_page'], array( 20, 50, 100 ), true ) ? (int) $_GET['per_page'] : 50;
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$logo_url = $settings['wm_logo_id'] ? wp_get_attachment_image_url( $settings['wm_logo_id'], 'medium' ) : '';

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp' ),
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => false,
			'meta_query'     => array(
				array( 'key' => OSN_IMG_META_WATERMARKED, 'compare' => 'NOT EXISTS' ),
				array( 'key' => OSN_IMG_META_EXCLUDED, 'compare' => 'NOT EXISTS' ),
			),
		);
		if ( '' !== $search ) {
			if ( is_numeric( $search ) ) {
				$args['post__in'] = array( (int) $search );
			} else {
				$args['s'] = $search;
			}
		}
		$q = new WP_Query( $args );

		// موقعیت واترمارک برای Preview
		$pos_css = array(
			'bottom-right' => 'bottom:3%; right:3%;',
			'bottom-left'  => 'bottom:3%; left:3%;',
			'top-right'    => 'top:3%; right:3%;',
			'top-left'     => 'top:3%; left:3%;',
			'center'       => 'top:50%; left:50%; transform:translate(-50%,-50%);',
		);
		$pos = isset( $pos_css[ $settings['wm_position'] ] ) ? $pos_css[ $settings['wm_position'] ] : $pos_css['bottom-right'];
		?>
		<?php if ( empty( $settings['wm_logo_id'] ) ) : ?>
			<div class="notice notice-error"><p>ابتدا لوگوی واترمارک را در <a href="<?php echo esc_url( admin_url( 'options-general.php?page=osn-image-settings' ) ); ?>">تنظیمات</a> انتخاب کنید.</p></div>
		<?php endif; ?>

		<div style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-start;">
			<div style="flex:1; min-width:520px;">
				<p class="description">واترمارک هرگز خودکار اجرا نمی‌شود. تصاویر را انتخاب کنید، Preview را ببینید و بعد از تأیید اجرا کنید. تصاویری که قبلاً واترمارک خورده‌اند (متای <code>_osn_watermarked</code>) اینجا نمایش داده نمی‌شوند.</p>

				<form method="get" style="margin-bottom:10px;">
					<input type="hidden" name="page" value="osn-image-manager">
					<input type="hidden" name="tab" value="watermark">
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="جستجوی نام یا شناسه">
					<select name="per_page">
						<option value="20" <?php selected( $per_page, 20 ); ?>>۲۰</option>
						<option value="50" <?php selected( $per_page, 50 ); ?>>۵۰</option>
						<option value="100" <?php selected( $per_page, 100 ); ?>>۱۰۰</option>
					</select>
					<button class="button">اعمال</button>
				</form>

				<div style="margin:10px 0;">
					<button class="button button-primary" id="osn-wm-bulk" disabled <?php disabled( empty( $settings['wm_logo_id'] ) ); ?>>💧 اعمال واترمارک گروهی (<span id="osn-wm-count">0</span>)</button>
					<button class="button" id="osn-wm-stop" style="display:none;">⏸ توقف</button>
				</div>
				<div id="osn-wm-progress" style="display:none; margin-bottom:10px;">
					<div class="osn-bar"><div id="osn-wm-bar"></div></div>
					<p id="osn-wm-text" style="margin-top:4px;"></p>
				</div>

				<table class="widefat striped osn-tbl">
					<thead><tr>
						<th style="width:28px;"><input type="checkbox" id="osn-wm-all"></th>
						<th style="width:60px;">تصویر</th><th>نام فایل</th><th>محصول مرتبط</th><th>وضعیت</th>
					</tr></thead>
					<tbody>
					<?php if ( $q->have_posts() ) : ?>
						<?php foreach ( $q->posts as $post ) :
							$id      = $post->ID;
							$file    = get_attached_file( $id );
							$prod_id = OSN_Image_Processor::get_related_product_id( $id );
							$thumb   = wp_get_attachment_image_url( $id, 'medium' );
						?>
						<tr data-id="<?php echo esc_attr( $id ); ?>">
							<td><input type="checkbox" class="osn-wm-check" value="<?php echo esc_attr( $id ); ?>" data-thumb="<?php echo esc_url( $thumb ); ?>"></td>
							<td><?php if ( $thumb ) : ?><img src="<?php echo esc_url( $thumb ); ?>" loading="lazy"><?php endif; ?></td>
							<td><?php echo esc_html( $file ? wp_basename( $file ) : '—' ); ?><br><span class="description">ID: <?php echo esc_html( $id ); ?></span></td>
							<td><?php echo $prod_id ? '<a href="' . esc_url( get_edit_post_link( $prod_id ) ) . '" target="_blank">' . esc_html( get_the_title( $prod_id ) ) . '</a>' : '—'; ?></td>
							<td class="osn-wm-status"><span class="osn-badge osn-b-gray">بدون واترمارک</span></td>
						</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr><td colspan="5">همه تصاویر واترمارک خورده‌اند 🎉</td></tr>
					<?php endif; ?>
					</tbody>
				</table>

				<?php
				$total_pages = (int) $q->max_num_pages;
				if ( $total_pages > 1 ) {
					echo '<div class="tablenav"><div class="tablenav-pages" style="float:right;">' . wp_kses_post( paginate_links( array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $total_pages,
					) ) ) . '</div></div>';
				}
				?>
			</div>

			<!-- Preview -->
			<div style="width:340px;">
				<div style="background:#fff; border:1px solid #dcdcde; border-radius:10px; padding:14px; position:sticky; top:48px;">
					<h3 style="margin-top:0;">👁 Preview</h3>
					<p class="description">اولین تصویر انتخاب‌شده با تنظیمات فعلی واترمارک (موقعیت: <?php echo esc_html( $settings['wm_position'] ); ?>، شفافیت: <?php echo esc_html( $settings['wm_opacity'] ); ?>٪، اندازه: <?php echo esc_html( $settings['wm_size'] ); ?>٪)</p>
					<div id="osn-preview-box" style="position:relative; width:100%; aspect-ratio:1/1; background:#f0f0f1; border-radius:8px; overflow:hidden;">
						<img id="osn-preview-img" src="" style="width:100%; height:100%; object-fit:cover; display:none;">
						<?php if ( $logo_url ) : ?>
							<img id="osn-preview-logo" src="<?php echo esc_url( $logo_url ); ?>"
								style="position:absolute; <?php echo esc_attr( $pos ); ?> width:<?php echo esc_attr( $settings['wm_size'] ); ?>%; opacity:<?php echo esc_attr( ( 100 - $settings['wm_opacity'] ) / 100 ); ?>; display:none;">
						<?php endif; ?>
						<p id="osn-preview-hint" style="text-align:center; padding-top:45%; color:#888;">یک تصویر انتخاب کنید</p>
					</div>
					<p class="description" style="margin-top:8px;">برای تغییر موقعیت/شفافیت/اندازه به <a href="<?php echo esc_url( admin_url( 'options-general.php?page=osn-image-settings' ) ); ?>">تنظیمات</a> بروید.</p>
				</div>
			</div>
		</div>

		<script>
		jQuery(function($){
			var nonce = '<?php echo esc_js( $nonce ); ?>';
			var queue = [], totalQ = 0, doneQ = 0, stopped = false;

			function upd(){
				var $checked = $('.osn-wm-check:checked');
				$('#osn-wm-count').text($checked.length);
				$('#osn-wm-bulk').prop('disabled', $checked.length === 0);
				// Preview اولین انتخاب
				if ($checked.length) {
					var t = $checked.first().data('thumb');
					if (t) {
						$('#osn-preview-img').attr('src', t).show();
						$('#osn-preview-logo').show();
						$('#osn-preview-hint').hide();
					}
				} else {
					$('#osn-preview-img, #osn-preview-logo').hide();
					$('#osn-preview-hint').show();
				}
			}
			$('#osn-wm-all').on('change', function(){ $('.osn-wm-check:not(:disabled)').prop('checked', this.checked); upd(); });
			$(document).on('change', '.osn-wm-check', upd);

			function next(){
				if (stopped || !queue.length) {
					$('#osn-wm-stop').hide();
					if (!queue.length) $('#osn-wm-text').append(' - ✅ پایان.');
					return;
				}
				var id = queue.shift();
				var $row = $('tr[data-id="'+id+'"]');
				$row.css('background', '#fff8e1');
				$.post(ajaxurl, {action:'osn_watermark_one', nonce:nonce, id:id})
				.done(function(res){
					if (res.success && res.data.status === 'done') {
						$row.find('.osn-wm-status').html('<span class="osn-badge osn-b-green">💧 واترمارک شد</span>');
						if (res.data.url) $row.find('img').attr('src', res.data.url + '?t=' + Date.now());
						$row.find('.osn-wm-check').prop('checked', false).prop('disabled', true);
					} else if (res.success) {
						$row.find('.osn-wm-status').html('<span class="osn-badge osn-b-gray">رد شد</span>');
					} else {
						$row.find('.osn-wm-status').html('<span class="osn-badge osn-b-red">خطا</span>');
					}
				})
				.always(function(){
					$row.css('background', '');
					doneQ++;
					var pct = Math.round(doneQ/totalQ*100);
					$('#osn-wm-bar').css('width', pct+'%').text(pct+'%');
					$('#osn-wm-text').text('در حال اعمال واترمارک: ' + doneQ + ' / ' + totalQ);
					setTimeout(next, 400);
				});
			}

			$('#osn-wm-bulk').on('click', function(){
				queue = $('.osn-wm-check:checked').map(function(){ return $(this).val(); }).get();
				if (!queue.length) return;
				if (!confirm('واترمارک روی ' + queue.length + ' تصویر اعمال شود؟ (Preview را بررسی کرده‌اید؟)')) return;
				totalQ = queue.length; doneQ = 0; stopped = false;
				$('#osn-wm-progress').show();
				$('#osn-wm-stop').show();
				next();
			});
			$('#osn-wm-stop').on('click', function(){ stopped = true; $('#osn-wm-text').append(' - متوقف شد.'); });
			upd();
		});
		</script>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * تب Cleaner
	 * ------------------------------------------------------------------- */

	private function render_cleaner_tab( $nonce ) {
		global $wpdb;
		$scan = get_option( OSN_Batch_Tool::SCAN_OPTION, array() );

		// تصاویر تکراری (بر اساس Hash از اسکن)
		$dupes = $wpdb->get_results(
			"SELECT meta_value AS hash, COUNT(*) AS cnt, GROUP_CONCAT(post_id ORDER BY post_id ASC) AS ids
			 FROM {$wpdb->postmeta}
			 WHERE meta_key = '_osn_hash'
			 GROUP BY meta_value
			 HAVING cnt > 1
			 ORDER BY cnt DESC
			 LIMIT 30"
		);

		// تصاویر بدون محصول و بدون والد (کاندیدای بدون استفاده)
		$orphans = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ),
			'post_parent'    => 0,
			'posts_per_page' => 50,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'no_found_rows'  => false,
		) );
		?>
		<h2>🧹 OptiSnap Cleaner</h2>
		<div class="notice notice-warning"><p><strong>احتیاط:</strong> حذف تصویر غیرقابل بازگشت است. تصاویری که به محصولی متصل باشند (اصلی یا گالری) از این بخش قابل حذف نیستند (ایمنی خودکار). برای تشخیص تکراری‌ها ابتدا <a href="<?php echo esc_url( admin_url( 'tools.php?page=osn-image-batch' ) ); ?>">اسکن</a> را اجرا کنید.</p></div>

		<h3>🔁 تصاویر تکراری (Hash یکسان)</h3>
		<?php if ( $dupes ) : ?>
			<table class="widefat striped osn-tbl">
				<thead><tr><th>تصاویر</th><th>تعداد نسخه</th><th>جزئیات و عملیات</th></tr></thead>
				<tbody>
				<?php foreach ( $dupes as $g ) :
					$ids = array_map( 'absint', explode( ',', $g->ids ) );
				?>
					<tr>
						<td>
							<?php foreach ( array_slice( $ids, 0, 6 ) as $id ) :
								$t = wp_get_attachment_image_url( $id, 'thumbnail' );
								if ( $t ) : ?><img src="<?php echo esc_url( $t ); ?>" title="ID <?php echo esc_attr( $id ); ?>" style="margin-left:4px;"><?php endif;
							endforeach; ?>
						</td>
						<td><strong><?php echo esc_html( $g->cnt ); ?></strong> نسخه</td>
						<td>
							<?php foreach ( $ids as $i => $id ) :
								$file    = get_attached_file( $id );
								$prod_id = OSN_Image_Processor::get_related_product_id( $id );
							?>
								<div style="padding:2px 0;">
									ID <?php echo esc_html( $id ); ?> - <?php echo esc_html( $file ? wp_basename( $file ) : '' ); ?>
									<?php if ( $prod_id ) : ?>
										<span class="osn-badge osn-b-blue">در محصول: <?php echo esc_html( get_the_title( $prod_id ) ); ?></span>
									<?php else : ?>
										<button class="button button-small osn-del-btn" data-id="<?php echo esc_attr( $id ); ?>">🗑 حذف این نسخه</button>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p>تصویر تکراری‌ای یافت نشد (یا هنوز اسکن اجرا نشده).</p>
		<?php endif; ?>

		<h3 style="margin-top:24px;">🗃 تصاویر بدون والد (کاندیدای بدون استفاده)</h3>
		<p class="description"><?php echo esc_html( number_format_i18n( $orphans->found_posts ) ); ?> تصویر بدون والد یافت شد (۵۰ قدیمی‌ترین نمایش داده می‌شود). تصاویر متصل به محصول به‌صورت خودکار محافظت می‌شوند؛ با این حال اگر تصویری در برگه‌ها یا اسلایدر استفاده شده، قبل از حذف مطمئن شوید.</p>
		<table class="widefat striped osn-tbl">
			<thead><tr><th style="width:60px;"></th><th>نام فایل</th><th>حجم</th><th>تاریخ</th><th>محصول</th><th style="width:130px;">عملیات</th></tr></thead>
			<tbody>
			<?php if ( $orphans->have_posts() ) : ?>
				<?php foreach ( $orphans->posts as $post ) :
					$id      = $post->ID;
					$file    = get_attached_file( $id );
					$size    = ( $file && file_exists( $file ) ) ? size_format( filesize( $file ), 1 ) : '—';
					$prod_id = OSN_Image_Processor::get_related_product_id( $id );
					$thumb   = wp_get_attachment_image_url( $id, 'thumbnail' );
				?>
				<tr data-id="<?php echo esc_attr( $id ); ?>">
					<td><?php if ( $thumb ) : ?><img src="<?php echo esc_url( $thumb ); ?>" loading="lazy"><?php endif; ?></td>
					<td><?php echo esc_html( $file ? wp_basename( $file ) : '—' ); ?><br><span class="description">ID: <?php echo esc_html( $id ); ?></span></td>
					<td><?php echo esc_html( $size ); ?></td>
					<td><?php echo esc_html( get_the_date( 'Y/m/d', $post ) ); ?></td>
					<td><?php echo $prod_id ? esc_html( get_the_title( $prod_id ) ) : '—'; ?></td>
					<td>
						<?php if ( $prod_id ) : ?>
							<span class="osn-badge osn-b-blue">محافظت‌شده</span>
						<?php else : ?>
							<button class="button button-small osn-del-btn" data-id="<?php echo esc_attr( $id ); ?>">🗑 حذف</button>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr><td colspan="6">موردی یافت نشد.</td></tr>
			<?php endif; ?>
			</tbody>
		</table>

		<h3 style="margin-top:24px;">🏋️ تصاویر خیلی بزرگ</h3>
		<p>لیست کامل در <a href="<?php echo esc_url( admin_url( 'admin.php?page=osn-image-manager&tab=images&orderby=size&status=unprocessed' ) ); ?>">Media Manager با مرتب‌سازی حجمی ←</a><?php if ( ! empty( $scan['heavy'] ) ) : ?> (<?php echo esc_html( count( $scan['heavy'] ) ); ?> تصویر حجیم در آخرین اسکن)<?php endif; ?></p>

		<script>
		jQuery(function($){
			var nonce = '<?php echo esc_js( $nonce ); ?>';
			$(document).on('click', '.osn-del-btn', function(){
				var $btn = $(this), id = $btn.data('id');
				if (!confirm('تصویر ID ' + id + ' برای همیشه حذف شود؟')) return;
				if (!confirm('مطمئن هستید؟ این عمل غیرقابل بازگشت است!')) return;
				$btn.prop('disabled', true).text('...');
				$.post(ajaxurl, {action:'osn_delete_attachment', nonce:nonce, id:id})
				.done(function(res){
					if (res.success) {
						$btn.closest('tr, div').css('opacity', .4);
						$btn.replaceWith('<span class="osn-badge osn-b-green">حذف شد ('+res.data.freed+')</span>');
					} else {
						$btn.prop('disabled', false).text('🗑 حذف');
						alert('خطا: ' + res.data);
					}
				});
			});
		});
		</script>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * تب SEO
	 * ------------------------------------------------------------------- */

	private function render_seo_tab( $nonce ) {
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

		$q = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp' ),
			'posts_per_page' => 50,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => false,
			'meta_query'     => array(
				'relation' => 'OR',
				array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_wp_attachment_image_alt', 'value' => '' ),
			),
		) );
		?>
		<h2>🔍 SEO Image Tools</h2>
		<p class="description">
			<strong><?php echo esc_html( number_format_i18n( $q->found_posts ) ); ?></strong> تصویر بدون متن Alt یافت شد.
			برای تصاویر متصل به محصول، Alt از <strong>نام محصول</strong> ساخته می‌شود (مثال: IMG_2026.jpg ← «ست ادویه چوبی ۱۵ عددی لیمون»). برای بقیه، از نام فایل انسانی‌شده.
		</p>

		<div style="margin:10px 0;">
			<button class="button button-primary" id="osn-alt-bulk" disabled>✍️ ساخت Alt برای انتخاب‌شده‌ها (<span id="osn-alt-count">0</span>)</button>
		</div>
		<div id="osn-alt-progress" style="display:none; margin-bottom:10px;">
			<div class="osn-bar"><div id="osn-alt-bar"></div></div>
			<p id="osn-alt-text" style="margin-top:4px;"></p>
		</div>

		<table class="widefat striped osn-tbl">
			<thead><tr>
				<th style="width:28px;"><input type="checkbox" id="osn-alt-all"></th>
				<th style="width:60px;">تصویر</th><th>نام فایل</th><th>محصول مرتبط</th><th>Alt پیشنهادی</th><th>وضعیت</th>
			</tr></thead>
			<tbody>
			<?php if ( $q->have_posts() ) : ?>
				<?php foreach ( $q->posts as $post ) :
					$id      = $post->ID;
					$file    = get_attached_file( $id );
					$prod_id = OSN_Image_Processor::get_related_product_id( $id );
					$thumb   = wp_get_attachment_image_url( $id, 'thumbnail' );
					$suggest = $prod_id ? get_the_title( $prod_id ) : trim( preg_replace( '/[-_]+/', ' ', $file ? pathinfo( $file, PATHINFO_FILENAME ) : '' ) );
				?>
				<tr data-id="<?php echo esc_attr( $id ); ?>">
					<td><input type="checkbox" class="osn-alt-check" value="<?php echo esc_attr( $id ); ?>"></td>
					<td><?php if ( $thumb ) : ?><img src="<?php echo esc_url( $thumb ); ?>" loading="lazy"><?php endif; ?></td>
					<td><?php echo esc_html( $file ? wp_basename( $file ) : '—' ); ?><br><span class="description">ID: <?php echo esc_html( $id ); ?></span></td>
					<td><?php echo $prod_id ? esc_html( get_the_title( $prod_id ) ) : '—'; ?></td>
					<td class="osn-alt-suggest"><em><?php echo esc_html( $suggest ); ?></em></td>
					<td class="osn-alt-status"><span class="osn-badge osn-b-red">بدون Alt</span></td>
				</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr><td colspan="6">همه تصاویر Alt دارند 🎉</td></tr>
			<?php endif; ?>
			</tbody>
		</table>

		<?php
		$total_pages = (int) $q->max_num_pages;
		if ( $total_pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages" style="float:right;">' . wp_kses_post( paginate_links( array(
				'base'    => add_query_arg( 'paged', '%#%' ),
				'format'  => '',
				'current' => $paged,
				'total'   => $total_pages,
			) ) ) . '</div></div>';
		}
		?>

		<script>
		jQuery(function($){
			var nonce = '<?php echo esc_js( $nonce ); ?>';
			var queue = [], totalQ = 0, doneQ = 0;

			function upd(){
				var n = $('.osn-alt-check:checked').length;
				$('#osn-alt-count').text(n);
				$('#osn-alt-bulk').prop('disabled', n === 0);
			}
			$('#osn-alt-all').on('change', function(){ $('.osn-alt-check:not(:disabled)').prop('checked', this.checked); upd(); });
			$(document).on('change', '.osn-alt-check', upd);

			function next(){
				if (!queue.length) { $('#osn-alt-text').append(' - ✅ پایان.'); return; }
				var id = queue.shift();
				var $row = $('tr[data-id="'+id+'"]');
				$.post(ajaxurl, {action:'osn_seo_alt', nonce:nonce, id:id})
				.done(function(res){
					if (res.success) {
						$row.find('.osn-alt-status').html('<span class="osn-badge osn-b-green">✅ ثبت شد</span>');
						$row.find('.osn-alt-suggest').html('<strong>'+esc(res.data.alt)+'</strong>');
						$row.find('.osn-alt-check').prop('checked', false).prop('disabled', true);
					} else {
						$row.find('.osn-alt-status').html('<span class="osn-badge osn-b-red">خطا</span>');
					}
				})
				.always(function(){
					doneQ++;
					var pct = Math.round(doneQ/totalQ*100);
					$('#osn-alt-bar').css('width', pct+'%').text(pct+'%');
					$('#osn-alt-text').text('در حال ثبت: ' + doneQ + ' / ' + totalQ);
					setTimeout(next, 200);
				});
			}

			$('#osn-alt-bulk').on('click', function(){
				queue = $('.osn-alt-check:checked').map(function(){ return $(this).val(); }).get();
				if (!queue.length) return;
				if (!confirm('متن Alt برای ' + queue.length + ' تصویر ساخته و ثبت شود؟')) return;
				totalQ = queue.length; doneQ = 0;
				$('#osn-alt-progress').show();
				next();
			});
		});
		</script>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * تب Logs
	 * ------------------------------------------------------------------- */

	private function render_logs_tab() {
		$logs = OSN_Logger::get_logs( 200 );
		?>
		<?php if ( isset( $_GET['cleared'] ) ) : ?>
			<div class="notice notice-success"><p>لاگ‌ها پاک شدند.</p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:10px;" onsubmit="return confirm('همه لاگ‌ها پاک شوند؟');">
			<input type="hidden" name="action" value="osn_clear_logs">
			<?php wp_nonce_field( 'osn_clear_logs' ); ?>
			<button class="button">🗑 پاک‌کردن لاگ‌ها</button>
			<span class="description">۲۰۰ رکورد آخر؛ حداکثر ۱۰۰۰ رکورد نگهداری می‌شود.</span>
		</form>

		<table class="widefat striped">
			<thead><tr>
				<th>تاریخ</th><th>تصویر (ID)</th><th>محصول (ID)</th><th>عملیات</th><th>Quality</th><th>قبل</th><th>بعد</th><th>کاهش %</th><th>نتیجه</th>
			</tr></thead>
			<tbody>
			<?php if ( $logs ) : ?>
				<?php foreach ( $logs as $row ) :
					$percent = ( $row->size_before > 0 && $row->size_after > 0 )
						? round( ( 1 - $row->size_after / $row->size_before ) * 100 )
						: null;
				?>
				<tr>
					<td><?php echo esc_html( wp_date( 'Y/m/d H:i:s', strtotime( $row->created_at ) ) ); ?></td>
					<td><?php echo esc_html( $row->image_name ); ?><br><span class="description"><?php echo esc_html( $row->attachment_id ); ?></span></td>
					<td><?php echo esc_html( $row->product ? $row->product : '—' ); ?><?php echo ! empty( $row->product_id ) ? '<br><span class="description">' . esc_html( $row->product_id ) . '</span>' : ''; ?></td>
					<td><?php echo esc_html( $row->operation ); ?></td>
					<td><?php echo esc_html( isset( $row->quality ) && $row->quality ? $row->quality : '—' ); ?></td>
					<td><?php echo $row->size_before ? esc_html( size_format( $row->size_before, 1 ) ) : '—'; ?></td>
					<td><?php echo $row->size_after ? esc_html( size_format( $row->size_after, 1 ) ) : '—'; ?></td>
					<td><?php echo null !== $percent ? '<strong style="color:#00a32a;">' . esc_html( $percent ) . '%</strong>' : '—'; ?></td>
					<td>
						<?php if ( 'SUCCESS' === $row->result ) : ?>
							<span class="osn-badge osn-b-green">SUCCESS</span>
						<?php elseif ( 'ERROR' === $row->result ) : ?>
							<span class="osn-badge osn-b-red">ERROR</span>
						<?php else : ?>
							<span class="osn-badge osn-b-gray"><?php echo esc_html( $row->result ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr><td colspan="9">هنوز عملیاتی ثبت نشده است.</td></tr>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
	}
}
