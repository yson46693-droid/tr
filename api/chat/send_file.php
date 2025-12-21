<?php
/**
 * API: إرسال مرفق (ملف/صورة/فيديو) إلى الدردشة الجماعية
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
    error_log('chat/send_file bootstrap error: ' . $e->getMessage());
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

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('لم يتم رفع الملف بشكل صحيح');
    }

    $file = $_FILES['file'];
    $replyTo = isset($_POST['reply_to']) && $_POST['reply_to'] ? (int) $_POST['reply_to'] : null;

    // Validate file size (50MB max)
    $maxSize = 50 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        throw new InvalidArgumentException('حجم الملف كبير جداً. الحد الأقصى 50 ميجابايت');
    }

    // Determine file type and directory
    $fileType = mime_content_type($file['tmp_name']);
    $isImage = strpos($fileType, 'image/') === 0;
    $isVideo = strpos($fileType, 'video/') === 0;
    $isAudio = strpos($fileType, 'audio/') === 0;

    if ($isImage) {
        $uploadDir = __DIR__ . '/../../uploads/chat/images/';
        $messageText = '[صورة]';
    } elseif ($isVideo) {
        $uploadDir = __DIR__ . '/../../uploads/chat/videos/';
        $messageText = '[فيديو]';
    } elseif ($isAudio) {
        $uploadDir = __DIR__ . '/../../uploads/chat/audio/';
        $messageText = '[ملف صوتي]';
    } else {
        $uploadDir = __DIR__ . '/../../uploads/chat/files/';
        $messageText = '[مرفق]';
    }

    // Create upload directory if it doesn't exist
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'bin';
    $filename = uniqid('file_', true) . '.' . $extension;
    $filepath = $uploadDir . $filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new RuntimeException('فشل في حفظ الملف');
    }

    // Create relative URL
    $relativeUrl = 'uploads/chat/' . ($isImage ? 'images/' : ($isVideo ? 'videos/' : ($isAudio ? 'audio/' : 'files/'))) . $filename;

    // Send message with file attachment
    $db = db();
    $connection = getDB();

    $db->beginTransaction();

    try {
        $result = $db->execute(
            "INSERT INTO messages (user_id, message_text, reply_to) VALUES (?, ?, ?)",
            [
                $userId,
                $messageText . "\n[FILE:" . $relativeUrl . ":" . htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8') . "]",
                $replyTo ?: null,
            ]
        );

        $messageId = (int) $result['insert_id'];
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
    error_log('chat/send_file error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}

