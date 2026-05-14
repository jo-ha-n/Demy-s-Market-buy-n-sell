<?php
require_once __DIR__ . '/../includes/helpers.php';
session_destroy();
header('Location: /demys/index.php');
exit;
