<?php
if (!isset($page_title)) $page_title = 'Admin — ' . APP_NAME;
$admin = current_admin();
// Determine active nav link from current script path
$current = basename($_SERVER['PHP_SELF']);
$current_dir = basename(dirname($_SERVER['PHP_SELF']));
$_agency_logo = brand_logo_src();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($page_title) ?></title>
<meta name="admin-csrf" content="<?= e(admin_csrf_token()) ?>">
<?php if (FAVICON_URL): ?><link rel="icon" type="image/png" href="<?= e(FAVICON_URL) ?>"><?php endif; ?>
<link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="/assets/css/admin.css">
<style>
:root { --brand-primary: <?= e(BRAND_PRIMARY_COLOR) ?>; --brand-primary-dark: <?= e(BRAND_SECONDARY_COLOR) ?>; --brand-primary-light: <?= e(BRAND_PRIMARY_LIGHT) ?>; }
.brand-logo { background: transparent; }
</style>
</head>
<body>
<?php if ($admin): ?>
<nav class="topnav admin-nav">
  <div class="topnav-inner">
    <a href="/admin/index.php" class="brand">
      <?php if ($_agency_logo): ?>
        <span class="brand-logo"><img src="<?= e($_agency_logo) ?>" alt="<?= e(AGENCY_NAME) ?> logo"></span>
      <?php else: ?>
        <span class="brand-logo brand-initials"><?= e(BRAND_LOGO_INITIALS) ?></span>
      <?php endif; ?>
      <span class="brand-sub">Admin</span>
    </a>
    <div class="admin-nav-links">
      <a href="/admin/index.php" class="admin-nav-link <?= in_array($current, ['index.php']) ? 'active' : '' ?>">Dashboard</a>
      <a href="/admin/clients.php" class="admin-nav-link <?= in_array($current, ['clients.php', 'client-edit.php']) ? 'active' : '' ?>">Clients</a>
      <a href="/admin/users.php" class="admin-nav-link <?= in_array($current, ['users.php', 'user-edit.php']) ? 'active' : '' ?>">Users</a>
      <a href="/admin/submissions.php" class="admin-nav-link <?= $current === 'submissions.php' ? 'active' : '' ?>">Submissions</a>
      <span class="admin-badge">Admin</span>
      <a href="/admin/logout.php" class="admin-nav-logout">Sign out</a>
    </div>
  </div>
</nav>
<?php endif; ?>
<?php if (MOCK_MONDAY): ?>
<div class="mock-banner"><?= icon('settings', 14) ?> Mock mode active — monday.com API calls are simulated.</div>
<?php endif; ?>
<main class="admin-main">
