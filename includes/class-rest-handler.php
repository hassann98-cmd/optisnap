<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * سازگاری با REST API و اپ JLuxe Admin
 *
 * نکته مهم:
 * فیلترهای wp_handle_upload / wp_handle_sideload (در فایل اصلی افزونه)
 * به‌صورت خودکار هر آپلودی را پوشش می‌دهند، از جمله:
 *  - /wp-json/wp/v2/media
 *  - /wp-json/jluxe-admin/v1/media  (اگر از media_handle_upload یا wp_handle_upload استفاده کند)
 *
 * این کلاس یک لایه اطمینان اضافه است:
 *  1) بعد از ساخت Attachment از طریق REST بررسی می‌کند که پردازش انجام شده باشد؛
 *     اگر نشده بود (مثلاً اندپوینت سفارشی مسیر متفاوتی رفته باشد) همان‌جا پردازش می‌کند.
 *  2) یک اندپوینت وضعیت برای اپ اندروید فراهم می‌کند.
 */
class OSN_Rest_Handler {

	public function __construct() {
		// شبکه ایمنی: بعد از درج Attachment از طریق REST
		add_action( 'rest_after_insert_attachment', array( $this, 'ensure_processed' ), 20, 2 );

		// اندپوینت وضعیت برای اپ JLuxe Admin
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * اگر آپلود REST به هر دلیلی از فیلتر wp_handle_upload رد نشده بود،
	 * همین‌جا Attachment را پردازش کن. (بدون خطر پردازش دوباره -
	 * process_attachment خودش متا و پسوند -jlx را چک می‌کند)
	 */
	public function ensure_processed( $attachment, $request ) {
		if ( empty( $attachment->ID ) ) {
			return;
		}

		// v3.9.1: احترام به Optimization Policy - قبلاً این لایه خودکار REST،
		// سیاست manual/products_only را نادیده می‌گرفت و تصویر را پردازش می‌کرد.
		// (پردازش دستی از Media Manager/Cron همچنان طبق سیاست خودش کار می‌کند.)
		$file = get_attached_file( $attachment->ID );
		if ( $file && ! osn_img_policy_allows_upload( $file ) ) {
			return;
		}

		$result = OSN_Image_Processor::process_attachment( $attachment->ID );

		if ( is_wp_error( $result ) ) {
			OSN_Logger::log( 'REST safety-net (ID=' . $attachment->ID . '): ' . $result->get_error_message() );
		}
	}

	/**
	 * فضای نام‌های REST این افزونه: هم نام جدید (optisnap/v1) و هم نام قدیمی
	 * (jluxe-image/v1) به‌طور کامل ثبت می‌شوند - نه یک Redirect - تا اگر اپ
	 * JLuxe Admin هنوز به مسیر قدیمی وصل است بدون تغییر کار کند. وقتی مطمئن
	 * شدید هیچ مصرف‌کننده‌ای از مسیر قدیمی استفاده نمی‌کند، می‌توانید
	 * $namespaces را فقط به 'optisnap/v1' محدود کنید.
	 */
	private static $namespaces = array( 'optisnap/v1', 'jluxe-image/v1' );

	public function register_routes() {
		// وضعیت واترمارک برای اپ JLuxe Admin (v3.1)
		// GET /wp-json/optisnap/v1/watermark-status (یا مسیر قدیمی /wp-json/jluxe-image/v1/watermark-status)
		// خروجی همیشه attachment_id محور است؛ هرگز URL به عنوان شناسه استفاده نشود.
		foreach ( self::$namespaces as $ns ) {
			register_rest_route( $ns, '/watermark-status', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'watermark_status' ),
				'permission_callback' => function () {
					return current_user_can( 'upload_files' );
				},
			) );

			register_rest_route( $ns, '/status', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => function () {
					return current_user_can( 'upload_files' );
				},
			) );
		}
	}

	/**
	 * وضعیت واترمارک: { total, watermarked, pending, queue }
	 */
	public function watermark_status() {
		$count_q = function ( $meta_query ) {
			$q = new WP_Query( array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => $meta_query,
			) );
			return (int) $q->found_posts;
		};

		$total       = $count_q( array() );
		$watermarked = $count_q( array( array( 'key' => OSN_IMG_META_WATERMARKED, 'compare' => 'EXISTS' ) ) );

		$state = get_option( OSN_Manager_Page::BULK_STATE, array() );
		$queue = ( ! empty( $state['queue'] ) && 'watermark' === $state['op'] ) ? count( $state['queue'] ) : 0;

		return rest_ensure_response( array(
			'total'       => $total,
			'watermarked' => $watermarked,
			'pending'     => max( 0, $total - $watermarked ),
			'queue'       => $queue,
		) );
	}

	/**
	 * وضعیت افزونه برای اپ اندروید:
	 * GET /wp-json/optisnap/v1/status (یا مسیر قدیمی /wp-json/jluxe-image/v1/status)
	 */
	public function status() {
		$settings = osn_img_get_settings();
		return rest_ensure_response( array(
			'webp_enabled'      => (bool) $settings['webp_enabled'],
			'watermark_enabled' => (bool) $settings['watermark_enabled'],
			'server_webp'       => OSN_Image_Processor::server_supports_webp(),
			'server_avif'       => function_exists( 'imageavif' ),
			'unprocessed_count' => OSN_Batch_Tool::count_unprocessed(),
			'version'           => OSN_IMG_VERSION,
		) );
	}
}
