<?php
require_once __DIR__ . '/../../includes/auth.php';
require_admin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']); exit;
}
if (!verify_admin_csrf($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Security token mismatch.']); exit;
}

$user_id = filter_var($_POST['user_id'] ?? '', FILTER_VALIDATE_INT);
if (!$user_id) {
    echo json_encode(['ok' => false, 'error' => 'Invalid user ID.']); exit;
}

$admin = current_admin();

// Prevent admin from deleting an account with the same numeric ID as their own
if ((int)$user_id === (int)$admin['id']) {
    echo json_encode(['ok' => false, 'error' => 'You cannot delete your own account.']); exit;
}

$db = db();

$stmt = $db->prepare("
    SELECT u.id, u.username, c.name AS client_name
    FROM users u
    JOIN clients c ON u.client_id = c.id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['ok' => false, 'error' => 'User not found.']); exit;
}

// DELETE — FK CASCADE removes all submissions made by this user
$db->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);

error_log("[ADMIN DELETE] Admin {$admin['id']} ({$admin['username']}) deleted user {$user_id} ({$user['username']}) from client '{$user['client_name']}'");

echo json_encode(['ok' => true, 'username' => $user['username']]);
