<?php
// پاک‌سازی تنظیمات هنگام حذف افزونه (متاهای تصاویر عمداً حفظ می‌شوند تا پردازش مجدد رخ ندهد)
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'osn_img_settings' );
delete_option( 'osn_img_version' );
delete_option( 'osn_img_scan_data' );
delete_option( 'osn_img_bulk_state' );
delete_option( 'osn_img_migrated_v39' );
// نام‌های قدیمی مبتنی بر jluxe (اگر مهاجرت به هر دلیلی اجرا نشده بود)
delete_option( 'jlx_img_settings' );
delete_option( 'jlx_img_version' );
delete_option( 'jlx_img_scan_data' );
delete_option( 'jlx_img_bulk_state' );

// v3.9.1: حذف جدول لاگ (قبلاً جدول osn_image_logs بعد از حذف افزونه باقی می‌ماند)
global $wpdb;
$osn_table = $wpdb->prefix . 'osn_image_logs';
$wpdb->query( "DROP TABLE IF EXISTS {$osn_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
