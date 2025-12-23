<?php
/**
 * Script لإغلاق الاتصالات المعلقة القديمة (أكثر من 5 دقائق)
 * يجب تشغيله بحذر - سيغلق الاتصالات النشطة
 */

define('ACCESS_ALLOWED', true);

header('Content-Type: application/json; charset=utf-8');

// التحقق من الصلاحيات (يمكن تخصيصه)
$allowedToCleanup = false;

// التحقق من وجود token أو أي آلية أمان أخرى
if (isset($_GET['token']) && $_GET['token'] === 'cleanup_db_connections_2024') {
    $allowedToCleanup = true;
}

if (!$allowedToCleanup) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Access denied. Provide valid token.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    
    $db = db();
    $connection = $db->getConnection();
    
    // جلب الاتصالات المعلقة (Sleep) التي تستغرق أكثر من 5 دقائق (300 ثانية)
    $connections = $connection->query("
        SELECT Id as connection_id, User as username, Time as time_seconds
        FROM information_schema.PROCESSLIST
        WHERE User = '" . $connection->real_escape_string(DB_USER) . "'
        AND Command = 'Sleep'
        AND Time > 300
        AND Id != CONNECTION_ID()
    ");
    
    $killedConnections = [];
    $errors = [];
    
    if ($connections instanceof mysqli_result) {
        while ($row = $connections->fetch_assoc()) {
            $connectionId = (int)$row['connection_id'];
            try {
                $killResult = $connection->query("KILL " . $connectionId);
                if ($killResult) {
                    $killedConnections[] = [
                        'connection_id' => $connectionId,
                        'time_seconds' => (int)$row['time_seconds']
                    ];
                } else {
                    $errors[] = "Failed to kill connection ID: " . $connectionId;
                }
            } catch (Exception $e) {
                $errors[] = "Error killing connection ID {$connectionId}: " . $e->getMessage();
            }
        }
        $connections->free();
    }
    
    $response = [
        'success' => true,
        'killed_connections' => count($killedConnections),
        'connections' => $killedConnections,
        'errors' => $errors
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error cleaning up connections: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

