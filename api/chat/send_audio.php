<?php
/**
 * API: إرسال تسجيل صوتي إلى الدردشة الجماعية
 */

define('ACCESS_ALLOWED', true);

error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

try {
    require_once __DIR__ . '/../../includes/config.php';
    require_once __DIR__ . '/../../includes/chat.php';
} catch (Throwable $e) {
    error_log('chat/send_audio bootstrap error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Initialization error: ' . $e->getMessage()]);
    exit;
}

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $currentUser = getCurrentUser();
    $userId = (int) $currentUser['id'];

    if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('لم يتم رفع الملف بشكل صحيح');
    }

    $audioFile = $_FILES['audio'];
    $replyTo = isset($_POST['reply_to']) && $_POST['reply_to'] ? (int) $_POST['reply_to'] : null;

    // Validate file type
    $allowedTypes = ['audio/webm', 'audio/ogg', 'audio/wav', 'audio/mpeg', 'audio/mp3'];
    $fileType = mime_content_type($audioFile['tmp_name']);
    
    if (!in_array($fileType, $allowedTypes, true)) {
        throw new InvalidArgumentException('نوع الملف غير مدعوم. يرجى استخدام ملف صوتي.');
    }

    // Validate file size (50MB max)
    $maxSize = 50 * 1024 * 1024;
    if ($audioFile['size'] > $maxSize) {
        throw new InvalidArgumentException('حجم الملف كبير جداً. الحد الأقصى 50 ميجابايت');
    }

    // Create uploads directory if it doesn't exist
    $uploadDir = __DIR__ . '/../../uploads/chat/audio/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Generate unique filename
    $extension = pathinfo($audioFile['name'], PATHINFO_EXTENSION) ?: 'webm';
    $filename = uniqid('audio_', true) . '.' . $extension;
    $filepath = $uploadDir . $filename;

    // Move uploaded file
    if (!move_uploaded_file($audioFile['tmp_name'], $filepath)) {
        throw new RuntimeException('فشل في حفظ الملف');
    }

    // Create relative URL
    $relativeUrl = 'uploads/chat/audio/' . $filename;
    $messageText = '[تسجيل صوتي]';

    // Send message with audio attachment
    $db = db();
    $connection = getDB();

    $db->beginTransaction();

    try {
        $result = $db->execute(
            "INSERT INTO messages (user_id, message_text, reply_to) VALUES (?, ?, ?)",
            [
                $userId,
                $messageText,
                $replyTo ?: null,
            ]
        );

        $messageId = (int) $result['insert_id'];

        // Store file info in message_attachments table if it exists, or in a custom field
        // For now, we'll store the URL in message_text or create a simple attachment system
        $db->execute(
            "UPDATE messages SET message_text = ? WHERE id = ?",
            [$messageText . "\n[FILE:" . $relativeUrl . "]", $messageId]
        );

        $db->commit();

        $message = getChatMessageById($messageId, $userId);
        markMessageAsRead($messageId, $userId);

        echo json_encode([
            'success' => true,
            'data' => $message,
        ]);
    } catch (Throwable $e) {
        $db->rollback();
        // Clean up uploaded file
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        throw $e;
    }
} catch (InvalidArgumentException $invalid) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $invalid->getMessage()]);
} catch (Throwable $e) {
    error_log('chat/send_audio error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}

