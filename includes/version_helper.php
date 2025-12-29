<?php
/**
 * مساعد إدارة الإصدار
 * يتحقق من التعديلات ويحدث الإصدار تلقائياً
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';

/**
 * التحقق من التعديلات وتحديث الإصدار إذا لزم الأمر
 * @param bool $forceIncrement فرض تحديث الإصدار حتى لو لم تكن هناك تعديلات
 * @return string رقم الإصدار الحالي
 */
function checkAndUpdateVersion(bool $forceIncrement = false): string {
    $versionFile = __DIR__ . '/../version.json';
    $lastCheckFile = __DIR__ . '/../storage/last_version_check.txt';
    
    // إنشاء مجلد storage إذا لم يكن موجوداً
    $storageDir = __DIR__ . '/../storage';
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0755, true);
    }
    
    // قراءة آخر hash للملفات
    $lastHash = '';
    if (file_exists($lastCheckFile)) {
        $lastHash = trim(file_get_contents($lastCheckFile));
    }
    
    // حساب hash للملفات الرئيسية
    $mainFiles = [
        __DIR__ . '/../templates/header.php',
        __DIR__ . '/../templates/footer.php',
        __DIR__ . '/../includes/config.php',
        __DIR__ . '/../index.php'
    ];
    
    $currentHash = '';
    foreach ($mainFiles as $file) {
        if (file_exists($file)) {
            $currentHash .= md5_file($file);
        }
    }
    $currentHash = md5($currentHash);
    
    // إذا تغير hash أو تم فرض التحديث، حدث الإصدار
    if ($forceIncrement || $currentHash !== $lastHash) {
        // حفظ hash الحالي
        @file_put_contents($lastCheckFile, $currentHash);
        
        // تحديث الإصدار
        if (function_exists('incrementVersionBuild')) {
            return incrementVersionBuild();
        }
    }
    
    // إرجاع الإصدار الحالي
    if (function_exists('getCurrentVersion')) {
        return getCurrentVersion();
    }
    
    return 'v1.0';
}

