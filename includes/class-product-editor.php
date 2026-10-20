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

		wp_register_style( 'osn-product-editor', false, array(), OSN_IMG_VERSION );
		wp_enqueue_style( 'osn-product-editor' );
		wp_add_inline_style( 'osn-product-editor', $this->inline_css() );

		wp_register_script( 'osn-product-editor', false, array( 'jquery' ), OSN_IMG_VERSION, true );
		wp_enqueue_script( 'osn-product-editor' );
		wp_localize_script( 'osn-product-editor', 'osnPE', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'osn_batch_nonce' ),
			'i18n'    => array(
				'optimize'   => 'بهینه‌سازی',
				'watermark'  => 'واترمارک',
				'confirmWm'  => 'واترمارک روی این تصویر اعمال شود؟ (Backup خودکار گرفته می‌شود)',
				'done'       => 'انجام شد',
				'error'      => 'خطا',
				'processing' => '...',
			),
		) );
		wp_add_inline_script( 'osn-product-editor', $this->inline_js() );
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

	private function inline_css() {
		return "
		.osn-pe-wrap { position: relative; display: inline-block; }
		.osn-pe-toolbar {
			position: absolute; top: 4px; inset-inline-end: 4px; z-index: 20;
			display: flex; gap: 3px; direction: ltr;
		}
		.osn-pe-btn {
			width: 24px; height: 24px; line-height: 22px; text-align: center; font-size: 13px;
			background: rgba(255,255,255,.95); border: 1px solid #dcdcde; border-radius: 4px;
			cursor: pointer; padding: 0; box-shadow: 0 1px 2px rgba(0,0,0,.15);
		}
		.osn-pe-btn:hover { background: #2271b1; color: #fff; }
		.osn-pe-btn.osn-busy { opacity: .5; pointer-events: none; }
		.osn-pe-badge {
			position: absolute; bottom: 3px; inset-inline-start: 3px; z-index: 20;
			font-size: 9px; line-height: 1; padding: 3px 5px; border-radius: 8px;
			background: rgba(0,0,0,.65); color: #fff; direction: rtl;
		}
		.osn-pe-badge.ok { background: rgba(0,163,42,.85); }
		.osn-pe-badge.warn { background: rgba(214,54,56,.85); }
		#postimagediv .inside, #product_images_container { position: relative; }
		";
	}

	private function inline_js() {
		return <<<'JS'
jQuery(function($){
	if (typeof osnPE === "undefined") return;

	function esc(s){
		return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
			return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
		});
	}

	function toast(msg, ok){
		var $t = $('<div>').text(msg).css({
			position:'fixed', bottom:'20px', insetInlineEnd:'20px', zIndex:99999,
			background: ok ? '#00a32a' : '#d63638', color:'#fff', padding:'10px 16px',
			borderRadius:'6px', fontSize:'13px', boxShadow:'0 2px 8px rgba(0,0,0,.2)'
		}).appendTo('body');
		setTimeout(function(){ $t.fadeOut(300, function(){ $t.remove(); }); }, 2500);
	}

	function statusTitle(status){
		var lines = [];
		if (status.excluded) { lines.push('استثناء از پردازش خودکار'); return lines.join(' | '); }
		if (status.optimized) {
			var opt = 'بهینه‌سازی: شده';
			if (status.percent !== null && status.percent !== undefined) opt += ' (' + status.percent + '%- کاهش حجم)';
			if (status.quality) opt += ' Q' + status.quality;
			lines.push(opt);
		} else if (status.skipped) {
			lines.push('بهینه‌سازی: از قبل مناسب بود (رد شد)');
		} else if (status.error) {
			lines.push('بهینه‌سازی: خطا خورده - نیاز به بررسی');
		} else {
			lines.push('بهینه‌سازی: هنوز انجام نشده');
		}
		lines.push('واترمارک: ' + (status.watermarked ? 'دارد' : 'ندارد'));
		if (status.brightness) {
			lines.push('نور دستی: ' + (status.brightness > 0 ? '+' : '') + status.brightness);
		}
		return lines.join(' | ');
	}

	function badgeHtml(status){
		if (!status) return '';
		var title = statusTitle(status);
		if (status.excluded) return '<span class="osn-pe-badge warn" title="' + esc(title) + '">⛔ استثناء</span>';
		var parts = [];
		if (status.optimized) parts.push('✅');
		else if (status.skipped) parts.push('⏭');
		else if (status.error) parts.push('❌');
		if (status.watermarked) parts.push('💧');
		if (status.brightness) parts.push('🔆');
		if (!parts.length) return '<span class="osn-pe-badge" title="' + esc(title) + '">—</span>';
		var cls = status.error ? 'warn' : (status.optimized || status.skipped ? 'ok' : '');
		return '<span class="osn-pe-badge ' + cls + '" title="' + esc(title) + '">' + parts.join(' ') + '</span>';
	}

	function wrapImage($img, id){
		if (!id) return;
		// اگر تصویر داخل یک <a> است (مثل #set-post-thumbnail که کل باکس تصویر اصلی
		// را به یک لینک بازکننده کتابخانه رسانه تبدیل می‌کند)، آن <a> را هدف قرار می‌دهیم
		// نه فقط <img> را - وگرنه toolbar داخل همان لینک قرار می‌گیرد و کلیک روی
		// دکمه‌ها هم لینک را باز می‌کند (باگ اصلی: توالی رویداد به لینک زودتر می‌رسد
		// از اینکه stopPropagation ما اجرا شود).
		var $target = $img.closest('a').length ? $img.closest('a') : $img;
		if ($target.parent().hasClass('osn-pe-wrap')) return; // قبلاً پوشیده شده

		var $wrap = $('<span class="osn-pe-wrap"></span>');
		$target.before($wrap);
		$wrap.append($target); // <a> (یا img) داخل wrap می‌رود

		// نکته کلیدی: toolbar به‌عنوان sibling همان <a>/img اضافه می‌شود، نه descendant آن.
		// بنابراین کلیک روی دکمه‌ها هرگز از مسیر DOM لینک بازکننده رسانه عبور نمی‌کند
		// و امکان باز شدن ناخواسته کتابخانه رسانه از بین می‌رود.
		$wrap.append(
			'<span class="osn-pe-toolbar">' +
				'<button type="button" class="osn-pe-btn osn-pe-opt" title="' + osnPE.i18n.optimize + '" data-id="' + id + '">⚡</button>' +
				'<button type="button" class="osn-pe-btn osn-pe-wm" title="' + osnPE.i18n.watermark + '" data-id="' + id + '">💧</button>' +
			'</span>'
		);
		return $wrap;
	}

	function collectTargets(){
		var targets = []; // {id, $img}

		// تصویر اصلی محصول (Featured Image)
		var thumbId = parseInt($('#_thumbnail_id').val(), 10);
		var $thumbImg = $('#postimagediv .inside img').first();
		if (thumbId && $thumbImg.length) {
			targets.push({ id: thumbId, $img: $thumbImg });
		}

		// گالری محصول
		$('#product_images_container ul.product_images li.image').each(function(){
			var id = parseInt($(this).data('attachment_id'), 10);
			var $img = $(this).find('img').first();
			if (id && $img.length) {
				targets.push({ id: id, $img: $img });
			}
		});

		return targets;
	}

	function refreshStatuses(targets){
		var ids = targets.map(function(t){ return t.id; });
		if (!ids.length) return;
		$.post(osnPE.ajaxurl, { action:'osn_status_batch', nonce: osnPE.nonce, ids: ids })
		.done(function(res){
			if (!res.success) return;
			targets.forEach(function(t){
				var st = res.data[t.id];
				var $wrap = t.$img.closest('.osn-pe-wrap');
				$wrap.find('.osn-pe-badge').remove();
				$wrap.append(badgeHtml(st));
			});
		});
	}

	function scan(){
		var targets = collectTargets();
		targets.forEach(function(t){ wrapImage(t.$img, t.id); });
		refreshStatuses(targets);
	}

	// اجرای اولیه + نظارت بر تغییرات (آپلود گالری جدید و تغییر تصویر اصلی به‌صورت AJAX در ووکامرس انجام می‌شود)
	setTimeout(scan, 800);
	if (window.MutationObserver) {
		var mo = new MutationObserver(function(){ clearTimeout(window.__osnPEDebounce); window.__osnPEDebounce = setTimeout(scan, 500); });
		var galleryEl = document.getElementById('product_images_container');
		var thumbEl = document.getElementById('postimagediv');
		if (galleryEl) mo.observe(galleryEl, { childList:true, subtree:true });
		if (thumbEl) mo.observe(thumbEl, { childList:true, subtree:true });
	}

	// کلیک: بهینه‌سازی
	$(document).on('click', '.osn-pe-opt', function(e){
		e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
		var $btn = $(this), id = $btn.data('id');
		$btn.addClass('osn-busy').text('⏳');
		$.post(osnPE.ajaxurl, { action:'osn_process_one', nonce: osnPE.nonce, id: id })
		.done(function(res){
			$btn.removeClass('osn-busy').text('⚡');
			if (res.success) {
				toast('تصویر بهینه شد ✅', true);
				var $img = $btn.closest('.osn-pe-wrap').find('img');
				if (res.data.url) $img.attr('src', res.data.url + '?t=' + Date.now());
				scan();
			} else {
				toast('خطا: ' + (res.data || osnPE.i18n.error), false);
			}
		})
		.fail(function(){ $btn.removeClass('osn-busy').text('⚡'); toast(osnPE.i18n.error, false); });
	});

	// کلیک: واترمارک (همیشه با تایید کاربر - هرگز خودکار)
	$(document).on('click', '.osn-pe-wm', function(e){
		e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
		var $btn = $(this), id = $btn.data('id');
		if (!window.confirm(osnPE.i18n.confirmWm)) return;
		$btn.addClass('osn-busy').text('⏳');
		$.post(osnPE.ajaxurl, { action:'osn_watermark_one', nonce: osnPE.nonce, id: id })
		.done(function(res){
			$btn.removeClass('osn-busy').text('💧');
			if (res.success) {
				if (res.data.status === 'already') {
					toast('این تصویر قبلاً واترمارک شده است', true);
				} else if (res.data.status === 'skipped') {
					toast('این تصویر رد شد (استثناء یا لوگو تنظیم نشده)', false);
				} else {
					toast('واترمارک اعمال شد 💧', true);
					var $img = $btn.closest('.osn-pe-wrap').find('img');
					if (res.data.url) $img.attr('src', res.data.url + '?t=' + Date.now());
				}
				scan();
			} else {
				toast('خطا: ' + (res.data || osnPE.i18n.error), false);
			}
		})
		.fail(function(){ $btn.removeClass('osn-busy').text('💧'); toast(osnPE.i18n.error, false); });
	});
});
JS;
	}
}
