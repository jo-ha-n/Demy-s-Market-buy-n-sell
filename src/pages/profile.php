<?php
require_once __DIR__ . '../../includes/helpers.php';
requireLogin();

$db   = getDB();
$user = currentUser();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $address = trim($_POST['address']        ?? '');
    $contact = trim($_POST['contact_number'] ?? '');
    $newPass =      $_POST['new_password']   ?? '';
    $confirm =      $_POST['confirm_pass']   ?? '';

    $stmt = $db->prepare('UPDATE Users SET address=?, contact_number=? WHERE userID=?');
    $stmt->bind_param('ssi', $address, $contact, $user['userID']);
    $stmt->execute();

    if ($newPass !== '') {
        if (strlen($newPass) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } elseif ($newPass !== $confirm) {
            $errors[] = 'Passwords do not match.';
        } else {
            $hash  = password_hash($newPass, PASSWORD_BCRYPT);
            $upd   = $db->prepare('UPDATE Users SET password=? WHERE userID=?');
            $upd->bind_param('si', $hash, $user['userID']);
            $upd->execute();
        }
    }

    if (empty($errors)) {
        setFlash('success', 'Profile updated.');
        header('Location: /demys/pages/profile.php'); exit;
    }
    $user = currentUser(); // refresh
}

$pageTitle = "My Profile — Demy's";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
  <div class="section" style="max-width:640px;margin:0 auto">

    <div style="display:flex;align-items:center;gap:16px;margin-bottom:32px">
      <div style="width:64px;height:64px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:26px">
        <?= strtoupper(substr($user['username'],0,1)) ?>
      </div>
      <div>
        <h1 style="font-size:22px"><?= h($user['username']) ?></h1>
        <p style="font-size:13px;color:var(--muted)"><?= h($user['email']) ?> · <?= h($user['role']) ?> · Joined <?= date('M Y', strtotime($user['date_joined'])) ?></p>
      </div>
    </div>

    <?php if ($errors): ?>
    <div style="background:var(--danger-light);color:var(--danger);border:1px solid #f0b8c0;border-radius:var(--radius);padding:12px 16px;margin-bottom:20px;font-size:13.5px">
      <?php foreach ($errors as $e): ?><div>• <?= h($e) ?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="page-card">
      <h2 class="page-card-title">Edit Profile</h2>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"/>

        <div class="form-group">
          <label class="form-label">Address</label>
          <input type="text" name="address" class="form-control" placeholder="Your area or city" value="<?= h($user['address']??'') ?>"/>
        </div>
        <div class="form-group">
          <label class="form-label">Contact Number</label>
          <input type="text" name="contact_number" class="form-control" placeholder="+63 9xx xxx xxxx" value="<?= h($user['contact_number']??'') ?>"/>
        </div>

        <hr style="border:none;border-top:1px solid var(--border);margin:20px 0"/>
        <p style="font-size:13px;color:var(--muted);margin-bottom:16px">Leave blank to keep current password.</p>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">New Password</label>
            <input type="password" name="new_password" class="form-control" placeholder="min. 8 chars"/>
          </div>
          <div class="form-group">
            <label class="form-label">Confirm Password</label>
            <input type="password" name="confirm_pass" class="form-control" placeholder="repeat"/>
          </div>
        </div>

        <button type="submit" class="btn-accent btn-lg btn-block">Save Changes</button>
      </form>
    </div>

    <div style="margin-top:16px;display:flex;gap:10px">
      <a href="/demys/pages/my-listings.php" class="btn-ghost">My Listings</a>
      <a href="/demys/pages/transactions.php" class="btn-ghost">Transactions</a>
      <a href="/demys/pages/wishlist.php" class="btn-ghost">Wishlist</a>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
