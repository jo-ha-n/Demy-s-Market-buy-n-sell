<?php
require_once __DIR__ . '/../includes/helpers.php';
if (isLoggedIn()) { header('Location: /demys/index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $email    = trim($_POST['email'] ?? '');
    $password =      $_POST['password'] ?? '';
    $db       = getDB();
    $stmt     = $db->prepare('SELECT userID, password, username FROM Users WHERE email = ?');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row && password_verify($password, $row['password'])) {
        $_SESSION['userID'] = $row['userID'];
        setFlash('success', "Welcome back, {$row['username']}!");
        header('Location: ' . ($_GET['next'] ?? '/demys/index.php')); exit;
    } else {
        $error = 'Invalid email or password.';
    }
}

$pageTitle = "Log In — Demy's";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="auth-wrap">
  <div class="auth-card">
    <h1 class="auth-title">Welcome back</h1>
    <p class="auth-sub">Log in to continue to Demy's.</p>

    <?php if ($error): ?>
    <div style="background:var(--danger-light);color:var(--danger);border:1px solid #f0b8c0;border-radius:var(--radius);padding:12px 16px;margin-bottom:20px;font-size:13.5px">
      <?= h($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"/>
      <div class="form-group">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" placeholder="you@example.com" value="<?= h($_POST['email'] ?? '') ?>" required/>
      </div>
      <div class="form-group">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" placeholder="••••••••" required/>
      </div>
      <button type="submit" class="btn-accent btn-block btn-lg">Log In</button>
    </form>

    <p class="auth-footer">Don't have an account? <a href="/demys/pages/register.php">Sign up</a></p>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
