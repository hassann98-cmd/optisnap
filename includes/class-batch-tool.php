<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ابزار مدیریتی: Tools → OptiSnap
 *
 * - اسکن کامل کتابخانه رسانه (مرحله‌به‌مرحله، بدون فشار به هاست)
 * - شناسایی تصاویر غیر WebP و تصاویر حجیم
 * - پردازش دسته‌ای با فیلتر، اندازه دسته و مکث قابل تنظیم
 * - مدیریت Backup: آزادسازی فضا و بازگردانی
 */
class OSN_Batch_Tool {

	const SCAN_OPTION = 'osn_img_scan_data';
	const SCAN_CHUNK  = 100; // تعداد بررسی در هر مرحله اسکن (فقط خواندن - سبک)

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );

		add_action( 'wp_ajax_osn_batch_count', array( $this, 'ajax_count' ) );
		add_action( 'wp_ajax_osn_batch_run', array( $this, 'ajax_run' ) );
		add_action( 'wp_ajax_osn_scan_step', array( $this, 'ajax_scan_step' ) );
		add_action( 'wp_ajax_osn_process_one', array( $this, 'ajax_process_one' ) );
		add_action( 'wp_ajax_osn_cleanup_step', array( $this, 'ajax_cleanup_step' ) );
		add_action( 'wp_ajax_osn_restore', array( $this, 'ajax_restore' ) );
	}

	public function add_menu() {
		add_management_page(
			'OptiSnap',
			'OptiSnap',
			'manage_options',
			'osn-image-batch',
			array( $this, 'render_page' )
		);
	}

	/* =====================================================================
	 * شمارش و انتخاب تصاویر بر اساس حالت (mode)
	 * =================================================================== */

	/**
	 * آرگومان‌های پایه WP_Query برای هر حالت
	 */
	private static function base_args_for_mode( $mode ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp' ),
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'meta_query'     => array(
				array(
					'key'     => OSN_IMG_META_FLAG,
					'compare' => 'NOT EXISTS',
				),
			),
		);

		switch ( $mode ) {
			case 'nonwebp':
				// فقط JPG / PNG (تبدیل‌نشده‌ها)
				$args['post_mime_type'] = array( 'image/jpeg', 'image/png' );
				break;

			case 'products':
				// تصاویری که به محصولات ووکامرس متصل شده‌اند
				// v3.9.2: کوئری مستقیم SQL فقط شناسه‌ها (قبلاً get_posts با WP_Query
				// همه محصولات را بارگذاری می‌کرد؛ روی فروشگاه‌های بزرگ حافظه زیادی می‌گرفت)
				global $wpdb;
				$parents = $wpdb->get_col(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status NOT IN ('trash','auto-draft')"
				);
				$args['post_parent__in'] = ! empty( $parents ) ? array_map( 'intval', $parents ) : array( 0 );
				break;

			case 'errors':
				// تصاویری که در اجرای قبلی خطا خورده‌اند
				$args['meta_query'] = array(
					array(
						'key'   => OSN_IMG_META_FLAG,
						'value' => 'error',
					),
				);
				break;
		}

		return $args;
	}

	/**
	 * شمارش تصاویر باقی‌مانده در یک حالت
	 */
	public static function count_for_mode( $mode = 'all' ) {
		if ( 'heavy' === $mode ) {
			return count( self::heavy_pending_ids() );
		}

		$args                   = self::base_args_for_mode( $mode );
		$args['posts_per_page'] = 1;
		$args['no_found_rows']  = false;
		$q                      = new WP_Query( $args );
		return (int) $q->found_posts;
	}

	/** سازگاری با نسخه قبل (اندپوینت status) */
	public static function count_unprocessed() {
		return self::count_for_mode( 'all' );
	}

	/**
	 * دریافت شناسه‌ها برای پردازش
	 */
	public static function get_ids_for_mode( $mode, $limit ) {
		if ( 'heavy' === $mode ) {
			return array_slice( self::heavy_pending_ids(), 0, $limit );
		}

		$args                   = self::base_args_for_mode( $mode );
		$args['posts_per_page'] = $limit;
		$args['no_found_rows']  = true;
		$q                      = new WP_Query( $args );
		return $q->posts;
	}

	/**
	 * شناسه تصاویر حجیمِ هنوز پردازش‌نشده (از نتیجه اسکن)
	 */
	private static function heavy_pending_ids() {
		$scan = get_option( self::SCAN_OPTION, array() );
		if ( empty( $scan['heavy'] ) ) {
			return array();
		}
		$ids = array();
		foreach ( $scan['heavy'] as $item ) {
			if ( ! get_post_meta( $item['id'], OSN_IMG_META_FLAG, true ) ) {
				$ids[] = (int) $item['id'];
			}
		}
		return $ids;
	}

	/* =====================================================================
	 * اسکن مرحله‌به‌مرحله کتابخانه رسانه
	 * =================================================================== */

	public function ajax_scan_step() {
		check_ajax_referer( 'osn_batch_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'دسترسی غیرمجاز', 403 );
		}

		$restart = ! empty( $_POST['restart'] );
		$scan    = $restart ? array() : get_option( self::SCAN_OPTION, array() );

		$defaults = array(
			'offset'        => 0,
			'total'         => 0,
			'bytes'         => 0,
			'non_webp'      => 0,
			'non_webp_bytes'=> 0,
			'processed'     => 0,
			'saved_bytes'   => 0,
			'backup_count'  => 0,
			'backup_bytes'  => 0,
			'webp_count'    => 0,
			'avif_count'    => 0,
			'no_alt'        => 0,
			'heavy'         => array(),
			'done'          => false,
			'grand_total'   => 0,
			'time'          => time(),
		);
		$scan = wp_parse_args( $scan, $defaults );

		if ( $restart ) {
			$scan          = $defaults;
			$scan['time']  = time();
			// شمارش کل برای نوار پیشرفت
			$q = new WP_Query( array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			) );
			$scan['grand_total'] = (int) $q->found_posts;
		}

		$settings   = osn_img_get_settings();
		$heavy_min  = max( 50, absint( $settings['heavy_kb'] ) ) * 1024;

		$q = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ),
			'posts_per_page' => self::SCAN_CHUNK,
			'offset'         => $scan['offset'],
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		) );

		foreach ( $q->posts as $id ) {
			$file = get_attached_file( $id );
			if ( ! $file || ! file_exists( $file ) ) {
				continue;
			}

			$size = (int) filesize( $file );
			$mime = get_post_mime_type( $id );
			$flag = get_post_meta( $id, OSN_IMG_META_FLAG, true );

			$scan['total']++;
			$scan['bytes'] += $size;

			// ایندکس حجم (برای مرتب‌سازی) و Hash (برای تشخیص تصاویر تکراری)
			update_post_meta( $id, OSN_IMG_META_FILESIZE, $size );
			if ( ! get_post_meta( $id, '_osn_hash', true ) ) {
				$hash = @md5_file( $file );
				if ( $hash ) {
					update_post_meta( $id, '_osn_hash', $hash );
				}
			}

			if ( in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
				$scan['non_webp']++;
				$scan['non_webp_bytes'] += $size;
			}
			if ( 'image/webp' === $mime ) {
				$scan['webp_count']++;
			}
			if ( get_post_meta( $id, '_osn_avif', true ) ) {
				$scan['avif_count']++;
			}
			if ( ! get_post_meta( $id, '_wp_attachment_image_alt', true ) ) {
				$scan['no_alt']++;
			}

			if ( $flag ) {
				$scan['processed']++;
			}

			// آمار Backup و صرفه‌جویی
			$orig = get_post_meta( $id, OSN_IMG_META_ORIGINAL, true );
			if ( $orig && file_exists( $orig ) ) {
				$orig_size = (int) filesize( $orig );
				$scan['backup_count']++;
				$scan['backup_bytes'] += $orig_size;
				if ( $orig_size > $size ) {
					$scan['saved_bytes'] += ( $orig_size - $size );
				}
			}

			// تصاویر حجیمِ پردازش‌نشده
			if ( ! $flag && $size >= $heavy_min ) {
				$scan['heavy'][] = array(
					'id'    => $id,
					'size'  => $size,
					'title' => get_the_title( $id ),
				);
			}
		}

		// نگه‌داشتن ۵۰ تصویر سنگین‌تر
		usort( $scan['heavy'], function ( $a, $b ) {
			return $b['size'] - $a['size'];
		} );
		$scan['heavy'] = array_slice( $scan['heavy'], 0, 50 );

		$scan['offset'] += self::SCAN_CHUNK;
		$scan['done']    = count( $q->posts ) < self::SCAN_CHUNK;

		update_option( self::SCAN_OPTION, $scan, false );

		wp_send_json_success( self::scan_summary( $scan ) );
	}

	/**
	 * خلاصه اسکن برای نمایش (با فرمت حجم خوانا)
	 */
	private static function scan_summary( $scan ) {
		$heavy_view = array();
		foreach ( array_slice( $scan['heavy'], 0, 20 ) as $item ) {
			if ( get_post_meta( $item['id'], OSN_IMG_META_FLAG, true ) ) {
				continue; // در همین جلسه پردازش شده
			}
			$meta = wp_get_attachment_metadata( $item['id'] );
			$heavy_view[] = array(
				'id'    => $item['id'],
				'title' => $item['title'],
				'size'  => size_format( $item['size'], 1 ),
				'dims'  => ( ! empty( $meta['width'] ) ? $meta['width'] . '×' . $meta['height'] : '—' ),
				'thumb' => wp_get_attachment_image_url( $item['id'], 'thumbnail' ),
				'edit'  => get_edit_post_link( $item['id'], 'raw' ),
			);
		}

		return array(
			'done'         => ! empty( $scan['done'] ),
			'scanned'      => (int) $scan['total'],
			'grand_total'  => (int) $scan['grand_total'],
			'bytes'        => size_format( $scan['bytes'], 1 ),
			'non_webp'     => (int) $scan['non_webp'],
			'non_webp_bytes' => size_format( $scan['non_webp_bytes'], 1 ),
			'processed'    => (int) $scan['processed'],
			'saved'        => size_format( $scan['saved_bytes'], 1 ),
			'backup_count' => (int) $scan['backup_count'],
			'backup_bytes' => size_format( $scan['backup_bytes'], 1 ),
			'heavy_count'  => count( $scan['heavy'] ),
			'webp_count'   => isset( $scan['webp_count'] ) ? (int) $scan['webp_count'] : 0,
			'avif_count'   => isset( $scan['avif_count'] ) ? (int) $scan['avif_count'] : 0,
			'no_alt'       => isset( $scan['no_alt'] ) ? (int) $scan['no_alt'] : 0,
			'heavy'        => $heavy_view,
			'time'         => ! empty( $scan['time'] ) ? wp_date( 'Y/m/d H:i', $scan['time'] ) : '',
		);
	}

	/* =====================================================================
	 * AJAX: شمارش / پردازش دسته‌ای / تکی / Backup
	 * =================================================================== */

	public function ajax_count() {
		check_ajax_referer( 'osn_batch_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'دسترسی غیرمجاز', 403 );
		}
		$mode = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'all';
		wp_send_json_success( array( 'count' => self::count_for_mode( $mode ) ) );
	}

	public function ajax_run() {
		check_ajax_referer( 'osn_batch_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'دسترسی غیرمجاز', 403 );
		}

		$mode       = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'all';
		$batch_size = isset( $_POST['batch_size'] ) ? max( 1, min( 10, absint( $_POST['batch_size'] ) ) ) : 3;
		$test_limit = isset( $_POST['test_limit'] ) ? absint( $_POST['test_limit'] ) : 0;
		$force      = ( 'errors' === $mode ); // خطاخورده‌ها با force دوباره امتحان می‌شوند

		if ( $test_limit > 0 ) {
			$batch_size = min( $test_limit, $batch_size );
		}

		@set_time_limit( 120 );

		$ids     = self::get_ids_for_mode( $mode, $batch_size );
		$done    = 0;
		$skipped = 0;
		$errors  = array();
		$samples = array();

		foreach ( $ids as $id ) {
			if ( 'errors' === $mode ) {
				delete_post_meta( $id, OSN_IMG_META_FLAG ); // پاک‌کردن علامت خطا برای تلاش مجدد
			}

			$result = OSN_Image_Processor::process_attachment( $id, $force );

			if ( is_wp_error( $result ) ) {
				$errors[] = 'ID ' . $id . ': ' . $result->get_error_message();
				update_post_meta( $id, OSN_IMG_META_FLAG, 'error' );
			} elseif ( 'done' === $result ) {
				$done++;
				$samples[] = array(
					'id'  => $id,
					'url' => wp_get_attachment_image_url( $id, 'medium' ),
				);
			} else {
				$skipped++;
			}
		}

		wp_send_json_success( array(
			'processed' => $done,
			'skipped'   => $skipped,
			'errors'    => $errors,
			'remaining' => self::count_for_mode( $mode ),
			'samples'   => $samples,
		) );
	}

	/** پردازش تکی (از جدول تصاویر حجیم) */
	public function ajax_process_one() {
		check_ajax_referer( 'osn_batch_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'دسترسی غیرمجاز', 403 );
		}
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$result = OSN_Image_Processor::process_attachment( $id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$file   = get_attached_file( $id );
		$report = get_post_meta( $id, OSN_IMG_META_REPORT, true );
		$out    = array(
			'status'   => $result,
			'new_size' => $file && file_exists( $file ) ? size_format( filesize( $file ), 1 ) : '',
			'url'      => wp_get_attachment_image_url( $id, 'medium' ),
		);
		if ( is_array( $report ) && ! empty( $report['before'] ) ) {
			$out['report'] = array(
				'before'   => (int) $report['before'],
				'after'    => (int) $report['after'],
				'before_h' => size_format( $report['before'], 1 ),
				'after_h'  => size_format( $report['after'], 1 ),
				'percent'  => (int) $report['percent'],
			);
		}
		wp_send_json_success( $out );
	}

	/**
	 * آزادسازی فضا: حذف مرحله‌به‌مرحله فایل‌های اصلی Backup
	 */
	public function ajax_cleanup_step() {
		check_ajax_referer( 'osn_batch_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'دسترسی غیرمجاز', 403 );
		}

		$q = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 20,
			'fields'         => 'ids',
			'no_found_rows'  => false,
			'meta_query'     => array(
				array(
					'key'     => OSN_IMG_META_ORIGINAL,
					'compare' => 'EXISTS',
				),
			),
		) );

		$freed = 0;
		foreach ( $q->posts as $id ) {
			$orig = get_post_meta( $id, OSN_IMG_META_ORIGINAL, true );
			if ( $orig && file_exists( $orig ) ) {
				$freed += (int) filesize( $orig );
				@unlink( $orig );
			}
			delete_post_meta( $id, OSN_IMG_META_ORIGINAL );
		}

		$remaining = max( 0, (int) $q->found_posts - count( $q->posts ) );

		OSN_Logger::debug( 'آزادسازی فضا: ' . size_format( $freed ) . ' حذف شد، ' . $remaining . ' باقی‌مانده' );

		wp_send_json_success( array(
			'freed'     => size_format( $freed, 1 ),
			'deleted'   => count( $q->posts ),
			'remaining' => $remaining,
		) );
	}

	/**
	 * بازگردانی یک تصویر از Backup
	 */
	public function ajax_restore() {
		check_ajax_referer( 'osn_batch_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'دسترسی غیرمجاز', 403 );
		}
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$result = OSN_Image_Processor::restore_attachment( $id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( array( 'message' => 'تصویر ID ' . $id . ' به نسخه اصلی بازگردانده شد.' ) );
	}

	/* =====================================================================
	 * صفحه مدیریت
	 * =================================================================== */

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی غیرمجاز' );
		}

		$settings = osn_img_get_settings();
		$nonce    = wp_create_nonce( 'osn_batch_nonce' );
		$scan     = get_option( self::SCAN_OPTION, array() );
		$summary  = ! empty( $scan ) ? self::scan_summary( wp_parse_args( $scan, array( 'heavy' => array(), 'grand_total' => 0 ) ) ) : null;
		$counts   = array(
			'all'      => self::count_for_mode( 'all' ),
			'nonwebp'  => self::count_for_mode( 'nonwebp' ),
			'heavy'    => self::count_for_mode( 'heavy' ),
			'products' => self::count_for_mode( 'products' ),
			'errors'   => self::count_for_mode( 'errors' ),
		);
		?>
		<div class="wrap" style="direction:rtl; text-align:right; max-width:960px;">
			<h1>🖼️ OptiSnap</h1>

			<?php if ( empty( $settings['keep_original'] ) ) : ?>
				<div class="notice notice-warning"><p><strong>توجه:</strong> گزینه «نگه‌داشتن فایل اصلی» خاموش است؛ فایل‌های اصلی بعد از پردازش حذف می‌شوند و امکان بازگردانی نخواهد بود.</p></div>
			<?php endif; ?>
			<?php if ( ! empty( $settings['auto_cron'] ) ) : ?>
				<div class="notice notice-info"><p>⏱ پردازش خودکار پس‌زمینه فعال است: هر ۵ دقیقه حداکثر <?php echo esc_html( $settings['cron_batch'] ); ?> تصویر پردازش می‌شود (بدون نیاز به باز بودن این صفحه).</p></div>
			<?php endif; ?>

			<style>
				.osn-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px 20px;margin-top:16px;}
				.osn-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:12px 0;}
				.osn-stat{background:#f6f7f7;border-radius:8px;padding:12px;text-align:center;}
				.osn-stat b{display:block;font-size:20px;color:#1d2327;}
				.osn-stat span{color:#646970;font-size:12px;}
				.osn-bar{background:#e5e5e5;border-radius:6px;overflow:hidden;height:22px;}
				.osn-bar>div{background:linear-gradient(90deg,#2271b1,#72aee6);height:100%;width:0%;transition:width .3s;color:#fff;font-size:12px;line-height:22px;text-align:center;}
				.osn-heavy-table img{width:48px;height:48px;object-fit:cover;border-radius:6px;}
			</style>

			<!-- ================= اسکن ================= -->
			<div class="osn-card">
				<h2 style="margin-top:0;">🔍 اسکن کامل تصاویر هاست</h2>
				<p class="description">تمام کتابخانه رسانه مرحله‌به‌مرحله (هر بار <?php echo esc_html( self::SCAN_CHUNK ); ?> تصویر، فقط خواندن) بررسی می‌شود - بدون فشار به هاست. تصاویر غیر WebP و تصاویر حجیم‌تر از <?php echo esc_html( $settings['heavy_kb'] ); ?>KB شناسایی می‌شوند.</p>
				<p>
					<button class="button button-primary" id="osn-scan-btn">شروع اسکن</button>
					<span id="osn-scan-status" class="description"><?php echo $summary ? esc_html( 'آخرین اسکن: ' . $summary['time'] ) : 'هنوز اسکنی انجام نشده.'; ?></span>
				</p>
				<div class="osn-bar" id="osn-scan-bar-wrap" style="display:none;"><div id="osn-scan-bar"></div></div>

				<div class="osn-stats" id="osn-stats">
					<div class="osn-stat"><b id="st-total"><?php echo $summary ? esc_html( number_format_i18n( $summary['scanned'] ) ) : '—'; ?></b><span>کل تصاویر</span></div>
					<div class="osn-stat"><b id="st-bytes"><?php echo $summary ? esc_html( $summary['bytes'] ) : '—'; ?></b><span>حجم کل</span></div>
					<div class="osn-stat"><b id="st-nonwebp" style="color:#d63638;"><?php echo $summary ? esc_html( number_format_i18n( $summary['non_webp'] ) ) : '—'; ?></b><span>غیر WebP (<span id="st-nonwebp-bytes"><?php echo $summary ? esc_html( $summary['non_webp_bytes'] ) : '—'; ?></span>)</span></div>
					<div class="osn-stat"><b id="st-heavy" style="color:#dba617;"><?php echo $summary ? esc_html( number_format_i18n( $summary['heavy_count'] ) ) : '—'; ?></b><span>تصاویر حجیم</span></div>
					<div class="osn-stat"><b id="st-processed" style="color:#00a32a;"><?php echo $summary ? esc_html( number_format_i18n( $summary['processed'] ) ) : '—'; ?></b><span>پردازش‌شده</span></div>
					<div class="osn-stat"><b id="st-saved" style="color:#00a32a;"><?php echo $summary ? esc_html( $summary['saved'] ) : '—'; ?></b><span>صرفه‌جویی تاکنون</span></div>
				</div>
			</div>

			<!-- ================= پردازش دسته‌ای ================= -->
			<div class="osn-card">
				<h2 style="margin-top:0;">⚙️ پردازش مرحله‌به‌مرحله</h2>
				<table class="form-table" role="presentation" style="margin-top:0;">
					<tr>
						<th scope="row">چه تصاویری؟</th>
						<td>
							<select id="osn-mode">
								<option value="all">همه پردازش‌نشده‌ها (<?php echo esc_html( number_format_i18n( $counts['all'] ) ); ?>)</option>
								<option value="nonwebp">فقط غیر WebP - یعنی JPG/PNG (<?php echo esc_html( number_format_i18n( $counts['nonwebp'] ) ); ?>)</option>
								<option value="heavy">فقط تصاویر حجیم از نتیجه اسکن (<?php echo esc_html( number_format_i18n( $counts['heavy'] ) ); ?>)</option>
								<option value="products">فقط تصاویر محصولات (<?php echo esc_html( number_format_i18n( $counts['products'] ) ); ?>)</option>
								<?php if ( $counts['errors'] > 0 ) : ?>
									<option value="errors">تلاش مجدد خطاخورده‌ها (<?php echo esc_html( number_format_i18n( $counts['errors'] ) ); ?>)</option>
								<?php endif; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">فشار روی هاست</th>
						<td>
							تعداد در هر دسته:
							<select id="osn-batch-size">
								<option value="1">۱ (کمترین فشار)</option>
								<option value="3" selected>۳</option>
								<option value="5">۵</option>
								<option value="10">۱۰ (سریع‌ترین)</option>
							</select>
							&nbsp;&nbsp;مکث بین دسته‌ها:
							<select id="osn-delay">
								<option value="0">بدون مکث</option>
								<option value="1000" selected>۱ ثانیه</option>
								<option value="2000">۲ ثانیه</option>
								<option value="5000">۵ ثانیه</option>
							</select>
						</td>
					</tr>
				</table>
				<p>
					<button class="button button-secondary" id="osn-test-btn">🧪 تست روی ۳ تصویر</button>
					<button class="button button-primary" id="osn-start-btn">▶️ شروع پردازش</button>
					<button class="button" id="osn-stop-btn" style="display:none;">⏸ توقف</button>
				</p>
				<div id="osn-progress-wrap" style="display:none;">
					<div class="osn-bar"><div id="osn-progress-bar"></div></div>
					<p id="osn-progress-text" style="margin-top:6px;"></p>
				</div>
				<div id="osn-samples" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;"></div>
				<div id="osn-errors" style="color:#d63638;margin-top:10px;"></div>
			</div>

			<!-- ================= تصاویر حجیم ================= -->
			<div class="osn-card">
				<h2 style="margin-top:0;">🏋️ سنگین‌ترین تصاویر پردازش‌نشده</h2>
				<p class="description">۲۰ تصویر سنگین‌تر از آخرین اسکن. می‌توانید تکی پردازش کنید یا از بخش بالا حالت «فقط تصاویر حجیم» را انتخاب کنید.</p>
				<table class="widefat striped osn-heavy-table" id="osn-heavy-table">
					<thead><tr><th style="width:60px;"></th><th>عنوان</th><th>حجم</th><th>ابعاد</th><th style="width:180px;">عملیات</th></tr></thead>
					<tbody>
						<?php if ( $summary && ! empty( $summary['heavy'] ) ) : ?>
							<?php foreach ( $summary['heavy'] as $h ) : ?>
								<tr data-id="<?php echo esc_attr( $h['id'] ); ?>">
									<td><?php if ( $h['thumb'] ) : ?><img src="<?php echo esc_url( $h['thumb'] ); ?>"><?php endif; ?></td>
									<td><a href="<?php echo esc_url( $h['edit'] ); ?>" target="_blank"><?php echo esc_html( $h['title'] ? $h['title'] : 'ID ' . $h['id'] ); ?></a></td>
									<td><strong><?php echo esc_html( $h['size'] ); ?></strong></td>
									<td><?php echo esc_html( $h['dims'] ); ?></td>
									<td><button class="button button-small osn-one-btn" data-id="<?php echo esc_attr( $h['id'] ); ?>">پردازش این تصویر</button></td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr><td colspan="5">برای مشاهده لیست، ابتدا اسکن را اجرا کنید.</td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<!-- ================= Backup ================= -->
			<div class="osn-card">
				<h2 style="margin-top:0;">💾 مدیریت Backup فایل‌های اصلی</h2>
				<p>
					فایل‌های اصلی نگه‌داشته‌شده:
					<strong id="osn-backup-count"><?php echo $summary ? esc_html( number_format_i18n( $summary['backup_count'] ) ) : '—'; ?></strong>
					(حجم: <strong id="osn-backup-bytes"><?php echo $summary ? esc_html( $summary['backup_bytes'] ) : '—'; ?></strong>)
				</p>
				<p>
					<button class="button" id="osn-cleanup-btn">🧹 آزادسازی فضا (حذف فایل‌های اصلی)</button>
					<span class="description">فقط وقتی از نتیجه پردازش مطمئن شدید! بعد از حذف، بازگردانی ممکن نیست.</span>
				</p>
				<p id="osn-cleanup-result" style="color:#00a32a;"></p>
				<hr>
				<p>
					بازگردانی یک تصویر به نسخه اصلی:
					شناسه تصویر (Attachment ID):
					<input type="number" id="osn-restore-id" class="small-text" min="1">
					<button class="button" id="osn-restore-btn">↩️ بازگردانی</button>
				</p>
				<p id="osn-restore-result"></p>
			</div>
		</div>

		<script>
		jQuery(function($){
			var nonce = '<?php echo esc_js( $nonce ); ?>';
			var running = false, scanning = false;
			var total = 0, processedTotal = 0;

			// Escape امن برای درج داده‌های سرور در HTML (رفع Stored/XSS در ادمین - v3.9.1)
			function esc(s){
				return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
					return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
				});
			}

			/* ---------- اسکن ---------- */
			function scanStep(restart){
				if (!scanning) return;
				$.post(ajaxurl, {action:'osn_scan_step', nonce:nonce, restart: restart ? 1 : 0})
				.done(function(res){
					if (!res.success) { scanning = false; alert('خطا در اسکن'); return; }
					var d = res.data;
					var pct = d.grand_total > 0 ? Math.min(100, Math.round(d.scanned / d.grand_total * 100)) : 100;
					$('#osn-scan-bar').css('width', pct + '%').text(pct + '%');
					$('#st-total').text(d.scanned.toLocaleString('fa'));
					$('#st-bytes').text(d.bytes);
					$('#st-nonwebp').text(d.non_webp.toLocaleString('fa'));
					$('#st-nonwebp-bytes').text(d.non_webp_bytes);
					$('#st-heavy').text(d.heavy_count.toLocaleString('fa'));
					$('#st-processed').text(d.processed.toLocaleString('fa'));
					$('#st-saved').text(d.saved);
					$('#osn-backup-count').text(d.backup_count.toLocaleString('fa'));
					$('#osn-backup-bytes').text(d.backup_bytes);

					if (d.done) {
						scanning = false;
						$('#osn-scan-btn').prop('disabled', false).text('اسکن مجدد');
						$('#osn-scan-status').text('اسکن کامل شد: ' + d.time);
						renderHeavy(d.heavy);
					} else {
						setTimeout(function(){ scanStep(false); }, 300); // مکث کوتاه = فشار کمتر
					}
				})
				.fail(function(){ scanning = false; $('#osn-scan-btn').prop('disabled', false); alert('خطای ارتباط با سرور'); });
			}

			function renderHeavy(list){
				var $tb = $('#osn-heavy-table tbody').empty();
				if (!list || !list.length) { $tb.append('<tr><td colspan="5">تصویر حجیم پردازش‌نشده‌ای یافت نشد 🎉</td></tr>'); return; }
				list.forEach(function(h){
					// v3.9.1: همه مقادیر سرور قبل از درج در HTML escape می‌شوند
					$tb.append('<tr data-id="'+esc(h.id)+'"><td>'+(h.thumb?'<img src="'+esc(h.thumb)+'">':'')+'</td>'+
						'<td><a href="'+esc(h.edit)+'" target="_blank">'+(h.title ? esc(h.title) : ('ID '+esc(h.id)))+'</a></td>'+
						'<td><strong>'+esc(h.size)+'</strong></td><td>'+esc(h.dims)+'</td>'+
						'<td><button class="button button-small osn-one-btn" data-id="'+esc(h.id)+'">پردازش این تصویر</button></td></tr>');
				});
			}

			$('#osn-scan-btn').on('click', function(){
				scanning = true;
				$(this).prop('disabled', true).text('در حال اسکن...');
				$('#osn-scan-bar-wrap').show();
				scanStep(true);
			});

			/* ---------- پردازش دسته‌ای ---------- */
			function refreshCount(cb){
				$.post(ajaxurl, {action:'osn_batch_count', nonce:nonce, mode: $('#osn-mode').val()})
				.done(function(res){ if (res.success){ total = res.data.count; if(cb) cb(); } });
			}

			function setProgress(remaining){
				var done = Math.max(0, total - remaining);
				var pct = total > 0 ? Math.round(done / total * 100) : 100;
				$('#osn-progress-bar').css('width', pct + '%').text(pct + '%');
				$('#osn-progress-text').text('پردازش‌شده در این جلسه: ' + processedTotal + ' | باقی‌مانده: ' + remaining);
			}

			function runBatch(testLimit){
				if (!running) return;
				$.post(ajaxurl, {
					action: 'osn_batch_run', nonce: nonce,
					mode: $('#osn-mode').val(),
					batch_size: $('#osn-batch-size').val(),
					test_limit: testLimit || 0
				}).done(function(res){
					if (!res.success) { $('#osn-errors').append('<p>خطا: '+(res.data||'نامشخص')+'</p>'); stop(); return; }
					var d = res.data;
					processedTotal += d.processed;
					setProgress(d.remaining);
					(d.samples||[]).forEach(function(s){
						if (s.url) $('#osn-samples').append('<img src="'+esc(s.url)+'" style="width:90px;height:90px;object-fit:cover;border-radius:8px;border:2px solid #2271b1;" title="ID '+esc(s.id)+'">');
					});
					(d.errors||[]).forEach(function(e){ $('#osn-errors').append('<p>⚠️ '+esc(e)+'</p>'); });

					if (testLimit) { stop(); $('#osn-progress-text').append(' - تست تمام شد؛ نتیجه را بررسی کنید.'); return; }

					if (d.remaining > 0 && (d.processed + d.skipped + (d.errors ? d.errors.length : 0)) > 0) {
						var delay = parseInt($('#osn-delay').val(), 10) || 0;
						setTimeout(function(){ runBatch(); }, delay);
					} else {
						stop(); $('#osn-progress-text').append(' - ✅ پایان پردازش.');
					}
				}).fail(function(){ $('#osn-errors').append('<p>خطای ارتباط؛ دوباره تلاش کنید.</p>'); stop(); });
			}

			function start(testLimit){
				refreshCount(function(){
					running = true;
					processedTotal = 0;
					$('#osn-progress-wrap').show();
					$('#osn-stop-btn').show();
					$('#osn-start-btn, #osn-test-btn').prop('disabled', true);
					runBatch(testLimit);
				});
			}
			function stop(){
				running = false;
				$('#osn-stop-btn').hide();
				$('#osn-start-btn, #osn-test-btn').prop('disabled', false);
			}

			$('#osn-start-btn').on('click', function(){
				if (!confirm('پردازش انبوه شروع شود؟')) return;
				start(0);
			});
			$('#osn-test-btn').on('click', function(){ start(3); });
			$('#osn-stop-btn').on('click', stop);

			/* ---------- پردازش تکی ---------- */
			$(document).on('click', '.osn-one-btn', function(){
				var $btn = $(this), id = $btn.data('id');
				$btn.prop('disabled', true).text('در حال پردازش...');
				$.post(ajaxurl, {action:'osn_process_one', nonce:nonce, id:id})
				.done(function(res){
					if (res.success) {
						$btn.closest('tr').css('opacity', .5).find('td:last').html('✅ انجام شد - حجم جدید: <strong>'+(res.data.new_size||'')+'</strong>');
					} else {
						$btn.prop('disabled', false).text('پردازش این تصویر');
						alert('خطا: ' + res.data);
					}
				});
			});

			/* ---------- Backup ---------- */
			function cleanupStep(totalFreedText){
				$.post(ajaxurl, {action:'osn_cleanup_step', nonce:nonce})
				.done(function(res){
					if (!res.success) { alert('خطا: ' + res.data); return; }
					var d = res.data;
					$('#osn-cleanup-result').text('حذف شد: ' + d.deleted + ' فایل (' + d.freed + ') - باقی‌مانده: ' + d.remaining);
					if (d.remaining > 0 && d.deleted > 0) {
						setTimeout(cleanupStep, 500);
					} else {
						$('#osn-cleanup-result').append(' ✅');
						$('#osn-cleanup-btn').prop('disabled', false);
					}
				});
			}
			$('#osn-cleanup-btn').on('click', function(){
				if (!confirm('همه فایل‌های اصلی Backup حذف شوند؟ این عمل غیرقابل بازگشت است!')) return;
				if (!confirm('مطمئن هستید؟ بعد از حذف، امکان بازگردانی تصاویر به نسخه اصلی وجود نخواهد داشت.')) return;
				$(this).prop('disabled', true);
				cleanupStep();
			});

			$('#osn-restore-btn').on('click', function(){
				var id = parseInt($('#osn-restore-id').val(), 10);
				if (!id) { alert('شناسه تصویر را وارد کنید.'); return; }
				if (!confirm('تصویر ID ' + id + ' به نسخه اصلی (قبل از WebP و واترمارک) بازگردانده شود؟')) return;
				$.post(ajaxurl, {action:'osn_restore', nonce:nonce, id:id})
				.done(function(res){
					$('#osn-restore-result').css('color', res.success ? '#00a32a' : '#d63638')
						.text(res.success ? res.data.message : ('خطا: ' + res.data));
				});
			});
		});
		</script>
		<?php
	}
}
