<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * صفحه تنظیمات: Settings → OptiSnap
 */
class OSN_Settings {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function add_menu() {
		add_options_page(
			'OptiSnap',
			'OptiSnap',
			'manage_options',
			'osn-image-settings',
			array( $this, 'render_page' )
		);
	}

	public function enqueue( $hook ) {
		if ( 'settings_page_osn-image-settings' !== $hook ) {
			return;
		}
		wp_enqueue_media(); // برای انتخاب لوگو از کتابخانه رسانه
	}

	public function register_settings() {
		register_setting( 'osn_img_group', OSN_IMG_OPTION, array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize' ),
		) );
	}

	/**
	 * پاک‌سازی ورودی‌ها
	 */
	public function sanitize( $input ) {
		$defaults = osn_img_default_settings();
		// شروع از تنظیمات فعلی: ذخیره یک فرم، تنظیمات فرم‌های دیگر را پاک نمی‌کند
		$saved_before = osn_img_get_settings();
		$clean        = $saved_before;
		$form  = isset( $input['_form'] ) ? sanitize_key( $input['_form'] ) : 'main';

		// چک‌باکس‌ها فقط در فرم خودشان «خاموش» تفسیر می‌شوند (نبودن در POST یعنی تیک برداشته شده)
		$checkbox_groups = array(
			'main'      => array( 'webp_enabled', 'square_crop', 'keep_original', 'debug_log', 'auto_cron', 'avif_enabled', 'responsive_sizes', 'wm_on_featured', 'wm_on_gallery', 'wm_on_other' ),
			'watermark' => array( 'wm_on_featured', 'wm_on_gallery', 'wm_on_other' ),
		);
		if ( isset( $checkbox_groups[ $form ] ) ) {
			foreach ( $checkbox_groups[ $form ] as $key ) {
				$clean[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
			}
		}

		if ( isset( $input['opt_mode'] ) ) {
			$clean['opt_mode'] = ( 'smart' === $input['opt_mode'] ) ? 'smart' : 'manual';
		}
		if ( isset( $input['opt_policy'] ) ) {
			$policies = array( 'upload_only', 'heavy_only', 'all', 'products_only', 'manual' );
			$clean['opt_policy'] = in_array( $input['opt_policy'], $policies, true ) ? $input['opt_policy'] : 'all';
		}

		$num_fields = array(
			// key => [min, max]
			'quality'             => array( 10, 200 ),
			'quality_featured'    => array( 10, 200 ),
			'quality_gallery'     => array( 10, 200 ),
			'max_size'            => array( 100, 4000 ),
			'max_size_featured'   => array( 100, 4000 ),
			'max_size_gallery'    => array( 100, 4000 ),
			'wm_logo_id'          => array( 0, PHP_INT_MAX ),
			'wm_opacity'          => array( 0, 100 ),
			'wm_size'             => array( 3, 60 ),
			'wm_min_width'        => array( 0, 2000 ),
			'wm_padding'          => array( 0, 200 ),
			'wm_logo_max_px'      => array( 40, 600 ),
			'wm_opacity_featured' => array( 0, 100 ),
			'wm_opacity_gallery'  => array( 0, 100 ),
			'wm_opacity_other'    => array( 0, 100 ),
			'wm_size_featured'    => array( 10, 50 ),
			'wm_size_gallery'     => array( 10, 50 ),
			'wm_size_other'       => array( 10, 50 ),
			'heavy_kb'            => array( 50, 10000 ),
			'size_guard_tolerance' => array( 0, 50 ),
			'cron_batch'          => array( 1, 20 ),
		);
		foreach ( $num_fields as $key => $range ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = max( $range[0], min( $range[1], absint( $input[ $key ] ) ) );
			}
		}

		// auto_cron فقط چک‌باکس گروه main است اما در گروه بالا هندل شد

		// دسته‌های مستثنا از واترمارک
		if ( isset( $input['wm_excluded_cats'] ) ) {
			$clean['wm_excluded_cats'] = array_values( array_filter( array_map( 'absint', (array) $input['wm_excluded_cats'] ) ) );
		} elseif ( in_array( $form, array( 'main', 'watermark' ), true ) ) {
			$clean['wm_excluded_cats'] = array(); // عدم انتخاب یعنی خالی (هر دو فرم این فیلد را دارند)
		}

		$positions = array( 'bottom-right', 'bottom-left', 'top-right', 'top-left', 'center' );
		if ( isset( $input['wm_position'] ) && in_array( $input['wm_position'], $positions, true ) ) {
			$clean['wm_position'] = $input['wm_position'];
		}

		unset( $clean['_form'] );

		// v3.9.2: اگر تنظیمات مؤثر در پردازش تغییر کرده باشد، وضعیت «skipped»
		// (از قبل بهینه) تصاویر پاک می‌شود تا در اجرای بعدی Batch/Cron دوباره
		// با تنظیمات جدید ارزیابی شوند (قبلاً برای همیشه رد می‌ماندند).
		$watch = array(
			'webp_enabled', 'opt_mode', 'opt_policy', 'avif_enabled', 'quality',
			'quality_featured', 'quality_gallery', 'max_size', 'max_size_featured',
			'max_size_gallery', 'size_guard_tolerance', 'heavy_kb',
		);
		$changed = false;
		foreach ( $watch as $key ) {
			if ( isset( $clean[ $key ] ) && ( ! isset( $saved_before[ $key ] ) || (string) $clean[ $key ] !== (string) $saved_before[ $key ] ) ) {
				$changed = true;
				break;
			}
		}
		if ( $changed ) {
			self::reset_skipped_flags();
		}

		return $clean;
	}

	/**
	 * پاک‌کردن وضعیت «skipped» همه تصاویر (فقط متا؛ فایل‌ها دست‌نخورده‌اند)
	 */
	private static function reset_skipped_flags() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = 'skipped'",
			OSN_IMG_META_FLAG
		) );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
			'_osn_skip_reason'
		) );
		OSN_Logger::log( 'تنظیمات پردازش تغییر کرد؛ وضعیت «skipped» تصاویر برای ارزیابی مجدد پاک شد.' );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی غیرمجاز' );
		}

		$s        = osn_img_get_settings();
		$logo_url = $s['wm_logo_id'] ? wp_get_attachment_image_url( $s['wm_logo_id'], 'medium' ) : '';
		$webp_ok  = OSN_Image_Processor::server_supports_webp();
		?>
		<div class="wrap" style="direction:rtl; text-align:right;">
			<h1>⚙️ تنظیمات OptiSnap</h1>

			<?php if ( ! $webp_ok ) : ?>
				<div class="notice notice-error"><p><strong>هشدار:</strong> سرور شما از تولید WebP پشتیبانی نمی‌کند. با هاست خود تماس بگیرید تا افزونه GD با پشتیبانی WebP فعال شود.</p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'osn_img_group' ); // شامل nonce ?>
				<input type="hidden" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[_form]" value="main">

				<h2>۱) تبدیل و فشرده‌سازی</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">حالت بهینه‌سازی</th>
						<td>
							<label style="margin-left:16px;"><input type="radio" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[opt_mode]" value="manual" <?php checked( $s['opt_mode'], 'manual' ); ?>> <strong>Manual Quality</strong> - کیفیت‌های دستی زیر استفاده شود</label>
							<label><input type="radio" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[opt_mode]" value="smart" <?php checked( $s['opt_mode'], 'smart' ); ?>> <strong>Smart Mode 🧠</strong> - انتخاب خودکار بهترین کیفیت</label>
							<p class="description">حالت هوشمند بر اساس ابعاد تصویر، میزان جزئیات (تحلیل پیکسلی)، نقش تصویر در محصول و حجم اولیه، بهترین کیفیت (بین 60 تا 92) را برای هر تصویر جداگانه انتخاب می‌کند.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Optimization Policy - سیاست پردازش</th>
						<td>
							<?php
							$policies = array(
								'upload_only'   => 'فقط تصاویر جدید هنگام آپلود',
								'heavy_only'    => 'فقط تصاویر حجیم (بالاتر از آستانه «تصویر حجیم»)',
								'all'           => 'همه تصاویر (آپلود + پس‌زمینه)',
								'products_only' => 'فقط تصاویر محصولات',
								'manual'        => 'دستی - هیچ پردازش خودکاری انجام نشود',
							);
							$cur_policy = isset( $s['opt_policy'] ) ? $s['opt_policy'] : 'all';
							foreach ( $policies as $pv => $pl ) : ?>
								<label style="display:block; margin-bottom:4px;">
									<input type="radio" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[opt_policy]" value="<?php echo esc_attr( $pv ); ?>" <?php checked( $cur_policy, $pv ); ?>> <?php echo esc_html( $pl ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description">این سیاست روی آپلود خودکار و Cron پس‌زمینه اثر می‌گذارد؛ دکمه‌های دستی Media Manager همیشه کار می‌کنند.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">تولید AVIF در کنار WebP</th>
						<td>
							<?php $avif_ok = function_exists( 'imageavif' ); ?>
							<label><input type="checkbox" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[avif_enabled]" value="1" <?php checked( $s['avif_enabled'], 1 ); ?> <?php disabled( ! $avif_ok ); ?>> برای هر تصویر یک نسخه AVIF هم ساخته شود (حجم کمتر از WebP)</label>
							<?php if ( ! $avif_ok ) : ?>
								<p class="description" style="color:#d63638;">⚠️ سرور شما از AVIF پشتیبانی نمی‌کند (نیاز: PHP 8.1+ با GD/libavif). از هاست بخواهید فعال کند.</p>
							<?php else : ?>
								<p class="description">فایل AVIF کنار WebP ذخیره و حجم آن در گزارش‌ها نمایش داده می‌شود.</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">سایزهای ریسپانسیو (srcset)</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[responsive_sizes]" value="1" <?php checked( $s['responsive_sizes'], 1 ); ?>> ساخت نسخه‌های 480 (موبایل)، 768 (تبلت) و 1200 (دسکتاپ)</label>
							<p class="description">وردپرس خودکار این سایزها را در srcset استفاده می‌کند تا هر دستگاه سبک‌ترین نسخه را دریافت کند. (نسخه 1200 فقط وقتی ساخته می‌شود که «حداکثر اندازه» بزرگتر از 1200 باشد.)</p>
						</td>
					</tr>
					<tr>
						<th scope="row">فعال‌سازی تبدیل WebP</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[webp_enabled]" value="1" <?php checked( $s['webp_enabled'], 1 ); ?>> تصاویر آپلودی به WebP تبدیل شوند</label></td>
					</tr>
					<tr>
						<th scope="row">کیفیت WebP (پیش‌فرض)</th>
						<td>
							<input type="number" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[quality]" value="<?php echo esc_attr( $s['quality'] ); ?>" min="10" max="200" class="small-text">
							<p class="description">10 تا 200. مقادیر <strong>101 تا 200 = حالت Lossless</strong> (بدون هیچ افت کیفیت، حجم بیشتر). پیش‌فرض: 80</p>
						</td>
					</tr>
					<tr>
						<th scope="row">کیفیت تصویر اصلی محصول ⭐</th>
						<td>
							<input type="number" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[quality_featured]" value="<?php echo esc_attr( $s['quality_featured'] ); ?>" min="10" max="200" class="small-text">
							<p class="description">تصویر Featured محصولات با بهینه‌سازی ملایم و کیفیت بالاتر. پیش‌فرض: 88</p>
						</td>
					</tr>
					<tr>
						<th scope="row">کیفیت تصاویر گالری محصول</th>
						<td>
							<input type="number" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[quality_gallery]" value="<?php echo esc_attr( $s['quality_gallery'] ); ?>" min="10" max="200" class="small-text">
							<p class="description">تصاویر گالری فشرده‌سازی بیشتری می‌شوند. پیش‌فرض: 75<br>نکته: نقش تصویر (اصلی/گالری) هنگام پردازش از «OptiSnap» تشخیص داده می‌شود؛ آپلودهای جدید (که هنوز به محصولی وصل نیستند) با کیفیت پیش‌فرض پردازش می‌شوند.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">حداکثر ابعاد تصویر اصلی محصول ⭐</th>
						<td>
							<input type="number" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[max_size_featured]" value="<?php echo esc_attr( isset( $s['max_size_featured'] ) ? $s['max_size_featured'] : 1200 ); ?>" min="100" max="4000" class="small-text"> px
							<span class="description">پیش‌فرض: 1200 → تصویر اصلی می‌تواند 1200×1200 باشد (کیفیت بالاتر + نسخه srcset دسکتاپ)</span>
						</td>
					</tr>
					<tr>
						<th scope="row">حداکثر ابعاد تصاویر گالری 🖼</th>
						<td>
							<input type="number" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[max_size_gallery]" value="<?php echo esc_attr( isset( $s['max_size_gallery'] ) ? $s['max_size_gallery'] : 1000 ); ?>" min="100" max="4000" class="small-text"> px
							<span class="description">پیش‌فرض: 1000 → تصاویر گالری از 1000×1000 بزرگتر نمی‌شوند</span>
						</td>
					</tr>
					<tr>
						<th scope="row">حداکثر ابعاد سایر تصاویر</th>
						<td>
							<input type="number" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[max_size]" value="<?php echo esc_attr( $s['max_size'] ); ?>" min="100" max="4000" class="small-text"> px
							<span class="description">پیش‌فرض: 1000. (هنگام آپلود که نقش تصویر هنوز معلوم نیست، همین مقدار مبناست)</span>
						</td>
					</tr>
					<tr>
						<th scope="row">کراپ مربعی 1:1</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[square_crop]" value="1" <?php checked( $s['square_crop'], 1 ); ?>> همه تصاویر با برش هوشمند از مرکز، مربع شوند</label></td>
					</tr>
					<tr>
						<th scope="row">نگه‌داشتن فایل اصلی (Backup)</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[keep_original]" value="1" <?php checked( $s['keep_original'], 1 ); ?>> فایل JPG/PNG اصلی حذف نشود</label>
							<p class="description">توصیه: روشن بماند. مسیر فایل اصلی در متای تصویر ذخیره می‌شود تا امکان بازگردانی باشد.</p>
						</td>
					</tr>
				</table>

				<h2 id="osn-wm-section">۲) واترمارک لوگو (همه تنظیمات یکجا - v3.6)</h2>
				<p style="margin:4px 0;"><strong style="color:#d63638;">❌ واترمارک خودکار حذف شده است</strong> - واترمارک فقط با انتخاب و تایید صریح شما از منوی <strong>OptiSnap Watermark</strong> اعمال می‌شود. برای تصاویری که از قبل واترمارک دارند، در همان منو دکمه «از قبل واترمارک دارد» را بزنید.</p>

				<div style="display:flex; gap:20px; flex-wrap:wrap; align-items:flex-start;">
				<div style="flex:1; min-width:520px;">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">لوگوی واترمارک</th>
						<td>
							<input type="hidden" id="osn_wm_logo_id" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[wm_logo_id]" value="<?php echo esc_attr( $s['wm_logo_id'] ); ?>">
							<div id="osn_logo_preview" style="margin-bottom:8px;">
								<?php if ( $logo_url ) : ?>
									<img src="<?php echo esc_url( $logo_url ); ?>" style="max-width:150px; background:#eee; padding:8px; border-radius:6px;">
								<?php endif; ?>
							</div>
							<button type="button" class="button" id="osn_select_logo">انتخاب / آپلود لوگو</button>
							<button type="button" class="button" id="osn_remove_logo" <?php echo $s['wm_logo_id'] ? '' : 'style="display:none"'; ?>>حذف لوگو</button>
							<p class="description">فرمت PNG یا WebP با پس‌زمینه شفاف</p>
						</td>
					</tr>
					<tr>
						<th scope="row">موقعیت</th>
						<td>
							<select name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[wm_position]" class="osn-live" data-live="position">
								<option value="bottom-right" <?php selected( $s['wm_position'], 'bottom-right' ); ?>>پایین راست (پیش‌فرض)</option>
								<option value="bottom-left" <?php selected( $s['wm_position'], 'bottom-left' ); ?>>پایین چپ</option>
								<option value="top-right" <?php selected( $s['wm_position'], 'top-right' ); ?>>بالا راست</option>
								<option value="top-left" <?php selected( $s['wm_position'], 'top-left' ); ?>>بالا چپ</option>
								<option value="center" <?php selected( $s['wm_position'], 'center' ); ?>>وسط تصویر</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">Opacity - میزان شفافیت (%)</th>
						<td>
							<input type="number" class="osn-live" data-live="opacity" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[wm_opacity]" value="<?php echo esc_attr( $s['wm_opacity'] ); ?>" min="0" max="100" style="width:70px;"> <span class="description">پیش‌فرض: 40 → عدد بیشتر = محوتر</span>
						</td>
					</tr>
					<tr>
						<th scope="row">Size - اندازه (% از عرض تصویر)</th>
						<td>
							<input type="number" class="osn-live" data-live="size" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[wm_size]" value="<?php echo esc_attr( $s['wm_size'] ); ?>" min="3" max="60" style="width:70px;"> <span class="description">پیش‌فرض: 22</span>
						</td>
					</tr>
					<tr>
						<th scope="row">Padding - فاصله از لبه (px)</th>
						<td>
							<input type="number" class="osn-live" data-live="padding" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[wm_padding]" value="<?php echo esc_attr( isset( $s['wm_padding'] ) ? $s['wm_padding'] : 20 ); ?>" min="0" max="200" style="width:70px;"> px
						</td>
					</tr>
					<tr>
						<th scope="row">Smart Logo Scaling - سقف عرض لوگو (px)</th>
						<td>
							<input type="number" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[wm_logo_max_px]" value="<?php echo esc_attr( isset( $s['wm_logo_max_px'] ) ? $s['wm_logo_max_px'] : 250 ); ?>" min="40" max="600" style="width:70px;"> px
							<p class="description">لوگو با ابعاد تصویر مقیاس می‌شود: تصویر 300px حداکثر ~80px لوگو، تصویر 2000px حداکثر این مقدار.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">حداقل عرض برای واترمارک (px)</th>
						<td>
							<input type="number" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[wm_min_width]" value="<?php echo esc_attr( $s['wm_min_width'] ); ?>" min="0" max="2000" style="width:70px;"> <span class="description">تصاویر کوچکتر (آیکون‌ها) واترمارک نمی‌خورند. پیش‌فرض: 400</span>
						</td>
					</tr>
					<tr>
						<th scope="row">قوانین بر اساس نوع تصویر</th>
						<td>
							<table class="widefat" style="max-width:520px;">
								<thead><tr><th>نوع تصویر</th><th>فعال</th><th>Opacity %</th><th>Size %</th></tr></thead>
								<tbody>
									<?php
									$wm_roles = array(
										'featured' => '⭐ تصویر اصلی محصول',
										'gallery'  => '🖼 گالری محصول',
										'other'    => '📄 سایر تصاویر',
									);
									foreach ( $wm_roles as $rk => $rl ) : ?>
									<tr>
										<td><?php echo esc_html( $rl ); ?></td>
										<td><input type="checkbox" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[wm_on_<?php echo esc_attr( $rk ); ?>]" value="1" <?php checked( isset( $s[ 'wm_on_' . $rk ] ) ? $s[ 'wm_on_' . $rk ] : 1, 1 ); ?>></td>
										<td><input type="number" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[wm_opacity_<?php echo esc_attr( $rk ); ?>]" value="<?php echo esc_attr( isset( $s[ 'wm_opacity_' . $rk ] ) ? $s[ 'wm_opacity_' . $rk ] : $s['wm_opacity'] ); ?>" min="0" max="100" style="width:60px;"></td>
										<td><input type="number" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[wm_size_<?php echo esc_attr( $rk ); ?>]" value="<?php echo esc_attr( isset( $s[ 'wm_size_' . $rk ] ) ? $s[ 'wm_size_' . $rk ] : $s['wm_size'] ); ?>" min="10" max="50" style="width:60px;"></td>
									</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
							<p class="description">مثال: تصویر اصلی Opacity 25 (ظریف‌تر)، گالری 40.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">استثناء دسته‌ها از واترمارک</th>
						<td>
							<?php
							$cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
							$ex   = isset( $s['wm_excluded_cats'] ) ? array_map( 'absint', (array) $s['wm_excluded_cats'] ) : array();
							?>
							<select name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[wm_excluded_cats][]" multiple size="5" style="min-width:260px;">
								<?php if ( ! is_wp_error( $cats ) ) : foreach ( $cats as $cat ) : ?>
									<option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( in_array( (int) $cat->term_id, $ex, true ) ); ?>><?php echo esc_html( $cat->name ); ?></option>
								<?php endforeach; endif; ?>
							</select>
							<p class="description">تصاویر محصولات این دسته‌ها هرگز واترمارک نمی‌شوند. استثناء تک‌محصول: چک‌باکس در صفحه ویرایش محصول. استثناء تک‌تصویر: دکمه «استثناء» در Media Manager.</p>
						</td>
					</tr>
				</table>
				</div>

				<!-- Preview زنده -->
				<div style="width:340px;">
					<div style="background:#fff; border:1px solid #dcdcde; border-radius:10px; padding:14px; position:sticky; top:48px;">
						<h3 style="margin-top:0;">👁 Preview زنده واترمارک</h3>
						<?php
						$sample = get_posts( array(
							'post_type'      => 'attachment',
							'post_status'    => 'inherit',
							'post_mime_type' => array( 'image/jpeg', 'image/webp', 'image/png' ),
							'posts_per_page' => 1,
							'orderby'        => 'date',
							'order'          => 'DESC',
							'fields'         => 'ids',
							'post__not_in'   => array( absint( $s['wm_logo_id'] ) ),
						) );
						$sample_url = $sample ? wp_get_attachment_image_url( $sample[0], 'medium' ) : '';
						?>
						<div id="osn-pv-box" style="position:relative; width:100%; aspect-ratio:1/1; background:#f0f0f1; border-radius:8px; overflow:hidden;">
							<img src="<?php echo esc_url( $sample_url ); ?>" style="width:100%;height:100%;object-fit:cover;" id="osn-pv-base">
							<img src="<?php echo esc_url( $logo_url ); ?>" id="osn-pv-logo" style="position:absolute; <?php echo $logo_url ? '' : 'display:none;'; ?>">
						</div>
						<p class="description" style="margin-top:8px;">با تغییر موقعیت/شفافیت/اندازه/Padding لحظه‌ای به‌روز می‌شود. <button type="button" class="button button-small" id="osn-pv-pick">انتخاب تصویر نمونه</button></p>
					</div>
				</div>
				</div>

				<h2>۳) اسکن و پردازش خودکار</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">آستانه «تصویر حجیم» (KB)</th>
						<td>
							<input type="number" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[heavy_kb]" value="<?php echo esc_attr( $s['heavy_kb'] ); ?>" min="50" max="10000" class="small-text"> <span class="description">تصاویر سنگین‌تر از این مقدار در اسکن به‌عنوان «حجیم» شناسایی می‌شوند. پیش‌فرض: 500</span>
						</td>
					</tr>
					<tr>
						<th scope="row">Size Guard - تحمل افزایش حجم (%)</th>
						<td>
							<input type="number" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[size_guard_tolerance]" value="<?php echo esc_attr( isset( $s['size_guard_tolerance'] ) ? $s['size_guard_tolerance'] : 0 ); ?>" min="0" max="50" class="small-text"> %
							<p class="description">اگر خروجی WebP از فایل اصلی بزرگتر شود، پردازش لغو و فایل اصلی (JPG/PNG) دست‌نخورده باقی می‌ماند - این تصویر با نشان «⏭ Skipped» مشخص می‌شود. پیش‌فرض: 0 (خروجی باید حتماً کوچکتر باشد). اگر می‌خواهید حتی با کمی افزایش حجم هم به WebP تبدیل شود (برای یکدست‌بودن فرمت سایت)، درصد تحمل را افزایش دهید.</span>
						</td>
					</tr>
					<tr>
						<th scope="row">پردازش خودکار پس‌زمینه</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[auto_cron]" value="1" <?php checked( $s['auto_cron'], 1 ); ?>> هر ۵ دقیقه تعدادی از تصاویر قدیمی خودکار پردازش شوند (WP-Cron)</label>
							<p class="description">مناسب وقتی تعداد تصاویر زیاد است و نمی‌خواهید صفحه ابزار را باز نگه دارید. فشار به هاست حداقلی است.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">تعداد در هر اجرای خودکار</th>
						<td>
							<input type="number" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[cron_batch]" value="<?php echo esc_attr( $s['cron_batch'] ); ?>" min="1" max="20" class="small-text"> <span class="description">پیش‌فرض: 5 تصویر در هر ۵ دقیقه ≈ ۱۴۰۰ تصویر در روز</span>
						</td>
					</tr>
				</table>

				<h2>۴) عیب‌یابی</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">لاگ رویدادها (Debug)</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( OSN_IMG_OPTION ); ?>[debug_log]" value="1" <?php checked( $s['debug_log'], 1 ); ?>> ثبت جزئیات پردازش در <code>wp-content/debug.log</code></label>
							<p class="description">خطاهای مهم همیشه ثبت می‌شوند؛ این گزینه لاگ کامل موفقیت‌ها را هم فعال می‌کند.</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'ذخیره تنظیمات' ); ?>
			</form>

			<script>
			jQuery(function($){
				// --- Preview زنده واترمارک (v3.6) ---
				var pvState = {
					position: '<?php echo esc_js( $s['wm_position'] ); ?>',
					opacity:  <?php echo (int) $s['wm_opacity']; ?>,
					size:     <?php echo (int) $s['wm_size']; ?>,
					padding:  <?php echo (int) ( isset( $s['wm_padding'] ) ? $s['wm_padding'] : 20 ); ?>
				};
				function renderPv(){
					var $logo = $('#osn-pv-logo');
					if (!$logo.attr('src')) return;
					var boxW = $('#osn-pv-box').width() || 300;
					var pad = Math.max(1, Math.round(pvState.padding * boxW / 1000));
					var css = {top:'', right:'', bottom:'', left:'', transform:'',
						width: pvState.size + '%', opacity: (100 - pvState.opacity) / 100, display:'block'};
					switch(pvState.position){
						case 'top-right':   css.top = pad+'px'; css.right = pad+'px'; break;
						case 'top-left':    css.top = pad+'px'; css.left = pad+'px'; break;
						case 'bottom-left': css.bottom = pad+'px'; css.left = pad+'px'; break;
						case 'center':      css.top = '50%'; css.left = '50%'; css.transform = 'translate(-50%,-50%)'; break;
						default:            css.bottom = pad+'px'; css.right = pad+'px';
					}
					$logo.attr('style', 'position:absolute;').css(css);
				}
				$(document).on('input change', '.osn-live', function(){
					var k = $(this).data('live'), v = $(this).val();
					pvState[k] = (k === 'position') ? v : parseInt(v, 10) || 0;
					renderPv();
				});
				renderPv();

				var frame2;
				$('#osn-pv-pick').on('click', function(){
					if (frame2) { frame2.open(); return; }
					frame2 = wp.media({ title:'انتخاب تصویر نمونه', library:{type:'image'}, multiple:false });
					frame2.on('select', function(){
						var att = frame2.state().get('selection').first().toJSON();
						var url = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
						$('#osn-pv-base').attr('src', url);
					});
					frame2.open();
				});

				var frame;
				$('#osn_select_logo').on('click', function(e){
					e.preventDefault();
					if (frame) { frame.open(); return; }
					frame = wp.media({
						title: 'انتخاب لوگوی واترمارک',
						button: { text: 'استفاده از این لوگو' },
						library: { type: 'image' },
						multiple: false
					});
					frame.on('select', function(){
						var att = frame.state().get('selection').first().toJSON();
						$('#osn_wm_logo_id').val(att.id);
						var url = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
						$('#osn_logo_preview').html('<img src="'+url+'" style="max-width:150px;background:#eee;padding:8px;border-radius:6px;">');
						$('#osn-pv-logo').attr('src', url);
						renderPv();
						$('#osn_remove_logo').show();
					});
					frame.open();
				});
				$('#osn_remove_logo').on('click', function(){
					$('#osn_wm_logo_id').val('0');
					$('#osn_logo_preview').empty();
					$(this).hide();
				});
			});
			</script>
		</div>
		<?php
	}
}
