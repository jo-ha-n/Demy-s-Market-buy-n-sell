<?php
require_once __DIR__ . '/helpers.php';
$user  = currentUser();
$flash = getFlash();
$csrf  = csrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= h($pageTitle ?? "Demy's — Buy and Sell") ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,300&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="/assets/css/main.css"/>
  <?= $extraHead ?? '' ?>
</head>
<body>

<!--TOPBAR-->
<header class="topbar" id="topbar">
  <a href="index.php" class="topbar-logo">Logo</a>

  <form class="topbar-search" action="/pages/search.php" method="GET">
    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
    </svg>
    <input type="text" name="q" placeholder="Search deals…" value="<?= h($_GET['q'] ?? '') ?>"/>
  </form>

  <nav class="topbar-nav">
    <?php if ($user): ?>
      <a href="/pages/sell.php" class="btn-accent">+ Sell</a>
      <a href="/pages/messages.php" class="topbar-icon" title="Messages">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
      </a>
      <a href="/pages/wishlist.php" class="topbar-icon" title="Wishlist">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
        </svg>
      </a>
      <div class="topbar-profile-wrap">
        <button class="topbar-avatar" id="profileBtn">
          <?= strtoupper(substr($user['username'], 0, 1)) ?>
        </button>
        <div class="profile-dropdown" id="profileDropdown">
          <div class="profile-dropdown-header">
            <span class="pd-name"><?= h($user['username']) ?></span>
            <span class="pd-role"><?= h($user['role']) ?></span>
          </div>
          <a href="/pages/profile.php">My Profile</a>
          <a href="/pages/my-listings.php">My Listings</a>
          <a href="/pages/transactions.php">Transactions</a>
          <div class="pd-divider"></div>
          <a href="/pages/logout.php" class="pd-danger">Sign Out</a>
        </div>
      </div>
    <?php else: ?>
      <a href="/pages/login.php" class="btn-ghost">Log In</a>
      <a href="/pages/register.php" class="btn-accent">Sign Up</a>
    <?php endif; ?>
    <button class="theme-toggle" id="themeToggle" title="Toggle theme">
      <svg class="icon-sun" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
        <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
      </svg>
      <svg class="icon-moon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
      </svg>
    </button>
  </nav>
</header>

<!--FLASH-->
<?php if ($flash): ?>
<div class="flash flash--<?= h($flash['type']) ?>" id="flashMsg">
  <?= h($flash['msg']) ?>
  <button onclick="this.parentElement.remove()" class="flash-close">✕</button>
</div>
<?php endif; ?>

<main class="page-main">
