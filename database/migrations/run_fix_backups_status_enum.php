<?php
/**
 * تنفيذ Migration: إصلاح ENUM status في جدول backups
 * 
 * هذا السكريبت يقوم بتحديث جدول backups لإضافة 'completed' كقيمة مسموحة في حقل status
 * لحل مشكلة عرض حالة النسخ الاحتياطية كـ "فشل" رغم نجاحها
 */

// منع الوصول المباشر من المتصفح (السماح فقط من CLI أو من ملفات النظام)
if (php_sapi_name() !== 'cli' && !defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed. Run this script from CLI or include it from a system file.');
}

// تحميل ملفات الإعدادات
if (!defined('ACCESS_ALLOWED')) {
    define('ACCESS_ALLOWED', true);
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

try {
    echo "بدء تنفيذ Migration: fix_backups_status_enum\n";
    echo "==========================================\n\n";
    
    $db = db();
    
    // 1. تحديث تعريف العمود status
    echo "1. تحديث تعريف العمود status...\n";
    $db->execute("
        ALTER TABLE `backups` 
        MODIFY COLUMN `status` ENUM('success','failed','completed') DEFAULT 'success'
    ");
    echo "   ✓ تم تحديث تعريف العمود بنجاح\n\n";
    
    // 2. تحديث السجلات القديمة
    echo "2. تحديث السجلات القديمة...\n";
    $result = $db->execute("
        UPDATE `backups` 
        SET `status` = 'success' 
        WHERE `status` IS NULL 
          AND `file_size` > 0 
          AND `filename` != ''
    ");
    
    $affectedRows = $result['affected_rows'] ?? 0;
    echo "   ✓ تم تحديث {$affectedRows} سجل\n\n";
    
    // 3. التحقق من النتائج
    echo "3. التحقق من النتائج...\n";
    $stats = $db->queryOne("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count
        FROM backups
    ");
    
    echo "   إجمالي النسخ الاحتياطية: {$stats['total']}\n";
    echo "   - نجحت (success): {$stats['success_count']}\n";
    echo "   - فشلت (failed): {$stats['failed_count']}\n";
    echo "   - مكتملة (completed): {$stats['completed_count']}\n\n";
    
    echo "==========================================\n";
    echo "✓ تم تنفيذ Migration بنجاح!\n";
    
    if (php_sapi_name() === 'cli') {
        exit(0);
    }
    
} catch (Exception $e) {
    echo "✗ خطأ في تنفيذ Migration:\n";
    echo "  " . $e->getMessage() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";
    
    if (php_sapi_name() === 'cli') {
        exit(1);
    } else {
        throw $e;
    }
}
