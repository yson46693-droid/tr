<?php
/**
 * db_backup.php
 * سكربت لعمل نسخة احتياطية من قاعدة بيانات MySQL (هيكل + بيانات)
 *
 * الاستخدام:
 * - ضع هذا الملف في مكان آمن على السيرفر.
 * - يمكن استخدامه يدوياً أو تلقائياً عبر النظام
 * - شغِّل عبر CLI أو كرون أو من المتصفح (يفضل CLI).
 *
 * المخرجات:
 * - ملف SQL مضغوط gzip داخل مجلد backups/ باسم: backup_<dbname>_YYYY-MM-DD_HH-MM-SS.sql.gz
 *
 * ملاحظة: السكربت يحاول استخدام mysqldump أولاً (إن وُجد)، وإلا يستخدم PDO لتصدير.
 */

set_time_limit(0);
date_default_timezone_set('Africa/Cairo'); // ضبط المنطقة الزمنية للمستخدم

/**
 * دالة لإنشاء نسخة احتياطية باستخدام bk.php
 * يمكن استدعاؤها من backup.php أو من أي مكان آخر في النظام
 * 
 * @param string|null $backupDir مجلد النسخ الاحتياطي (اختياري)
 * @param bool $exportStructureOnly تصدير الهيكل فقط بدون بيانات (افتراضي: false)
 * @param bool $useMysqldumpIfAvailable استخدام mysqldump إن كان متاحاً (افتراضي: true)
 * @return array|false مصفوفة تحتوي على ['success' => true, 'file_path' => ..., 'filename' => ...] أو false في حالة الفشل
 */
if (!function_exists('createBackupUsingBkScript')) {
    function createBackupUsingBkScript($backupDir = null, $exportStructureOnly = false, $useMysqldumpIfAvailable = true) {
        set_time_limit(0);
        
        // ---------- إعدادات الاتصال (من config.php) ----------
        if (file_exists(__DIR__ . '/includes/config.php')) {
            if (!defined('ACCESS_ALLOWED')) {
                define('ACCESS_ALLOWED', true);
            }
            require_once __DIR__ . '/includes/config.php';
            
            $dbHost = defined('DB_HOST') ? DB_HOST : 'sql110.infinityfree.com';
            $dbPort = defined('DB_PORT') ? DB_PORT : '3306';
            $dbName = defined('DB_NAME') ? DB_NAME : 'if0_40278066_co_db';
            $dbUser = defined('DB_USER') ? DB_USER : 'if0_40278066';
            $dbPass = defined('DB_PASS') ? DB_PASS : 'Osama744';
        } else {
            $dbHost = 'sql110.infinityfree.com';
            $dbPort = '3306';
            $dbName = 'if0_40278066_co_db';
            $dbUser = 'if0_40278066';
            $dbPass = 'Osama744';
        }
        
        // تحديد مجلد النسخ الاحتياطي
        if ($backupDir === null) {
            $backupDir = defined('BASE_PATH') ? BASE_PATH . '/backups' : __DIR__ . '/backups';
        }
        
        // إنشاء مجلد النسخ الاحتياطي إن لم يكن موجودًا
        if (!is_dir($backupDir)) {
            if (!mkdir($backupDir, 0755, true)) {
                return ['success' => false, 'message' => "فشل في إنشاء مجلد النسخ الاحتياطي: $backupDir"];
            }
        }
        
        // اسم الملف
        $ts = date('Y-m-d_H-i-s');
        $filenameSql = "backup_{$dbName}_{$ts}.sql";
        $filenameGz  = $filenameSql . '.gz';
        $fullPathGz  = $backupDir . DIRECTORY_SEPARATOR . $filenameGz;
        
        // محاولة mysqldump أولاً
        if ($useMysqldumpIfAvailable && function_exists('exec')) {
            $whichCmd = (stripos(PHP_OS, 'WIN') === 0) ? 'where mysqldump' : 'which mysqldump';
            @exec($whichCmd, $out, $ret);
            $mysqldumpPath = ($ret === 0 && !empty($out)) ? trim($out[0]) : null;
            
            if ($mysqldumpPath) {
                $structureOnlyFlag = $exportStructureOnly ? '--no-data' : '';
                $cmd = escapeshellcmd($mysqldumpPath)
                    . " --host=" . escapeshellarg($dbHost)
                    . " --port=" . escapeshellarg($dbPort)
                    . " --user=" . escapeshellarg($dbUser)
                    . " --password=" . escapeshellarg($dbPass)
                    . " --routines --triggers --events --single-transaction --quick --hex-blob "
                    . " $structureOnlyFlag "
                    . " " . escapeshellarg($dbName)
                    . " 2>&1";
                
                $tmpSql = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filenameSql;
                $cmdOut = $cmd . " > " . escapeshellarg($tmpSql);
                
                exec($cmdOut, $dumpOut, $dumpRet);
                if ($dumpRet === 0 && file_exists($tmpSql)) {
                    $fpIn = fopen($tmpSql, 'rb');
                    if ($fpIn === false) {
                        return ['success' => false, 'message' => "فشل في فتح الملف المؤقت للتصدير."];
                    }
                    $fpOut = gzopen($fullPathGz, 'wb9');
                    if ($fpOut === false) {
                        fclose($fpIn);
                        return ['success' => false, 'message' => "فشل في إنشاء الملف المضغوط: $fullPathGz"];
                    }
                    while (!feof($fpIn)) {
                        $chunk = fread($fpIn, 1024 * 512);
                        gzwrite($fpOut, $chunk);
                    }
                    fclose($fpIn);
                    gzclose($fpOut);
                    unlink($tmpSql);
                    
                    return [
                        'success' => true,
                        'file_path' => $fullPathGz,
                        'filename' => $filenameGz,
                        'method' => 'mysqldump'
                    ];
                }
            }
        }
        
        // طريقة PDO (تصدير عبر PHP)
        try {
            $dsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ]);
        } catch (Exception $e) {
            return ['success' => false, 'message' => "فشل الاتصال بقاعدة البيانات: " . $e->getMessage()];
        }
        
        // ملف مؤقت نصي
        $tmpSql = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filenameSql;
        $fh = fopen($tmpSql, 'w');
        if (!$fh) {
            return ['success' => false, 'message' => "فشل في إنشاء الملف المؤقت: $tmpSql"];
        }
        
        // رأس الملف
        fwrite($fh, "-- Backup created by db_backup.php\n");
        fwrite($fh, "-- Database: {$dbName}\n");
        fwrite($fh, "-- Generated: " . date('Y-m-d H:i:s') . " (Africa/Cairo)\n\n");
        fwrite($fh, "SET NAMES utf8mb4;\n");
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n\n");
        
        // جلب قائمة الجداول
        $tables = [];
        $stmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        
        // أيضاً جلب views
        $views = [];
        $stmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $views[] = $row[0];
        }
        
        // تصدير كل جدول (CREATE + بيانات)
        foreach ($tables as $table) {
            $cr = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
            $createSql = $cr['Create Table'] ?? $cr['Create View'] ?? null;
            if ($createSql) {
              
            }
            
            if (!$exportStructureOnly) {
                // تصدير البيانات (INSERTs)
                $colStmt = $pdo->query("DESCRIBE `{$table}`");
                $cols = [];
                while ($c = $colStmt->fetch(PDO::FETCH_ASSOC)) {
                    $cols[] = "`" . $c['Field'] . "`";
                }
                $colList = implode(', ', $cols);
                
                $rowCountStmt = $pdo->query("SELECT COUNT(*) AS c FROM `{$table}`");
                $rowCount = (int)$rowCountStmt->fetch(PDO::FETCH_ASSOC)['c'];
                if ($rowCount === 0) {
                    fwrite($fh, "-- Empty table `{$table}`\n\n");
                    continue;
                }
                
                $batchSize = 500;
                $offset = 0;
                $select = $pdo->prepare("SELECT * FROM `{$table}` LIMIT :lim OFFSET :off");
                while ($offset < $rowCount) {
                    $select->bindValue(':lim', (int)$batchSize, PDO::PARAM_INT);
                    $select->bindValue(':off', (int)$offset, PDO::PARAM_INT);
                    $select->execute();
                    $rows = $select->fetchAll(PDO::FETCH_ASSOC);
                    if (!$rows) break;
                    
                    $values = [];
                    foreach ($rows as $r) {
                        $escaped = [];
                        foreach ($r as $v) {
                            if ($v === null) {
                                $escaped[] = "NULL";
                            } else {
                                $escaped[] = $pdo->quote($v);
                            }
                        }
                        $values[] = "(" . implode(", ", $escaped) . ")";
                    }
                    
                    fwrite($fh, "LOCK TABLES `{$table}` WRITE;\n");
                    fwrite($fh, "/*!40000 ALTER TABLE `{$table}` DISABLE KEYS */;\n");
                    fwrite($fh, "INSERT INTO `{$table}` ({$colList}) VALUES\n");
                    fwrite($fh, implode(",\n", $values) . ";\n");
                    fwrite($fh, "/*!40000 ALTER TABLE `{$table}` ENABLE KEYS */;\n");
                    fwrite($fh, "UNLOCK TABLES;\n\n");
                    
                    $offset += $batchSize;
                }
            }
        }
        
        // تصدير الـ views
        foreach ($views as $view) {
            $cr = $pdo->query("SHOW CREATE VIEW `{$view}`")->fetch(PDO::FETCH_ASSOC);
            if (!empty($cr['Create View'])) {
                fwrite($fh, "-- --------------------------------------------------------\n");
                fwrite($fh, "-- View structure for view `{$view}`\n");
                fwrite($fh, "-- --------------------------------------------------------\n\n");
                fwrite($fh, "DROP VIEW IF EXISTS `{$view}`;\n");
                fwrite($fh, $cr['Create View'] . ";\n\n");
            }
        }
        
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);
        
        // ضغط الملف المؤقت إلى gzip النهائي
        $fpIn = fopen($tmpSql, 'rb');
        $fpOut = gzopen($fullPathGz, 'wb9');
        if ($fpIn && $fpOut) {
            while (!feof($fpIn)) {
                gzwrite($fpOut, fread($fpIn, 1024 * 512));
            }
            fclose($fpIn);
            gzclose($fpOut);
            @unlink($tmpSql);
            
            return [
                'success' => true,
                'file_path' => $fullPathGz,
                'filename' => $filenameGz,
                'method' => 'pdo'
            ];
        } else {
            if ($fpIn) fclose($fpIn);
            if ($fpOut) gzclose($fpOut);
            return ['success' => false, 'message' => "فشل أثناء ضغط الملف إلى gzip."];
        }
    }
}

// ---------- الكود التنفيذي عند الاستدعاء المباشر (CLI أو المتصفح) ----------
// يتم تنفيذ هذا الكود فقط عند استدعاء الملف مباشرة وليس كدالة
if (php_sapi_name() === 'cli' || !function_exists('createDatabaseBackup')) {
    // إعدادات الاتصال
    if (file_exists(__DIR__ . '/includes/config.php')) {
        if (!defined('ACCESS_ALLOWED')) {
            define('ACCESS_ALLOWED', true);
        }
        require_once __DIR__ . '/includes/config.php';
        
        $dbHost = defined('DB_HOST') ? DB_HOST : 'sql110.infinityfree.com';
        $dbPort = defined('DB_PORT') ? DB_PORT : '3306';
        $dbName = defined('DB_NAME') ? DB_NAME : 'if0_40278066_co_db';
        $dbUser = defined('DB_USER') ? DB_USER : 'if0_40278066';
        $dbPass = defined('DB_PASS') ? DB_PASS : 'Osama744';
    } else {
        $dbHost = 'sql110.infinityfree.com';
        $dbPort = '3306';
        $dbName = 'if0_40278066_co_db';
        $dbUser = 'if0_40278066';
        $dbPass = 'Osama744';
    }
    
    // إعدادات التصدير
    $exportStructureOnly = false;
    $backupDir = defined('BASE_PATH') ? BASE_PATH . '/backups' : __DIR__ . '/backups';
    $useMysqldumpIfAvailable = true;
    
    // إنشاء مجلد النسخ الاحتياطي
    if (!is_dir($backupDir)) {
        if (!mkdir($backupDir, 0755, true)) {
            die("فشل في إنشاء مجلد النسخ الاحتياطي: $backupDir\n");
        }
    }
    
    // استدعاء الدالة
    $result = createBackupUsingBkScript($backupDir, $exportStructureOnly, $useMysqldumpIfAvailable);
    
    if ($result && $result['success']) {
        echo "النسخة الاحتياطية (" . ($result['method'] ?? 'unknown') . ") حفظت: " . $result['file_path'] . "\n";
        exit(0);
    } else {
        echo "فشل إنشاء النسخة الاحتياطية: " . ($result['message'] ?? 'سبب غير معروف') . "\n";
        exit(1);
    }
}

// ---------- محاولة mysqldump أولاً ----------
if ($useMysqldumpIfAvailable && function_exists('exec')) {
    // نبحث عن mysqldump في المسار
    $whichCmd = (stripos(PHP_OS, 'WIN') === 0) ? 'where mysqldump' : 'which mysqldump';
    @exec($whichCmd, $out, $ret);
    $mysqldumpPath = ($ret === 0 && !empty($out)) ? trim($out[0]) : null;

    if ($mysqldumpPath) {
        echo "استخدام mysqldump الموجود عند: $mysqldumpPath\n";

        // بناء أمر mysqldump
        $structureOnlyFlag = $exportStructureOnly ? '--no-data' : '';
        // نضمن شاملة الإجراءات، التريجرات، الإعدادات
        $cmd = escapeshellcmd($mysqldumpPath)
            . " --host=" . escapeshellarg($dbHost)
            . " --port=" . escapeshellarg($dbPort)
            . " --user=" . escapeshellarg($dbUser)
            . " --password=" . escapeshellarg($dbPass)
            . " --routines --triggers --events --single-transaction --quick --hex-blob "
            . " $structureOnlyFlag "
            . " " . escapeshellarg($dbName)
            . " 2>&1";

        // نجري التفريغ إلى ملف مؤقت غير مضغوط ثم نضغطه
        $tmpSql = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filenameSql;
        $cmdOut = $cmd . " > " . escapeshellarg($tmpSql);

        exec($cmdOut, $dumpOut, $dumpRet);
        if ($dumpRet === 0 && file_exists($tmpSql)) {
            // ضغط الملف
            $fpIn = fopen($tmpSql, 'rb');
            if ($fpIn === false) {
                die("فشل في فتح الملف المؤقت للتصدير.\n");
            }
            $fpOut = gzopen($fullPathGz, 'wb9');
            if ($fpOut === false) {
                fclose($fpIn);
                die("فشل في إنشاء الملف المضغوط: $fullPathGz\n");
            }
            while (!feof($fpIn)) {
                $chunk = fread($fpIn, 1024 * 512);
                gzwrite($fpOut, $chunk);
            }
            fclose($fpIn);
            gzclose($fpOut);
            unlink($tmpSql);
            echo "النسخة الاحتياطية (بـ mysqldump) حفظت: $fullPathGz\n";
            exit(0);
        } else {
            echo "فشل mysqldump أو لم يعد 0. سيتم المحاولة بطريقة PHP. مخرجات mysqldump:\n";
            echo implode("\n", $dumpOut) . "\n";
        }
    } else {
        echo "mysqldump غير متوفر على السيرفر — سيتم استخدام طريقة PDO (PHP) للتصدير.\n";
    }
}

// ---------- طريقة PDO (تصدير عبر PHP) ----------
echo "استخدام طريقة PHP (PDO) للتصدير...\n";

try {
    $dsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
    ]);
} catch (Exception $e) {
    die("فشل الاتصال بقاعدة البيانات: " . $e->getMessage() . "\n");
}

// ملف مؤقت نصي
$tmpSql = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filenameSql;
$fh = fopen($tmpSql, 'w');
if (!$fh) {
    die("فشل في إنشاء الملف المؤقت: $tmpSql\n");
}

// رأس الملف
fwrite($fh, "-- Backup created by db_backup.php\n");
fwrite($fh, "-- Database: {$dbName}\n");
fwrite($fh, "-- Generated: " . date('Y-m-d H:i:s') . " (Africa/Cairo)\n\n");
fwrite($fh, "SET NAMES utf8mb4;\n");
fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n\n");

// جلب قائمة الجداول
$tables = [];
$stmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    $tables[] = $row[0];
}

// أيضاً جلب views
$views = [];
$stmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'");
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    $views[] = $row[0];
}

// تصدير كل جدول (CREATE + بيانات)
foreach ($tables as $table) {
    echo "تصدير هيكل الجدول: $table\n";
    $cr = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
    $createSql = $cr['Create Table'] ?? $cr['Create View'] ?? null;
    if ($createSql) {
        fwrite($fh, "-- --------------------------------------------------------\n");
        fwrite($fh, "-- Table structure for table `{$table}`\n");
        fwrite($fh, "-- --------------------------------------------------------\n\n");
        fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n");
        fwrite($fh, $createSql . ";\n\n");
    }

    if (!$exportStructureOnly) {
        // تصدير البيانات (INSERTs) — نحاول دفعات لتحسين الأداء
        echo "تصدير بيانات الجدول: $table\n";
        $colStmt = $pdo->query("DESCRIBE `{$table}`");
        $cols = [];
        while ($c = $colStmt->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = "`" . $c['Field'] . "`";
        }
        $colList = implode(', ', $cols);

        $rowCountStmt = $pdo->query("SELECT COUNT(*) AS c FROM `{$table}`");
        $rowCount = (int)$rowCountStmt->fetch(PDO::FETCH_ASSOC)['c'];
        if ($rowCount === 0) {
            fwrite($fh, "-- Empty table `{$table}`\n\n");
            continue;
        }

        $batchSize = 500; // عدد الصفوف في كل INSERT
        $offset = 0;
        $select = $pdo->prepare("SELECT * FROM `{$table}` LIMIT :lim OFFSET :off");
        while ($offset < $rowCount) {
            $select->bindValue(':lim', (int)$batchSize, PDO::PARAM_INT);
            $select->bindValue(':off', (int)$offset, PDO::PARAM_INT);
            $select->execute();
            $rows = $select->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) break;

            $values = [];
            foreach ($rows as $r) {
                $escaped = [];
                foreach ($r as $v) {
                    if ($v === null) {
                        $escaped[] = "NULL";
                    } else {
                        // استعمل PDO->quote لحماية النصوص
                        $escaped[] = $pdo->quote($v);
                    }
                }
                $values[] = "(" . implode(", ", $escaped) . ")";
            }

            fwrite($fh, "LOCK TABLES `{$table}` WRITE;\n");
            fwrite($fh, "/*!40000 ALTER TABLE `{$table}` DISABLE KEYS */;\n");
            fwrite($fh, "INSERT INTO `{$table}` ({$colList}) VALUES\n");
            fwrite($fh, implode(",\n", $values) . ";\n");
            fwrite($fh, "/*!40000 ALTER TABLE `{$table}` ENABLE KEYS */;\n");
            fwrite($fh, "UNLOCK TABLES;\n\n");

            $offset += $batchSize;
            // تفريغ الذاكرة إن احتاج
        }
    }
}

// تصدير الـ views
foreach ($views as $view) {
    echo "تصدير view: $view\n";
    $cr = $pdo->query("SHOW CREATE VIEW `{$view}`")->fetch(PDO::FETCH_ASSOC);
    if (!empty($cr['Create View'])) {
        fwrite($fh, "-- --------------------------------------------------------\n");
        fwrite($fh, "-- View structure for view `{$view}`\n");
        fwrite($fh, "-- --------------------------------------------------------\n\n");
        fwrite($fh, "DROP VIEW IF EXISTS `{$view}`;\n");
        fwrite($fh, $cr['Create View'] . ";\n\n");
    }
}

fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
fclose($fh);

// ضغط الملف المؤقت إلى gzip النهائي
$fpIn = fopen($tmpSql, 'rb');
$fpOut = gzopen($fullPathGz, 'wb9');
if ($fpIn && $fpOut) {
    while (!feof($fpIn)) {
        gzwrite($fpOut, fread($fpIn, 1024 * 512));
    }
    fclose($fpIn);
    gzclose($fpOut);
    // حذف المؤقت
    @unlink($tmpSql);
    echo "النسخة الاحتياطية (بـ PHP) حفظت: $fullPathGz\n";
} else {
    echo "فشل أثناء ضغط الملف إلى gzip.\n";
    if ($fpIn) fclose($fpIn);
    if ($fpOut) gzclose($fpOut);
    exit(1);
}

exit(0);
