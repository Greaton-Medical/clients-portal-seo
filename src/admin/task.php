<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/monday.php';
require_once __DIR__ . '/../includes/status_map.php';
require_once __DIR__ . '/../includes/cache.php';
require_admin();

$monday_id = filter_input(INPUT_GET, 'monday_id', FILTER_VALIDATE_INT);
if (!$monday_id) {
    http_response_code(404);
    die('Not found.');
}

// Fetch live item
$item      = null;
$api_error = null;
$result    = monday_get_items([$monday_id]);
if (isset($result['error'])) {
    $api_error = $result['error'];
    error_log('[Admin task.php] fetch failed: ' . json_encode($result));
} else {
    $item = $result['data']['items'][0] ?? null;
}

// Fetch comments (admins see ALL — no post-approval filtering)
$updates       = [];
$updates_error = null;
if ($item) {
    $upd_result = monday_get_item_updates($monday_id);
    if (isset($upd_result['error'])) {
        $updates_error = $upd_result['error'];
    } else {
        $updates = $upd_result['updates'] ?? [];
    }
}

function admin_td_relative_time(string $datetime): string {
    $ts = strtotime($datetime);
    if ($ts === false || $ts === 0) return '—';
    $diff = time() - $ts;
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return (int)($diff / 60) . 'm ago';
    if ($diff < 86400)  return (int)($diff / 3600) . 'h ago';
    if ($diff < 172800) return 'yesterday';
    return date('M j, Y', $ts);
}

function admin_render_column_value(array $col): string {
    $type    = strtolower($col['type'] ?? '');
    $raw_val = $col['value'] ?? null;
    $text    = trim($col['text'] ?? '');

    if ($type === 'link') {
        $parsed = $raw_val ? json_decode($raw_val, true) : null;
        $url    = $parsed['url'] ?? '';
        if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
            $label = !empty($parsed['text']) ? $parsed['text'] : $url;
            return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener" class="task-col-link">'
                 . htmlspecialchars($label, ENT_QUOTES) . ' ↗</a>';
        }
        if ($text && filter_var($text, FILTER_VALIDATE_URL)) {
            return '<a href="' . htmlspecialchars($text, ENT_QUOTES) . '" target="_blank" rel="noopener" class="task-col-link">'
                 . htmlspecialchars($text, ENT_QUOTES) . ' ↗</a>';
        }
        return htmlspecialchars($text, ENT_QUOTES);
    }

    if ($text === '') return '';

    return preg_replace_callback(
        '/(https?:\/\/[^\s<]+)/i',
        function ($m) {
            $url = htmlspecialchars($m[1], ENT_QUOTES);
            return '<a href="' . $url . '" target="_blank" rel="noopener" class="task-col-link">' . $url . ' ↗</a>';
        },
        htmlspecialchars($text, ENT_QUOTES)
    );
}

$prominent_col_ids = ['color_mksbwnby', 'color_mksb2tks', 'priority_Mjj26KQF', 'date4', 'link_mksbghas'];

if ($item) {
    $raw_status    = extract_item_status($item);
    $status        = map_monday_status_to_client_status($raw_status);
    $priority      = extract_item_column($item, 'priority_Mjj26KQF');
    $deadline      = extract_item_column($item, 'date4');
    $output_url    = extract_item_link_url($item, 'link_mksbghas');
    $updated_at    = $item['updated_at'] ?? '';
    $task_name     = $item['name'];
    $board_id      = $item['board']['id'] ?? '';
    $group_id      = $item['group']['id'] ?? '';
    $group_title   = $item['group']['title'] ?? '';
    $status_col_id = find_task_status_column_id($item);

    // Check for unresolved change request
    $cr_stmt = db()->prepare(
        "SELECT COUNT(*) FROM task_change_requests WHERE monday_item_id = ? AND resolved_at IS NULL"
    );
    $cr_stmt->execute([$monday_id]);
    $has_change_request = (int)$cr_stmt->fetchColumn() > 0;
} else {
    $raw_status    = '';
    $status        = map_monday_status_to_client_status('');
    $priority      = '';
    $deadline      = '';
    $output_url    = '';
    $updated_at    = '';
    $task_name     = 'Task #' . $monday_id;
    $board_id      = '';
    $group_id      = '';
    $group_title   = '';
    $status_col_id = null;
    $has_change_request = false;
}

$monday_item_url = ($board_id && MONDAY_DOMAIN) ? "https://" . MONDAY_DOMAIN . ".monday.com/boards/{$board_id}/pulses/{$monday_id}" : '';

$page_title = e($task_name) . ' — Admin';
include __DIR__ . '/includes/admin_header.php';
?>
<style>
.admin-task-back { margin-bottom: 16px; }
.admin-task-back a { color: var(--text-muted); font-size: .875rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
.admin-task-back a:hover { color: var(--text); }
.admin-task-card,
.admin-comments-card,
.admin-debug-card { background: #fff; border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 24px; margin-bottom: 20px; }
.admin-task-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 20px; }
.admin-task-header h1 { font-size: 1.25rem; margin: 0 0 8px; }
.admin-task-meta { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.admin-key-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; margin-bottom: 20px; }
.admin-key-item { background: var(--surface-alt, #f9fafb); border-radius: 8px; padding: 12px; }
.admin-key-label { display: block; font-size: .75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 4px; }
.admin-key-value { font-size: .9375rem; font-weight: 500; }
.admin-open-monday { display: inline-flex; align-items: center; gap: 6px; }
.admin-section-title { font-size: .875rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); margin: 0 0 12px; border-bottom: 1px solid var(--border, #e5e7eb); padding-bottom: 8px; }
.admin-fields-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
.admin-fields-table th { width: 160px; padding: 8px 12px 8px 0; text-align: left; color: var(--text-muted); font-weight: 500; vertical-align: top; white-space: nowrap; }
.admin-fields-table td { padding: 8px 0; vertical-align: top; }
.admin-fields-table tr + tr th,
.admin-fields-table tr + tr td { border-top: 1px solid var(--border, #e5e7eb); }
.admin-debug-card { background: #fafaf9; }
.admin-debug-table { width: 100%; border-collapse: collapse; font-size: .8125rem; }
.admin-debug-table th { width: 180px; padding: 6px 12px 6px 0; text-align: left; color: var(--text-muted); font-weight: 500; }
.admin-debug-table td { padding: 6px 0; font-family: monospace; word-break: break-all; }
.admin-debug-table tr + tr th,
.admin-debug-table tr + tr td { border-top: 1px solid var(--border, #e5e7eb); }
.admin-subitems-list { display: flex; flex-direction: column; gap: 10px; }
.admin-subitem-card { background: var(--surface-alt, #f9fafb); border: 1px solid var(--border, #e5e7eb); border-radius: 8px; padding: 12px 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.admin-subitem-name { font-weight: 500; font-size: .9375rem; }
.admin-subitem-meta { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.changes-badge { display: inline-flex; align-items: center; gap: 6px; background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; border-radius: 6px; padding: 4px 10px; font-size: .8125rem; font-weight: 500; }
.task-col-link { color: #2563eb; text-decoration: underline; word-break: break-all; }
.task-col-link:hover { opacity: .8; }
</style>
<div class="admin-container">
  <div class="admin-task-back">
    <a href="/admin/submissions.php"><?= icon('arrow-left', 14) ?> Back to Submissions</a>
  </div>

  <?php if ($api_error): ?>
    <div class="alert alert-error" style="margin-bottom:16px;">
      Could not load monday data: <?= e($api_error) ?>
    </div>
  <?php endif; ?>

  <div class="admin-task-card">
    <div class="admin-task-header">
      <div>
        <h1><?= e($task_name) ?></h1>
        <div class="admin-task-meta">
          <span class="status-pill status-<?= e($status['slug']) ?>"><?= e($status['label']) ?></span>
          <?php if ($has_change_request): ?>
            <span class="changes-badge"><?= icon('message-circle', 14) ?> Client requested changes</span>
          <?php endif; ?>
          <?php if ($priority): ?>
            <span class="muted small">Priority: <strong><?= e($priority) ?></strong></span>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($monday_item_url): ?>
        <a href="<?= e($monday_item_url) ?>" target="_blank" rel="noopener"
           class="btn-secondary btn-inline admin-open-monday">
          <?= icon('external-link', 14) ?> Open on monday
        </a>
      <?php endif; ?>
    </div>

    <div class="admin-key-grid">
      <?php if ($updated_at): ?>
      <div class="admin-key-item">
        <span class="admin-key-label">Last Updated</span>
        <span class="admin-key-value"><?= e(admin_td_relative_time($updated_at)) ?></span>
      </div>
      <?php endif; ?>
      <?php if ($deadline): ?>
      <div class="admin-key-item">
        <span class="admin-key-label">Deadline</span>
        <span class="admin-key-value <?= $deadline !== '' && strtotime($deadline) < time() ? 'deadline-overdue' : '' ?>">
          <?= e(date('M j, Y', strtotime($deadline))) ?>
        </span>
      </div>
      <?php endif; ?>
      <div class="admin-key-item">
        <span class="admin-key-label">Monday ID</span>
        <span class="admin-key-value"><code><?= e($monday_id) ?></code></span>
      </div>
    </div>

    <?php if ($output_url): ?>
    <div class="task-output-banner" style="margin-bottom:20px;">
      <span class="task-output-label">Output ready</span>
      <a href="<?= e($output_url) ?>" target="_blank" rel="noopener noreferrer" class="btn-primary btn-inline">
        <?= icon('external-link', 14) ?> View files
      </a>
    </div>
    <?php endif; ?>

    <?php if ($item && !empty($item['column_values'])): ?>
    <h3 class="admin-section-title">All Fields</h3>
    <table class="admin-fields-table">
      <tbody>
        <?php foreach ($item['column_values'] as $col):
          if (in_array($col['id'], $prominent_col_ids, true)) continue;
          $display = trim($col['text'] ?? '');
          if ($col['type'] !== 'link' && ($display === '' || $display === '-')) continue;
          if ($col['type'] === 'link' && empty($col['value']) && ($display === '' || $display === '-')) continue;
        ?>
          <tr>
            <th><?= e($col['column']['title'] ?? $col['id']) ?></th>
            <td><?= admin_render_column_value($col) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <?php
    $subitems = $item ? ($item['subitems'] ?? []) : [];
    $subitems = array_values(array_filter($subitems, fn($s) => ($s['state'] ?? '') !== 'deleted'));
    if (!empty($subitems)):
    ?>
    <h3 class="admin-section-title" style="margin-top:24px;">Subitems (<?= count($subitems) ?>)</h3>
    <div class="admin-subitems-list">
      <?php foreach ($subitems as $sub):
        $sub_raw = extract_item_status($sub);
        $sub_st  = map_monday_status_to_client_status($sub_raw);
        $sub_pri = extract_item_column($sub, 'priority_Mjj26KQF');
        $sub_upd = $sub['created_at'] ?? '';
      ?>
        <div class="admin-subitem-card">
          <span class="admin-subitem-name"><?= e($sub['name']) ?></span>
          <div class="admin-subitem-meta">
            <span class="status-pill status-<?= e($sub_st['slug']) ?>"><?= e($sub_st['label']) ?></span>
            <?php if ($sub_pri): ?>
              <span class="muted small">Priority: <strong><?= e($sub_pri) ?></strong></span>
            <?php endif; ?>
            <?php if ($sub_upd): ?>
              <span class="muted small"><?= e(admin_td_relative_time($sub_upd)) ?></span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($item): ?>
  <div class="admin-comments-card">
    <h2 class="admin-section-title"><?= icon('message-square', 15) ?> Comments (admin sees all)</h2>
    <?php if ($updates_error): ?>
      <p class="muted small">Could not load comments.</p>
    <?php elseif (empty($updates)): ?>
      <p class="muted">No comments yet.</p>
    <?php else: ?>
      <div class="comments-list">
        <?php foreach ($updates as $upd): ?>
          <div class="comment-item">
            <div class="comment-meta">
              <strong><?= e($upd['creator']['name'] ?? 'Unknown') ?></strong>
              <span class="muted small"><?= e(admin_td_relative_time($upd['created_at'] ?? '')) ?></span>
            </div>
            <div class="comment-body"><?= render_comment_body($upd['body'] ?? '') ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="admin-debug-card">
    <h3 class="admin-section-title"><?= icon('terminal', 14) ?> Debug Info</h3>
    <table class="admin-debug-table">
      <tbody>
        <tr><th>monday_item_id</th><td><?= e($monday_id) ?></td></tr>
        <tr><th>board_id</th><td><?= e($board_id) ?></td></tr>
        <tr><th>group_id</th><td><?= e($group_id) ?></td></tr>
        <tr><th>group_title</th><td><?= e($group_title) ?></td></tr>
        <tr><th>status_col_id</th><td><?= e($status_col_id ?? '— not found') ?></td></tr>
        <tr><th>raw_status</th><td><?= isset($raw_status) ? e($raw_status) : '—' ?></td></tr>
        <tr><th>mapped_status_slug</th><td><?= e($status['slug']) ?></td></tr>
        <tr><th>mapped_status_label</th><td><?= e($status['label']) ?></td></tr>
        <tr><th>has_change_request</th><td><?= $has_change_request ? 'yes (unresolved)' : 'no' ?></td></tr>
        <?php if ($item): ?>
        <tr>
          <th>submitted_by columns</th>
          <td>
            <?php
            foreach ($item['column_values'] as $cv) {
                if (str_contains(strtolower($cv['column']['title'] ?? ''), 'submitted') || str_contains(strtolower($cv['column']['title'] ?? ''), 'submit')) {
                    echo '<code>' . e($cv['id']) . '</code>: ' . e($cv['text'] ?? '') . '<br>';
                }
            }
            ?>
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/includes/admin_footer.php'; ?>
