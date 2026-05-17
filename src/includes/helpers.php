<?php
// Demy's — Shared Helpers
require_once dirname(__DIR__, 2) . '/config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Auth
function isLoggedIn(): bool {
    return isset($_SESSION['userID']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ../pages/login.php');
        exit;
    }
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    $db  = getDB();
    $id  = (int) $_SESSION['userID'];
    $stmt = $db->prepare('SELECT userID, email, username, role, address, contact_number, date_joined FROM Users WHERE userID = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// Flash messages
function setFlash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function getFlash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

// CSRF
function csrfToken(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
}

// Sanitize
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function formatPrice(float $p): string {
    return '₱' . number_format($p, 2);
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)        return 'just now';
    if ($diff < 3600)      return floor($diff/60) . 'm ago';
    if ($diff < 86400)     return floor($diff/3600) . 'h ago';
    if ($diff < 604800)    return floor($diff/86400) . 'd ago';
    return date('M j, Y', strtotime($datetime));
}
