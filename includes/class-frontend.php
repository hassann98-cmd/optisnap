<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OptiSnap Frontend / Responsive Output (v3.3)
 *
 * ۱) payload کامل تصویر با توابع استاندارد وردپرس (هیچ‌جا فقط URL برنگردد)
 * ۲) Alt خودکار تصاویر محصول در خروجی سایت (فقط Featured/Gallery؛ بنر و استثناءها هرگز)
 * ۳) غنی‌سازی REST ووکامرس با آرایه optisnap_images (srcset/sizes/alt/ابعاد)
 * ۴) اندپوینت /image/{id} برای فرانت‌اند سفارشی و اپ JLuxe Admin
 */
class OSN_Frontend {

	public function __construct() {
		// Alt خالی تصاویر محصول در همه خروجی‌های wp_get_attachment_image
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'fill_product_alt' ), 20, 2 );

		// غنی‌سازی پاسخ REST محصولات ووکامرس
		add_filter( 'woocommerce_rest_prepare_product_object', array( $this, 'enrich_wc_product' ), 20, 2 );

		// اندپوینت payload تصویر
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/* =====================================================================
	 * Payload کامل یک تصویر - استاندارد v3.3
	 * =================================================================== */

	/**
	 * تمام اطلاعات لازم برای Responsive واقعی در فرانت‌اند.
	 * از توابع استاندارد وردپرس استفاده می‌شود.
	 *
	 * @param int    $attachment_id
	 * @param string $size سایز مبنا برای srcset (پیش‌فرض full)
	 * @return array|null
	 */
	public static function payload( $attachment_id, $size = 'full' ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			return null;
		}

		$src = wp_get_attachment_image_src( $attachment_id, $size );
		if ( ! $src ) {
			return null;
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		$file = get_attached_file( $attachment_id );

		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( '' === $alt ) {
			// Fallback فقط برای تصاویر محصول (بنر و غیرمحصولی‌ها دست‌نخورده)
			$alt = self::product_alt_fallback( $attachment_id );
		}

		return array(
			'attachment_id'     => $attachment_id, // همیشه شناسه یکتا - هرگز URL به‌عنوان key
			'original_url'      => wp_get_attachment_image_url( $attachment_id, 'full' ),
			'thumbnail_url'     => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
			'medium_url'        => wp_get_attachment_image_url( $attachment_id, 'medium' ),
			'large_url'         => wp_get_attachment_image_url( $attachment_id, 'large' ),
			'src'               => $src[0],
			'responsive_srcset' => (string) wp_get_attachment_image_srcset( $attachment_id, $size ),
			'responsive_sizes'  => (string) wp_get_attachment_image_sizes( $attachment_id, $size ),
			'alt_text'          => (string) $alt,
			'width'             => ! empty( $meta['width'] ) ? (int) $meta['width'] : (int) $src[1],
			'height'            => ! empty( $meta['height'] ) ? (int) $meta['height'] : (int) $src[2],
			'file_size'         => ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0,
		);
	}

	/**
	 * Alt پیشنهادی از نام محصول - فقط برای تصاویر Featured/Gallery محصول
	 * تصاویر استثناءشده، بنر و غیرمحصولی: رشته خالی برمی‌گردد (بدون تغییر)
	 */
	public static function product_alt_fallback( $attachment_id ) {
		if ( get_post_meta( $attachment_id, OSN_IMG_META_EXCLUDED, true ) ) {
			return '';
		}
		$role = OSN_Image_Processor::get_image_role( $attachment_id );
		if ( ! in_array( $role, array( 'featured', 'gallery' ), true ) ) {
			return '';
		}
		$prod_id = OSN_Image_Processor::get_related_product_id( $attachment_id );
		return $prod_id ? (string) get_the_title( $prod_id ) : '';
	}

	/* =====================================================================
	 * فیلترها
	 * =================================================================== */

	/**
	 * پرکردن Alt خالی در خروجی wp_get_attachment_image
	 * (ووکامرس و قالب‌های استاندارد از همین تابع استفاده می‌کنند)
	 */
	public function fill_product_alt( $attr, $attachment ) {
		if ( ! empty( $attr['alt'] ) || empty( $attachment->ID ) ) {
			return $attr;
		}
		$alt = self::product_alt_fallback( $attachment->ID );
		if ( '' !== $alt ) {
			$attr['alt'] = $alt;
		}
		return $attr;
	}

	/**
	 * افزودن optisnap_images به پاسخ REST محصولات ووکامرس:
	 * /wp-json/wc/v3/products
	 * هر تصویر (اصلی + گالری) با payload کامل، نه فقط URL.
	 * کلید قدیمی jluxe_images هم برای سازگاری با اپ JLuxe Admin کنارش نگه داشته می‌شود.
	 */
	public function enrich_wc_product( $response, $product ) {
		if ( ! is_object( $response ) || ! method_exists( $response, 'get_data' ) ) {
			return $response;
		}

		$images = array();

		$thumb = $product->get_image_id();
		if ( $thumb ) {
			$p = self::payload( $thumb );
			if ( $p ) {
				$p['role'] = 'featured';
				$images[]  = $p;
			}
		}
		foreach ( (array) $product->get_gallery_image_ids() as $gid ) {
			$p = self::payload( $gid );
			if ( $p ) {
				$p['role'] = 'gallery';
				$images[]  = $p;
			}
		}

		$data                    = $response->get_data();
		$data['optisnap_images'] = $images;
		$data['jluxe_images']    = $images; // نام قدیمی - برای سازگاری با اپ JLuxe Admin
		$response->set_data( $data );

		return $response;
	}

	/* =====================================================================
	 * REST: payload تکی برای فرانت‌اند سفارشی
	 * =================================================================== */

	public function register_routes() {
		// GET /wp-json/optisnap/v1/image/123?size=large (یا مسیر قدیمی /wp-json/jluxe-image/v1/image/123)
		foreach ( array( 'optisnap/v1', 'jluxe-image/v1' ) as $ns ) {
			register_rest_route( $ns, '/image/(?P<id>\d+)', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_image' ),
				'permission_callback' => '__return_true', // تصاویر محصول عمومی هستند
				'args'                => array(
					'id'   => array( 'validate_callback' => 'is_numeric' ),
					'size' => array( 'default' => 'full' ),
				),
			) );
		}
	}

	public function rest_image( $request ) {
		$id   = absint( $request['id'] );
		$size = sanitize_key( $request['size'] );

		// فقط تصاویر منتشرشده کتابخانه رسانه
		$att = get_post( $id );
		if ( ! $att || 'attachment' !== $att->post_type || 'inherit' !== $att->post_status ) {
			return new WP_Error( 'osn_not_found', 'تصویر یافت نشد', array( 'status' => 404 ) );
		}

		$payload = self::payload( $id, $size ? $size : 'full' );
		if ( ! $payload ) {
			return new WP_Error( 'osn_not_image', 'این Attachment تصویر نیست', array( 'status' => 400 ) );
		}

		return rest_ensure_response( $payload );
	}
}
