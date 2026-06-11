<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$page_title = 'Clients — Admin';

$flash = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);

$clients = db()->query("
    SELECT c.*,
           COUNT(DISTINCT u.id)  AS user_count,
           COUNT(DISTINCT s.id)  AS submission_count
    FROM clients c
    LEFT JOIN users u       ON u.client_id = c.id
    LEFT JOIN submissions s ON s.client_id = c.id
    GROUP BY c.id
    ORDER BY c.name
")->fetchAll();

include __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-container">

  <div class="admin-page-header">
    <div>
      <h1>Clients</h1>
      <p><?= count($clients) ?> client<?= count($clients) !== 1 ? 's' : '' ?> registered</p>
    </div>
    <a href="/admin/client-edit.php" class="btn-primary btn-inline"><?= icon('plus', 14) ?> Add Client</a>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-success" style="margin-bottom:20px;"><?= e($flash) ?></div>
  <?php endif; ?>

  <div class="admin-table-card">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Client</th>
          <th>Slug</th>
          <th>Monday Board ID</th>
          <th>Accent</th>
          <th>Users</th>
          <th>Submissions</th>
          <th>Active</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($clients as $c): ?>
          <tr data-client-id="<?= (int)$c['id'] ?>">
            <td><strong><?= e($c['name']) ?></strong></td>
            <td><code><?= e($c['slug']) ?></code></td>
            <td><code><?= e($c['monday_board_id']) ?></code></td>
            <td>
              <span class="color-swatch" style="background:<?= e($c['accent_color']) ?>"></span>
              <code style="font-size:11px;"><?= e($c['accent_color']) ?></code>
            </td>
            <td><?= (int)$c['user_count'] ?></td>
            <td><?= (int)$c['submission_count'] ?></td>
            <td>
              <label class="toggle" title="Toggle active/inactive">
                <input type="checkbox" class="js-toggle" data-type="client" data-id="<?= (int)$c['id'] ?>"
                       <?= $c['active'] ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
              </label>
            </td>
            <td>
              <div class="actions">
                <a href="/admin/client-edit.php?id=<?= (int)$c['id'] ?>" class="btn-sm">Edit</a>
                <a href="/admin/user-edit.php?client_id=<?= (int)$c['id'] ?>" class="btn-sm"><?= icon('plus', 12) ?> User</a>
                <button type="button" class="btn-sm btn-sm-danger js-delete-client"
                        data-id="<?= (int)$c['id'] ?>"
                        data-name="<?= e($c['name']) ?>"
                        data-users="<?= (int)$c['user_count'] ?>"
                        data-subs="<?= (int)$c['submission_count'] ?>">
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

<script>
(function () {
  'use strict';

  var adminCsrf   = document.querySelector('meta[name="admin-csrf"]').content;
  var overlay     = document.getElementById('delete-modal-overlay');
  var titleEl     = document.getElementById('delete-modal-title');
  var detailsEl   = document.getElementById('delete-modal-details');
  var confirmBtn  = document.getElementById('delete-modal-confirm');
  var cancelBtn   = document.getElementById('delete-modal-cancel');
  var pendingId   = null;
  var pendingName = null;
  var forceMode   = false;

  function openModal() { overlay.classList.add('open'); }
  function closeModal() {
    overlay.classList.remove('open');
    pendingId = null; pendingName = null; forceMode = false;
    confirmBtn.disabled = false;
    confirmBtn.textContent = 'Yes, delete permanently';
  }

  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  document.querySelectorAll('.js-delete-client').forEach(function (btn) {
    btn.addEventListener('click', function () {
      pendingId   = this.dataset.id;
      pendingName = this.dataset.name;
      var users   = this.dataset.users;
      var subs    = this.dataset.subs;
      forceMode   = false;

      titleEl.textContent = 'Delete \u201c' + pendingName + '\u201d?';
      detailsEl.innerHTML =
        'This will also permanently delete:<br>' +
        '&bull; ' + escHtml(users) + ' user' + (users !== '1' ? 's' : '') + ' belonging to this client<br>' +
        '&bull; ' + escHtml(subs) + ' submission record' + (subs !== '1' ? 's' : '') + '<br><br>' +
        '<strong>This action cannot be undone.</strong>';
      confirmBtn.textContent = 'Yes, delete permanently';
      openModal();
    });
  });

  cancelBtn.addEventListener('click', closeModal);
  overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });

  confirmBtn.addEventListener('click', async function () {
    confirmBtn.disabled = true;
    confirmBtn.textContent = 'Deleting\u2026';

    var body = 'client_id=' + encodeURIComponent(pendingId) +
               '&csrf_token=' + encodeURIComponent(adminCsrf);
    if (forceMode) body += '&force=1';

    try {
      var res  = await fetch('/admin/api/client-delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body,
      });
      var json = await res.json();

      if (json.ok) {
        var row = document.querySelector('tr[data-client-id="' + pendingId + '"]');
        if (row) row.remove();
        var remaining = document.querySelectorAll('tbody tr').length;
        var p = document.querySelector('.admin-page-header p');
        if (p) p.textContent = remaining + ' client' + (remaining !== 1 ? 's' : '') + ' registered';
        closeModal();
      } else if (json.requires_force) {
        forceMode = true;
        titleEl.textContent = 'This client has recent activity';
        detailsEl.innerHTML = escHtml(pendingName) + ' has submissions in the last 7 days.<br><br>' +
          'Consider deactivating the client first and waiting 7 days.<br><br>' +
          '<strong>Are you sure you want to force-delete this client and all its data?</strong>';
        confirmBtn.textContent = 'Force delete anyway';
        confirmBtn.disabled = false;
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
</script>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
