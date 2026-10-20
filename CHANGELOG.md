# تاریخچه تغییرات – OptiSnap

## [3.9.1] – 2026-10-20

### امنیت (Security)
- **رفع Stored/XSS در پنل ادمین**: همه مقادیر سرور (عنوان تصویر، URLها، پیام‌های خطا، پیشنهاد Alt) قبل از درج در HTML جاوااسکریپت escape می‌شوند (`esc()`) — `class-batch-tool.php`, `class-manager-page.php`, `class-product-editor.php`.
- **رفع Path Traversal در نمایش Backup** (`ajax_view_backup`): بررسی مسیر حالا با جداکننده دایرکتوری انجام می‌شود تا پوشه‌های هم‌پیشوند قابل‌قبول نباشند.
- **توقف نوشتن debug.log در وب‌روت**: دیگر پیام‌های افزونه به‌صورت پیش‌فرض در `wp-content/debug.log` (قابل دانلود عمومی) نوشته نمی‌شوند؛ فقط با فعال‌بودن گزینه Debug در تنظیمات. پیام‌های مهم به `error_log` سرور می‌روند.
- **محافظت بهتر پوشه Backup**: `.htaccess` با syntax هر دو نسخه Apache (2.2 و 2.4) + `web.config` برای IIS + هشدار در داشبورد سلامت برای کاربران nginx.

### رفع باگ (Fixed)
- **احترام به Optimization Policy در آپلود REST**: لایه ایمنی REST دیگر در حالت `manual`/`products_only` تصویر را به‌صورت خودکار پردازش نمی‌کند.
- **رفع نشت فایل موقت در Encode**: فایل‌های موقت `wp_tempnam` دیگر در /tmp باقی نمی‌مانند (قبلاً در هر تبدیل WebP/AVIF یک فایل خالی تولید می‌شد).
- **نوشتن اتمیک در واترمارک و تنظیم نور**: خروجی ابتدا در فایل موقت نوشته و سپس جایگزین می‌شود؛ شکست Encode دیگر فایل فعلی را خراب/خالی نمی‌کند.
- **رفع انباشت پسوند در Force Optimize**: `image-jlx.webp` دیگر به `image-jlx-jlx.webp` تبدیل نمی‌شود.
- **رفع رد همیشگی آپلودهای تازه با `post_date_gmt` نامعتبر** در لایه ایمنی متادیتا (fallback به `post_date`).
- **رفع Race Condition در صف پردازش انبوه**: قفل اتمیک `GET_LOCK` جلوی pop هم‌زمان دو درخواست موازی را می‌گیرد.
- **حذف جدول لاگ هنگام Uninstall** (`osn_image_logs`).

### بهینه‌سازی (Performance)
- **کش درخواستی تشخیص نقش تصویر/محصول مرتبط** (`get_image_role`, `get_related_product_id`): ده‌ها کوئری SQL اضافه در صفحات لیست محصول و payloadهای REST حذف شد.

### مخزن / توسعه (Repository)
- **افزودن کامل سورس به مخزن** (قبلاً فقط فایل zip بود): کد قابل‌مرور، قابل review و قابل مشارکت.
- افزودن `README.md`، `CHANGELOG.md` (این فایل)، `docs/ISSUES.md` (گزارش کامل audit)، `readme.txt` استاندارد وردپرس، `LICENSE` (GPL-2.0-or-later)، `.gitignore`، اسکریپت build (`bin/build-zip.sh`) و CI بررسی syntax (`php -l`).

## [3.9.0] – قبلی

- مهاجرت از نام‌های `jluxe` به `osn` (OptiSnap) با مهاجرت یک‌باره متاها/تنظیمات/جدول لاگ — بدون از دست رفتن داده.
- (بقیه تاریخچه در کد نسخه‌های قبلی ثبت شده: Smart Quality Engine، Size Guard، موتورهای cwebp/Imagick/GD، واترمارک نقش‌محور، صف پایدار Pause/Resume، REST سازگار با اپ JLuxe Admin، Product Editor، تنظیم نور، Cleaner و...)
