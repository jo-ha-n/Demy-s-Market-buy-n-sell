<?php
require_once __DIR__ . '/../src/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
session_unset();
session_destroy();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <title>Signing out…</title>
  <meta http-equiv="refresh" content="1;url=../src/index.html"/>
</head>
<body>
  <script>
    try {
      localStorage.removeItem('demys-session');
    } catch (e) {
      // ignore
    }
    window.location.href = '../src/index.html';
  </script>
  <p>Signing out… If you are not redirected, <a href="../src/index.html">click here</a>.</p>
</body>
</html>
