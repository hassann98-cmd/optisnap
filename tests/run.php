<?php
/**
 * تست‌های خودکار OptiSnap — بدون نیاز به وردپرس (فقط PHP 7.4+ با GD)
 *
 * اجرا:
 *   php tests/run.php
 *
 * پوشش:
 *  - اصلاح EXIF Orientation (هر ۸ حالت — شامل Flipهای 2/4/5/7 از v3.9.2)
 *  - نام‌گذاری فایل پردازش‌شده (processed_base_name)
 *  - کراپ مربعی و محدودسازی اندازه
 *  - کیفیت هوشمند (Smart Quality) — بازه‌های مستندشده
 *  - واترمارک: موقعیت/مقیاس/شفافیت
 */

error_reporting( E_ALL );

/* ---------------------------------------------------------------------
 * Bootstrap: ثابت‌ها و Stubs حداقلی وردپرس
 * ------------------------------------------------------------------- */

define( 'ABSPATH', __DIR__ . '/' );
define( 'KB_IN_BYTES', 1024 );
define( 'MB_IN_BYTES', 1024 * 1024 );
define( 'OSN_IMG_VERSION', '3.9.2' );
define( 'OSN_IMG_SUFFIX', '-jlx' );
define( 'OSN_IMG_OPTION', 'osn_img_settings' );
define( 'OSN_IMG_META_FLAG', '_osn_optimized' );
define( 'OSN_IMG_META_ORIGINAL', '_osn_original_file' );
define( 'OSN_IMG_META_WATERMARKED', '_osn_watermarked' );
define( 'OSN_IMG_META_FILESIZE', '_osn_filesize' );
define( 'OSN_IMG_META_REPORT', '_osn_report' );
define( 'OSN_IMG_META_EXCLUDED', '_osn_excluded' );

function wp_basename( $path ) { return basename( $path ); }
function absint( $v ) { return abs( (int) $v ); }
function size_format( $bytes, $decimals = 0 ) { return round( $bytes / 1024, $decimals ) . ' KB'; }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, (array) $args ); }
function wp_tempnam( $prefix = '' ) { return tempnam( sys_get_temp_dir(), $prefix ? $prefix : 'osn' ); }
function get_option( $k, $d = false ) { return $d; }
function update_option( $k, $v, $autoload = null ) { return true; }
function add_option( $k, $v = '' ) { return true; }
function delete_option( $k ) { return true; }
function get_attached_file( $id ) { return isset( $GLOBALS['osn_test_logo'] ) ? $GLOBALS['osn_test_logo'] : false; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

function osn_img_get_settings() {
	return array(
		'webp_enabled' => 1, 'opt_mode' => 'manual', 'quality' => 80,
		'wm_logo_id'   => 1, 'wm_position' => 'bottom-right', 'wm_opacity' => 0,
		'wm_size'      => 22, 'wm_min_width' => 400, 'wm_padding' => 20,
		'wm_logo_max_px' => 250,
		'wm_on_featured' => 1, 'wm_on_gallery' => 1, 'wm_on_other' => 1,
		'wm_opacity_featured' => 0, 'wm_opacity_gallery' => 0, 'wm_opacity_other' => 0,
		'wm_size_featured' => 22, 'wm_size_gallery' => 22, 'wm_size_other' => 22,
		'debug_log' => 0,
	);
}

require_once dirname( __DIR__ ) . '/includes/class-logger.php';
require_once dirname( __DIR__ ) . '/includes/class-watermark.php';
require_once dirname( __DIR__ ) . '/includes/class-image-processor.php';

/* ---------------------------------------------------------------------
 * Test harness بسیار سبک
 * ------------------------------------------------------------------- */

$osn_passed = 0;
$osn_failed = 0;

function ok( $cond, $label ) {
	global $osn_passed, $osn_failed;
	if ( $cond ) {
		$osn_passed++;
		echo "  ✔ $label\n";
	} else {
		$osn_failed++;
		echo "  ❌ $label\n";
	}
}

function px( $im, $x, $y ) {
	$c = imagecolorat( $im, $x, $y );
	return array( ( $c >> 16 ) & 0xFF, ( $c >> 8 ) & 0xFF, $c & 0xFF );
}

function px_eq( $im, $x, $y, $rgb ) {
	$a = px( $im, $x, $y );
	return abs( $a[0] - $rgb[0] ) <= 2 && abs( $a[1] - $rgb[1] ) <= 2 && abs( $a[2] - $rgb[2] ) <= 2;
}

/** تصویر تستی: هر پیکسل رنگ یکتای خودش را دارد (x*40+8 , y*40+8 , 100) */
function make_pattern( $w, $h ) {
	$im = imagecreatetruecolor( $w, $h );
	for ( $y = 0; $y < $h; $y++ ) {
		for ( $x = 0; $x < $w; $x++ ) {
			imagesetpixel( $im, $x, $y, ( ( $x * 40 + 8 ) << 16 ) | ( ( $y * 40 + 8 ) << 8 ) | 100 );
		}
	}
	return $im;
}

function call_private( $class, $method, $args ) {
	$m = new ReflectionMethod( $class, $method );
	$m->setAccessible( true );
	return $m->invokeArgs( null, $args );
}

/* ---------------------------------------------------------------------
 * 1) processed_base_name
 * ------------------------------------------------------------------- */

echo "\n[1] processed_base_name — جلوگیری از انباشت پسوند\n";
ok( OSN_Image_Processor::processed_base_name( '/u/photo.jpg' ) === 'photo', 'photo.jpg → photo' );
ok( OSN_Image_Processor::processed_base_name( '/u/photo-jlx.webp' ) === 'photo', 'photo-jlx.webp → photo (Force)' );
ok( OSN_Image_Processor::processed_base_name( '/u/photo-2-jlx.webp' ) === 'photo', 'photo-2-jlx.webp → photo (counter)' );
ok( OSN_Image_Processor::processed_base_name( '/u/my-photo.png' ) === 'my-photo', 'my-photo.png → my-photo (پسوند واقعی حفظ می‌شود)' );

/* ---------------------------------------------------------------------
 * 2) EXIF Orientation — هر ۸ حالت
 * ------------------------------------------------------------------- */

echo "\n[2] apply_exif_transform — چرخش و Flip (EXIF 1 تا 8)\n";

$W = 6; $H = 4;

// حالت 1/نامعتبر: بدون تغییر
$im = make_pattern( $W, $H );
$out = OSN_Image_Processor::apply_exif_transform( $im, 1 );
ok( imagesx( $out ) === $W && px_eq( $out, 3, 2, px( make_pattern( $W, $H ), 3, 2 ) ), 'orientation 1: بدون تغییر' );
$im = make_pattern( $W, $H );
$out = OSN_Image_Processor::apply_exif_transform( $im, 9 );
ok( imagesx( $out ) === $W && px_eq( $out, 0, 0, px( make_pattern( $W, $H ), 0, 0 ) ), 'orientation نامعتبر: بدون تغییر' );

// 2 = Mirror horizontal: out(x,y) = in(W-1-x, y)
$im = make_pattern( $W, $H );
$out = OSN_Image_Processor::apply_exif_transform( $im, 2 );
ok( imagesx( $out ) === $W && px_eq( $out, 0, 0, px( make_pattern( $W, $H ), $W - 1, 0 ) ), 'orientation 2: آینه افقی' );

// 3 = Rotate 180: out(x,y) = in(W-1-x, H-1-y)
$im = make_pattern( $W, $H );
$out = OSN_Image_Processor::apply_exif_transform( $im, 3 );
ok( imagesx( $out ) === $W && px_eq( $out, 0, 0, px( make_pattern( $W, $H ), $W - 1, $H - 1 ) ), 'orientation 3: چرخش 180' );

// 4 = Mirror vertical: out(x,y) = in(x, H-1-y)
$im = make_pattern( $W, $H );
$out = OSN_Image_Processor::apply_exif_transform( $im, 4 );
ok( imagesx( $out ) === $W && px_eq( $out, 0, 0, px( make_pattern( $W, $H ), 0, $H - 1 ) ), 'orientation 4: آینه عمودی' );

// 5 = Transpose: out(x,y) = in(y, x) — ابعاد عوض می‌شود
$im = make_pattern( $W, $H );
$out = OSN_Image_Processor::apply_exif_transform( $im, 5 );
$pat = make_pattern( $W, $H );
ok( imagesx( $out ) === $H && imagesy( $out ) === $W, 'orientation 5: ابعاد جابه‌جا شد' );
ok( px_eq( $out, 0, 1, px( $pat, 1, 0 ) ) && px_eq( $out, 2, 3, px( $pat, 3, 2 ) ), 'orientation 5: Transpose (out(x,y)=in(y,x))' );

// 6 = Rotate 90 CW: out(x,y) = in(y, H-1-x) — ابعاد عوض می‌شود
$im = make_pattern( $W, $H );
$out = OSN_Image_Processor::apply_exif_transform( $im, 6 );
ok( imagesx( $out ) === $H && imagesy( $out ) === $W, 'orientation 6: ابعاد جابه‌جا شد' );
ok( px_eq( $out, 0, 0, px( $pat, 0, $H - 1 ) ) && px_eq( $out, 3, 2, px( $pat, 2, $H - 1 - 3 ) ), 'orientation 6: چرخش 90 ساعت‌گرد' );

// 7 = Transverse: out(x,y) = in(W-1-y, H-1-x) — ابعاد عوض می‌شود
$im = make_pattern( $W, $H );
$out = OSN_Image_Processor::apply_exif_transform( $im, 7 );
ok( imagesx( $out ) === $H && imagesy( $out ) === $W, 'orientation 7: ابعاد جابه‌جا شد' );
ok( px_eq( $out, 0, 0, px( $pat, $W - 1, $H - 1 ) ) && px_eq( $out, 3, 2, px( $pat, $W - 1 - 2, $H - 1 - 3 ) ), 'orientation 7: Transverse' );

// 8 = Rotate 270 CW: out(x,y) = in(W-1-y, x)
$im = make_pattern( $W, $H );
$out = OSN_Image_Processor::apply_exif_transform( $im, 8 );
ok( imagesx( $out ) === $H && imagesy( $out ) === $W, 'orientation 8: ابعاد جابه‌جا شد' );
ok( px_eq( $out, 0, 0, px( $pat, $W - 1, 0 ) ) && px_eq( $out, 3, 2, px( $pat, $W - 1 - 2, 3 ) ), 'orientation 8: چرخش 270 ساعت‌گرد' );

// سازگاری: 6 و 8 معکوس یکدیگرند
$im = make_pattern( $W, $H );
$back = OSN_Image_Processor::apply_exif_transform( OSN_Image_Processor::apply_exif_transform( $im, 6 ), 8 );
$same = true;
for ( $y = 0; $y < $H && $same; $y++ ) {
	for ( $x = 0; $x < $W; $x++ ) {
		if ( ! px_eq( $back, $x, $y, px( $pat, $x, $y ) ) ) { $same = false; break; }
	}
}
ok( $same, 'orientation 6 سپس 8 = تصویر اصلی (معکوس یکدیگر)' );

/* ---------------------------------------------------------------------
 * 3) کراپ مربعی و محدودسازی اندازه
 * ------------------------------------------------------------------- */

echo "\n[3] square_crop_center / constrain_size\n";
$im = imagecreatetruecolor( 800, 600 );
$sq = call_private( 'OSN_Image_Processor', 'square_crop_center', array( $im ) );
ok( imagesx( $sq ) === 600 && imagesy( $sq ) === 600, 'کراپ 800x600 → 600x600' );

$im = imagecreatetruecolor( 400, 500 );
$sq = call_private( 'OSN_Image_Processor', 'square_crop_center', array( $im ) );
ok( imagesx( $sq ) === 400 && imagesy( $sq ) === 400, 'کراپ 400x500 → 400x400' );

$im = imagecreatetruecolor( 2000, 1500 );
$sm = call_private( 'OSN_Image_Processor', 'constrain_size', array( $im, 1000 ) );
ok( imagesx( $sm ) === 1000 && imagesy( $sm ) === 750, '2000x1500 → max 1000 (حفظ نسبت = 1000x750)' );

$im = imagecreatetruecolor( 500, 400 );
$sm = call_private( 'OSN_Image_Processor', 'constrain_size', array( $im, 1000 ) );
ok( imagesx( $sm ) === 500 && imagesy( $sm ) === 400, 'تصویر کوچکتر از max دست‌نخورده می‌ماند' );

/* ---------------------------------------------------------------------
 * 4) Smart Quality — بازه‌های مستندشده
 * ------------------------------------------------------------------- */

echo "\n[4] smart_quality — بازه‌ها\n";

// تصویر ساده (پس‌زمینه یکدست) → کیفیت پایین‌تر بازه
$flat = imagecreatetruecolor( 1200, 1200 );
imagefill( $flat, 0, 0, imagecolorallocate( $flat, 240, 240, 240 ) );
$q_flat = call_private( 'OSN_Image_Processor', 'smart_quality', array( $flat, 500 * KB_IN_BYTES, 'other', osn_img_get_settings() ) );
ok( $q_flat >= 70 && $q_flat <= 85, "تصویر ساده/other: $q_flat در بازه 70-85" );

// تصویر Featured → بازه 84-90
$noise = imagecreatetruecolor( 1200, 1200 );
for ( $y = 0; $y < 1200; $y += 4 ) {
	for ( $x = 0; $x < 1200; $x += 4 ) {
		$c = imagecolorallocate( $noise, ( $x * 7 + $y * 13 ) % 256, ( $x * 13 + $y * 3 ) % 256, ( $x + $y ) % 256 );
		imagefilledrectangle( $noise, $x, $y, $x + 3, $y + 3, $c );
	}
}
$q_feat = call_private( 'OSN_Image_Processor', 'smart_quality', array( $noise, 300 * KB_IN_BYTES, 'featured', osn_img_get_settings() ) );
ok( $q_feat >= 84 && $q_feat <= 90, "نقش featured: $q_feat در بازه 84-90" );

$q_gal = call_private( 'OSN_Image_Processor', 'smart_quality', array( $noise, 300 * KB_IN_BYTES, 'gallery', osn_img_get_settings() ) );
ok( $q_gal >= 75 && $q_gal <= 82, "نقش gallery: $q_gal در بازه 75-82" );

/* ---------------------------------------------------------------------
 * 5) واترمارک — موقعیت/مقیاس
 * ------------------------------------------------------------------- */

echo "\n[5] OSN_Watermark::apply — موقعیت و مقیاس\n";

// لوگوی تستی: 100x50 آبی خالص (PNG با آلفا)
$logo_path = sys_get_temp_dir() . '/osn-test-logo.png';
$logo = imagecreatetruecolor( 100, 50 );
imagefill( $logo, 0, 0, imagecolorallocate( $logo, 0, 0, 255 ) );
imagepng( $logo, $logo_path );
$GLOBALS['osn_test_logo'] = $logo_path;

// تصویر مقصد: 800x600 قرمز خالص
$base = imagecreatetruecolor( 800, 600 );
imagefill( $base, 0, 0, imagecolorallocate( $base, 255, 0, 0 ) );

$settings = osn_img_get_settings();
$applied = OSN_Watermark::apply( $base, $settings, 'other' );
ok( true === $applied, 'واترمارک اعمال شد' );

// محاسبه مورد انتظار: target_w = min(800*22%, 250, 800*0.27) = 176 → target_h = 88
// bottom-right با padding=20: x=800-176-20=604 , y=600-88-20=492
ok( px_eq( $base, 50, 50, array( 255, 0, 0 ) ), 'گوشه بالا-چپ قرمز ماند' );
ok( px_eq( $base, 700, 540, array( 0, 0, 255 ) ), 'داخل ناحیه لوگو (bottom-right) آبی شد' );
ok( px_eq( $base, 790, 100, array( 255, 0, 0 ) ), 'بالا-راست (خارج لوگو) قرمز ماند' );

// تصویر کوچکتر از wm_min_width=400 → بدون واترمارک
$small = imagecreatetruecolor( 300, 300 );
imagefill( $small, 0, 0, imagecolorallocate( $small, 255, 0, 0 ) );
$applied = OSN_Watermark::apply( $small, $settings, 'other' );
ok( false === $applied, 'تصویر کوچک (<400px) واترمارک نمی‌خورد' );

// نقش غیرفعال
$settings2 = $settings;
$settings2['wm_on_other'] = 0;
$base2 = imagecreatetruecolor( 800, 600 );
imagefill( $base2, 0, 0, imagecolorallocate( $base2, 255, 0, 0 ) );
$applied = OSN_Watermark::apply( $base2, $settings2, 'other' );
ok( false === $applied, 'نقش غیرفعال → بدون واترمارک' );

@unlink( $logo_path );

/* ---------------------------------------------------------------------
 * 6) به‌روزرسانی URLهای محتوا (v3.10.0 - B9)
 * ------------------------------------------------------------------- */

echo "\n[6] content_replace_pairs / apply_url_pairs — به‌روزرسانی لینک‌های قدیمی\n";

$old_u = 'https://shop.example/wp-content/uploads/2025/01/photo.jpg';
$new_u = 'https://shop.example/wp-content/uploads/2025/01/photo-jlx.webp';
$pairs = OSN_Image_Processor::content_replace_pairs( $old_u, $new_u );
ok( count( $pairs ) === 3, 'سه جفت جایگزینی ساخته شد (کامل/نسبی/بدون اسلش)' );
ok( $pairs[0][0] === $old_u && $pairs[0][1] === $new_u, 'جفت اول = URL کامل' );
ok( $pairs[1][0] === '/wp-content/uploads/2025/01/photo.jpg', 'جفت دوم = مسیر نسبی سایت' );
ok( $pairs[2][0] === 'wp-content/uploads/2025/01/photo.jpg', 'جفت سوم = بدون اسلش اول' );

$content = '<img src="' . $old_u . '"><a href="/wp-content/uploads/2025/01/photo.jpg">دانلود</a> wp-content/uploads/2025/01/photo.jpg';
$updated = OSN_Image_Processor::apply_url_pairs( $content, $pairs );
ok( substr_count( $updated, 'photo-jlx.webp' ) === 3, 'هر ۳ حالت در محتوا به‌روزرسانی شد' );
ok( strpos( $updated, 'photo.jpg' ) === false, 'هیچ لینک قدیمی باقی نماند' );

// URL یکسان / خالی → بدون جفت
ok( count( OSN_Image_Processor::content_replace_pairs( $old_u, $old_u ) ) === 0, 'URL یکسان → بدون جایگزینی' );
ok( count( OSN_Image_Processor::content_replace_pairs( '', $new_u ) ) === 0, 'URL خالی → بدون جایگزینی' );

// محتوای بدون لینک قدیمی دست‌نخورده می‌ماند
$safe = '<p>متن بدون لینک</p>';
ok( OSN_Image_Processor::apply_url_pairs( $safe, $pairs ) === $safe, 'محتوای بدون URL قدیمی تغییر نمی‌کند' );

/* ---------------------------------------------------------------------
 * نتیجه نهایی
 * ------------------------------------------------------------------- */

echo "\n========================================\n";
echo "نتیجه: $osn_passed موفق ❌ $osn_failed ناموفق\n";
if ( $osn_failed > 0 ) {
	exit( 1 );
}
echo "همه تست‌ها سبز شد ✅\n";
exit( 0 );
