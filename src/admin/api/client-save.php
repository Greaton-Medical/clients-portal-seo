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

// ── Input validation ────────────────────────────────────────────────────────
$id              = filter_var($_POST['id'] ?? '',                    FILTER_VALIDATE_INT);
$name            = trim($_POST['name'] ?? '');
$slug            = trim($_POST['slug'] ?? '');
$board_id        = trim($_POST['monday_board_id'] ?? '');
$group_id        = trim($_POST['monday_group_id'] ?? '');
$accent_color    = trim($_POST['accent_color'] ?? '');
$logo_url        = trim($_POST['logo_url'] ?? '');
$iframe_url      = trim($_POST['form_iframe_url'] ?? '');
$submitted_by_col        = trim($_POST['submitted_by_column_id']           ?? '');
$revision_notes_col      = trim($_POST['subitem_revision_notes_column_id'] ?? '');
$hidden_column_ids       = trim($_POST['hidden_column_ids']                 ?? '');
$active                  = filter_var($_POST['active'] ?? '0', FILTER_VALIDATE_INT) ? 1 : 0;
$task_status_col         = trim($_POST['task_status_column_id']            ?? '');
$production_status_col   = trim($_POST['production_status_column_id']      ?? '');
$copy_review_enabled     = empty($_POST['copy_review_enabled']) ? 0 : 1;

if ($name === '') {
    echo json_encode(['ok' => false, 'error' => 'Client name is required.']); exit;
}
if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
    echo json_encode(['ok' => false, 'error' => 'Slug must contain only lowercase letters, numbers, hyphens, and underscores.']); exit;
}
if (!filter_var($board_id, FILTER_VALIDATE_INT)) {
    echo json_encode(['ok' => false, 'error' => 'Board ID must be a number.']); exit;
}
if ($group_id === '') {
    echo json_encode(['ok' => false, 'error' => 'Group ID is required.']); exit;
}
if (!preg_match('/^#[0-9a-f]{6}$/i', $accent_color)) {
    echo json_encode(['ok' => false, 'error' => 'Accent color must be a valid hex color (e.g. #ff0000).']); exit;
}
if ($logo_url !== '' && !filter_var($logo_url, FILTER_VALIDATE_URL)) {
    echo json_encode(['ok' => false, 'error' => 'Logo URL is not a valid URL.']); exit;
}
if ($submitted_by_col !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $submitted_by_col)) {
    echo json_encode(['ok' => false, 'error' => 'Submitted By Column ID must contain only letters, numbers, and underscores.']); exit;
}
if ($revision_notes_col !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $revision_notes_col)) {
    echo json_encode(['ok' => false, 'error' => 'Subitem Revision Notes Column ID must contain only letters, numbers, and underscores.']); exit;
}
if ($task_status_col !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $task_status_col)) {
    echo json_encode(['ok' => false, 'error' => 'Task Status Column ID must contain only letters, numbers, and underscores.']); exit;
}
if ($production_status_col !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $production_status_col)) {
    echo json_encode(['ok' => false, 'error' => 'Production Status Column ID must contain only letters, numbers, and underscores.']); exit;
}
if ($hidden_column_ids !== '' && !preg_match('/^[a-zA-Z0-9_,\s]+$/', $hidden_column_ids)) {
    echo json_encode(['ok' => false, 'error' => 'Hidden Column IDs must contain only letters, numbers, underscores, and commas.']); exit;
}
// Normalize: trim each ID, drop empties, rejoin
$hidden_col_parts = array_filter(array_map('trim', explode(',', $hidden_column_ids)));
$hidden_column_ids_val = !empty($hidden_col_parts) ? implode(',', $hidden_col_parts) : null;

if ($iframe_url !== '') {
    if (str_starts_with($iframe_url, 'https://wkf.ms/')) {
        echo json_encode(['ok' => false, 'error' => 'That is a share link, not an embed URL. Build the embed URL manually: https://forms.monday.com/forms/embed/YOUR_FORM_ID?r=use1']); exit;
    }
    if (str_contains($iframe_url, 'forms.ms')) {
        echo json_encode(['ok' => false, 'error' => 'The forms.ms domain is broken and will not load in an iframe. Build the embed URL manually: https://forms.monday.com/forms/embed/YOUR_FORM_ID?r=use1']); exit;
    }
    if (!preg_match('#^https://forms\.monday\.com/forms/embed/[a-f0-9]+#i', $iframe_url)) {
        echo json_encode(['ok' => false, 'error' => 'Monday Form Embed URL must match the pattern: https://forms.monday.com/forms/embed/YOUR_FORM_ID?r=use1']); exit;
    }
}

$db = db();
$is_new = !$id;

// Slug uniqueness check
$slug_check = $db->prepare("SELECT id FROM clients WHERE slug = ? AND id != ?");
$slug_check->execute([$slug, $id ?: 0]);
if ($slug_check->fetch()) {
    echo json_encode(['ok' => false, 'error' => "Slug '{$slug}' is already taken."]); exit;
}

$submitted_by_val       = $submitted_by_col       !== '' ? $submitted_by_col       : null;
$revision_notes_val     = $revision_notes_col     !== '' ? $revision_notes_col     : null;
$task_status_val        = $task_status_col        !== '' ? $task_status_col        : null;
$production_status_val  = $production_status_col  !== '' ? $production_status_col  : null;

if ($is_new) {
    $stmt = $db->prepare("
        INSERT INTO clients (name, slug, monday_board_id, monday_group_id, accent_color, logo_url, form_iframe_url,
                             submitted_by_column_id, subitem_revision_notes_column_id, hidden_column_ids,
                             task_status_column_id, production_status_column_id, copy_review_enabled, active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
    ");
    $stmt->execute([$name, $slug, (int)$board_id, $group_id, $accent_color, $logo_url ?: null, $iframe_url,
                    $submitted_by_val, $revision_notes_val, $hidden_column_ids_val,
                    $task_status_val, $production_status_val, $copy_review_enabled]);
    $new_id = (int)$db->lastInsertId();
    echo json_encode(['ok' => true, 'is_new' => true, 'id' => $new_id, 'name' => $name]);
} else {
    $stmt = $db->prepare("
        UPDATE clients
        SET name=?, slug=?, monday_board_id=?, monday_group_id=?, accent_color=?, logo_url=?, form_iframe_url=?,
            submitted_by_column_id=?, subitem_revision_notes_column_id=?, hidden_column_ids=?,
            task_status_column_id=?, production_status_column_id=?, copy_review_enabled=?, active=?
        WHERE id=?
    ");
    $stmt->execute([$name, $slug, (int)$board_id, $group_id, $accent_color, $logo_url ?: null, $iframe_url,
                    $submitted_by_val, $revision_notes_val, $hidden_column_ids_val,
                    $task_status_val, $production_status_val, $copy_review_enabled, $active, $id]);
    echo json_encode(['ok' => true, 'is_new' => false, 'id' => $id, 'name' => $name]);
}
