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

$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
if (!$id) {
    echo json_encode(['ok' => false, 'error' => 'Invalid user ID.']); exit;
}

// Verify user exists
$stmt = db()->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'User not found.']); exit;
}

$temp_pw = generate_temp_password();
$hash    = password_hash($temp_pw, PASSWORD_BCRYPT);

db()->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $id]);

// Return temp password once — never log it
echo json_encode(['ok' => true, 'password' => $temp_pw]);
