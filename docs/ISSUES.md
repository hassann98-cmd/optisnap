# گزارش کامل بررسی (Audit) افزونه OptiSnap

> تاریخ بررسی: 2026-10-20 | نسخه بررسی‌شده: 3.9.0 | حجم: ~6,600 خط PHP در 14 فایل
> وضعیت: مشکلات بحرانی و مهم در نسخه **3.9.1** رفع شدند (ستون «وضعیت»).

## خلاصه معماری

افزونه وردپرس «OptiSnap» (پیش‌تر JLuxe Image) با این مسیر پردازش کار می‌کند:

```
آپلود → [فیلتر wp_handle_upload] → اصلاح EXIF → کراپ مربعی 1:1 → Resize →
واترمارک (دستی) → ذخیره WebP (cwebp / Imagick / GD) + AVIF اختیاری →
انتقال فایل اصلی به uploads/osn-backup/ → ثبت متا و لاگ
```

- **لایه‌های پردازش**: ۴ لایه (wp_handle_upload، add_attachment، لایه ایمنی `wp_generate_attachment_metadata`، REST safety-net)
- **موتورهای Encode**: cwebp/avifenc (باینری همراه افزونه) → Imagick → GD
- **بخش‌ها**: Settings، Media Manager، Watermark Manager، Batch Tool (اسکن/پردازش انبوه)، Cleaner، SEO Alt، لاگ دیتابیس، Product Editor (دکمه روی تصاویر محصول)، REST (optisnap/v1 + jluxe-image/v1)، WP-Cron
- **نقاط قوت**: معماری لایه‌ای با گارد بازگشت (recursion guard)، سیاست‌های پردازش (Policy)، Size Guard، Backup اجباری قبل از تغییر، صف پایدار Pause/Resume، کوئری‌های آماده (prepared statements)، بررسی nonce + capability در همه AJAXها، سازگاری عقب‌رو (legacy namespaceها)

---

## الف) مشکلات امنیتی

| # | شدت | مشکل | فایل | وضعیت |
|---|------|------|------|--------|
| A1 | متوسط | **Stored XSS در پنل ادمین**: مقادیر `h.title`، `h.edit`، `h.thumb`، `d.error`، `d.reason`، `res.data.alt` بدون escape در HTML تزریق می‌شدند (عنوان رسانه/محصول و پیام خطا حاوی نام فایل، قابل کنترل توسط کاربر). کاربر با `unfiltered_html` می‌توانست اسکریپت در ادمین اجرا کند. | class-batch-tool.php، class-manager-page.php، class-product-editor.php | ✅ رفع شد در 3.9.1 (تابع `esc()` + escape همه خروجی‌های سرور در JS) |
| A2 | متوسط | **Path Traversal در `ajax_view_backup`**: بررسی `strpos($real, $backup)` بدون جداکننده مسیر؛ پوشه‌ای با پیشوند یکسان (مثل `osn-backup-evil`) از فیلتر رد می‌شد. | class-manager-page.php | ✅ رفع شد در 3.9.1 (`$backup . DIRECTORY_SEPARATOR`) |
| A3 | متوسط | **فایل‌های Backup روی nginx عمومی**: `.htaccess` فقط روی Apache کار می‌کند؛ مسیر `uploads/osn-backup/` روی nginx/IIS قابل حدس و قابل دانلود بود (تصاویر اصلی بدون واترمارک + نسخه‌های قبل از پردازش). | optisnap.php (`osn_img_backup_dir`) | ✅ کاهش ریسک در 3.9.1: `web.config` برای IIS، `.htaccess` با syntax هر دو نسخه Apache (2.2/2.4)، هشدار در داشبورد سلامت برای nginx. ⚠️ راه‌حل نهایی: مسدودسازی در تنظیمات سرور (در README مستند شد). |
| A4 | متوسط | **نوشتن debug.log در وب‌روت**: پیام‌های `force` بدون توجه به تنظیم debug در `wp-content/debug.log` نوشته می‌شدند — فایل اغلب قابل دانلود عمومی + حاوی مسیرهای سرور + پرشدن دیسک. | class-logger.php | ✅ رفع شد در 3.9.1 (فقط با فعال‌بودن debug در فایل نوشته می‌شود؛ پیام‌های مهم به error_log سرور می‌روند) |
| A5 | پایین | **اجرای باینری همراه افزونه با exec()**: `chmod 0755` + اجرای cwebp/avifenc از `assets/bin/`؛ اگر مسیر افزونه قابل‌نوشتن توسط کاربر دیگری باشد، جایگزینی باینری = اجرای کد دلخواه. | class-encoder.php | 📝 مستند شد (ریسک ذاتی مدل؛ پیشنهاد: هشدار در README + امکان غیرفعال‌سازی موتور Shell با فیلتر) |
| A6 | پایین | REST endpoint عمومی `/image/{id}` هر Attachment را برمی‌گرداند (حتی متصل‌نشده به محصول). اطلاعات حساسی ندارد (فقط URL/ابعاد) ولی بهتر است محدود به تصاویر منتشرشده/عمومی شود. | class-frontend.php | 📝 در Roadmap |
| A7 | پایین | Nonce در query string برای `ajax_view_backup` (لاگ‌های سرور/مرورگر ممکن است URL را ثبت کنند). | class-manager-page.php | 📝 در Roadmap (POST + blob) |

**نکته مثبت امنیتی**: همه ۱۵ اکشن AJAX با `check_ajax_referer` + `current_user_can('manage_options')` محافظت شده‌اند؛ کوئری‌های SQL از `$wpdb->prepare` استفاده می‌کنند؛ `abspath` guard در همه فایل‌ها هست؛ خروجی‌های PHP عمدتاً `esc_html/esc_attr/esc_url` شده‌اند.

---

## ب) باگ‌های عملکردی

| # | شدت | مشکل | فایل | وضعیت |
|---|------|------|------|--------|
| B1 | بالا | **عبور از Optimization Policy در REST**: `ensure_processed` حتی در حالت `manual`/`products_only` تصویر را پردازش می‌کرد (بقیه مسیرها به سیاست احترام می‌گذاشتند). | class-rest-handler.php | ✅ رفع شد در 3.9.1 |
| B2 | بالا | **نشت فایل موقت در هر Encode**: `wp_tempnam() . '.png'` — خودِ `wp_tempnam` فایل می‌سازد و فایل خالی بدون پسوند در /tmp باقی می‌ماند (۴ فراخوانی در هر WebP/AVIF). روی هاست‌های پرترافیک /tmp پر می‌شود. | class-encoder.php | ✅ رفع شد در 3.9.1 |
| B3 | بالا | **خرابی فایل در صورت شکست Encode** (واترمارک/نور): نوشتن مستقیم روی فایل اصلی؛ شکست Encode می‌توانست فایل را نیمه/خالی کند. | class-image-processor.php | ✅ رفع شد در 3.9.1 (نوشتن اتمیک: فایل موقت + rename) |
| B4 | متوسط | **انباشت پسوند در Force Optimize**: `image-jlx.webp` → `image-jlx-jlx.webp` → … | class-image-processor.php | ✅ رفع شد در 3.9.1 (حذف پسوند قبلی از نام) |
| B5 | متوسط | **رد همیشگی آپلودهای با `post_date_gmt` خالی**: `strtotime('0000-00-00…')` برمی‌گرداند `false` → لایه ایمنی آپلود تازه را «قدیمی» تفسیر و برای همیشه رد می‌کرد. | optisnap.php | ✅ رفع شد در 3.9.1 (fallback به post_date) |
| B6 | متوسط | **Race Condition در صف پردازش انبوه**: دو درخواست موازی `osn_bulk_step` همزمان از صف pop می‌کردند (از دست رفتن آیتم یا پردازش دوباره). | class-manager-page.php | ✅ رفع شد در 3.9.1 (قفل اتمیک MySQL `GET_LOCK`) |
| B7 | متوسط | **۲ تا ۶ کوئری SQL اضافه به ازای هر تصویر در فرانت‌اند**: `get_image_role`/`get_related_product_id` کش نداشتند؛ `fill_product_alt` برای هر تصویر خروجی `wp_get_attachment_image` اجرا می‌شد (صفحه لیست محصول = ده‌ها کوئری اضافه). | class-image-processor.php | ✅ رفع شد در 3.9.1 (کش درخواستی static) |
| B8 | متوسط | **جدول لاگ بعد از حذف افزونه باقی می‌ماند** (`osn_image_logs`). | uninstall.php | ✅ رفع شد در 3.9.1 (DROP TABLE) |
| B9 | متوسط | **شکستن URLهای هاردکدشده بعد از پردازش دسته‌ای**: فایل `photo.jpg` به `photo-jlx.webp` تغییر نام/جا‌به‌جایی می‌شود؛ لینک‌های مستقیم قدیمی در محتوای برگه‌ها/قالب 404 می‌شوند (گالری‌های مبتنی بر Attachment ID سالم می‌مانند). | class-image-processor.php | 📝 محدودیت شناخته‌شده — در README هشدار داده شد؛ راه‌حل (کپی سازگاری/ریدایرکت) در Roadmap |
| B10 | پایین | `should_auto_skip` تصاویر کوچک (<30KB) را با متای `skipped` برای همیشه رد می‌کند؛ با تغییر تنظیمات، این تصاویر دوباره بررسی نمی‌شوند (مگر Force). | class-image-processor.php | 📝 در Roadmap (پاک‌سازی متای skipped با تغییر تنظیمات) |
| B11 | پایین | `wm_opacity` معکوس است: مقدار بیشتر = محوتر (شفافیت)؛ با اسم «opacity» انتظار کاربر برعکس است. | class-watermark.php | 📝 رابط کاربری/مستندات (توضیح داده شد؛ تغییر رفتار = Breaking) |
| B12 | پایین | EXIF Orientation فقط حالت‌های 3/6/8 (چرخش) را اصلاح می‌کند؛ 2/4/5/7 (flip) نادیده گرفته می‌شوند. | class-image-processor.php | 📝 در Roadmap |
| B13 | پایین | نسخه در header فایل اصلی و ثابت `OSN_IMG_VERSION` دستی همگام می‌شوند (ریسک فراموشی). | optisnap.php | 📝 در Roadmap (اسکریپت build همگام‌ساز) |

---

## ج) مسائل کارایی / مقیاس‌پذیری

| # | مشکل | وضعیت |
|---|------|--------|
| C1 | حالت `products` در اسکن/Cron با `posts_per_page => -1` همه شناسه محصولات را بارگذاری می‌کند (فروشگاه بزرگ = مصرف حافظه). | 📝 در Roadmap (زیرکوئری EXISTS) |
| C2 | اسکن برای هر تصویر `md5_file` می‌گیرد (فایل‌های حجیم = I/O سنگین). | 📝 در Roadmap (هش اختیاری/مرحله جدا) |
| C3 | غنی‌سازی REST ووکامرس (`enrich_wc_product`) به ازای هر تصویر چند کوئری می‌زد. | ✅ با کش B7 بخش زیادی بهبود یافت |
| C4 | `Imagick → GD` fallback برای CMYK با تبدیل به PNG32 حافظه دوبرابر مصرف می‌کند. | 📝 مستند شد |
| C5 | عملیات Batch در request واحد (تا ۱۰ تصویر با `set_time_limit(120)`) ممکن است روی هاست‌های محدود timeout بخورد. | 📝 معماری فعلی (AJAX chunked) قابل قبول |

---

## د) مسائل مهندسی / استاندارد

| # | مشکل | وضعیت |
|---|------|--------|
| D1 | مخزن فقط حاوی یک فایل zip بود؛ بدون سورس قابل‌مرور، بدون تاریخچه، بدون License و CI. | ✅ رفع شد — سورس کامل + README + CHANGELOG + LICENSE + CI + اسکریپت build |
| D2 | متن‌های فارسی hardcoded بدون `__()` / Text Domain (عدم ترجمه‌پذیری + عدم تایید wordpress.org). | 📝 در Roadmap (i18n کامل) |
| D3 | فایل‌های UI بزرگ (class-manager-page.php با 1,700+ خط و JS inline) — نگهداری سخت. | 📝 در Roadmap (تفکیک asset) |
| D4 | بدون تست خودکار. | 📝 در Roadmap (unit tests برای OSN_Watermark/OSN_Encoder) |
| D5 | `readme.txt` استاندارد وردپرس وجود نداشت. | ✅ اضافه شد |
| D6 | باینری‌های ۱۹ مگابایتی داخل zip توزیع می‌شوند (حجم مخزن/دانلود). | 📝 مستند شد (دانلود اختیاری در Roadmap) |
| D7 | `add_submenu_page` با slug خارجی (`tools.php?page=…`) تکنیک شکننده‌ای برای لینک منو است. | 📝 در Roadmap |

---

## اولویت‌بندی پیشنهادی توسعه (Roadmap)

1. **v3.9.2**: i18n کامل (`__()` + فایل‌های .pot/.po) + تست‌های واحد + رفع B10/B12
2. **v4.0**: راه‌حل سازگاری URL (B9) — ریدایرکت/کپی هوشمند فایل اصلی + تنظیم «حفظ نام فایل» + دانلود اختیاری باینری‌ها + بازطراحی UI (تفکیک JS/CSS)
3. **v4.1**: بهینه‌سازی کوئری‌ها (C1/C2)، محدودسازی REST (A6)، ابزار «بازسازی هوشمند» بر اساس Hash

---

## روش بازتولید این بررسی

- مرور دستی تمام ۱۴ فایل PHP (6,637 خط)
- جستجوی الگوهای خطر: خروجی escape‌نشده در JS، `strpos` مسیر، `exec/shell_exec`، `$_GET/$_POST` بدون sanitize، نوشتن فایل در وب‌روت، temp file، race condition در optionها
- بررسی همه ۱۵ اکشن AJAX از نظر nonce/capability
