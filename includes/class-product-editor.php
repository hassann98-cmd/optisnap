<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OptiSnap Product Editor Integration (v3.7)
 *
 * دکمه‌های ⚡ بهینه‌سازی و 💧 واترمارک را مستقیم روی تصویر اصلی محصول و
 * تک‌تک تصاویر گالری، داخل خود صفحه ویرایش محصول ووکامرس نمایش می‌دهد -
 * بدون نیاز به رفتن به منوی جداگانه. از همان AJAX handlerهای موجود
 * (osn_process_one و osn_watermark_one) استفاده می‌کند، پس منطق پردازش
 * جای دیگری تکرار نشده است.
 */
class OSN_Product_Editor {

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_osn_status_batch', array( $this, 'ajax_status_batch' ) );
	}

	public function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}
		// همه AJAX handlerهای مصرف‌شده (osn_process_one / osn_watermark_one) نیاز به
		// manage_options دارند؛ برای جلوگیری از نمایش دکمه‌هایی که برای مدیر فروشگاه
		// (Shop Manager بدون این دسترسی) با خطای «غیرمجاز» مواجه می‌شوند، فقط به
		// کاربرانی نشان داده می‌شود که واقعاً می‌توانند از آن‌ها استفاده کنند.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_register_style( 'osn-product-editor', OSN_IMG_URL . 'assets/css/product-editor.css', array(), OSN_IMG_VERSION );
		wp_enqueue_style( 'osn-product-editor' );

		wp_register_script( 'osn-product-editor', OSN_IMG_URL . 'assets/js/product-editor.js', array( 'jquery' ), OSN_IMG_VERSION, true );
		wp_enqueue_script( 'osn-product-editor' );
		wp_localize_script( 'osn-product-editor', 'osnPE', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'osn_batch_nonce' ),
			'i18n'    => array(
				'optimize'   => __( 'بهینه‌سازی', 'optisnap' ),
				'watermark'  => __( 'واترمارک', 'optisnap' ),
				'confirmWm'  => __( 'واترمارک روی این تصویر اعمال شود؟ (Backup خودکار گرفته می‌شود)', 'optisnap' ),
				'done'       => __( 'انجام شد', 'optisnap' ),
				'error'      => __( 'خطا', 'optisnap' ),
				'processing' => __( '...', 'optisnap' ),
				'excluded'   => __( 'استثناء از پردازش خودکار', 'optisnap' ),
				'optimized'  => __( 'بهینه‌سازی: شده', 'optisnap' ),
				'reduction'  => __( 'کاهش حجم', 'optisnap' ),
				'already'    => __( 'بهینه‌سازی: از قبل مناسب بود (رد شد)', 'optisnap' ),
				'failed'     => __( 'بهینه‌سازی: خطا خورده - نیاز به بررسی', 'optisnap' ),
				'notYet'     => __( 'بهینه‌سازی: هنوز انجام نشده', 'optisnap' ),
				'has'        => __( 'دارد', 'optisnap' ),
				'notHas'     => __( 'ندارد', 'optisnap' ),
				'manualLight'=> __( 'نور دستی: ', 'optisnap' ),
				'optimizedToast' => __( 'تصویر بهینه شد ✅', 'optisnap' ),
				'alreadyWm'  => __( 'این تصویر قبلاً واترمارک شده است', 'optisnap' ),
				'skippedWm'  => __( 'این تصویر رد شد (استثناء یا لوگو تنظیم نشده)', 'optisnap' ),
				'appliedWm'  => __( 'واترمارک اعمال شد 💧', 'optisnap' ),
				'watermarkPrefix' => __( 'واترمارک: ', 'optisnap' ),
				'errorPrefix' => __( 'خطا: ', 'optisnap' ),
			),
		) );
	}

	/**
	 * وضعیت دسته‌ای برای چند Attachment ID - برای نمایش اولیه بج‌ها بدون N درخواست جدا
	 */
	public function ajax_status_batch() {
		check_ajax_referer( 'osn_batch_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'دسترسی غیرمجاز', 403 );
		}
		$ids = isset( $_POST['ids'] ) ? array_filter( array_map( 'absint', (array) $_POST['ids'] ) ) : array();
		$out = array();
		foreach ( $ids as $id ) {
			$flag       = get_post_meta( $id, OSN_IMG_META_FLAG, true );
			$report     = get_post_meta( $id, OSN_IMG_META_REPORT, true );
			$brightness = (int) get_post_meta( $id, '_osn_brightness', true );
			$out[ $id ] = array(
				'optimized'   => ( '1' === (string) $flag ),
				'skipped'     => ( 'skipped' === $flag ),
				'error'       => ( 'error' === $flag ),
				'watermarked' => (bool) get_post_meta( $id, OSN_IMG_META_WATERMARKED, true ),
				'excluded'    => (bool) get_post_meta( $id, OSN_IMG_META_EXCLUDED, true ),
				'percent'     => ( is_array( $report ) && isset( $report['percent'] ) ) ? (int) $report['percent'] : null,
				'quality'     => ( is_array( $report ) && ! empty( $report['quality'] ) ) ? $report['quality'] : null,
				'brightness'  => $brightness,
			);
		}
		wp_send_json_success( $out );
	}

}
