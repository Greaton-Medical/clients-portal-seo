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

$client_id = filter_var($_POST['client_id'] ?? '', FILTER_VALIDATE_INT);
if (!$client_id) {
    echo json_encode(['ok' => false, 'error' => 'Invalid client ID.']); exit;
}

$force = !empty($_POST['force']);
$db    = db();

$stmt = $db->prepare("SELECT id, name, form_iframe_url FROM clients WHERE id = ? LIMIT 1");
$stmt->execute([$client_id]);
$client = $stmt->fetch();

if (!$client) {
    echo json_encode(['ok' => false, 'error' => 'Client not found.']); exit;
}

// Safety guard: active client with recent submissions (within 7 days)
if (!$force && $client['form_iframe_url'] !== '') {
    $recent = $db->prepare("SELECT COUNT(*) FROM submissions WHERE client_id = ? AND submitted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $recent->execute([$client_id]);
    if ((int)$recent->fetchColumn() > 0) {
        echo json_encode([
            'ok'            => false,
            'requires_force' => true,
            'error'         => 'This client has submissions in the last 7 days. Deactivate the client first, wait 7 days, then delete — or confirm force-delete.',
        ]); exit;
    }
}

// DELETE — FK CASCADE removes all users + submissions for this client
$db->prepare("DELETE FROM clients WHERE id = ?")->execute([$client_id]);

$admin = current_admin();
error_log("[ADMIN DELETE] Admin {$admin['id']} ({$admin['username']}) deleted client {$client_id} ({$client['name']})");

echo json_encode(['ok' => true, 'name' => $client['name']]);
