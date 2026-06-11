<?php
if (!isset($page_title)) $page_title = APP_NAME;
$user = current_user();
$_agency_logo = brand_logo_src();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($page_title) ?></title>
<?php if (FAVICON_URL): ?><link rel="icon" type="image/png" href="<?= e(FAVICON_URL) ?>"><?php endif; ?>
<link rel="dns-prefetch" href="https://forms.monday.com">
<link rel="preconnect" href="https://forms.monday.com" crossorigin>
<link rel="preconnect" href="https://cdn.monday.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
:root {
  --brand-primary: <?= e(BRAND_PRIMARY_COLOR) ?>;
  --brand-primary-dark: <?= e(BRAND_SECONDARY_COLOR) ?>;
  --brand-primary-light: <?= e(BRAND_PRIMARY_LIGHT) ?>;
<?php if ($user): ?>
  --client-accent: <?= e($user['accent_color']) ?>;
<?php endif; ?>
}
<?php if ($user): ?>
.client-switcher { position:relative; }
.client-switcher-trigger { display:flex; align-items:center; gap:6px; background:none; border:none; cursor:pointer; color:inherit; font:inherit; font-size:0.9rem; padding:4px 6px; border-radius:6px; }
.client-switcher-trigger:hover { background:rgba(255,255,255,.12); }
.client-switcher-menu { position:absolute; right:0; top:calc(100% + 6px); min-width:180px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; box-shadow:0 4px 16px rgba(0,0,0,.12); z-index:200; padding:4px 0; }
.client-switcher-item { display:flex; align-items:center; gap:8px; padding:9px 14px; text-decoration:none; color:#111; font-size:0.875rem; white-space:nowrap; }
.client-switcher-item:hover { background:#f9fafb; }
.client-switcher-item.is-active { font-weight:600; background:#f0f9ff; }
<?php endif; ?>
</style>
<?php if (!empty($head_extra)) echo $head_extra; ?>
</head>
<body>
<?php if ($user): ?>
<nav class="topnav">
  <div class="topnav-inner">
    <a href="/dashboard.php" class="brand">
      <?php if (!empty($user['logo_url'])): ?>
        <span class="brand-logo"><img src="<?= e($user['logo_url']) ?>" alt="<?= e($user['client_name']) ?> logo"></span>
      <?php elseif ($_agency_logo): ?>
        <span class="brand-logo"><img src="<?= e($_agency_logo) ?>" alt="<?= e(AGENCY_NAME) ?> logo"></span>
      <?php else: ?>
        <span class="brand-logo brand-initials"><?= e(BRAND_LOGO_INITIALS) ?></span>
      <?php endif; ?>
      <span class="brand-sub">Client Portal</span>
    </a>
    <div class="nav-links">
      <a href="/dashboard.php" class="nav-link">Dashboard</a>
      <a href="/new-request.php" class="nav-link">New Request</a>
      <?php if (!empty($user['clients']) && count($user['clients']) > 1): ?>
      <div class="client-switcher">
        <button class="client-switcher-trigger" onclick="toggleClientSwitcher(event)" aria-haspopup="true">
          <span class="client-dot" style="background: <?= e($user['accent_color']) ?>"></span>
          <?= e($user['client_name']) ?>
          <?= icon('chevron-down', 13) ?>
        </button>
        <div class="client-switcher-menu" id="client-switcher-menu" hidden>
          <?php foreach ($user['clients'] as $c): ?>
            <a href="/api/switch-client.php?client_id=<?= (int)$c['id'] ?>"
               class="client-switcher-item<?= $c['id'] === $user['active_client_id'] ? ' is-active' : '' ?>">
              <span class="client-dot" style="background: <?= e($c['accent_color']) ?>"></span>
              <?= e($c['name']) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php else: ?>
      <div class="nav-client">
        <span class="client-dot" style="background: <?= e($user['accent_color']) ?>"></span>
        <span class="client-name"><?= e($user['client_name']) ?></span>
      </div>
      <?php endif; ?>
      <span class="nav-user"><?= e($user['full_name'] ?: $user['username']) ?></span>
      <a href="/logout.php" class="nav-logout">Sign out</a>
    </div>
  </div>
</nav>
<?php endif; ?>
<?php if (MOCK_MONDAY): ?>
<div class="mock-banner"><?= icon('settings', 14) ?> Mock mode active — submissions are simulated, no real monday.com calls are made.</div>
<?php endif; ?>
<script>
window.toggleClientSwitcher = function(e) {
  e.stopPropagation();
  var m = document.getElementById('client-switcher-menu');
  if (m) m.hidden = !m.hidden;
};
document.addEventListener('click', function() {
  var m = document.getElementById('client-switcher-menu');
  if (m) m.hidden = true;
});
</script>
<main class="main">
