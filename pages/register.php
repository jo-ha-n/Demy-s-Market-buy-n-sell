<?php
require_once __DIR__ . '/../includes/helpers.php';
if (isLoggedIn()) { header('Location: /demys/index.php'); exit; }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $email    = trim($_POST['email']    ?? '');
    $username = trim($_POST['username'] ?? '');
    $password =      $_POST['password'] ?? '';
    $confirm  =      $_POST['confirm']  ?? '';
    $role     =      $_POST['role']     ?? 'buyer';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
    if (strlen($username) < 3)   $errors[] = 'Username must be at least 3 characters.';
    if (strlen($password) < 8)   $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm)  $errors[] = 'Passwords do not match.';
    if (!in_array($role, ['buyer','seller'])) $role = 'buyer';

    if (empty($errors)) {
        $db   = getDB();
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $db->prepare('INSERT INTO Users (email,username,password,role) VALUES (?,?,?,?)');
        $stmt->bind_param('ssss', $email, $username, $hash, $role);
        if ($stmt->execute()) {
            $_SESSION['userID'] = $db->insert_id;
            setFlash('success', "Welcome to Demy's, {$username}!");
            header('Location: /demys/index.php'); exit;
        } else {
            $errors[] = $db->errno === 1062 ? 'Email or username already taken.' : 'Registration failed. Try again.';
        }
    }
}

$pageTitle = "Sign Up — Demy's";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="auth-wrap">
  <div class="auth-card">
    <h1 class="auth-title">Create account</h1>
    <p class="auth-sub">Join the community. Buy and sell with ease.</p>

    <?php if ($errors): ?>
    <div style="background:var(--danger-light);color:var(--danger);border:1px solid #f0b8c0;border-radius:var(--radius);padding:12px 16px;margin-bottom:20px;font-size:13.5px">
      <?php foreach ($errors as $e): ?><div>• <?= h($e) ?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"/>

      <div class="form-group">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" placeholder="you@example.com" value="<?= h($_POST['email'] ?? '') ?>" required/>
      </div>
      <div class="form-group">
        <label class="form-label">Username</label>
        <input type="text" name="username" class="form-control" placeholder="your_handle" value="<?= h($_POST['username'] ?? '') ?>" required/>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-control" placeholder="min. 8 chars" required/>
        </div>
        <div class="form-group">
          <label class="form-label">Confirm</label>
          <input type="password" name="confirm" class="form-control" placeholder="repeat password" required/>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">I want to…</label>
        <select name="role" class="form-control">
          <option value="buyer"  <?= ($_POST['role']??'buyer')==='buyer'?'selected':'' ?>>Buy items</option>
          <option value="seller" <?= ($_POST['role']??'')==='seller'?'selected':'' ?>>Sell items</option>
        </select>
      </div>

      <button type="submit" class="btn-accent btn-block btn-lg">Create Account</button>
    </form>

    <p class="auth-footer">Already have an account? <a href="/demys/pages/login.php">Log in</a></p>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
