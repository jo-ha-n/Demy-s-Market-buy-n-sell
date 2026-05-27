<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Wipe session data
$_SESSION = [];
// Delete the session cookie from the browser
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
    try { localStorage.removeItem('demys-session'); } catch (e) {}
    window.location.href = '../src/index.html?loggedout=1';
  </script>
  <p>Signing out… <a href="../src/index.html?loggedout=1">Click here</a> if not redirected.</p>
</body>
</html>
