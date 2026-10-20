<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OptiSnap Watermark Manager (v3.1)
 *
 * منوی مستقل «OptiSnap Watermark»:
 *  - جدول کامل تصاویر (مثل Media Manager) با وضعیت واترمارک
 *  - Bulk Watermark با صف پایدار در دیتابیس (Pause/Resume/Stop)
 *  - Preview دوپنجره (اصل / واترمارک‌دار) قبل از تایید
 *  - Restore Original برای تصاویر واترمارک‌خورده
 *  - صفحه تنظیمات مستقل با Preview زنده
 *  - استثناء محصول (چک‌باکس در صفحه محصول) و استثناء دسته
 */
class OSN_Watermark_Manager {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 11 ); // بعد از ثبت منوی والد OSN_Manager_Page
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_osn_wm_restore', array( $this, 'ajax_restore' ) );
		add_action( 'wp_ajax_osn_wm_mark', array( $this, 'ajax_mark_preexisting' ) );

		// استثناء محصول: چک‌باکس در متاباکس محصول + ذخیره
		add_action( 'add_meta_boxes', array( $this, 'add_exclude_metabox' ) );
		add_action( 'save_post_product', array( $this, 'save_exclude_metabox' ) );
	}

	public function add_menu() {
		// v3.6.1: منوی مستقل حذف شد - «OptiSnap Watermark» حالا زیرمنوی «OptiSnap» است (منوی واحد)
		add_submenu_page(
			'osn-image-manager',
			'OptiSnap Watermark',
			'💧 OptiSnap Watermark',
			'manage_options',
			'osn-watermark',
			array( $this, 'render_manager' )
		);
	}

	public function enqueue( $hook ) {
		if ( false !== strpos( $hook, 'osn-watermark' ) ) {
			wp_enqueue_media();
		}
	}

	/* =====================================================================
	 * AJAX: Restore Original (بازگردانی نسخه قبل از واترمارک)
	 * =================================================================== */

	public function ajax_restore() {
		check_ajax_referer( 'osn_batch_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'دسترسی غیرمجاز', 403 );
		}
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$result = OSN_Image_Processor::restore_watermark( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( array( 'url' => wp_get_attachment_image_url( $id, 'thumbnail' ) ) );
	}

	/**
	 * علامت‌گذاری «از قبل واترمارک دارد» (v3.5)
	 * برای تصاویری که خارج از افزونه واترمارک خورده‌اند:
	 * فقط متا ثبت می‌شود؛ فایل به هیچ وجه تغییر نمی‌کند.
	 */
	public function ajax_mark_preexisting() {
		check_ajax_referer( 'osn_batch_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'دسترسی غیرمجاز', 403 );
		}
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( 'شناسه نامعتبر' );
		}
		update_post_meta( $id, OSN_IMG_META_WATERMARKED, 'pre-existing' );
		update_post_meta( $id, '_osn_watermark_date', current_time( 'mysql' ) );
		update_post_meta( $id, '_osn_watermark_version', 'pre-existing' );
		OSN_Logger::db( $id, 'Mark Pre-Watermarked', 0, 0, 'SUCCESS', 'فایل تغییر نکرد' );
		wp_send_json_success();
	}

	/* =====================================================================
	 * استثناء محصول از واترمارک
	 * =================================================================== */

	public function add_exclude_metabox() {
		add_meta_box(
			'osn_wm_exclude',
			'💧 واترمارک OptiSnap',
			array( $this, 'render_exclude_metabox' ),
			'product',
			'side',
			'low'
		);
	}

	public function render_exclude_metabox( $post ) {
		wp_nonce_field( 'osn_wm_exclude_save', 'osn_wm_exclude_nonce' );
		$excluded = get_post_meta( $post->ID, '_osn_wm_exclude_product', true );
		echo '<label style="direction:rtl;"><input type="checkbox" name="osn_wm_exclude_product" value="1" ' . checked( $excluded, '1', false ) . '> تصاویر این محصول واترمارک <strong>نشوند</strong></label>';
	}

	public function save_exclude_metabox( $post_id ) {
		if ( ! isset( $_POST['osn_wm_exclude_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['osn_wm_exclude_nonce'] ), 'osn_wm_exclude_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! empty( $_POST['osn_wm_exclude_product'] ) ) {
			update_post_meta( $post_id, '_osn_wm_exclude_product', '1' );
		} else {
			delete_post_meta( $post_id, '_osn_wm_exclude_product' );
		}
	}

	/* =====================================================================
	 * صفحه اصلی: Watermark Manager
	 * =================================================================== */

	public function render_manager() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی غیرمجاز' );
		}

		$settings = osn_img_get_settings();
		$nonce    = wp_create_nonce( 'osn_batch_nonce' );

		$per_page = isset( $_GET['per_page'] ) && in_array( (int) $_GET['per_page'], array( 20, 50, 100 ), true ) ? (int) $_GET['per_page'] : 20;
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$filter   = isset( $_GET['wm'] ) ? sanitize_key( $_GET['wm'] ) : 'pending';
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp' ),
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => false,
		);
		if ( 'pending' === $filter ) {
			$args['meta_query'] = array(
				array( 'key' => OSN_IMG_META_WATERMARKED, 'compare' => 'NOT EXISTS' ),
				array( 'key' => OSN_IMG_META_EXCLUDED, 'compare' => 'NOT EXISTS' ),
			);
		} elseif ( 'done' === $filter ) {
			$args['meta_query'] = array(
				array( 'key' => OSN_IMG_META_WATERMARKED, 'compare' => 'EXISTS' ),
			);
		}
		if ( '' !== $search ) {
			if ( is_numeric( $search ) ) {
				$args['post__in'] = array( (int) $search );
			} else {
				$args['s'] = $search;
			}
		}
		$q = new WP_Query( $args );

		$logo_url  = $settings['wm_logo_id'] ? wp_get_attachment_image_url( $settings['wm_logo_id'], 'medium' ) : '';
		$logo_hash = OSN_Watermark::current_logo_hash( $settings ); // برای تشخیص واترمارک با لوگوی قدیمی (v3.2)

		// وضعیت صف نیمه‌کاره (مشترک با Media Manager)
		$pending_state = get_option( OSN_Manager_Page::BULK_STATE, array() );
		$has_pending   = ! empty( $pending_state['queue'] ) && 'watermark' === $pending_state['op'];

		// موقعیت‌های CSS برای Preview
		$pos_css = array(
			'bottom-right' => 'bottom:PADpx; right:PADpx;',
			'bottom-left'  => 'bottom:PADpx; left:PADpx;',
			'top-right'    => 'top:PADpx; right:PADpx;',
			'top-left'     => 'top:PADpx; left:PADpx;',
			'center'       => 'top:50%; left:50%; transform:translate(-50%,-50%);',
		);
		$pad = max( 2, (int) round( absint( $settings['wm_padding'] ) / 3 ) ); // مقیاس برای باکس کوچک Preview
		$pos = str_replace( 'PAD', (string) $pad, isset( $pos_css[ $settings['wm_position'] ] ) ? $pos_css[ $settings['wm_position'] ] : $pos_css['bottom-right'] );
		?>
		<div class="wrap" style="direction:rtl; text-align:right;">
			<h1>💧 OptiSnap Watermark Manager</h1>

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
				.osn-pane{position:relative;width:100%;aspect-ratio:1/1;background:#f0f0f1;border-radius:8px;overflow:hidden;}
				.osn-pane img.base{width:100%;height:100%;object-fit:cover;}
			</style>

			<?php if ( empty( $settings['wm_logo_id'] ) ) : ?>
				<div class="notice notice-error"><p>ابتدا لوگوی واترمارک را در <a href="<?php echo esc_url( admin_url( 'options-general.php?page=osn-image-settings#osn-wm-section' ) ); ?>">تنظیمات یکپارچه OptiSnap</a> انتخاب کنید.</p></div>
			<?php endif; ?>

			<?php if ( $has_pending ) : ?>
				<div class="notice notice-warning">
					<p>⏸ یک عملیات واترمارک نیمه‌کاره وجود دارد: <strong><?php echo esc_html( $pending_state['done'] . ' / ' . $pending_state['total'] ); ?></strong>
					<button class="button button-primary" id="osn-resume-db">▶️ ادامه از همان‌جا</button>
					<button class="button" id="osn-discard-db">✖ انصراف از صف</button></p>
				</div>
			<?php endif; ?>

			<form method="get" style="margin-bottom:10px;">
				<input type="hidden" name="page" value="osn-watermark">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="جستجوی نام یا شناسه">
				<select name="wm">
					<option value="pending" <?php selected( $filter, 'pending' ); ?>>⭕ بدون واترمارک</option>
					<option value="done" <?php selected( $filter, 'done' ); ?>>✅ واترمارک‌شده</option>
					<option value="all" <?php selected( $filter, 'all' ); ?>>همه</option>
				</select>
				<select name="per_page">
					<option value="20" <?php selected( $per_page, 20 ); ?>>۲۰</option>
					<option value="50" <?php selected( $per_page, 50 ); ?>>۵۰</option>
					<option value="100" <?php selected( $per_page, 100 ); ?>>۱۰۰</option>
				</select>
				<button class="button">اعمال</button>
				<a class="button" href="<?php echo esc_url( admin_url( 'options-general.php?page=osn-image-settings#osn-wm-section' ) ); ?>">⚙️ تنظیمات (یکپارچه)</a>
			</form>

			<div style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-start;">
				<div style="flex:1; min-width:560px;">
					<div style="margin:10px 0;">
						<button class="button button-primary" id="osn-wm-bulk" disabled <?php disabled( empty( $settings['wm_logo_id'] ) ); ?>>💧 Bulk Watermark (<span id="osn-wm-count">0</span>)</button>
						<button class="button" id="osn-pause-btn" style="display:none;">⏸ Pause</button>
						<button class="button" id="osn-resume-btn" style="display:none;">▶️ Resume</button>
						<button class="button" id="osn-stop-btn" style="display:none;">⏹ Stop</button>
					</div>

					<!-- پنل تایید با Preview -->
					<div id="osn-confirm-panel" style="display:none; background:#fff; border:2px solid #2271b1; border-radius:10px; padding:16px; margin-bottom:12px;">
						<h3 style="margin-top:0;">👁 Preview قبل از اجرا</h3>
						<div style="display:flex; gap:12px; max-width:560px;">
							<div style="flex:1;">
								<p style="margin:4px 0;"><strong>تصویر اصلی</strong></p>
								<div class="osn-pane"><img class="base" id="osn-cf-orig" src=""></div>
							</div>
							<div style="flex:1;">
								<p style="margin:4px 0;"><strong>بعد از واترمارک</strong></p>
								<div class="osn-pane">
									<img class="base" id="osn-cf-wm" src="">
									<?php if ( $logo_url ) : ?>
										<img id="osn-cf-logo" src="<?php echo esc_url( $logo_url ); ?>" style="position:absolute; <?php echo esc_attr( $pos ); ?> width:<?php echo esc_attr( $settings['wm_size'] ); ?>%; opacity:<?php echo esc_attr( ( 100 - $settings['wm_opacity'] ) / 100 ); ?>;">
									<?php endif; ?>
								</div>
							</div>
						</div>
						<p id="osn-cf-msg" style="font-size:14px; margin:12px 0;"></p>
						<p>
							<button class="button button-primary" id="osn-cf-yes">✅ تایید و اجرا</button>
							<button class="button" id="osn-cf-no">لغو</button>
						</p>
					</div>

					<div id="osn-wm-progress" style="display:none; margin-bottom:10px;">
						<div class="osn-bar"><div id="osn-wm-bar"></div></div>
						<p id="osn-wm-text" style="margin-top:4px;"></p>
					</div>

					<table class="widefat striped osn-tbl">
						<thead><tr>
							<th style="width:28px;"><input type="checkbox" id="osn-wm-all"></th>
							<th style="width:60px;">تصویر</th>
							<th>نام فایل</th>
							<th>حجم</th>
							<th>ابعاد</th>
							<th>نوع</th>
							<th>محصول مرتبط</th>
							<th>وضعیت واترمارک</th>
							<th style="width:150px;">عملیات</th>
						</tr></thead>
						<tbody>
						<?php if ( $q->have_posts() ) : ?>
							<?php foreach ( $q->posts as $post ) :
								$id       = $post->ID;
								$file     = get_attached_file( $id );
								$size     = ( $file && file_exists( $file ) ) ? size_format( filesize( $file ), 1 ) : '—';
								$meta     = wp_get_attachment_metadata( $id );
								$dims     = ( ! empty( $meta['width'] ) ) ? $meta['width'] . '×' . $meta['height'] : '—';
								$mime     = strtoupper( str_replace( 'image/', '', get_post_mime_type( $id ) ) );
								$wm       = get_post_meta( $id, OSN_IMG_META_WATERMARKED, true );
								$wm_date  = get_post_meta( $id, '_osn_watermark_date', true );
								$wm_bak   = get_post_meta( $id, '_osn_wm_original', true );
								$excluded = (bool) get_post_meta( $id, OSN_IMG_META_EXCLUDED, true );
								$prod_id  = OSN_Image_Processor::get_related_product_id( $id );
								$role     = OSN_Image_Processor::get_image_role( $id );
								$thumb    = wp_get_attachment_image_url( $id, 'medium' );
							?>
							<tr data-id="<?php echo esc_attr( $id ); ?>">
								<td><input type="checkbox" class="osn-wm-check" value="<?php echo esc_attr( $id ); ?>" data-thumb="<?php echo esc_url( $thumb ); ?>" <?php disabled( $wm || $excluded ); ?>></td>
								<td><?php if ( $thumb ) : ?><img src="<?php echo esc_url( $thumb ); ?>" loading="lazy"><?php endif; ?></td>
								<td><?php echo esc_html( $file ? wp_basename( $file ) : '—' ); ?><br><span class="description">ID: <?php echo esc_html( $id ); ?></span></td>
								<td><?php echo esc_html( $size ); ?></td>
								<td><?php echo esc_html( $dims ); ?></td>
								<td><?php echo esc_html( $mime ); ?></td>
								<td>
									<?php if ( $prod_id ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link( $prod_id ) ); ?>" target="_blank"><?php echo esc_html( get_the_title( $prod_id ) ); ?></a><br>
									<?php endif; ?>
									<?php echo wp_kses_post( OSN_Manager_Page::role_badge( $role, false ) ); ?>
								</td>
								<td class="osn-wm-status">
									<?php if ( $excluded ) : ?>
										<span class="osn-badge osn-b-gray">⛔ استثناء</span>
									<?php elseif ( $wm ) : ?>
										<span class="osn-badge osn-b-green">✅ واترمارک شده</span>
										<?php
										$row_hash = get_post_meta( $id, '_osn_watermark_logohash', true );
										if ( $logo_hash && $row_hash && $row_hash !== $logo_hash ) : ?>
											<span class="osn-badge osn-b-gold" title="لوگوی واترمارک از آن زمان عوض شده است">⚠️ لوگوی قدیمی</span>
										<?php endif; ?>
										<?php if ( $wm_date ) : ?><br><span class="description"><?php echo esc_html( wp_date( 'Y/m/d H:i', strtotime( $wm_date ) ) ); ?></span><?php endif; ?>
									<?php else : ?>
										<span class="osn-badge osn-b-red">⭕ بدون واترمارک</span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( ! $wm && ! $excluded ) : ?>
										<button class="button button-small osn-wm-mark" data-id="<?php echo esc_attr( $id ); ?>" title="اگر این تصویر از قبل (خارج از افزونه) واترمارک دارد">🏷 از قبل واترمارک دارد</button>
									<?php endif; ?>
									<?php if ( $wm && $wm_bak ) : ?>
										<button class="button button-small osn-wm-restore" data-id="<?php echo esc_attr( $id ); ?>">↩️ Restore Original</button>
									<?php elseif ( $wm ) : ?>
										<span class="description">قبلاً واترمارک شده</span>
									<?php endif; ?>
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
								'base'    => add_query_arg( 'paged', '%#%' ),
								'format'  => '',
								'current' => $paged,
								'total'   => $total_pages,
							) ) ) . '</div></div>';
					}
					?>
				</div>
			</div>
		</div>

		<script>
		jQuery(function($){
			var nonce = '<?php echo esc_js( "$nonce" ); ?>';
			var paused = false, active = false;

			function upd(){
				var n = $('.osn-wm-check:checked').length;
				$('#osn-wm-count').text(n);
				$('#osn-wm-bulk').prop('disabled', n === 0 || active);
			}
			$('#osn-wm-all').on('change', function(){ $('.osn-wm-check:not(:disabled)').prop('checked', this.checked); upd(); });
			$(document).on('change', '.osn-wm-check', upd);

			function ui(state){
				active = (state === 'running');
				$('#osn-pause-btn').toggle(state === 'running');
				$('#osn-resume-btn').toggle(state === 'paused');
				$('#osn-stop-btn').toggle(state === 'running' || state === 'paused');
				upd();
			}

			function step(){
				if (paused || !active) return;
				$.post(ajaxurl, {action:'osn_bulk_step', nonce:nonce})
				.done(function(res){
					if (!res.success) { ui('idle'); alert('خطا در پردازش'); return; }
					var d = res.data;
					if (d.id) {
						var $row = $('tr[data-id="'+d.id+'"]');
						if (d.status === 'done') {
							$row.find('.osn-wm-status').html('<span class="osn-badge osn-b-green">✅ واترمارک شد</span>');
							if (d.thumb) $row.find('img').attr('src', d.thumb + '?t=' + Date.now());
							$row.find('.osn-wm-check').prop('checked', false).prop('disabled', true);
						} else if (d.status === 'already') {
							$row.find('.osn-wm-status').append('<br><span class="description">این تصویر قبلاً واترمارک شده است</span>');
						} else if (d.status === 'error') {
							$row.find('.osn-wm-status').append('<span class="osn-badge osn-b-red">خطا</span>');
						}
					}
					if (d.total) {
						var pct = Math.round(d.done / d.total * 100);
						$('#osn-wm-bar').css('width', pct+'%').text(pct+'%');
						$('#osn-wm-text').text('در حال پردازش: ' + d.done + ' / ' + d.total + ' (' + pct + '%)' + (d.file ? ' - تصویر فعلی: ' + d.file : ''));
					}
					if (d.finished) {
						ui('idle');
						$('#osn-wm-text').append(' - ✅ پایان.');
					} else {
						setTimeout(step, 350);
					}
				})
				.fail(function(){ paused = true; ui('paused'); $('#osn-wm-text').append(' - قطع ارتباط؛ صف در دیتابیس ذخیره است، Resume بزنید.'); });
			}

			// Bulk با تاییدیه + Preview
			$('#osn-wm-bulk').on('click', function(){
				var $checked = $('.osn-wm-check:checked');
				if (!$checked.length) return;
				var t = $checked.first().data('thumb');
				$('#osn-cf-orig, #osn-cf-wm').attr('src', t || '');
				$('#osn-cf-msg').text('آیا مطمئن هستید واترمارک روی ' + $checked.length + ' تصویر اعمال شود؟ (Backup خودکار قبل از هر تغییر گرفته می‌شود)');
				$('#osn-confirm-panel').slideDown();
			});
			$('#osn-cf-no').on('click', function(){ $('#osn-confirm-panel').slideUp(); });
			$('#osn-cf-yes').on('click', function(){
				$('#osn-confirm-panel').slideUp();
				var ids = $('.osn-wm-check:checked').map(function(){ return $(this).val(); }).get();
				$.post(ajaxurl, {action:'osn_bulk_start', nonce:nonce, ids:ids, op:'watermark'})
				.done(function(res){
					if (!res.success) { alert(res.data || 'خطا'); return; }
					paused = false;
					$('#osn-wm-progress').show();
					ui('running');
					step();
				});
			});

			$('#osn-pause-btn').on('click', function(){ paused = true; ui('paused'); $('#osn-wm-text').append(' - ⏸ متوقف شد (صف در دیتابیس ذخیره است).'); });
			$('#osn-resume-btn').on('click', function(){ paused = false; ui('running'); step(); });
			$('#osn-stop-btn').on('click', function(){
				if (!confirm('عملیات کاملاً متوقف و صف حذف شود؟')) return;
				paused = true;
				$.post(ajaxurl, {action:'osn_bulk_stop', nonce:nonce}).always(function(){ ui('idle'); $('#osn-wm-text').append(' - ⏹ لغو شد.'); });
			});
			$('#osn-resume-db').on('click', function(){
				paused = false;
				$('#osn-wm-progress').show();
				ui('running');
				$(this).closest('.notice').slideUp();
				step();
			});
			$('#osn-discard-db').on('click', function(){
				var $n = $(this).closest('.notice');
				$.post(ajaxurl, {action:'osn_bulk_stop', nonce:nonce}).always(function(){ $n.slideUp(); });
			});

			// علامت‌گذاری «از قبل واترمارک دارد» - فایل تغییر نمی‌کند
			$(document).on('click', '.osn-wm-mark', function(){
				var $btn = $(this), id = $btn.data('id');
				if (!confirm('این تصویر به‌عنوان «از قبل واترمارک‌دار» علامت‌گذاری شود؟\nفایل تغییری نمی‌کند؛ فقط از لیست انتظار واترمارک خارج می‌شود.')) return;
				$.post(ajaxurl, {action:'osn_wm_mark', nonce:nonce, id:id})
				.done(function(res){
					if (res.success) {
						var $row = $btn.closest('tr');
						$row.find('.osn-wm-status').html('<span class="osn-badge osn-b-green">✅ واترمارک شده</span><br><span class="description">علامت‌گذاری دستی - فایل تغییر نکرد</span>');
						$row.find('.osn-wm-check').prop('checked', false).prop('disabled', true);
						$btn.remove();
					} else { alert('خطا: ' + res.data); }
				});
			});

			// Restore Original
			$(document).on('click', '.osn-wm-restore', function(){
				var $btn = $(this), id = $btn.data('id');
				if (!confirm('تصویر ID ' + id + ' به نسخه قبل از واترمارک بازگردانده شود؟')) return;
				$btn.prop('disabled', true).text('...');
				$.post(ajaxurl, {action:'osn_wm_restore', nonce:nonce, id:id})
				.done(function(res){
					if (res.success) {
						var $row = $btn.closest('tr');
						$row.find('.osn-wm-status').html('<span class="osn-badge osn-b-red">⭕ بدون واترمارک</span>');
						if (res.data.url) $row.find('img').attr('src', res.data.url + '?t=' + Date.now());
						$row.find('.osn-wm-check').prop('disabled', false);
						$btn.remove();
					} else {
						$btn.prop('disabled', false).text('↩️ Restore Original');
						alert('خطا: ' + res.data);
					}
				});
			});
		});
		</script>
		<?php
	}
}
