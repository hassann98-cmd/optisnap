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
		add_action( 'wp_ajax_osn_product_apply_profile', array( $this, 'ajax_apply_profile' ) );
		add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_profile_field' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( $this, 'save_attachment_profile' ), 10, 2 );
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
				'profileTitle' => __( 'پروفایل این تصویر', 'optisnap' ),
				'profileApply' => __( 'ذخیره و اجرا', 'optisnap' ),
				'profileCancel' => __( 'انصراف', 'optisnap' ),
				'profileSaved' => __( 'پروفایل ذخیره شد.', 'optisnap' ),
				'profileAlready' => __( 'این تصویر با همین پروفایل قبلاً پردازش شده است.', 'optisnap' ),
				'profileNeedsRestore' => __( 'این تصویر قبلاً پردازش شده است. برای اعمال پروفایل تازه، ابتدا از OptiSnap آن را Restore کن؛ سپس دوباره بهینه‌سازی را اجرا کن.', 'optisnap' ),
				'profileFailed' => __( 'اجرای پروفایل تصویر ناموفق بود.', 'optisnap' ),
			),
			'profiles' => self::profile_labels(),
			'profileDescriptions' => array(
				'default' => __( 'از کیفیت و ابعاد تنظیمات عمومی OptiSnap استفاده می‌کند.', 'optisnap' ),
				'high_quality' => __( 'کیفیت WebP بالاتر؛ حداکثر ابعاد بر اساس نقش تصویر باقی می‌ماند.', 'optisnap' ),
				'compact' => __( 'حجم کمتر؛ کیفیت 74 و حداکثر ضلع 1280px.', 'optisnap' ),
				'banner_desktop' => __( 'کیفیت 86، حداکثر ضلع 1920px؛ نسبت تصویر حفظ می‌شود.', 'optisnap' ),
				'banner_mobile' => __( 'کیفیت 84، حداکثر ضلع 960px؛ نسبت تصویر حفظ می‌شود.', 'optisnap' ),
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
				'profile'     => get_post_meta( $id, OSN_IMG_META_PROFILE, true ) ?: 'default',
				'percent'     => ( is_array( $report ) && isset( $report['percent'] ) ) ? (int) $report['percent'] : null,
				'quality'     => ( is_array( $report ) && ! empty( $report['quality'] ) ) ? $report['quality'] : null,
				'brightness'  => $brightness,
			);
		}
		wp_send_json_success( $out );
	}

	/** پروفایل‌های عمومی OptiSnap — بدون وابستگی به پوسته یا صفحه‌ساز */
	private static function profile_labels() {
		return array(
			'default'        => __( 'تنظیمات عمومی سایت', 'optisnap' ),
			'high_quality'   => __( 'کیفیت بالاتر', 'optisnap' ),
			'compact'        => __( 'حجم کمتر', 'optisnap' ),
			'banner_desktop' => __( 'بنر دسکتاپ — حداکثر 1920px، بدون کراپ', 'optisnap' ),
			'banner_mobile'  => __( 'بنر موبایل — حداکثر 960px، بدون کراپ', 'optisnap' ),
		);
	}

	/** فیلد پروفایل برای هر تصویر در Media Library؛ مستقل از Product Editor */
	public function attachment_profile_field( $fields, $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) || ! wp_attachment_is_image( $post->ID ) ) {
			return $fields;
		}
		$current = get_post_meta( $post->ID, OSN_IMG_META_PROFILE, true );
		$current = $current ? $current : 'default';
		$options = self::profile_labels();
		$html    = '<select name="attachments[' . absint( $post->ID ) . '][osn_profile]">';
		foreach ( $options as $value => $label ) {
			$html .= '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$html .= '</select>';
		$fields['osn_profile'] = array(
			'label' => __( 'پروفایل OptiSnap', 'optisnap' ),
			'input' => 'html',
			'html'  => $html,
			'helps' => __( 'این تنظیم فقط متعلق به OptiSnap است. بنرها نسبت تصویرشان را حفظ می‌کنند؛ برای تغییر تصویر بهینه‌شده ابتدا Restore کنید.', 'optisnap' ),
		);
		return $fields;
	}

	/** ذخیره امن فیلد پروفایل در فرم استاندارد Attachment وردپرس */
	public function save_attachment_profile( $post, $attachment ) {
		$id = isset( $post['ID'] ) ? absint( $post['ID'] ) : 0;
		if ( ! $id || ! current_user_can( 'edit_post', $id ) || ! isset( $attachment['osn_profile'] ) ) {
			return $post;
		}
		$raw_profile = is_scalar( $attachment['osn_profile'] ) ? (string) wp_unslash( $attachment['osn_profile'] ) : '';
		$profile = sanitize_key( $raw_profile );
		if ( ! array_key_exists( $profile, self::profile_labels() ) ) {
			return $post;
		}
		if ( 'default' === $profile ) {
			delete_post_meta( $id, OSN_IMG_META_PROFILE );
		} else {
			update_post_meta( $id, OSN_IMG_META_PROFILE, $profile );
		}
		return $post;
	}

	/** ذخیره/اجرای یک پروفایل از صفحه ویرایش محصول؛ processing تصاویر موجود اجباری نیست */
	public function ajax_apply_profile() {
		check_ajax_referer( 'osn_batch_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'دسترسی غیرمجاز', 403 );
		}
		$id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$raw_profile = isset( $_POST['profile'] ) && is_scalar( $_POST['profile'] ) ? (string) wp_unslash( $_POST['profile'] ) : 'default';
		$profile = sanitize_key( $raw_profile );
		if ( ! $id || 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) || ! array_key_exists( $profile, self::profile_labels() ) ) {
			wp_send_json_error( 'شناسه تصویر یا پروفایل نامعتبر است.', 400 );
		}

		$previous = get_post_meta( $id, OSN_IMG_META_PROFILE, true );
		$flag     = get_post_meta( $id, OSN_IMG_META_FLAG, true );
		$file     = get_attached_file( $id );
		$processed = ( '1' === (string) $flag ) || ( $file && OSN_Image_Processor::is_already_processed_file( $file ) );
		$previous  = $previous ? $previous : 'default';

		if ( $processed && $previous === $profile ) {
			wp_send_json_success( array(
				'status'  => 'already',
				'profile' => $profile,
				'url'     => wp_get_attachment_image_url( $id, 'medium' ),
			) );
		}

		if ( 'default' === $profile ) {
			delete_post_meta( $id, OSN_IMG_META_PROFILE );
		} else {
			update_post_meta( $id, OSN_IMG_META_PROFILE, $profile );
		}

		// فایل بهینه‌شده را بی‌اجازه overwrite نمی‌کنیم؛ Restore باید از مسیر استاندارد انجام شود.
		if ( $processed ) {
			wp_send_json_success( array(
				'status'          => 'profile_saved',
				'profile'         => $profile,
				'requires_restore'=> true,
				'url'             => wp_get_attachment_image_url( $id, 'medium' ),
			) );
		}

		$result = OSN_Image_Processor::process_attachment( $id, true );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		$report = get_post_meta( $id, OSN_IMG_META_REPORT, true );
		wp_send_json_success( array(
			'status'  => $result,
			'profile' => $profile,
			'url'     => wp_get_attachment_image_url( $id, 'medium' ),
			'report'  => is_array( $report ) ? $report : array(),
		) );
	}

}
