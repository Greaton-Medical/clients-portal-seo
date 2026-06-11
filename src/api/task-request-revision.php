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
$comment        = trim((string)($body['comment'] ?? ''));
$csrf_token     = (string)($body['csrf_token'] ?? '');

error_log("[REQUEST-REVISION] Request: user_id={$user['id']} client={$user['active_client_id']} item={$monday_item_id}");

if (!$monday_item_id) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid item ID']);
    exit;
}
if (mb_strlen($comment) < 5) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Please describe what needs to change (at least 5 characters).']);
    exit;
}
if (mb_strlen($comment) > 2000) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Comment exceeds 2000 characters']);
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
error_log("[REQUEST-REVISION] monday_get_items: " . json_encode($fetch));
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

// Defense in depth: re-verify state
if (!should_show_copy_review_buttons($item, $user)) {
    echo json_encode(['ok' => false, 'error' => 'This action is no longer available for this task. Please refresh the page.']);
    exit;
}

$task_status_col = $user['task_status_column_id'];
$actual_board_id = (int)($item['board']['id'] ?? $user['monday_board_id']);
$author          = $user['full_name'] ?: $user['username'];
$full_body       = COPY_REVIEW_REVISION_COMMENT_PREFIX . "\n\n[Client request from {$author} ({$user['client_name']})]: " . $comment;

// ── Step 1: Post comment ─────────────────────────────────────────────────────
// Comment first — a posted comment is always recoverable; a stale status is too.
$upd = monday_create_update($monday_item_id, $full_body);
error_log("[REQUEST-REVISION] create_update: " . json_encode($upd));
if (isset($upd['error'])) {
    echo json_encode(['ok' => false, 'error' => 'Failed to post your request on monday.com. Please try again.']);
    exit;
}

// ── Step 2: Change Task Status → REVIEWED ────────────────────────────────────
// Comment is already posted. If status update fails, log verbosely and return error;
// the team can correct the status manually — the comment is already there.
$result = monday_change_item_status($monday_item_id, $actual_board_id, $task_status_col, COPY_REVIEW_REVISION_TASK_STATUS);
error_log("[REQUEST-REVISION] change_item_status: " . json_encode($result));
if (isset($result['error'])) {
    error_log("[REQUEST-REVISION] WARNING: comment posted but status update failed for item={$monday_item_id}");
    db()->prepare("INSERT INTO review_actions (user_id, action, monday_item_id) VALUES (?, 'request_revision', ?)")
        ->execute([$user['id'], $monday_item_id]);
    db()->prepare("INSERT INTO task_change_requests (monday_item_id, requested_by_user_id, comment_text) VALUES (?, ?, ?)")
        ->execute([$monday_item_id, $user['id'], $comment]);
    cache_invalidate('monday_board_items_' . $user['active_client_id'] . '_' . $user['monday_board_id']);
    echo json_encode([
        'ok'    => false,
        'error' => 'Your feedback was received, but we could not update the task status automatically. The ' . AGENCY_NAME . ' team has been notified and will handle it.',
    ]);
    exit;
}

db()->prepare("INSERT INTO review_actions (user_id, action, monday_item_id) VALUES (?, 'request_revision', ?)")
    ->execute([$user['id'], $monday_item_id]);

db()->prepare("INSERT INTO task_change_requests (monday_item_id, requested_by_user_id, comment_text) VALUES (?, ?, ?)")
    ->execute([$monday_item_id, $user['id'], $comment]);

cache_invalidate('monday_board_items_' . $user['active_client_id'] . '_' . $user['monday_board_id']);

echo json_encode(['ok' => true]);
