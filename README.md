# OptiSnap – بهینه‌ساز و واترمارک تصویر برای وردپرس

افزونه وردپرس برای تبدیل خودکار تصاویر به **WebP** (و AVIF اختیاری)، فشرده‌سازی، کراپ مربعی ۱:۱، سایزهای ریسپانسیو (srcset)، **واترمارک لوگو** و مدیریت کامل کتابخانه رسانه — سازگار با **WooCommerce** و اپ **JLuxe Admin**.

- نسخه فعلی: **3.13.0**
- حداقل PHP: 7.4 (پیشنهاد: 8.1+)
- پیش‌نیازها: GD با پشتیبانی WebP (یا Imagick) — برای حداکثر فشرده‌سازی: اجرای باینری `cwebp`

---

## ✨ امکانات

| بخش | توضیح |
|------|--------|
| 🗜 تبدیل و فشرده‌سازی | WebP با سه موتور: **cwebp** (باینری همراه افزونه) → **Imagick** → **GD**؛ تولید AVIF اختیاری |
| 🧠 Smart Mode | انتخاب خودکار کیفیت بر اساس ابعاد، جزئیات پیکسلی، نقش تصویر (Featured/گالری) و حجم اولیه |
| 🎯 نقش‌محور | کیفیت و اندازه جدا برای تصویر اصلی محصول / گالری / سایر |
| 🖼 کراپ مربعی ۱:۱ | از مرکز (با استثنای `-noopt` در نام فایل) |
| 💧 واترمارک دستی | با Backup خودکار، Preview دوپنجله، قوانین نقش‌محور (شفافیت/اندازه/موقعیت)، استثنای دسته/محصول |
| 💾 Backup اجباری | هیچ فایل اصلی بدون Backup تغییر نمی‌کند (`uploads/osn-backup/`) + Restore کامل |
| ⚙️ Media Manager | پردازش گروهی با صف پایدار در دیتابیس (Pause/Resume/Stop)، فیلتر، مقایسه قبل/بعد |
| 🛠 ابزارها | اسکن مرحله‌ای کتابخانه، تصاویر حجیم، آزادسازی فضا، Cron پس‌زمینه |
| 🧹 Cleaner | تصاویر تکراری (Hash)، بدون والد، خیلی بزرگ |
| 🔍 SEO | ساخت Alt/Title از نام محصول |
| 🌐 REST | `optisnap/v1` (و `jluxe-image/v1` برای سازگاری) + غنی‌سازی REST ووکامرس با `optisnap_images` |
| 🛒 WooCommerce | تشخیص خودکار نقش تصویر، دکمه‌های ⚡/💧 روی تصاویر محصول |
| 📝 متن جایگزین | ویرایش Alt تصویر شاخص و گالری مستقیماً از همان ویرایش محصول |
| 📊 نتیجهٔ پردازش | درصد کاهش روی تصویر؛ حجم و ابعاد قبل/بعد در جزئیات پروفایل |
| 🎛 کنترل تصویر | پروفایل جداگانه برای هر تصویر محصول/رسانه: کیفیت بالاتر، حجم کمتر، بنر دسکتاپ/موبایل؛ بنر بدون کراپ مربعی |

## 📦 نصب

1. فایل `optisnap.zip` را از [Releases](../../releases) دانلود کنید (یا خودتان بسازید: `bin/build-zip.sh`).
2. در وردپرس: **افزونه‌ها › افزودن › بارگذاری افزونه** → فعال‌سازی.
3. از منوی **OptiSnap** › ⚙️ تنظیمات: کیفیت‌ها، لوگوی واترمارک و سیاست پردازش را تنظیم کنید.

> ✅ **لینک‌های قدیمی (v3.10.0)**: با فعال بودن «به‌روزرسانی خودکار لینک‌های قدیمی در محتوا» (پیش‌فرض روشن)، لینک‌های هاردکدشده تصاویر در مطالب/محصولات بعد از پردازش خودکار به URL جدید منتقل می‌شوند. فقط لینک‌هایی که مستقیماً در فایل‌های PHP قالب نوشته شده‌اند باید دستی اصلاح شوند.

> ⚠️ **nginx**: پوشه `uploads/osn-backup/` فقط با `.htaccess` (Apache) بسته می‌شود. روی nginx در تنظیمات سرور دسترسی مستقیم به `/wp-content/uploads/osn-backup/` را مسدود کنید:
> ```nginx
> location ^~ /wp-content/uploads/osn-backup/ { deny all; }
> ```

## 🗂 ساختار مخزن

```
optisnap/
├── optisnap.php              # فایل اصلی افزونه (هوک‌های آپلود، مهاجرت، Cron)
├── uninstall.php
├── includes/
│   ├── class-image-processor.php   # هسته پردازش (EXIF، کراپ، Resize، Backup، Restore)
│   ├── class-encoder.php           # موتورهای WebP/AVIF (cwebp / Imagick / GD)
│   ├── class-watermark.php         # اعمال واترمارک
│   ├── class-settings.php          # صفحه تنظیمات
│   ├── class-manager-page.php      # داشبورد / Media Manager / Cleaner / SEO / Logs
│   ├── class-watermark-manager.php # مدیریت واترمارک + صف Bulk
│   ├── class-batch-tool.php        # اسکن / پردازش انبوه / Backup
│   ├── class-product-editor.php    # دکمه‌های تصویر محصول ووکامرس
│   ├── class-rest-handler.php      # REST سازگار با اپ JLuxe
│   ├── class-frontend.php          # Alt خودکار + payload ریسپانسیو
│   └── class-logger.php            # لاگ دیتابیس و فایل
├── assets/bin/               # باینری‌های cwebp/avifenc (linux x86_64 / aarch64)
├── docs/ISSUES.md            # 📋 گزارش کامل مشکلات (Audit)
├── CHANGELOG.md              # تاریخچه تغییرات
├── bin/build-zip.sh          # ساخت فایل zip نصب
├── tests/run.php             # تست‌های خودکار (php tests/run.php)
└── .github/workflows/        # CI: بررسی syntax روی PHP 7.4 تا 8.3 + build
```

## 🔧 توسعه

```bash
git clone https://github.com/hassann98-cmd/optisnap.git
cd optisnap

# ساخت zip نصب
bin/build-zip.sh

# اجرای تست‌ها (نیاز به PHP با GD)
php tests/run.php
```

- **قواعد**: هر تغییر باید در `CHANGELOG.md` ثبت شود؛ commit ها توضیح فارسی/انگلیسی واضح داشته باشند.
- **امنیت**: هر خروجی HTML/JS باید escape شود؛ هر AJAX باید nonce + capability داشته باشد؛ هر فایل PHP با `ABSPATH` guard شروع شود.
- **سازگاری**: پسوند پردازش‌شده `-jlx` عمداً ثابت است (فایل‌های هزاران تصویر روی سایت زنده با همین پسوند نام‌گذاری شده‌اند؛ تغییر آن = پردازش دوباره/واترمارک دوبل).

## 📋 نتایج بررسی (Audit)

📊 **گزارش کامل بررسی** — ۳۲ یافته بررسی‌شده در نسخه 3.9.0 با وضعیت به‌روز هر کدام:
- 📄 [`docs/ISSUES.md`](docs/ISSUES.md) — گزارش کامل با جدول شدت/وضعیت + Roadmap
- 🗂 [Issue #1 — نسخه تعاملی گزارش](https://github.com/hassann98-cmd/optisnap/issues/1)

خلاصه مشکلات شناخته‌شده باقی‌مانده:

- ✅ مشکلات بحرانی/مهم (XSS، Path Traversal، نشت temp file، خرابی فایل، race condition، عبور از Policy) در **3.9.1** رفع شدند.
- 📝 باقی‌مانده در Roadmap: i18n، تست واحد، ریدایرکت لینک‌های قدیمی، بهینه‌سازی کوئری‌های فروشگاه‌های بزرگ.

## 📄 مجوز

[GPL-2.0-or-later](LICENSE) — مطابق الزام مخزن افزونه‌های وردپرس.
باینری‌های `cwebp`/`avifenc` از پروژه‌های متن‌باز [libwebp](https://github.com/webmproject/libwebp) و [libavif](https://github.com/AOMediaCodec/libavif) (Apache-2.0) هستند.
