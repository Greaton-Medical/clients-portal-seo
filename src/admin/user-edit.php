<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$user_id     = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
// Pre-select a client when chaining from client-edit or clients.php "+ User" button
$preset_client = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT);

$user   = null;
$is_new = true;

if ($user_id) {
    $stmt = db()->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $user   = $stmt->fetch();
    if (!$user) { header('Location: /admin/users.php'); exit; }
    $is_new = false;
}

$clients = db()->query("SELECT id, name, accent_color FROM clients WHERE active = 1 ORDER BY name")->fetchAll();

// Load existing client assignments for edit, or pre-select from URL param for new
$assigned_client_ids = [];
$primary_client_id   = 0;
if (!$is_new && $user) {
    $stmt = db()->prepare("SELECT client_id, is_primary FROM user_clients WHERE user_id = ? ORDER BY is_primary DESC");
    $stmt->execute([$user_id]);
    foreach ($stmt->fetchAll() as $uc) {
        $assigned_client_ids[] = (int)$uc['client_id'];
        if ($uc['is_primary']) $primary_client_id = (int)$uc['client_id'];
    }
    // Fallback to legacy column if pivot has no data yet
    if (empty($assigned_client_ids) && !empty($user['client_id'])) {
        $assigned_client_ids = [(int)$user['client_id']];
        $primary_client_id   = (int)$user['client_id'];
    }
} elseif ($is_new && $preset_client) {
    $assigned_client_ids = [(int)$preset_client];
    $primary_client_id   = (int)$preset_client;
}

$page_title = ($is_new ? 'Add User' : 'Edit: ' . $user['username']) . ' — Admin';
include __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-container">

  <div class="admin-page-header">
    <div>
      <h1><?= $is_new ? 'Add New User' : 'Edit User' ?></h1>
      <p><?= $is_new ? 'Create a portal user for a client' : e($user['username']) ?></p>
    </div>
    <a href="/admin/users.php" class="btn-secondary btn-inline"><?= icon('arrow-left', 14) ?> Back to users</a>
  </div>

  <!-- Temp password display (shown once after creating a user) -->
  <div id="temp-pw-box" class="temp-pw-box" style="display:none;">
    <div class="temp-pw-title"><?= icon('circle-alert', 14) ?> Temp password - copy now. This will not be shown again.</div>
    <div class="temp-pw-warning">
      Send this to the user via a secure channel (not email in plaintext). Once you leave or reload this page, this password cannot be retrieved.
    </div>
    <div class="temp-pw-value">
      <code id="temp-pw-display"></code>
      <button type="button" class="btn-copy" id="copy-pw-btn">Copy</button>
    </div>
    <p class="temp-pw-send">Do not log or store this password anywhere.</p>
  </div>

  <div id="save-error" style="display:none;" class="alert alert-error"></div>

  <div class="admin-form-card">
    <div class="admin-form-header">
      <h1><?= $is_new ? 'New User' : e($user['username']) ?></h1>
      <p><?= $is_new ? 'A temporary password will be generated automatically.' : 'Update user details' ?></p>
    </div>
    <form id="user-form" class="admin-form-body">
      <input type="hidden" name="csrf_token" value="<?= e(admin_csrf_token()) ?>">
      <?php if (!$is_new): ?>
        <input type="hidden" name="id" value="<?= (int)$user_id ?>">
      <?php endif; ?>

      <div class="admin-form-section">
        <div class="admin-form-section-title">Account</div>
        <div class="field">
          <label>Clients <span class="required">*</span></label>

          <!-- Hidden real inputs submitted with the form -->
          <div id="cms-hidden-inputs"></div>

          <!-- Tag-picker widget -->
          <div class="cms-wrap" id="cms-wrap">
            <div class="cms-field" id="cms-field" tabindex="0">
              <div class="cms-tags" id="cms-tags">
                <span class="cms-placeholder" id="cms-placeholder">Select clients…</span>
              </div>
              <svg class="cms-chevron" id="cms-chevron" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
            </div>
            <div class="cms-dropdown" id="cms-dropdown" hidden>
              <?php foreach ($clients as $c):
                $cid       = (int)$c['id'];
                $checked   = in_array($cid, $assigned_client_ids);
              ?>
              <div class="cms-option <?= $checked ? 'is-selected' : '' ?>"
                   data-id="<?= $cid ?>"
                   data-name="<?= e($c['name']) ?>"
                   data-color="<?= e($c['accent_color']) ?>">
                <span class="cms-opt-dot" style="background:<?= e($c['accent_color']) ?>"></span>
                <span class="cms-opt-name"><?= e($c['name']) ?></span>
                <svg class="cms-opt-tick" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <p class="desc">Select all clients this user can access. Click the <strong>★</strong> on a tag to set that client as the default on login.</p>
          <div id="client-error" class="modal-error-msg" style="display:none;"></div>
        </div>
        <div class="admin-form-row">
          <div class="field">
            <label for="username">Username <span class="required">*</span></label>
            <input type="text" id="username" name="username" required
                   value="<?= e($user['username'] ?? '') ?>"
                   placeholder="e.g. jane_smith"
                   autocomplete="off">
          </div>
          <div class="field">
            <label for="role">Role</label>
            <select id="role" name="role">
              <option value="user" <?= ($user['role'] ?? 'user') === 'user' ? 'selected' : '' ?>>User</option>
              <option value="client_admin" <?= ($user['role'] ?? '') === 'client_admin' ? 'selected' : '' ?>>Client Admin</option>
            </select>
          </div>
        </div>
      </div>

      <div class="admin-form-section">
        <div class="admin-form-section-title">Contact</div>
        <div class="field">
          <label for="full_name">Full Name</label>
          <input type="text" id="full_name" name="full_name"
                 value="<?= e($user['full_name'] ?? '') ?>"
                 placeholder="e.g. Jane Smith">
        </div>
        <div class="field">
          <label for="email">Email <span class="required">*</span></label>
          <input type="email" id="email" name="email" required
                 value="<?= e($user['email'] ?? '') ?>"
                 placeholder="jane@client.com">
        </div>
      </div>

      <?php if (!$is_new): ?>
      <div class="admin-form-section">
        <div class="admin-form-section-title">Status</div>
        <div class="field">
          <label>
            <input type="checkbox" name="active" value="1" <?= $user['active'] ? 'checked' : '' ?>>
            &nbsp;Active (user can log in)
          </label>
        </div>
      </div>
      <?php endif; ?>

    </form>
    <div class="admin-form-actions">
      <button type="submit" form="user-form" id="save-btn" class="btn-primary btn-inline">
        <?= $is_new ? 'Create User & Generate Password' : 'Save Changes' ?>
      </button>
      <a href="/admin/users.php" class="btn-secondary btn-inline">Cancel</a>
    </div>
  </div>

</div>

<style>
/* ── Client tag-picker ─────────────────────────────────────────────── */
.cms-wrap { position: relative; }

.cms-field {
  display: flex;
  align-items: center;
  min-height: 42px;
  padding: 5px 10px 5px 8px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  background: #fff;
  cursor: pointer;
  gap: 6px;
  transition: border-color .15s, box-shadow .15s;
}
.cms-field:focus, .cms-wrap.is-open .cms-field {
  outline: none;
  border-color: #6366f1;
  box-shadow: 0 0 0 3px rgba(99,102,241,.15);
}
.cms-tags { display: flex; flex-wrap: wrap; gap: 5px; flex: 1; min-width: 0; }
.cms-placeholder { font-size: .875rem; color: #9ca3af; }

/* Tags */
.cms-tag {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 8px 3px 6px;
  border-radius: 20px;
  font-size: .8rem;
  font-weight: 500;
  color: #fff;
  background: #374151;
  white-space: nowrap;
  max-width: 160px;
}
.cms-tag-dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  background: rgba(255,255,255,.6);
  flex-shrink: 0;
}
.cms-tag-name { overflow: hidden; text-overflow: ellipsis; }
.cms-tag-star {
  background: none; border: none; cursor: pointer;
  padding: 0; margin-left: 1px;
  font-size: .85rem; line-height: 1;
  color: rgba(255,255,255,.5);
  transition: color .1s, transform .1s;
}
.cms-tag-star:hover { color: #fbbf24; transform: scale(1.2); }
.cms-tag-star.is-primary { color: #fbbf24; }
.cms-tag-remove {
  background: none; border: none; cursor: pointer;
  padding: 0; margin-left: 2px;
  font-size: .9rem; line-height: 1;
  color: rgba(255,255,255,.55);
  transition: color .1s;
}
.cms-tag-remove:hover { color: #fff; }

/* Chevron */
.cms-chevron { flex-shrink: 0; color: #9ca3af; transition: transform .2s; }
.cms-wrap.is-open .cms-chevron { transform: rotate(180deg); }

/* Dropdown */
.cms-dropdown {
  position: absolute;
  top: calc(100% + 4px);
  left: 0; right: 0;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  box-shadow: 0 8px 24px rgba(0,0,0,.1);
  z-index: 300;
  padding: 4px;
  max-height: 220px;
  overflow-y: auto;
}
.cms-option {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 9px 10px;
  border-radius: 6px;
  cursor: pointer;
  font-size: .875rem;
  color: #111827;
  transition: background .1s;
}
.cms-option:hover { background: #f3f4f6; }
.cms-option.is-selected { background: #eef2ff; }
.cms-opt-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.cms-opt-name { flex: 1; font-weight: 500; }
.cms-opt-tick { color: #6366f1; opacity: 0; transition: opacity .1s; flex-shrink: 0; }
.cms-option.is-selected .cms-opt-tick { opacity: 1; }
</style>
<script>
(function() {
  var form     = document.getElementById('user-form');
  var saveBtn  = document.getElementById('save-btn');
  var errBox   = document.getElementById('save-error');
  var tempBox  = document.getElementById('temp-pw-box');
  var tempDisp = document.getElementById('temp-pw-display');
  var copyBtn  = document.getElementById('copy-pw-btn');

  // ── Client tag-picker ────────────────────────────────────────────────────
  var cmsWrap     = document.getElementById('cms-wrap');
  var cmsField    = document.getElementById('cms-field');
  var cmsTags     = document.getElementById('cms-tags');
  var cmsDropdown = document.getElementById('cms-dropdown');
  var cmsHidden   = document.getElementById('cms-hidden-inputs');
  var cmsPlaceholder = document.getElementById('cms-placeholder');

  // State: map of id → {id, name, color}
  var selected = {};
  var primaryId = null;

  // Seed from PHP
  var seedSelected = <?= json_encode(array_map(function($cid) use ($clients) {
      foreach ($clients as $c) { if ((int)$c['id'] === $cid) return ['id' => $cid, 'name' => $c['name'], 'color' => $c['accent_color']]; }
      return null;
  }, $assigned_client_ids)) ?>;
  var seedPrimary  = <?= json_encode($primary_client_id) ?>;

  seedSelected.forEach(function(c) { if (c) selected[c.id] = c; });
  primaryId = seedPrimary || null;

  function renderHidden() {
    cmsHidden.innerHTML = '';
    Object.keys(selected).forEach(function(id) {
      var inp = document.createElement('input');
      inp.type = 'hidden'; inp.name = 'clients[]'; inp.value = id;
      cmsHidden.appendChild(inp);
    });
    if (primaryId) {
      var p = document.createElement('input');
      p.type = 'hidden'; p.name = 'primary_client_id'; p.value = primaryId;
      cmsHidden.appendChild(p);
    }
  }

  function renderTags() {
    cmsTags.innerHTML = '';
    var ids = Object.keys(selected);
    if (ids.length === 0) {
      cmsTags.appendChild(cmsPlaceholder);
      return;
    }
    ids.forEach(function(id) {
      var c = selected[id];
      var tag = document.createElement('span');
      tag.className = 'cms-tag';
      tag.dataset.id = id;
      tag.style.background = c.color || '#374151';
      tag.innerHTML =
        '<span class="cms-tag-dot" style="background:rgba(255,255,255,.45)"></span>' +
        '<span class="cms-tag-name">' + escHtml(c.name) + '</span>' +
        '<button type="button" class="cms-tag-star' + (String(primaryId) === String(id) ? ' is-primary' : '') + '" title="Set as primary">★</button>' +
        '<button type="button" class="cms-tag-remove" title="Remove">×</button>';

      tag.querySelector('.cms-tag-star').addEventListener('click', function(e) {
        e.stopPropagation();
        primaryId = parseInt(id);
        renderTags(); renderHidden(); renderOptions();
      });
      tag.querySelector('.cms-tag-remove').addEventListener('click', function(e) {
        e.stopPropagation();
        delete selected[id];
        if (String(primaryId) === String(id)) {
          var remaining = Object.keys(selected);
          primaryId = remaining.length ? parseInt(remaining[0]) : null;
        }
        renderTags(); renderHidden(); renderOptions();
      });
      cmsTags.appendChild(tag);
    });
  }

  function renderOptions() {
    cmsDropdown.querySelectorAll('.cms-option').forEach(function(opt) {
      var id = opt.dataset.id;
      opt.classList.toggle('is-selected', !!selected[id]);
    });
  }

  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // Toggle dropdown
  cmsField.addEventListener('click', function(e) {
    if (e.target.closest('.cms-tag-star') || e.target.closest('.cms-tag-remove')) return;
    var open = !cmsDropdown.hidden;
    cmsDropdown.hidden = open;
    cmsWrap.classList.toggle('is-open', !open);
  });
  cmsField.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); cmsField.click(); }
  });

  // Option click
  cmsDropdown.querySelectorAll('.cms-option').forEach(function(opt) {
    opt.addEventListener('click', function(e) {
      e.stopPropagation();
      var id   = parseInt(opt.dataset.id);
      var name = opt.dataset.name;
      var color= opt.dataset.color;
      if (selected[id]) {
        delete selected[id];
        if (primaryId === id) {
          var remaining = Object.keys(selected);
          primaryId = remaining.length ? parseInt(remaining[0]) : null;
        }
      } else {
        selected[id] = {id: id, name: name, color: color};
        if (!primaryId) primaryId = id;
      }
      renderTags(); renderHidden(); renderOptions();
    });
  });

  // Close on outside click
  document.addEventListener('click', function(e) {
    if (!cmsWrap.contains(e.target)) {
      cmsDropdown.hidden = true;
      cmsWrap.classList.remove('is-open');
    }
  });

  // Init
  renderTags(); renderHidden(); renderOptions();
  // ── End tag-picker ────────────────────────────────────────────────────────

  form.addEventListener('submit', async function(e) {
    e.preventDefault();

    var clientErr = document.getElementById('client-error');
    if (Object.keys(selected).length === 0) {
      clientErr.textContent = 'Please select at least one client.';
      clientErr.style.display = 'block';
      return;
    }
    if (!primaryId) {
      clientErr.textContent = 'Please set a primary client (click the ★ on a tag).';
      clientErr.style.display = 'block';
      return;
    }
    clientErr.style.display = 'none';

    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving…';
    errBox.style.display = 'none';

    var data = new FormData(form);
    if (!form.querySelector('[name=active]')?.checked) {
      data.set('active', '0');
    }

    try {
      var res  = await fetch('/admin/api/user-save.php', { method: 'POST', body: data });
      var json = await res.json();
      if (json.ok) {
        if (json.password) {
          // New user — show temp password once
          tempDisp.textContent = json.password;
          tempBox.style.display = 'block';
          tempBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
          saveBtn.textContent = 'Save Changes'; // switch to edit mode label
          // Update URL so a reload doesn't recreate
          history.replaceState(null, '', '/admin/user-edit.php?id=' + json.id);
          form.querySelector('[name=id]') || (() => {
            var h = document.createElement('input');
            h.type='hidden'; h.name='id'; h.value=json.id; form.appendChild(h);
          })();
        } else {
          // Edit save — flash and go back
          sessionStorage.setItem('flash', 'User "' + json.username + '" updated.');
          window.location.href = '/admin/users.php';
        }
      } else {
        errBox.textContent = json.error || 'Save failed.';
        errBox.style.display = 'block';
      }
    } catch(err) {
      errBox.textContent = 'Network error.';
      errBox.style.display = 'block';
    }

    if (!form.querySelector('[name=id]')) {
      saveBtn.disabled = false;
      saveBtn.textContent = 'Create User & Generate Password';
    }
  });

  copyBtn.addEventListener('click', function() {
    navigator.clipboard.writeText(tempDisp.textContent).then(function() {
      copyBtn.textContent = 'Copied!';
      copyBtn.classList.add('copied');
      setTimeout(function() {
        copyBtn.textContent = 'Copy';
        copyBtn.classList.remove('copied');
      }, 2000);
    });
  });
}());
</script>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
