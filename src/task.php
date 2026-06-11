<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/monday.php';
require_once __DIR__ . '/includes/status_map.php';
require_once __DIR__ . '/includes/cache.php';
require_login();

$user = current_user();

$monday_id = filter_input(INPUT_GET, 'monday_id', FILTER_VALIDATE_INT);
if (!$monday_id) {
    http_response_code(404);
    die('Not found.');
}

// Fetch live item from monday (board + group returned for isolation check)
$item      = null;
$api_error = null;
$result    = monday_get_items([$monday_id]);
if (isset($result['error'])) {
    $api_error = $result['error'];
    error_log('[Monday] task.php fetch failed: ' . json_encode($result));
} else {
    $item = $result['data']['items'][0] ?? null;
}

// Multi-tenant isolation: verify item belongs to this user's board.
// Subitems live on a separate monday board; check their parent item's board instead.
// Group is NOT checked — tasks move between groups via monday automations.
if (!MOCK_MONDAY) {
    $deny = false;
    if ($item === null) {
        $deny = true;
    } else {
        $user_board = (string)$user['monday_board_id'];
        if (!empty($item['parent_item'])) {
            // Subitem: authorise via parent's board
            $deny = ((string)($item['parent_item']['board']['id'] ?? '') !== $user_board);
        } else {
            $deny = ((string)($item['board']['id'] ?? '') !== $user_board);
        }
    }
    if ($deny) {
        http_response_code(403);
        include __DIR__ . '/includes/header.php';
        echo '<div class="container"><div class="alert alert-error" style="margin-top:32px;">'
            . 'You do not have permission to view this task.'
            . '<a href="/dashboard.php" style="margin-left:8px;">' . icon('arrow-left', 14) . ' Back to dashboard</a>'
            . '</div></div>';
        include __DIR__ . '/includes/footer.php';
        exit;
    }
}

// Fetch comments
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

// Comment filtering: for approved tasks, hide internal comments posted after approval.
// Admins (is_admin_logged_in()) always see everything.
$visible_updates = $updates;
$hidden_count    = 0;

// Find status column ID needed for review action buttons
$status_col_id = $item ? find_task_status_column_id($item) : null;

// Helpers
function td_relative_time(string $datetime): string {
    $ts = strtotime($datetime);
    if ($ts === false || $ts === 0) return '—';
    $diff = time() - $ts;
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return (int)($diff / 60) . 'm ago';
    if ($diff < 86400)  return (int)($diff / 3600) . 'h ago';
    if ($diff < 172800) return 'yesterday';
    return date('M j, Y', $ts);
}

// Columns shown prominently — skip from the generic table below
$prominent_col_ids = ['color_mksbwnby', 'color_mksb2tks', 'priority_Mjj26KQF', 'date4', 'link_mksbghas'];

// Column IDs the admin has hidden for this client's view (Issue 1)
$hidden_ids = array_filter(array_map('trim', explode(',', $user['hidden_column_ids'] ?? '')));

function render_column_value(array $col): string {
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

    // Auto-link URLs in plain text columns
    return preg_replace_callback(
        '/(https?:\/\/[^\s<]+)/i',
        function ($m) {
            $url = htmlspecialchars($m[1], ENT_QUOTES);
            return '<a href="' . $url . '" target="_blank" rel="noopener" class="task-col-link">' . $url . ' ↗</a>';
        },
        htmlspecialchars($text, ENT_QUOTES)
    );
}

if ($item) {
    $raw_status = extract_item_status($item);
    $status     = map_monday_status_to_client_status($raw_status);
    $priority   = extract_item_column($item, 'priority_Mjj26KQF');
    $deadline   = extract_item_column($item, 'date4');
    $output_url = extract_item_link_url($item, 'link_mksbghas');
    $updated_at = $item['updated_at'] ?? '';
    $task_name  = $item['name'];

    // Handle change-request state for this item (works for both parent tasks and subitems)
    $cr_stmt = db()->prepare(
        "SELECT MAX(UNIX_TIMESTAMP(tcr.requested_at)) FROM task_change_requests tcr
         JOIN user_clients uc ON tcr.requested_by_user_id = uc.user_id
         WHERE uc.client_id = ? AND tcr.monday_item_id = ? AND tcr.resolved_at IS NULL"
    );
    $cr_stmt->execute([$user['active_client_id'], $monday_id]);
    $latest_requested_at = $cr_stmt->fetchColumn();

    if ($latest_requested_at && $status['slug'] === 'client_review') {
        // Only resolve if the item was updated in monday AFTER the change request (+ 60s buffer).
        // This ensures the team actually moved the status away and back — not just a same-session reload.
        $item_updated_ts = strtotime($item['updated_at'] ?? '');
        if ($item_updated_ts !== false && $item_updated_ts > ((int)$latest_requested_at + 60)) {
            db()->prepare(
                "UPDATE task_change_requests tcr JOIN user_clients uc ON tcr.requested_by_user_id = uc.user_id
                 SET tcr.resolved_at = NOW()
                 WHERE uc.client_id = ? AND tcr.monday_item_id = ? AND tcr.resolved_at IS NULL"
            )->execute([$user['active_client_id'], $monday_id]);
        } else {
            // Timing check failed: automation hasn't moved status yet — keep showing "Changes Requested"
            $status = ['label' => 'Changes Requested', 'slug' => 'changes_requested', 'color' => '#f59e0b'];
        }
    } elseif ($latest_requested_at) {
        $status = ['label' => 'Changes Requested', 'slug' => 'changes_requested', 'color' => '#f59e0b'];
    }
} else {
    $status     = map_monday_status_to_client_status('');
    $priority   = '';
    $deadline   = '';
    $output_url = '';
    $updated_at = '';
    $task_name  = 'Task #' . $monday_id;
}

// For approved tasks, filter out internal team comments posted after the approval time.
// Clients only see discussion that happened before/at approval. Admins see everything.
if ($status['slug'] === 'approved' && !empty($updates) && !is_admin_logged_in()) {
    $stmt = db()->prepare("SELECT UNIX_TIMESTAMP(approved_at) FROM task_approvals WHERE monday_item_id = ? LIMIT 1");
    $stmt->execute([$monday_id]);
    $approval_ts = $stmt->fetchColumn();
    if ($approval_ts !== false) {
        $approval_ts     = (int)$approval_ts;
        $visible_updates = array_values(array_filter($updates, function (array $upd) use ($approval_ts): bool {
            $ts = strtotime($upd['created_at'] ?? '');
            return $ts === false || $ts <= $approval_ts;
        }));
        $hidden_count = count($updates) - count($visible_updates);
    }
}

// Show Approve Copy / Request Revision buttons only when the item is in the exact
// COPYWRITING + CLIENT REVIEW state AND the client has copy-review configured.
$show_copy_review = ($item && $status['slug'] === 'client_review')
    ? should_show_copy_review_buttons($item, $user)
    : false;

$page_title = e($task_name) . ' — ' . APP_NAME;
include __DIR__ . '/includes/header.php';
?>
<div class="container-wide">
  <div class="task-back">
    <a href="/dashboard.php" class="back-link"><?= icon('arrow-left', 14) ?> Back to dashboard</a>
  </div>

  <?php if ($api_error): ?>
    <div class="alert alert-error" style="margin-bottom:20px;">
      Could not load live monday data: <?= e($api_error) ?>
    </div>
  <?php endif; ?>

  <div class="task-detail-card">

    <!-- Header -->
    <div class="task-detail-header">
      <div class="task-detail-header-main">
        <div class="task-detail-title-row">
          <h1><?= e($task_name) ?></h1>
        </div>
        <div class="task-detail-meta">
          <span class="status-pill status-<?= e($status['slug']) ?>"><?= e($status['label']) ?></span>
          <?php if ($priority): ?>
            <span class="muted small">Priority: <strong><?= e($priority) ?></strong></span>
          <?php endif; ?>
        </div>
      </div>
      <?php if (!empty($user['logo_url'])): ?>
        <img src="<?= e($user['logo_url']) ?>" alt="<?= e($user['client_name']) ?> logo" class="page-header-logo">
      <?php endif; ?>
    </div>

    <!-- Key details -->
    <div class="task-detail-body">
      <div class="task-key-grid">
        <?php if ($updated_at): ?>
        <div class="task-key-item">
          <span class="task-key-label">Last Updated</span>
          <span class="task-key-value"><?= e(td_relative_time($updated_at)) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($deadline): ?>
        <div class="task-key-item">
          <span class="task-key-label">Deadline</span>
          <span class="task-key-value <?= $deadline !== '' && strtotime($deadline) < time() ? 'deadline-overdue' : '' ?>">
            <?= e(date('M j, Y', strtotime($deadline))) ?>
          </span>
        </div>
        <?php endif; ?>
        <div class="task-key-item">
          <span class="task-key-label">ID</span>
          <span class="task-key-value"><code><?= e($monday_id) ?></code></span>
        </div>
      </div>

      <?php if ($output_url): ?>
      <div class="task-output-banner">
        <span class="task-output-label">Output ready</span>
        <a href="<?= e($output_url) ?>" target="_blank" rel="noopener noreferrer" class="btn-primary btn-inline">
          <?= icon('external-link', 14) ?> View files
        </a>
      </div>
      <?php endif; ?>

      <?php if ($item && !empty($item['column_values'])): ?>
      <h2 class="task-section-title">All fields</h2>
      <table class="task-fields-table">
        <tbody>
          <?php foreach ($item['column_values'] as $col):
            if (in_array($col['id'], $prominent_col_ids, true)) continue;
            if (in_array($col['id'], $hidden_ids, true)) continue;
            $display = trim($col['text'] ?? '');
            if ($col['type'] !== 'link' && ($display === '' || $display === '-')) continue;
            if ($col['type'] === 'link' && empty($col['value']) && ($display === '' || $display === '-')) continue;
          ?>
            <tr>
              <th><?= e($col['column']['title'] ?? $col['id']) ?></th>
              <td><?= render_column_value($col) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>

      <?php
      $subitems = $item ? array_values(array_filter($item['subitems'] ?? [], fn($s) => ($s['state'] ?? '') !== 'deleted')) : [];

      // Batch change-request check for subitems (Issue 2)
      $sub_unresolved_ids = [];
      if (!empty($subitems)) {
          $sub_ids = array_map(fn($s) => (int)$s['id'], $subitems);
          $ph      = implode(',', array_fill(0, count($sub_ids), '?'));

          // Fetch unresolved change requests with their timestamps
          $cr_stmt = db()->prepare(
              "SELECT tcr.monday_item_id, MAX(UNIX_TIMESTAMP(tcr.requested_at)) as latest_requested_at
               FROM task_change_requests tcr
               JOIN user_clients uc ON tcr.requested_by_user_id = uc.user_id
               WHERE uc.client_id = ? AND tcr.resolved_at IS NULL AND tcr.monday_item_id IN ($ph)
               GROUP BY tcr.monday_item_id"
          );
          $cr_stmt->execute(array_merge([$user['active_client_id']], $sub_ids));
          $sub_unresolved_ts = []; // monday_item_id => latest_requested_at (unix)
          foreach ($cr_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
              $sub_unresolved_ts[(int)$row['monday_item_id']] = (int)$row['latest_requested_at'];
          }

          // Auto-resolve subitems back in CLIENT REVIEW, but only if monday updated them
          // after the change request was submitted (proves team actually moved status away and back)
          $cr_subs = [];
          foreach ($subitems as $sub) {
              $sub_id = (int)$sub['id'];
              if (!isset($sub_unresolved_ts[$sub_id])) continue;
              if (map_monday_status_to_client_status(extract_item_status($sub))['slug'] !== 'client_review') continue;
              $sub_updated_ts = strtotime($sub['updated_at'] ?? '');
              if ($sub_updated_ts !== false && $sub_updated_ts > ($sub_unresolved_ts[$sub_id] + 60)) {
                  $cr_subs[] = $sub_id;
              }
          }
          if (!empty($cr_subs)) {
              $ph2 = implode(',', array_fill(0, count($cr_subs), '?'));
              db()->prepare(
                  "UPDATE task_change_requests tcr JOIN user_clients uc ON tcr.requested_by_user_id = uc.user_id
                   SET tcr.resolved_at = NOW()
                   WHERE uc.client_id = ? AND tcr.resolved_at IS NULL AND tcr.monday_item_id IN ($ph2)"
              )->execute(array_merge([$user['active_client_id']], $cr_subs));
              foreach ($cr_subs as $rid) { unset($sub_unresolved_ts[$rid]); }
          }

          $sub_unresolved_ids = array_fill_keys(array_keys($sub_unresolved_ts), true);
      }

      if (!empty($subitems)):
      ?>
      <h2 class="task-section-title" id="subitems">Subitems (<?= count($subitems) ?>)</h2>
      <div class="subitems-list">
        <?php foreach ($subitems as $sub):
          $sub_raw_status = extract_item_status($sub);
          $sub_status     = map_monday_status_to_client_status($sub_raw_status);
          if (isset($sub_unresolved_ids[$sub['id']]) && $sub_status['slug'] !== 'client_review') {
              $sub_status = ['label' => 'Changes Requested', 'slug' => 'changes_requested', 'color' => '#f59e0b'];
          }
          $sub_priority   = extract_item_column($sub, 'priority_Mjj26KQF');
          $sub_updated    = $sub['created_at'] ?? '';
        ?>
          <a href="/task.php?monday_id=<?= (int)$sub['id'] ?>" class="subitem-card">
            <div class="subitem-name"><?= e($sub['name']) ?></div>
            <div class="subitem-meta">
              <span class="status-pill status-<?= e($sub_status['slug']) ?>"><?= e($sub_status['label']) ?></span>
              <?php if ($sub_priority): ?>
                <span class="muted small">Priority: <strong><?= e($sub_priority) ?></strong></span>
              <?php endif; ?>
              <?php if ($sub_updated): ?>
                <span class="muted small"><?= e(td_relative_time($sub_updated)) ?></span>
              <?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

  </div>

<?php if ($item && $status['slug'] === 'client_review'): ?>
  <div class="task-actions-card" id="task-actions">
    <div class="task-actions-header">
      <?= icon('circle-alert', 20) ?>
      <h2>Your action needed</h2>
    </div>

    <?php if ($show_copy_review): ?>
      <p class="task-actions-desc">This copy is ready for your review.</p>
      <p class="task-actions-helper">
        <strong>Approve Copy</strong> — confirms the copy is ready to move to the next stage.<br>
        <strong>Request Revision</strong> — sends the copy back with your feedback.
      </p>
      <div class="task-action-buttons">
        <button id="btn-approve-copy" class="btn-action-approve" onclick="openApproveCopyModal()">
          <?= icon('check-circle', 18) ?> Approve Copy
        </button>
        <button id="btn-request-revision" class="btn-action-changes" onclick="openRevisionModal()">
          <?= icon('message-circle', 15) ?> Request Revision
        </button>
      </div>
    <?php else: ?>
      <p class="task-actions-desc">This task is ready for your review.</p>
      <p class="task-actions-helper">
        <strong>Approve</strong> — confirms the task is complete and ready for delivery.<br>
        <strong>Request Changes</strong> — sends the task back to our team with your feedback.
      </p>
      <div class="task-action-buttons">
        <button id="btn-approve" class="btn-action-approve" onclick="approveTask()" autofocus>
          <?= icon('check-circle', 18) ?> Approve
        </button>
        <button id="btn-request-changes" class="btn-action-changes" onclick="openChangesModal()">
          <?= icon('message-circle', 15) ?> Request Changes
        </button>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($item): ?>
  <div class="task-comments-card">
    <h2><?= icon('message-square', 16) ?> Comments</h2>
    <?php if ($updates_error): ?>
      <p class="muted small">Could not load comments.</p>
    <?php elseif (empty($visible_updates) && $hidden_count === 0): ?>
      <p class="muted">No comments yet.</p>
    <?php else: ?>
      <?php if ($hidden_count > 0): ?>
        <p class="comments-hidden-notice muted small">
          <?= $hidden_count === 1 ? '1 internal team comment is' : $hidden_count . ' internal team comments are' ?> not shown (posted after approval).
        </p>
      <?php endif; ?>
      <?php if (!empty($visible_updates)): ?>
      <div class="comments-list">
        <?php foreach ($visible_updates as $upd): ?>
          <div class="comment-item">
            <div class="comment-meta">
              <strong><?= e($upd['creator']['name'] ?? 'Unknown') ?></strong>
              <span class="muted small"><?= e(td_relative_time($upd['created_at'] ?? '')) ?></span>
            </div>
            <div class="comment-body"><?= render_comment_body($upd['body'] ?? '') ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

</div>

<!-- Request Changes Modal -->
<div id="changes-modal-overlay" class="modal-overlay" style="display:none;" onclick="closeChangesModal(event)">
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <div class="modal-header">
      <h3 id="modal-title"><?= icon('pencil', 16) ?> Request changes</h3>
      <button class="modal-close" onclick="closeChangesModal()" aria-label="Close"><?= icon('x', 16) ?></button>
    </div>
    <div class="modal-body">
      <p class="modal-desc">Describe what needs to change. Your feedback will be sent to the team and they'll get back to you with revisions.</p>
      <textarea id="changes-comment" rows="5" maxlength="2000"
                placeholder="What needs to change?" class="modal-textarea"></textarea>
      <div id="modal-error" class="modal-error-msg" style="display:none;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn-secondary btn-inline" onclick="closeChangesModal()">Cancel</button>
      <button id="btn-send-changes" class="btn-primary btn-inline" onclick="submitRequestChanges()">Send request</button>
    </div>
  </div>
</div>

<!-- Approve Copy confirmation modal -->
<div id="approve-copy-modal-overlay" class="modal-overlay" style="display:none;" onclick="closeApproveCopyModal(event)">
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="approve-copy-modal-title">
    <div class="modal-header">
      <h3 id="approve-copy-modal-title"><?= icon('check-circle', 16) ?> Approve this copy?</h3>
      <button class="modal-close" onclick="closeApproveCopyModal()" aria-label="Close"><?= icon('x', 16) ?></button>
    </div>
    <div class="modal-body">
      <p class="modal-desc">Approve this copy and move it to the next stage? This cannot be undone from the portal.</p>
    </div>
    <div class="modal-footer">
      <button class="btn-secondary btn-inline" onclick="closeApproveCopyModal()">Cancel</button>
      <button id="btn-confirm-approve-copy" class="btn-primary btn-inline" onclick="approveCopy()">Approve Copy</button>
    </div>
  </div>
</div>

<!-- Request Revision modal -->
<div id="revision-modal-overlay" class="modal-overlay" style="display:none;" onclick="closeRevisionModal(event)">
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="revision-modal-title">
    <div class="modal-header">
      <h3 id="revision-modal-title"><?= icon('pencil', 16) ?> Request a revision</h3>
      <button class="modal-close" onclick="closeRevisionModal()" aria-label="Close"><?= icon('x', 16) ?></button>
    </div>
    <div class="modal-body">
      <p class="modal-desc">Describe what needs to change. Your feedback will be sent to the team.</p>
      <label for="revision-comment" class="sr-only">What needs to change?</label>
      <textarea id="revision-comment" rows="5" minlength="5" maxlength="2000"
                placeholder="What needs to change?" class="modal-textarea"></textarea>
      <!-- TODO: image upload input will be added here (separate "image upload via comment" feature) -->
      <div id="revision-modal-error" class="modal-error-msg" style="display:none;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn-secondary btn-inline" onclick="closeRevisionModal()">Cancel</button>
      <button id="btn-send-revision" class="btn-primary btn-inline" onclick="submitRevision()" disabled>Send request</button>
    </div>
  </div>
</div>

<!-- Toast -->
<div id="toast" class="toast" role="status" aria-live="polite"></div>

<script>
(function () {
  var ITEM_ID   = <?= (int)$monday_id ?>;
  var CSRF      = <?= json_encode(csrf_token()) ?>;

  // ── Toast ────────────────────────────────────────────────────────────────────
  function showToast(msg) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('toast-show');
    setTimeout(function () { t.classList.remove('toast-show'); }, 3000);
  }

  // ── Approve ──────────────────────────────────────────────────────────────────
  window.approveTask = function () {
    var btn = document.getElementById('btn-approve');
    btn.disabled = true;
    btn.innerHTML = '<?= icon('check-circle', 18) ?> Approving\u2026';

    fetch('/api/task-approve.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({monday_item_id: ITEM_ID, csrf_token: CSRF})
    })
    .then(function (r) { return r.text().then(function (t) { return {httpOk: r.ok, status: r.status, text: t}; }); })
    .then(function (res) {
      if (!res.httpOk) {
        alert('Request failed (HTTP ' + res.status + '). Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<?= icon('check-circle', 18) ?> Approve';
        return;
      }
      var data;
      try { data = JSON.parse(res.text); } catch (e) {
        console.error('[approve] Non-JSON response:', res.text);
        alert('Unexpected response from server. Please try again or contact support.');
        btn.disabled = false;
        btn.innerHTML = '<?= icon('check-circle', 18) ?> Approve';
        return;
      }
      if (data.ok) {
        showToast('Task approved!');
        setTimeout(function () { location.reload(); }, 1200);
      } else {
        console.error('[approve] Response:', data);
        alert(data.error || 'Action could not be completed. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<?= icon('check-circle', 18) ?> Approve';
      }
    })
    .catch(function () {
      alert('Network error. Please check your connection and try again.');
      btn.disabled = false;
      btn.innerHTML = '<?= icon('thumbs-up', 16) ?> Approve';
    });
  };

  // ── Request Changes modal ────────────────────────────────────────────────────
  window.openChangesModal = function () {
    document.getElementById('changes-modal-overlay').style.display = 'flex';
    document.getElementById('changes-comment').focus();
  };

  window.closeChangesModal = function (e) {
    if (e && e.target !== document.getElementById('changes-modal-overlay')) return;
    document.getElementById('changes-modal-overlay').style.display = 'none';
    document.getElementById('modal-error').style.display = 'none';
  };

  window.submitRequestChanges = function () {
    var comment = document.getElementById('changes-comment').value.trim();
    var errEl   = document.getElementById('modal-error');
    errEl.style.display = 'none';

    if (!comment) {
      errEl.textContent = 'Please describe what needs to change.';
      errEl.style.display = 'block';
      return;
    }
    if (comment.length > 2000) {
      errEl.textContent = 'Comment must be 2000 characters or fewer.';
      errEl.style.display = 'block';
      return;
    }

    var btn = document.getElementById('btn-send-changes');
    btn.disabled = true;
    btn.textContent = 'Sending\u2026';

    fetch('/api/task-request-changes.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({monday_item_id: ITEM_ID, comment: comment, csrf_token: CSRF})
    })
    .then(function (r) { return r.text().then(function (t) { return {httpOk: r.ok, status: r.status, text: t}; }); })
    .then(function (res) {
      if (!res.httpOk) {
        errEl.textContent = 'Request failed (HTTP ' + res.status + '). Please try again.';
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Send request';
        return;
      }
      var data;
      try { data = JSON.parse(res.text); } catch (e) {
        console.error('[request-changes] Non-JSON response:', res.text);
        errEl.textContent = 'Unexpected response from server. Please try again or contact support.';
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Send request';
        return;
      }
      if (data.ok) {
        document.getElementById('changes-modal-overlay').style.display = 'none';
        showToast('Changes requested. Our team has been notified.');
        setTimeout(function () { location.reload(); }, 1500);
      } else {
        console.error('[request-changes] Response:', data);
        errEl.textContent = data.error || 'Action could not be completed. Please try again.';
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Send request';
      }
    })
    .catch(function () {
      errEl.textContent = 'Network error. Please check your connection and try again.';
      errEl.style.display = 'block';
      btn.disabled = false;
      btn.textContent = 'Send request';
    });
  };

  // ── Approve Copy ─────────────────────────────────────────────────────────────
  window.openApproveCopyModal = function () {
    document.getElementById('approve-copy-modal-overlay').style.display = 'flex';
    document.getElementById('btn-confirm-approve-copy').focus();
  };

  window.closeApproveCopyModal = function (e) {
    if (e && e.target !== document.getElementById('approve-copy-modal-overlay')) return;
    document.getElementById('approve-copy-modal-overlay').style.display = 'none';
  };

  window.approveCopy = function () {
    var btn = document.getElementById('btn-confirm-approve-copy');
    btn.disabled = true;
    btn.textContent = 'Approving\u2026';

    fetch('/api/task-approve-copy.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({monday_item_id: ITEM_ID, csrf_token: CSRF})
    })
    .then(function (r) { return r.text().then(function (t) { return {httpOk: r.ok, status: r.status, text: t}; }); })
    .then(function (res) {
      if (!res.httpOk) {
        alert('Request failed (HTTP ' + res.status + '). Please try again.');
        btn.disabled = false;
        btn.textContent = 'Approve Copy';
        return;
      }
      var data;
      try { data = JSON.parse(res.text); } catch (e) {
        console.error('[approve-copy] Non-JSON response:', res.text);
        alert('Unexpected response from server. Please try again or contact support.');
        btn.disabled = false;
        btn.textContent = 'Approve Copy';
        return;
      }
      if (data.ok) {
        document.getElementById('approve-copy-modal-overlay').style.display = 'none';
        showToast('Copy approved.');
        setTimeout(function () { location.reload(); }, 1200);
      } else {
        console.error('[approve-copy] Response:', data);
        alert(data.error || 'Action could not be completed. Please try again.');
        btn.disabled = false;
        btn.textContent = 'Approve Copy';
      }
    })
    .catch(function () {
      alert('Network error. Please check your connection and try again.');
      btn.disabled = false;
      btn.textContent = 'Approve Copy';
    });
  };

  // ── Request Revision modal ────────────────────────────────────────────────────
  window.openRevisionModal = function () {
    document.getElementById('revision-modal-overlay').style.display = 'flex';
    document.getElementById('revision-comment').focus();
  };

  window.closeRevisionModal = function (e) {
    if (e && e.target !== document.getElementById('revision-modal-overlay')) return;
    document.getElementById('revision-modal-overlay').style.display = 'none';
    document.getElementById('revision-modal-error').style.display = 'none';
  };

  // Enable submit only when textarea has >= 5 characters
  document.addEventListener('DOMContentLoaded', function () {
    var ta  = document.getElementById('revision-comment');
    var btn = document.getElementById('btn-send-revision');
    if (!ta || !btn) return;
    ta.addEventListener('input', function () {
      btn.disabled = ta.value.trim().length < 5;
    });
  });

  window.submitRevision = function () {
    var comment = document.getElementById('revision-comment').value.trim();
    var errEl   = document.getElementById('revision-modal-error');
    errEl.style.display = 'none';

    if (comment.length < 5) {
      errEl.textContent = 'Please describe what needs to change (at least 5 characters).';
      errEl.style.display = 'block';
      return;
    }
    if (comment.length > 2000) {
      errEl.textContent = 'Comment must be 2000 characters or fewer.';
      errEl.style.display = 'block';
      return;
    }

    var btn = document.getElementById('btn-send-revision');
    btn.disabled = true;
    btn.textContent = 'Sending\u2026';

    fetch('/api/task-request-revision.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({monday_item_id: ITEM_ID, comment: comment, csrf_token: CSRF})
    })
    .then(function (r) { return r.text().then(function (t) { return {httpOk: r.ok, status: r.status, text: t}; }); })
    .then(function (res) {
      if (!res.httpOk) {
        errEl.textContent = 'Request failed (HTTP ' + res.status + '). Please try again.';
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Send request';
        return;
      }
      var data;
      try { data = JSON.parse(res.text); } catch (e) {
        console.error('[request-revision] Non-JSON response:', res.text);
        errEl.textContent = 'Unexpected response from server. Please try again or contact support.';
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Send request';
        return;
      }
      if (data.ok) {
        document.getElementById('revision-modal-overlay').style.display = 'none';
        showToast('Revision requested. The team has been notified.');
        setTimeout(function () { location.reload(); }, 1500);
      } else {
        console.error('[request-revision] Response:', data);
        errEl.textContent = data.error || 'Action could not be completed. Please try again.';
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Send request';
      }
    })
    .catch(function () {
      errEl.textContent = 'Network error. Please check your connection and try again.';
      errEl.style.display = 'block';
      btn.disabled = false;
      btn.textContent = 'Send request';
    });
  };

  // Close modals on Escape
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.getElementById('changes-modal-overlay').style.display = 'none';
      document.getElementById('approve-copy-modal-overlay').style.display = 'none';
      document.getElementById('revision-modal-overlay').style.display = 'none';
    }
  });
}());
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
