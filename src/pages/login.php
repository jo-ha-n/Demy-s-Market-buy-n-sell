<?php
require_once __DIR__ . '/../includes/helpers.php';
$csrf = csrfToken();

$ajax = $_GET['ajax'] ?? $_POST['ajax'] ?? null;
if ($ajax === '1' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = currentUser();
    respond([
        'csrfToken' => csrfToken(),
        'user'      => $user ? ['userID' => $user['userID'], 'username' => $user['username'], 'role' => $user['role']] : null,
    ]);
}

// isLoggedIn guard removed — PHP session may still be alive after JS logout.

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
        $user = ['userID' => $row['userID'], 'username' => $row['username'], 'role' => $row['role'] ?? 'buyer'];
        if ($ajax === '1') {
            respond(['success' => true, 'message' => "Welcome back, {$row['username']}!", 'user' => $user]);
        }
        $sessionParam = urlencode(json_encode($user));
        $next = $_GET['next'] ?? '../index.html';
        $sep  = strpos($next, '?') !== false ? '&' : '?';
        header('Location: ' . $next . $sep . 'session=' . $sessionParam); exit;
    } else {
        if ($ajax === '1') {
            respond(['success' => false, 'error' => 'Invalid email or password.']);
        }
        $error = 'Invalid email or password.';
    }
}

$pageTitle = "Log In — Demy's";
$hideHeader = true;
$hideFooter = true;
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.login-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.45);
  backdrop-filter: blur(6px);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  z-index: 50;
  animation: fadeOverlay 0.3s ease;
}
@keyframes fadeOverlay { from{opacity:0} to{opacity:1} }
.login-modal {
  width: 100%; max-width: 820px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 20px;
  box-shadow: 0 24px 64px rgba(0,0,0,0.22);
  display: grid;
  grid-template-columns: 1fr 1fr;
  overflow: hidden;
  animation: slideUp 0.35s cubic-bezier(0.16,1,0.3,1);
}
@keyframes slideUp {
  from{opacity:0;transform:translateY(24px) scale(0.97)}
  to{opacity:1;transform:translateY(0) scale(1)}
}
.login-illus {
  background: var(--text);
  position: relative;
  display: flex; flex-direction: column;
  align-items: center; justify-content: flex-end;
  padding: 40px 32px 44px;
  overflow: hidden; min-height: 480px;
}
[data-theme="dark"] .login-illus { background: #1a1918; }
.login-illus-bg::before {
  content:''; position:absolute; width:340px; height:340px;
  border-radius:50%; background:var(--accent); opacity:0.12;
  top:-80px; left:-80px;
}
.login-illus-bg::after {
  content:''; position:absolute; width:220px; height:220px;
  border-radius:50%; background:var(--accent); opacity:0.08;
  bottom:40px; right:-60px;
}
.login-illus-art { position:relative; z-index:1; margin-bottom:28px; }
.illus-svg { width:220px; height:200px; display:block; margin:0 auto; }
.login-illus-text { position:relative; z-index:1; text-align:center; }
.login-illus-text h2 {
  font-family:'Syne',sans-serif; font-weight:800; font-size:22px;
  color:#fff; margin-bottom:8px; letter-spacing:-0.5px;
}
[data-theme="dark"] .login-illus-text h2 { color:var(--text); }
.login-illus-text p { font-size:13px; color:rgba(255,255,255,0.55); line-height:1.6; }
[data-theme="dark"] .login-illus-text p { color:var(--muted); }
.login-illus-brand {
  position:absolute; top:28px; left:28px;
  font-family:'Syne',sans-serif; font-weight:800; font-size:18px;
  color:#fff; letter-spacing:-0.5px; z-index:2;
}
[data-theme="dark"] .login-illus-brand { color:var(--accent); }
.login-illus-brand span { color:var(--accent); }
.login-form-panel {
  padding: 48px 44px;
  display: flex; flex-direction:column; justify-content:center;
}
.login-form-title {
  font-family:'Syne',sans-serif; font-weight:800; font-size:28px;
  letter-spacing:-0.8px; color:var(--text); margin-bottom:6px;
}
.login-form-sub { font-size:13.5px; color:var(--muted); margin-bottom:32px; }
.login-error {
  background:var(--danger-light); color:var(--danger);
  border:1px solid #f0b8c0; border-radius:var(--radius);
  padding:11px 14px; margin-bottom:20px; font-size:13px;
  display:flex; align-items:center; gap:8px;
}
.login-field { display:flex; flex-direction:column; gap:6px; margin-bottom:18px; }
.login-label {
  font-size:11px; font-weight:700; text-transform:uppercase;
  letter-spacing:0.08em; color:var(--muted);
}
.login-input {
  background:var(--bg); border:1.5px solid var(--border);
  border-radius:var(--radius); padding:11px 14px;
  font-size:14px; color:var(--text); outline:none;
  transition:border-color 0.2s,background 0.2s; width:100%;
}
.login-input:focus { border-color:var(--accent); background:var(--surface); }
.login-input::placeholder { color:var(--muted2); }
.login-divider {
  display:flex; align-items:center; gap:12px; margin:16px 0;
}
.login-divider::before,.login-divider::after {
  content:''; flex:1; height:1px; background:var(--border);
}
.login-divider span {
  font-size:11px; text-transform:uppercase; letter-spacing:0.06em; color:var(--muted2);
}
.btn-google {
  width:100%; display:flex; align-items:center; justify-content:center;
  gap:10px; padding:11px 18px; background:var(--bg);
  border:1.5px solid var(--border); border-radius:var(--radius);
  font-size:14px; font-weight:500; color:var(--text); cursor:pointer;
  transition:background 0.2s,border-color 0.2s,transform 0.15s;
  font-family:'DM Sans',sans-serif;
}
.btn-google:hover { background:var(--surface2); border-color:var(--border2); transform:translateY(-1px); }
.login-submit {
  width:100%; padding:12px; background:var(--accent); color:#fff;
  border:none; border-radius:var(--radius); font-size:15px;
  font-weight:600; font-family:'Syne',sans-serif; cursor:pointer;
  transition:background 0.2s,transform 0.15s; letter-spacing:-0.2px; margin-bottom:4px;
}
.login-submit:hover { background:var(--accent-h); transform:translateY(-1px); }
.login-footer { text-align:center; margin-top:20px; font-size:13px; color:var(--muted); }
.login-footer a { color:var(--accent); font-weight:600; }
.login-footer a:hover { text-decoration:underline; }
@media(max-width:680px){
  .login-modal{grid-template-columns:1fr;max-width:440px;}
  .login-illus{display:none;}
  .login-form-panel{padding:36px 28px;}
}
</style>
<link rel="stylesheet" href="../assets/css/main.css"/>
<div class="login-modal-overlay">
  <div class="login-modal">
    <div class="login-illus">
      <div class="login-illus-bg"></div>
      <div class="login-illus-brand">Demy<span>'s</span></div>
      <div class="login-illus-art">
        <svg class="illus-svg" viewBox="0 0 220 200" fill="none" xmlns="http://www.w3.org/2000/svg">
          <rect x="20" y="148" width="180" height="10" rx="4" fill="rgba(255,255,255,0.15)"/>
          <rect x="55" y="112" width="110" height="38" rx="6" fill="rgba(255,255,255,0.18)"/>
          <rect x="58" y="72" width="104" height="44" rx="5" fill="rgba(255,255,255,0.22)"/>
          <rect x="62" y="76" width="96" height="36" rx="3" fill="rgba(232,65,10,0.35)"/>
          <rect x="68" y="82" width="50" height="4" rx="2" fill="rgba(255,255,255,0.6)"/>
          <rect x="68" y="91" width="34" height="3" rx="2" fill="rgba(255,255,255,0.35)"/>
          <rect x="68" y="99" width="44" height="3" rx="2" fill="rgba(255,255,255,0.35)"/>
          <ellipse cx="110" cy="148" rx="32" ry="18" fill="rgba(255,255,255,0.12)"/>
          <rect x="90" y="110" width="40" height="38" rx="14" fill="rgba(255,255,255,0.2)"/>
          <circle cx="110" cy="96" r="20" fill="rgba(255,255,255,0.25)"/>
          <circle cx="103" cy="95" r="6" stroke="rgba(232,65,10,0.8)" stroke-width="2" fill="none"/>
          <circle cx="117" cy="95" r="6" stroke="rgba(232,65,10,0.8)" stroke-width="2" fill="none"/>
          <line x1="109" y1="95" x2="111" y2="95" stroke="rgba(232,65,10,0.8)" stroke-width="2"/>
          <line x1="97" y1="95" x2="93" y2="93" stroke="rgba(232,65,10,0.8)" stroke-width="1.5"/>
          <line x1="123" y1="95" x2="127" y2="93" stroke="rgba(232,65,10,0.8)" stroke-width="1.5"/>
          <path d="M95 128 Q80 136 72 138" stroke="rgba(255,255,255,0.2)" stroke-width="12" stroke-linecap="round"/>
          <path d="M125 128 Q140 136 148 138" stroke="rgba(255,255,255,0.2)" stroke-width="12" stroke-linecap="round"/>
          <rect x="148" y="60" width="48" height="20" rx="10" fill="rgba(232,65,10,0.25)" stroke="rgba(232,65,10,0.5)" stroke-width="1"/>
          <text x="172" y="74" text-anchor="middle" font-size="9" fill="rgba(255,255,255,0.8)" font-family="sans-serif">buy &amp; sell</text>
          <rect x="20" y="80" width="40" height="20" rx="10" fill="rgba(255,255,255,0.1)" stroke="rgba(255,255,255,0.2)" stroke-width="1"/>
          <text x="40" y="94" text-anchor="middle" font-size="9" fill="rgba(255,255,255,0.6)" font-family="sans-serif">secure</text>
        </svg>
      </div>
      <div class="login-illus-text">
        <h2>Your marketplace<br>awaits</h2>
        <p>Discover deals and connect<br>with buyers across your city.</p>
      </div>
    </div>

    <div class="login-form-panel">
      <h1 class="login-form-title">Welcome back</h1>
      <p class="login-form-sub">Log in to continue to Demy's.</p>

      <?php if ($error): ?>
      <div class="login-error">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= h($error) ?>
      </div>
      <?php endif; ?>

      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"/>
        <div class="login-field">
          <label class="login-label">Username or Email</label>
          <input type="email" name="email" class="login-input" placeholder="you@example.com"
                value="<?= h($_POST['email'] ?? '') ?>" required/>
        </div>
        <div class="login-field">
          <label class="login-label">Password</label>
          <input type="password" name="password" class="login-input" placeholder="••••••••" required/>
        </div>
        <button type="submit" class="login-submit">Log In</button>
      </form>

      <div class="login-divider"><span>or</span></div>

      <button class="btn-google" type="button" onclick="alert('Google login coming soon!')">
        <svg width="18" height="18" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
          <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
          <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
          <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
          <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
        </svg>
        Log in with Google
      </button>

      <p class="login-footer">Don't have an account? <a href="../pages/register.php">Sign up</a></p>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>