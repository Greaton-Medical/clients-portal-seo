<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$page_title = 'Users — Admin';

$flash = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);

// Optional client filter
$filter_client = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT) ?: 0;

$where  = $filter_client ? 'WHERE uc.client_id = ?' : '';
$params = $filter_client ? [$filter_client] : [];

$stmt = db()->prepare("
    SELECT u.id, u.username, u.full_name, u.email, u.role, u.active, u.last_login, u.created_at,
           GROUP_CONCAT(c.name ORDER BY uc.is_primary DESC, c.name ASC SEPARATOR ', ') AS client_names,
           MAX(CASE WHEN uc.is_primary = 1 THEN c.accent_color ELSE NULL END) AS accent_color,
           COUNT(DISTINCT uc.client_id) AS client_count,
           COUNT(DISTINCT s.id) AS submission_count
    FROM users u
    LEFT JOIN user_clients uc ON uc.user_id = u.id
    LEFT JOIN clients c       ON uc.client_id = c.id AND c.active = 1
    LEFT JOIN submissions s   ON s.user_id = u.id
    {$where}
    GROUP BY u.id
    ORDER BY MIN(c.name), u.username
");
$stmt->execute($params);
$users = $stmt->fetchAll();

$clients = db()->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();

include __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-container">

  <div class="admin-page-header">
    <div>
      <h1>Users</h1>
      <p><?= count($users) ?> user<?= count($users) !== 1 ? 's' : '' ?><?= $filter_client ? ' in selected client' : ' across all clients' ?></p>
    </div>
    <a href="/admin/user-edit.php<?= $filter_client ? '?client_id='.$filter_client : '' ?>" class="btn-primary btn-inline"><?= icon('plus', 14) ?> Add User</a>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-success" style="margin-bottom:20px;"><?= e($flash) ?></div>
  <?php endif; ?>

  <!-- Client filter -->
  <div class="admin-filter-bar" style="margin-bottom:16px;">
    <form method="GET" style="display:flex;gap:10px;align-items:flex-end;">
      <div>
        <label for="client_id">Filter by client</label>
        <select name="client_id" id="client_id" onchange="this.form.submit()" style="min-width:200px;">
          <option value="0" <?= !$filter_client ? 'selected' : '' ?>>All clients</option>
          <?php foreach ($clients as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $filter_client === $c['id'] ? 'selected' : '' ?>>
              <?= e($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
  </div>

  <div class="admin-table-card">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Username</th>
          <th>Full Name</th>
          <th>Email</th>
          <th>Client</th>
          <th>Role</th>
          <th>Last Login</th>
          <th>Active</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr data-user-id="<?= (int)$u['id'] ?>">
            <td><strong><?= e($u['username']) ?></strong></td>
            <td><?= e($u['full_name'] ?: '—') ?></td>
            <td class="muted small"><?= e($u['email']) ?></td>
            <td>
              <?php if ($u['accent_color']): ?>
                <span class="color-swatch" style="background:<?= e($u['accent_color']) ?>"></span>
              <?php endif; ?>
              <?php
                $names     = $u['client_names'] ?? '—';
                $cnt       = (int)($u['client_count'] ?? 0);
                $nameArr   = array_map('trim', explode(',', $names));
                if ($cnt > 3) {
                    echo e(implode(', ', array_slice($nameArr, 0, 2))) . ', <span class="muted small">+' . ($cnt - 2) . ' more</span>';
                } else {
                    echo e($names);
                }
              ?>
            </td>
            <td>
              <span class="admin-tag <?= $u['role'] === 'client_admin' ? 'admin-tag-admin' : 'admin-tag-user' ?>">
                <?= e($u['role']) ?>
              </span>
            </td>
            <td class="muted small">
              <?= $u['last_login'] ? e(date('M j, Y', strtotime($u['last_login']))) : 'Never' ?>
            </td>
            <td>
              <label class="toggle">
                <input type="checkbox" class="js-toggle" data-type="user" data-id="<?= (int)$u['id'] ?>"
                       <?= $u['active'] ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
              </label>
            </td>
            <td>
              <div class="actions">
                <a href="/admin/user-edit.php?id=<?= (int)$u['id'] ?>" class="btn-sm">Edit</a>
                <button type="button" class="btn-sm btn-sm-danger js-reset-password"
                        data-id="<?= (int)$u['id'] ?>"
                        data-username="<?= e($u['username']) ?>">
                  Reset PW
                </button>
                <button type="button" class="btn-sm btn-sm-danger js-delete-user"
                        data-id="<?= (int)$u['id'] ?>"
                        data-username="<?= e($u['username']) ?>"
                        data-client="<?= e($u['client_names'] ?? '') ?>"
                        data-subs="<?= (int)$u['submission_count'] ?>">
                  <?= icon('trash-2', 12) ?> Delete
                </button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</div>

<!-- Delete confirmation modal -->
<div id="delete-modal-overlay" class="delete-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="delete-modal-title">
  <div class="delete-modal">
    <h3 id="delete-modal-title"></h3>
    <div id="delete-modal-details" class="delete-modal-details"></div>
    <div class="delete-modal-actions">
      <button type="button" id="delete-modal-cancel" class="btn-secondary btn-inline">Cancel</button>
      <button type="button" id="delete-modal-confirm" class="btn-delete-confirm">Yes, delete permanently</button>
    </div>
  </div>
</div>

<!-- Reset password result modal (simple inline display) -->
<div id="reset-pw-result" style="display:none;" class="temp-pw-box"
     style="position:fixed;bottom:24px;right:24px;max-width:420px;z-index:999;">
  <div class="temp-pw-title">New temp password for <span id="reset-pw-username"></span></div>
  <div class="temp-pw-warning">This will not be shown again. Copy it now and send via secure channel.</div>
  <div class="temp-pw-value">
    <code id="reset-pw-display"></code>
    <button type="button" class="btn-copy" onclick="copyResetPw()">Copy</button>
  </div>
  <p class="temp-pw-send">Do not log or email this password directly.</p>
  <button type="button" onclick="document.getElementById('reset-pw-result').style.display='none'"
          style="margin-top:10px;background:none;border:none;cursor:pointer;color:#92400e;font-size:12px;">
    <?= icon('x', 14) ?> Dismiss
  </button>
</div>

<script>
var adminCsrf = document.querySelector('meta[name="admin-csrf"]').content;

// ── Delete user ──────────────────────────────────────────────────────────────
(function () {
  var overlay    = document.getElementById('delete-modal-overlay');
  var titleEl    = document.getElementById('delete-modal-title');
  var detailsEl  = document.getElementById('delete-modal-details');
  var confirmBtn = document.getElementById('delete-modal-confirm');
  var cancelBtn  = document.getElementById('delete-modal-cancel');
  var pendingId  = null;

  function openModal()  { overlay.classList.add('open'); }
  function closeModal() {
    overlay.classList.remove('open');
    pendingId = null;
    confirmBtn.disabled = false;
    confirmBtn.textContent = 'Yes, delete permanently';
  }

  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  document.querySelectorAll('.js-delete-user').forEach(function (btn) {
    btn.addEventListener('click', function () {
      pendingId = this.dataset.id;
      var username = this.dataset.username;
      var client   = this.dataset.client;
      var subs     = this.dataset.subs;

      titleEl.textContent = 'Delete \u201c' + username + '\u201d?';
      detailsEl.innerHTML =
        'Client: <strong>' + escHtml(client) + '</strong><br><br>' +
        'This will also permanently delete:<br>' +
        '&bull; ' + escHtml(subs) + ' submission record' + (subs !== '1' ? 's' : '') + ' made by this user<br><br>' +
        '<strong>This action cannot be undone.</strong>';
      openModal();
    });
  });

  cancelBtn.addEventListener('click', closeModal);
  overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });

  confirmBtn.addEventListener('click', async function () {
    confirmBtn.disabled = true;
    confirmBtn.textContent = 'Deleting\u2026';

    try {
      var res  = await fetch('/admin/api/user-delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'user_id=' + encodeURIComponent(pendingId) + '&csrf_token=' + encodeURIComponent(adminCsrf),
      });
      var json = await res.json();

      if (json.ok) {
        var row = document.querySelector('tr[data-user-id="' + pendingId + '"]');
        if (row) row.remove();
        var remaining = document.querySelectorAll('tbody tr').length;
        var p = document.querySelector('.admin-page-header p');
        if (p) {
          var suffix = remaining + ' user' + (remaining !== 1 ? 's' : '');
          p.textContent = suffix + p.textContent.replace(/^\d+ users?/, '');
        }
        closeModal();
      } else {
        alert(json.error || 'Delete failed.');
        closeModal();
      }
    } catch (err) {
      alert('Network error. Please try again.');
      closeModal();
    }
  });
}());

// Reset password buttons
document.querySelectorAll('.js-reset-password').forEach(function(btn) {
  btn.addEventListener('click', async function() {
    var userId   = this.dataset.id;
    var username = this.dataset.username;
    if (!confirm('Reset password for ' + username + '? The old password will stop working immediately.')) return;

    try {
      var res = await fetch('/admin/api/user-reset-password.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'id=' + encodeURIComponent(userId) + '&csrf_token=' + encodeURIComponent(adminCsrf)
      });
      var json = await res.json();
      if (json.ok) {
        document.getElementById('reset-pw-username').textContent = username;
        document.getElementById('reset-pw-display').textContent = json.password;
        document.getElementById('reset-pw-result').style.display = 'block';
        document.getElementById('reset-pw-result').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      } else {
        alert(json.error || 'Reset failed.');
      }
    } catch(err) {
      alert('Network error.');
    }
  });
});

function copyResetPw() {
  var pw = document.getElementById('reset-pw-display').textContent;
  navigator.clipboard.writeText(pw).then(function() {
    var btn = document.querySelector('#reset-pw-result .btn-copy');
    btn.textContent = 'Copied!';
    btn.classList.add('copied');
    setTimeout(function() { btn.textContent = 'Copy'; btn.classList.remove('copied'); }, 2000);
  });
}
</script>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
