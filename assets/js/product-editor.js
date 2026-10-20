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
		if (status.excluded) { lines.push(osnPE.i18n.excluded); return lines.join(' | '); }
		if (status.optimized) {
			var opt = osnPE.i18n.optimized;
			if (status.percent !== null && status.percent !== undefined) opt += ' (' + status.percent + '%- ' + osnPE.i18n.reduction + ')';
			if (status.quality) opt += ' Q' + status.quality;
			lines.push(opt);
		} else if (status.skipped) {
			lines.push(osnPE.i18n.already);
		} else if (status.error) {
			lines.push(osnPE.i18n.failed);
		} else {
			lines.push(osnPE.i18n.notYet);
		}
		lines.push(osnPE.i18n.watermarkPrefix + (status.watermarked ? osnPE.i18n.has : osnPE.i18n.notHas));
		if (status.brightness) {
			lines.push(osnPE.i18n.manualLight + (status.brightness > 0 ? '+' : '') + status.brightness);
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
				'<button type="button" class="osn-pe-btn osn-pe-opt" title="' + esc(osnPE.i18n.optimize) + '" data-id="' + id + '">⚡</button>' +
				'<button type="button" class="osn-pe-btn osn-pe-wm" title="' + esc(osnPE.i18n.watermark) + '" data-id="' + id + '">💧</button>' +
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
				toast(osnPE.i18n.optimizedToast, true);
				var $img = $btn.closest('.osn-pe-wrap').find('img');
				if (res.data.url) $img.attr('src', res.data.url + '?t=' + Date.now());
				scan();
			} else {
				toast(osnPE.i18n.errorPrefix + (res.data || osnPE.i18n.error), false);
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
					toast(osnPE.i18n.alreadyWm, true);
				} else if (res.data.status === 'skipped') {
					toast(osnPE.i18n.skippedWm, false);
				} else {
					toast(osnPE.i18n.appliedWm, true);
					var $img = $btn.closest('.osn-pe-wrap').find('img');
					if (res.data.url) $img.attr('src', res.data.url + '?t=' + Date.now());
				}
				scan();
			} else {
				toast(osnPE.i18n.errorPrefix + (res.data || osnPE.i18n.error), false);
			}
		})
		.fail(function(){ $btn.removeClass('osn-busy').text('💧'); toast(osnPE.i18n.error, false); });
	});
});
