<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Fully destroy the PHP session
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']
    );
}
session_destroy();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <title>Signing out…</title>
</head>
<body>
  <script>
    try {
      localStorage.removeItem('demys-session');
    } catch (e) {}
    window.location.href = '../src/index.html?loggedout=1';
  </script>
  <p>Signing out… If you are not redirected, <a href="../src/index.html?loggedout=1">click here</a>.</p>
</body>
</html>
