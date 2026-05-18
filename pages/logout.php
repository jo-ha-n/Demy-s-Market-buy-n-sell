<?php
require_once __DIR__ . '/../src/includes/helpers.php';
session_destroy();
header('Location: ../src/index.html');
exit;
