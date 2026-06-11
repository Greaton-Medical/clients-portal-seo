<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/monday.php';
require_once __DIR__ . '/../includes/cache.php';
require_admin();

$client_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$client    = null;
$is_new    = true;

if ($client_id) {
    $stmt = db()->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch();
    if (!$client) { header('Location: /admin/clients.php'); exit; }
    $is_new = false;
}

// Defaults for new client
$name              = $client['name']                  ?? '';
$slug              = $client['slug']                  ?? '';
$accent_color      = $client['accent_color']          ?? '#0066cc';
$logo_url          = $client['logo_url']              ?? '';
$board_id          = $client['monday_board_id']       ?? '';
$group_id          = $client['monday_group_id']       ?? '';
$iframe_url        = $client['form_iframe_url']       ?? '';
$submitted_by_col       = $client['submitted_by_column_id']           ?? '';
$revision_notes_col     = $client['subitem_revision_notes_column_id'] ?? '';
$hidden_column_ids      = $client['hidden_column_ids']                 ?? '';
$active            = $client['active']                ?? 1;
$task_status_col        = $client['task_status_column_id']            ?? '';
$production_status_col  = $client['production_status_column_id']      ?? '';
$copy_review_enabled    = (bool)($client['copy_review_enabled']       ?? false);

// Fetch board columns for hidden-columns checkbox UI (5-min cache)
$board_columns       = [];
$columns_fetch_error = null;
if ($board_id !== '') {
    if (MOCK_MONDAY) {
        $r = monday_get_board_columns(0);
        $board_columns = $r['columns'] ?? [];
    } else {
        $cache_key = 'admin_board_cols_' . preg_replace('/\D/', '', $board_id);
        $cached = cache_get($cache_key);
        if ($cached !== null) {
            $board_columns = $cached;
        } else {
            $r = monday_get_board_columns((int)$board_id);
            if (isset($r['error'])) {
                $columns_fetch_error = 'Could not load columns from monday — paste IDs manually.';
            } else {
                $board_columns = $r['columns'];
                cache_set($cache_key, $board_columns, 300);
            }
        }
    }
}

// Build a lookup set of currently hidden IDs for pre-checking boxes
$hidden_ids_set = [];
foreach (array_filter(array_map('trim', explode(',', $hidden_column_ids))) as $hid) {
    $hidden_ids_set[$hid] = true;
}

$page_title = ($is_new ? 'Add Client' : 'Edit: ' . $name) . ' — Admin';
include __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-container">

  <div class="admin-page-header">
    <div>
      <h1><?= $is_new ? 'Add New Client' : 'Edit Client' ?></h1>
      <p><?= $is_new ? 'Create a new client account' : e($name) ?></p>
    </div>
    <a href="/admin/clients.php" class="btn-secondary btn-inline"><?= icon('arrow-left', 14) ?> Back to clients</a>
  </div>

  <!-- Chain CTA (shown after AJAX save) -->
  <div id="save-success" style="display:none;" class="chain-cta">
    <span class="chain-cta-text" id="save-success-msg"><?= icon('circle-check', 16) ?> Client saved!</span>
    <a href="#" id="add-user-link" class="btn-primary">Add user to this client <?= icon('chevron-right', 14) ?></a>
  </div>
  <div id="save-error" style="display:none;" class="alert alert-error" style="margin-bottom:20px;"></div>

  <div style="display:grid;grid-template-columns:1fr 340px;gap:24px;align-items:start;">

    <!-- ── Form ───────────────────────────────────────────── -->
    <div class="admin-form-card" style="max-width:none;">
      <div class="admin-form-header">
        <h1><?= $is_new ? 'New Client' : e($name) ?></h1>
        <p><?= $is_new ? 'Fill in the details below' : 'Update client details' ?></p>
      </div>
      <form id="client-form" class="admin-form-body">
        <input type="hidden" name="csrf_token" value="<?= e(admin_csrf_token()) ?>">
        <?php if (!$is_new): ?>
          <input type="hidden" name="id" value="<?= (int)$client_id ?>">
        <?php endif; ?>

        <!-- ── Basic info ──────────────────────────────────── -->
        <div class="admin-form-section">
          <div class="admin-form-section-title">Basic info</div>
          <div class="field">
            <label for="name">Client Name <span class="required">*</span></label>
            <input type="text" id="name" name="name" required
                   value="<?= e($name) ?>" placeholder="e.g. Acme Corp">
          </div>
          <div class="admin-form-row">
            <div class="field">
              <label for="slug">Slug <span class="required">*</span></label>
              <input type="text" id="slug" name="slug" required
                     value="<?= e($slug) ?>" placeholder="e.g. acme-corp"
                     pattern="[a-z0-9_-]+"
                     title="Lowercase letters, numbers, hyphens and underscores only">
              <p class="desc">Auto-generated from name — override if needed.</p>
            </div>
            <?php if (!$is_new): ?>
            <div class="field" style="align-self:center;padding-top:20px;">
              <label>
                <input type="checkbox" name="active" value="1" <?= $active ? 'checked' : '' ?>>
                &nbsp;Active (users can log in)
              </label>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- ── Brand assets ────────────────────────────────── -->
        <div class="admin-form-section">
          <div class="admin-form-section-title">Brand assets</div>

          <div class="field">
            <label>Accent Color <span class="required">*</span></label>
            <div class="color-picker-row">
              <input type="color" id="accent_color_picker"
                     value="<?= e($accent_color) ?>">
              <input type="text" id="accent_color" name="accent_color"
                     class="hex-input" value="<?= e($accent_color) ?>"
                     placeholder="#0066cc" maxlength="7"
                     pattern="#[0-9a-fA-F]{6}"
                     title="6-digit hex color, e.g. #ff0000">
              <span class="color-preview-swatch" id="color-swatch"
                    style="background:<?= e($accent_color) ?>"></span>
            </div>
            <p class="desc">Used as the client's badge color and card accent in the portal.</p>
          </div>

          <div class="field">
            <label for="logo_url">Logo URL</label>
            <input type="url" id="logo_url" name="logo_url"
                   value="<?= e($logo_url) ?>"
                   placeholder="https://example.com/logo.png">
            <p class="desc">Public image URL. Shown in the portal nav instead of the default agency logo. Leave blank to use the agency logo.</p>
            <div class="logo-preview-wrap">
              <img id="logo-preview-img" class="logo-preview-img"
                   src="<?= e($logo_url) ?>"
                   alt="Logo preview"
                   <?= $logo_url ? 'style="display:block;"' : '' ?>>
              <p id="logo-preview-error" class="logo-preview-error">
                <?= icon('circle-alert', 14) ?> Could not load image — check the URL.
              </p>
            </div>
          </div>
        </div>

        <!-- ── Monday integration ──────────────────────────── -->
        <div class="admin-form-section">
          <div class="admin-form-section-title">Monday.com integration</div>
          <div class="field">
            <label for="monday_board_id">Board ID <span class="required">*</span></label>
            <div class="test-conn-wrap">
              <input type="text" id="monday_board_id" name="monday_board_id"
                     value="<?= e($board_id) ?>" placeholder="e.g. 18409671597"
                     inputmode="numeric">
              <button type="button" id="test-conn-btn" class="btn-test"
                <?= MOCK_MONDAY ? 'disabled' : '' ?>>
                <?= MOCK_MONDAY ? icon('circle-alert', 14) . ' Test (mock)' : icon('zap', 14) . ' Test connection' ?>
              </button>
            </div>
            <?php if (MOCK_MONDAY): ?>
              <p class="conn-result mock-mode">Connection test disabled — MOCK_MONDAY=true.</p>
            <?php else: ?>
              <p id="conn-result" class="conn-result" style="display:none;"></p>
            <?php endif; ?>
          </div>
          <div class="field">
            <label for="monday_group_id">Group ID <span class="required">*</span></label>
            <input type="text" id="monday_group_id" name="monday_group_id"
                   value="<?= e($group_id) ?>" placeholder="e.g. group_mkznjcej">
          </div>
          <div class="field">
            <label for="submitted_by_column_id">Submitted By Column ID <span class="desc-inline">(optional)</span></label>
            <input type="text" id="submitted_by_column_id" name="submitted_by_column_id"
                   value="<?= e($submitted_by_col) ?>"
                   placeholder="e.g. short_text5nn96kew"
                   pattern="[a-zA-Z0-9_]*"
                   title="Alphanumeric characters and underscores only, or leave empty">
            <p class="desc">
              If the monday form has a hidden text column with URL-prefill enabled, paste its column ID here.
              The portal will automatically fill that column with the logged-in user's username on each submission,
              enabling the "My Requests" / "Other team requests" split on the client dashboard.
              Leave empty to show all tasks in a single unified view.<br>
              <a href="/docs/per-user-tracking.md" target="_blank">Setup guide →</a>
            </p>
          </div>

          <div class="field">
            <label for="subitem_revision_notes_column_id">Subitem Revision Notes Column ID <span class="desc-inline">(optional)</span></label>
            <input type="text" id="subitem_revision_notes_column_id" name="subitem_revision_notes_column_id"
                   value="<?= e($revision_notes_col) ?>"
                   placeholder="e.g. text_mm2xqbeh"
                   pattern="[a-zA-Z0-9_]*"
                   title="Alphanumeric characters and underscores only, or leave empty">
            <p class="desc">
              Column ID on the subitems board where "Request Changes" feedback is written.
              Mae configures monday automations to watch this column and trigger status transitions.
              Leave empty to fall back to the hardcoded default (<code>text_mm2xqbeh</code>).
            </p>
          </div>

          <div class="field">
            <label>Hidden Columns <span class="desc-inline">(client view only)</span></label>
            <?php if (!empty($board_columns)):
              $type_group_map = [
                'color' => 'Status & Labels', 'status' => 'Status & Labels',
                'priority' => 'Status & Labels', 'dropdown' => 'Status & Labels',
                'date' => 'Dates', 'timeline' => 'Dates',
                'text' => 'Text', 'short-text' => 'Text', 'long-text' => 'Text', 'long_text' => 'Text',
                'link' => 'Links & Files', 'file' => 'Links & Files',
                'people' => 'People', 'email' => 'People', 'phone' => 'People',
                'numbers' => 'Numbers', 'rating' => 'Numbers', 'formula' => 'Numbers',
              ];
              $always_shown_types = ['name', 'subtasks'];
              $grouped = [];
              foreach ($board_columns as $col) {
                  $grouped[$type_group_map[$col['type']] ?? 'Other'][] = $col;
              }
            ?>
            <?php if (count($board_columns) >= 20): ?>
            <div class="col-search-wrap">
              <?= icon('search', 13) ?>
              <input type="text" id="col-search" placeholder="Filter columns…" autocomplete="off">
            </div>
            <?php endif; ?>
            <input type="hidden" name="hidden_column_ids" id="hidden_column_ids"
                   value="<?= e($hidden_column_ids) ?>">
            <div class="col-checkbox-list" id="col-checkbox-list">
              <?php foreach ($grouped as $group_name => $cols): ?>
              <div class="col-group">
                <div class="col-group-header"><?= e($group_name) ?></div>
                <?php foreach ($cols as $col):
                  $always    = in_array($col['type'], $always_shown_types);
                  $checked   = isset($hidden_ids_set[$col['id']]);
                  $type_slug = preg_replace('/[^a-z0-9]/', '-', strtolower($col['type']));
                ?>
                <label class="col-checkbox-row<?= $always ? ' col-always' : '' ?>"
                       data-title="<?= e(strtolower($col['title'])) ?>">
                  <input type="checkbox" class="col-hide-cb" value="<?= e($col['id']) ?>"
                         <?= $checked ? 'checked' : '' ?>
                         <?= $always  ? 'disabled' : '' ?>>
                  <span class="col-row-title"><?= e($col['title']) ?></span>
                  <span class="col-type-badge col-type-<?= e($type_slug) ?>"><?= e($col['type']) ?></span>
                  <?php if ($always): ?>
                    <span class="col-always-tag"><?= icon('eye', 11) ?> always shown</span>
                  <?php endif; ?>
                </label>
                <?php endforeach; ?>
              </div>
              <?php endforeach; ?>
            </div>
            <p class="desc">Check columns to hide from clients on task detail pages. Admin always sees all columns.</p>
            <?php else: ?>
            <?php if ($columns_fetch_error): ?>
              <p class="conn-result err" style="margin-bottom:8px;"><?= icon('circle-alert', 13) ?> <?= e($columns_fetch_error) ?></p>
            <?php elseif ($board_id === ''): ?>
              <p class="conn-result mock-mode" style="margin-bottom:8px;"><?= icon('circle-alert', 13) ?> Enter a Board ID above — column names will appear here.</p>
            <?php endif; ?>
            <textarea id="hidden_column_ids" name="hidden_column_ids" rows="2"
                      placeholder="long_text_abc, text_xyz"
                      style="font-family:monospace;"><?= e($hidden_column_ids) ?></textarea>
            <p class="desc">
              Comma-separated monday column IDs to hide from client view on task pages.
              Admin view always shows all columns.
            </p>
            <?php endif; ?>
          </div>

        </div>

        <!-- ── Copy review workflow ────────────────────────────── -->
        <div class="admin-form-section">
          <div class="admin-form-section-title">Copy review workflow</div>

          <div class="field">
            <label>
              <input type="checkbox" name="copy_review_enabled" value="1"
                     id="copy_review_enabled" <?= $copy_review_enabled ? 'checked' : '' ?>>
              &nbsp;Enable copy review buttons
            </label>
            <p class="desc">
              When enabled, tasks whose Production Status = <strong>COPYWRITING</strong> and Task Status = <strong>CLIENT REVIEW</strong>
              will show <strong>Approve Copy</strong> and <strong>Request Revision</strong> buttons instead of the generic Approved button.
            </p>
          </div>

          <div class="field">
            <label for="task_status_column_id">Task Status Column ID</label>
            <input type="text" id="task_status_column_id" name="task_status_column_id"
                   value="<?= e($task_status_col) ?>"
                   placeholder="e.g. color_mksbwnby"
                   pattern="[a-zA-Z0-9_]*"
                   title="Alphanumeric characters and underscores only, or leave empty">
            <p class="desc">Monday column ID for the Task Status column on this client's board.</p>
          </div>

          <div class="field">
            <label for="production_status_column_id">Production Status Column ID</label>
            <input type="text" id="production_status_column_id" name="production_status_column_id"
                   value="<?= e($production_status_col) ?>"
                   placeholder="e.g. color_mksb2tks"
                   pattern="[a-zA-Z0-9_]*"
                   title="Alphanumeric characters and underscores only, or leave empty">
            <p class="desc">Monday column ID for the Production Status column on this client's board.</p>
          </div>
        </div>

        <!-- ── Form embed ───────────────────────────────────────── -->
        <div class="admin-form-section">
          <div class="admin-form-section-title">Form embed</div>
          <div class="field">
            <label for="form_iframe_url">Monday Form Embed URL</label>
            <div style="display:flex;gap:8px;align-items:center;">
              <input type="url" id="form_iframe_url" name="form_iframe_url"
                     value="<?= e($iframe_url) ?>"
                     placeholder="https://forms.monday.com/forms/embed/abc123?r=use1"
                     style="flex:1;">
              <?php if ($iframe_url): ?>
                <a id="preview-form-btn" href="<?= e($iframe_url) ?>" target="_blank" rel="noopener noreferrer"
                   class="btn-secondary btn-inline"><?= icon('external-link', 14) ?> Preview</a>
              <?php else: ?>
                <button type="button" id="preview-form-btn" class="btn-secondary btn-inline" disabled>
                  <?= icon('external-link', 14) ?> Preview
                </button>
              <?php endif; ?>
            </div>
            <p class="desc">
              How to get the embed URL:<br>
              1. Open your form view in monday → <strong>Share form → Copy link</strong> — you get a URL like <code>https://wkf.ms/abc123</code><br>
              2. Open that link in a browser and copy the long form ID from the address bar (the hash after <code>/forms/</code>)<br>
              3. Build the embed URL: <code>https://forms.monday.com/forms/embed/<strong>YOUR_FORM_ID</strong>?r=use1</code><br>
              <br>
              <strong>Do NOT paste <code>wkf.ms</code> URLs</strong> (share links) or <code>forms.ms</code> URLs (monday's embed code is broken) — both will result in a blank iframe.
            </p>
          </div>
        </div>

      </form>
      <div class="admin-form-actions">
        <button type="submit" form="client-form" id="save-btn" class="btn-primary btn-inline">
          <?= $is_new ? 'Create Client' : 'Save Changes' ?>
        </button>
        <a href="/admin/clients.php" class="btn-secondary btn-inline">Cancel</a>
      </div>
    </div><!-- /form card -->

    <!-- ── Live Brand Preview ─────────────────────────────── -->
    <div>
      <div class="brand-preview-wrap">
        <div class="brand-preview-label">Live Preview</div>
        <div class="brand-preview" id="brand-preview">

          <!-- Portal nav mock -->
          <div class="bp-nav">
            <div id="bp-logo-area">
              <!-- Populated by JS -->
            </div>
            <span class="bp-portal-label">Client Portal</span>
            <div class="bp-spacer"></div>
            <div class="bp-client-pill">
              <span class="bp-client-dot" id="bp-client-dot"></span>
              <span id="bp-client-name"><?= e($name ?: 'Client Name') ?></span>
            </div>
          </div>

          <!-- Sample portal card -->
          <div class="bp-body">
            <div class="bp-card" id="bp-card">
              <div class="bp-card-header"></div>
              <div class="bp-card-body">
                <span class="bp-badge" id="bp-badge">Category</span>
                <span class="bp-card-title">Sample task title</span>
              </div>
            </div>
          </div>

        </div><!-- /brand-preview -->
        <p class="desc" style="margin-top:8px;">Updates live as you change the name, color, and logo above.</p>
      </div>
    </div><!-- /preview column -->

  </div><!-- /grid -->
</div>

<script>
(function () {
  'use strict';

  // ── Element refs ──────────────────────────────────────────────────────────
  var nameInput   = document.getElementById('name');
  var slugInput   = document.getElementById('slug');
  var colorPicker = document.getElementById('accent_color_picker');
  var colorHex    = document.getElementById('accent_color');
  var colorSwatch = document.getElementById('color-swatch');
  var logoInput   = document.getElementById('logo_url');
  var logoImg     = document.getElementById('logo-preview-img');
  var logoErr     = document.getElementById('logo-preview-error');

  // Preview elements
  var bpLogoArea  = document.getElementById('bp-logo-area');
  var bpClientDot = document.getElementById('bp-client-dot');
  var bpClientName = document.getElementById('bp-client-name');
  var bpCard      = document.getElementById('bp-card');
  var bpBadge     = document.getElementById('bp-badge');

  var form    = document.getElementById('client-form');
  var saveBtn = document.getElementById('save-btn');
  var errBox  = document.getElementById('save-error');
  var successBox  = document.getElementById('save-success');
  var successMsg  = document.getElementById('save-success-msg');
  var addUserLink = document.getElementById('add-user-link');

  var AGENCY_LOGO_URL = <?= json_encode(brand_logo_src() ?? '') ?>;
  var AGENCY_LOGO_INITIALS = <?= json_encode(BRAND_LOGO_INITIALS) ?>;

  // ── Color picker ↔ hex text sync ─────────────────────────────────────────
  colorPicker.addEventListener('input', function () {
    colorHex.value = this.value;
    applyAccentColor(this.value);
  });

  colorHex.addEventListener('input', function () {
    var val = this.value.trim();
    if (/^#[0-9a-fA-F]{6}$/.test(val)) {
      colorPicker.value = val;
      applyAccentColor(val);
    }
  });

  function applyAccentColor(hex) {
    colorSwatch.style.background = hex;
    bpClientDot.style.background = hex;
    bpBadge.style.background     = hex;
    bpCard.style.borderTopColor  = hex;
  }
  // Init
  applyAccentColor(colorPicker.value);

  // ── Logo URL: live preview + brand preview logo ───────────────────────────
  var logoDebounce = null;
  logoInput.addEventListener('input', function () {
    clearTimeout(logoDebounce);
    logoDebounce = setTimeout(refreshLogoPreview, 350);
  });

  logoImg.addEventListener('load', function () {
    logoErr.style.display = 'none';
    this.style.display = 'block';
    updateBpLogo(logoInput.value.trim());
  });
  logoImg.addEventListener('error', function () {
    this.style.display = 'none';
    if (logoInput.value.trim()) {
      logoErr.style.display = 'block';
    }
    updateBpLogo(''); // fallback to agency logo/initials in preview
  });

  function refreshLogoPreview() {
    var url = logoInput.value.trim();
    if (url) {
      logoImg.src = url;
      // load/error events handle display
    } else {
      logoImg.style.display = 'none';
      logoErr.style.display = 'none';
      updateBpLogo('');
    }
  }

  function updateBpLogo(url) {
    if (url) {
      bpLogoArea.innerHTML = '<img class="bp-logo-img" src="' + escHtml(url) + '" alt="logo">';
    } else if (AGENCY_LOGO_URL) {
      bpLogoArea.innerHTML = '<img class="bp-logo-img" src="' + escHtml(AGENCY_LOGO_URL) + '" alt="agency logo">';
    } else {
      bpLogoArea.innerHTML = '<span class="bp-logo-initials">' + escHtml(AGENCY_LOGO_INITIALS) + '</span>';
    }
  }
  // Init logo area
  updateBpLogo(<?= json_encode($logo_url) ?>);

  // ── Client name → preview ─────────────────────────────────────────────────
  nameInput.addEventListener('input', function () {
    bpClientName.textContent = this.value || 'Client Name';
    if (!slugManuallyEdited) {
      slugInput.value = this.value
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');
    }
  });

  // ── Slug: only auto-generate when not manually edited ─────────────────────
  var slugManuallyEdited = <?= (!$is_new && $client) ? 'true' : 'false' ?>;
  slugInput.addEventListener('input', function () { slugManuallyEdited = true; });

  // ── AJAX form save ────────────────────────────────────────────────────────
  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving…';
    errBox.style.display = 'none';
    successBox.style.display = 'none';

    var data = new FormData(form);
    if (!form.querySelector('[name=active]')?.checked) data.set('active', '0');

    try {
      var res  = await fetch('/admin/api/client-save.php', { method: 'POST', body: data });
      var json = await res.json();
      if (json.ok) {
        successMsg.textContent = json.is_new
          ? 'Client "' + json.name + '" created!'
          : 'Client updated!';
        addUserLink.href = '/admin/user-edit.php?client_id=' + json.id;
        successBox.style.display = 'flex';
        successBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        if (json.is_new) {
          history.replaceState(null, '', '/admin/client-edit.php?id=' + json.id);
          var hidden = document.createElement('input');
          hidden.type = 'hidden'; hidden.name = 'id'; hidden.value = json.id;
          form.appendChild(hidden);
        }
      } else {
        errBox.textContent = json.error || 'Save failed.';
        errBox.style.display = 'block';
        errBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    } catch (err) {
      errBox.textContent = 'Network error. Check your connection.';
      errBox.style.display = 'block';
    }
    saveBtn.disabled = false;
    saveBtn.textContent = form.querySelector('[name=id]') ? 'Save Changes' : '<?= $is_new ? 'Create Client' : 'Save Changes' ?>';
  });

  <?php if (!MOCK_MONDAY): ?>
  // ── Test connection ───────────────────────────────────────────────────────
  var testBtn    = document.getElementById('test-conn-btn');
  var connResult = document.getElementById('conn-result');

  testBtn.addEventListener('click', async function () {
    var boardId = document.getElementById('monday_board_id').value.trim();
    var groupId = document.getElementById('monday_group_id').value.trim();
    if (!boardId) {
      connResult.textContent = 'Enter a Board ID first.';
      connResult.className = 'conn-result err';
      connResult.style.display = 'block';
      return;
    }
    testBtn.disabled = true;
    testBtn.textContent = 'Testing…';
    connResult.style.display = 'none';

    var csrf = document.querySelector('meta[name="admin-csrf"]').content;
    try {
      var res  = await fetch('/admin/api/monday-test.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'board_id=' + encodeURIComponent(boardId) +
              '&group_id=' + encodeURIComponent(groupId) +
              '&csrf_token=' + encodeURIComponent(csrf)
      });
      var json = await res.json();
      connResult.textContent = json.message;
      connResult.className = 'conn-result ' + (json.ok ? 'ok' : 'err');
      connResult.style.display = 'block';
    } catch (err) {
      connResult.textContent = 'Request failed.';
      connResult.className = 'conn-result err';
      connResult.style.display = 'block';
    }
    testBtn.disabled = false;
    testBtn.textContent = 'Test connection';
  });
  <?php endif; ?>

  // ── Form URL preview button ───────────────────────────────────────────────
  var iframeInput  = document.getElementById('form_iframe_url');
  var previewBtn   = document.getElementById('preview-form-btn');

  if (iframeInput && previewBtn) {
    iframeInput.addEventListener('input', function () {
      var url = this.value.trim();
      var valid = url === '' || url.startsWith('https://forms.monday.com/') || url.startsWith('https://wkf.ms/');
      if (url && valid) {
        previewBtn.href = url;
        previewBtn.removeAttribute('disabled');
        previewBtn.setAttribute('target', '_blank');
        previewBtn.setAttribute('rel', 'noopener noreferrer');
        previewBtn.tagName === 'BUTTON' && previewBtn.replaceWith((function(){
          var a = document.createElement('a');
          a.id = 'preview-form-btn'; a.href = url; a.target = '_blank';
          a.rel = 'noopener noreferrer'; a.className = previewBtn.className;
          a.innerHTML = previewBtn.innerHTML;
          return a;
        })());
      } else if (!url) {
        if (previewBtn.tagName === 'A') {
          var btn = document.createElement('button');
          btn.type = 'button'; btn.id = 'preview-form-btn';
          btn.className = previewBtn.className; btn.disabled = true;
          btn.innerHTML = previewBtn.innerHTML;
          previewBtn.replaceWith(btn);
        } else {
          previewBtn.disabled = true;
        }
      }
    });
  }

  // ── Column checkboxes → hidden field sync ────────────────────────────────
  (function () {
    var list   = document.getElementById('col-checkbox-list');
    var hidden = document.getElementById('hidden_column_ids');
    if (!list || !hidden) return;

    function syncHidden() {
      var ids = Array.from(list.querySelectorAll('.col-hide-cb:checked'))
                     .map(function (cb) { return cb.value.trim(); })
                     .filter(Boolean);
      hidden.value = ids.join(', ');
    }
    list.addEventListener('change', syncHidden);

    var searchInput = document.getElementById('col-search');
    if (searchInput) {
      searchInput.addEventListener('input', function () {
        var q = this.value.toLowerCase();
        list.querySelectorAll('.col-checkbox-row').forEach(function (row) {
          row.style.display = (!q || (row.dataset.title || '').includes(q)) ? '' : 'none';
        });
        list.querySelectorAll('.col-group').forEach(function (grp) {
          var hasVisible = Array.from(grp.querySelectorAll('.col-checkbox-row'))
                                .some(function (r) { return r.style.display !== 'none'; });
          grp.style.display = hasVisible ? '' : 'none';
        });
      });
    }
  }());

  function escHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

}());
</script>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
