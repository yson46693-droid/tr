<?php
/**
 * واجهة دردشة جماعية شبيهة بـ Signal
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/chat.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireRole(['manager', 'production', 'sales', 'accountant', 'developer']);

$currentUser = getCurrentUser();
$currentUserId = (int) ($currentUser['id'] ?? 0);
$currentUserName = $currentUser['full_name'] ?? ($currentUser['username'] ?? 'عضو');
$currentUserRole = $currentUser['role'] ?? 'member';

$apiBase = getRelativeUrl('api/chat');
$roomName = 'شات فريق الشركة';

$chatCssRelative = 'assets/css/chat.css';
$chatJsRelative = 'assets/js/chat.js';
$chatCssUrl = getRelativeUrl($chatCssRelative);
$chatJsUrl = getRelativeUrl($chatJsRelative);

$chatCssContent = '';
$chatCssFile = __DIR__ . '/../../' . $chatCssRelative;
if (file_exists($chatCssFile) && is_readable($chatCssFile)) {
    $chatCssContent = trim((string) file_get_contents($chatCssFile));
}

$chatJsContent = '';
$chatJsFile = __DIR__ . '/../../' . $chatJsRelative;
if (file_exists($chatJsFile) && is_readable($chatJsFile)) {
    $chatJsContent = trim((string) file_get_contents($chatJsFile));
}

$onlineUsers = getActiveUsers();
$onlineCount = 0;
foreach ($onlineUsers as $onlineUser) {
    if (!empty($onlineUser['is_online'])) {
        $onlineCount++;
    }
}
$membersCount = count($onlineUsers);
?>

<?php if ($chatCssContent !== ''): ?>
<style><?php echo $chatCssContent; ?></style>
<?php else: ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($chatCssUrl, ENT_QUOTES, 'UTF-8'); ?>">
<?php endif; ?>

<div class="chat-app" dir="rtl" data-chat-app
     data-current-user-id="<?php echo $currentUserId; ?>"
     data-current-user-name="<?php echo htmlspecialchars($currentUserName, ENT_QUOTES, 'UTF-8'); ?>"
     data-current-user-role="<?php echo htmlspecialchars($currentUserRole, ENT_QUOTES, 'UTF-8'); ?>">
    <button class="chat-sidebar-toggle" type="button" data-chat-sidebar-toggle aria-label="تبديل قائمة الأعضاء">
        <span class="chat-sidebar-toggle-icon">☰</span>
    </button>
    <div class="chat-sidebar-overlay" data-chat-sidebar-overlay></div>
    <aside class="chat-sidebar" data-chat-sidebar>
        <div class="chat-sidebar-header">
            <h2>الأعضاء</h2>
            <span class="chat-loading">تحديث</span>
        </div>
        <div class="chat-sidebar-search">
            <input type="search" placeholder="ابحث عن عضو..." data-chat-search>
        </div>
        <div class="chat-user-list" data-chat-users>
            <!-- سيتم تعبئته عبر JavaScript -->
        </div>
    </aside>
    <main class="chat-main">
        <header class="chat-header">
            <div class="chat-header-left">
                <h1><?php echo htmlspecialchars($roomName, ENT_QUOTES, 'UTF-8'); ?></h1>
                <span data-chat-count><?php echo $onlineCount; ?> متصل / <?php echo $membersCount; ?> أعضاء</span>
            </div>
            <div class="chat-header-actions">
                <button class="chat-button chat-members-toggle" type="button" data-chat-members-toggle aria-label="عرض الأعضاء">
                    <span class="chat-members-icon">👥</span>
                    <span class="chat-members-text">الأعضاء</span>
                </button>
                <button class="chat-button chat-theme-toggle" type="button" data-chat-theme-toggle aria-label="تبديل الوضع الليلي">
                    <span class="chat-theme-icon">🌙</span>
                    <span class="chat-theme-text">الوضع الليلي</span>
                </button>
            </div>
        </header>
        <section class="chat-messages" data-chat-messages>
            <div class="chat-empty-state" data-chat-empty>
                <h3>ابدأ المحادثة الآن</h3>
                <p>شارك فريقك آخر المستجدات، إرسال الرسائل يتم تحديثه فورياً مع ظهور إشعارات عند وصول أي رسالة جديدة.</p>
            </div>
        </section>
        <footer class="chat-composer" data-chat-composer>
            <div class="chat-reply-bar" data-chat-reply>
                <div class="chat-reply-info">
                    <strong data-chat-reply-name></strong>
                    <span data-chat-reply-text></span>
                </div>
                <button class="chat-reply-dismiss" type="button" data-chat-reply-dismiss>&times;</button>
            </div>
            <div class="chat-input-wrapper">
                <textarea
                    class="chat-input"
                    data-chat-input
                    rows="1"
                    placeholder="اكتب رسالة ودية..."
                    autocomplete="off"></textarea>
                <div class="chat-composer-actions">
                    <button class="chat-icon-button chat-send-button" type="button" title="إرسال" data-chat-send aria-label="إرسال الرسالة">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="22" y1="2" x2="11" y2="13"></line>
                            <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                        </svg>
                    </button>
                </div>
            </div>
        </footer>
        <div class="chat-toast" data-chat-toast>تم تحديث الدردشة</div>
    </main>
</div>
<script>
    window.CHAT_API_BASE = '<?php echo htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8'); ?>';
</script>
<?php if ($chatJsContent !== ''): ?>
<script><?php echo $chatJsContent; ?></script>
<?php else: ?>
<script src="<?php echo htmlspecialchars($chatJsUrl, ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<?php endif; ?>

