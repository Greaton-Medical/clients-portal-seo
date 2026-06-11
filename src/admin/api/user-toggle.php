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
    echo json_encode(['ok' => false, 'error' => 'Invalid ID.']); exit;
}

$stmt = db()->prepare("UPDATE users SET active = NOT active WHERE id = ?");
$stmt->execute([$id]);

echo json_encode(['ok' => true]);
