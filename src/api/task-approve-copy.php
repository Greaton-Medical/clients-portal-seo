<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/monday.php';
require_once __DIR__ . '/../includes/cache.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

require_login();
$user = current_user();

$raw_body       = file_get_contents('php://input');
$body           = json_decode($raw_body, true) ?? [];
$monday_item_id = (int)($body['monday_item_id'] ?? 0);
$csrf_token     = (string)($body['csrf_token'] ?? '');

error_log("[APPROVE-COPY] Request: user_id={$user['id']} client={$user['active_client_id']} item={$monday_item_id}");

if (!$monday_item_id) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid item ID']);
    exit;
}
if (!verify_csrf($csrf_token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Rate limit: max 10 review actions per user per hour
$stmt = db()->prepare("SELECT COUNT(*) FROM review_actions WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
$stmt->execute([$user['id']]);
if ((int)$stmt->fetchColumn() >= 10) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Too many review actions. Please wait before trying again.']);
    exit;
}

// Fetch item and verify ownership
$fetch = monday_get_items([$monday_item_id]);
error_log("[APPROVE-COPY] monday_get_items: " . json_encode($fetch));
if (isset($fetch['error'])) {
    echo json_encode(['ok' => false, 'error' => 'Could not fetch task from monday.com']);
    exit;
}
$item = $fetch['data']['items'][0] ?? null;
if ($item === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Task not found']);
    exit;
}
if (!MOCK_MONDAY) {
    $is_subitem = !empty($item['parent_item']);
    $allowed    = $is_subitem
        ? ((string)($item['parent_item']['board']['id'] ?? '') === (string)$user['monday_board_id'])
        : ((string)($item['board']['id'] ?? '')              === (string)$user['monday_board_id']);
    if (!$allowed) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You do not have permission to modify this task']);
        exit;
    }
}

// Defense in depth: re-verify the item is still in the copy-review state
if (!should_show_copy_review_buttons($item, $user)) {
    echo json_encode(['ok' => false, 'error' => 'This action is no longer available for this task. Please refresh the page.']);
    exit;
}

$task_status_col  = $user['task_status_column_id'];
$actual_board_id  = (int)($item['board']['id'] ?? $user['monday_board_id']);

// Set Task Status → APPROVED COPY (Production Status stays COPYWRITING — no change)
$result = monday_change_item_status($monday_item_id, $actual_board_id, $task_status_col, COPY_REVIEW_APPROVE_TASK_STATUS);
error_log("[APPROVE-COPY] change_item_status: " . json_encode($result));
if (isset($result['error'])) {
    $detail = is_array($result['detail'] ?? null) ? json_encode($result['detail']) : ($result['detail'] ?? '');
    echo json_encode(['ok' => false, 'error' => 'Failed to update task status. Please try again.']);
    exit;
}

db()->prepare("INSERT INTO review_actions (user_id, action, monday_item_id) VALUES (?, 'approve_copy', ?)")
    ->execute([$user['id'], $monday_item_id]);

cache_invalidate('monday_board_items_' . $user['active_client_id'] . '_' . $user['monday_board_id']);

echo json_encode(['ok' => true]);
