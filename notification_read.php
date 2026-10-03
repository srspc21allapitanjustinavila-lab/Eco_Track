<?php

require_once 'config.php';

requireLogin();

$user = getCurrentUser();
$conn = getDBConnection();
$notificationId = (int)($_GET['id'] ?? 0);
$destination = (($user['user_type'] ?? '') === 'staff') ? 'staff_home.php' : 'admin_dashboard.php';

if ($conn && $notificationId > 0 && !empty($user['id'])) {
    try {
        ensureNotificationsTable($conn);
        $stmt = $conn->prepare('SELECT destination_url FROM notifications WHERE id = ? AND recipient_user_id = ? LIMIT 1');
        $stmt->execute([$notificationId, (int)$user['id']]);
        $notification = $stmt->fetch();

        if ($notification) {
            $storedDestination = trim((string)($notification['destination_url'] ?? ''));
            // Notifications only navigate within EcoTrack; do not accept an
            // external redirect from a query string.
            if (
                $storedDestination !== '' &&
                preg_match('/^[A-Za-z0-9_-]+\\.php(?:\\?[A-Za-z0-9_=&%.-]+)?$/', $storedDestination)
            ) {
                $destination = normalizeEcoTrackRoute($storedDestination);
            }

            $markRead = $conn->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND recipient_user_id = ? AND is_read = 0');
            $markRead->execute([$notificationId, (int)$user['id']]);
            if ($markRead->rowCount() === 1) {
                logActivity('Read notification #' . $notificationId, 'Notifications', 'Success', $conn, $user);
            }
        }
    } catch (PDOException $e) {
        error_log('Failed to mark notification as read: ' . $e->getMessage());
        logActivity('Read notification #' . $notificationId, 'Notifications', 'Failed', $conn, $user);
    }
}

header('Location: ' . $destination);
exit();
