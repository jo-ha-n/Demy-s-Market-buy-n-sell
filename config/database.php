<?php

// ============================================================
// DB CONNECTION
// ============================================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'dummy_demys_db');

function getDB(): mysqli {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

// ============================================================
// HELPERS
// ============================================================

/** Send a JSON response and exit. */
function respond(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/** Return the current logged-in userID from session, or null. */
function currentUserID(): ?int {
    return $_SESSION['userID'] ?? null;
}

/** Require a logged-in session or abort with 401. */
function requireAuth(): int {
    $id = currentUserID();
    if (!$id) respond(['error' => 'Unauthenticated'], 401);
    return $id;
}

/** Require a specific role ('admin', 'seller', 'buyer'). */
function requireRole(string $role): void {
    $userID = requireAuth();
    $db   = getDB();
    $stmt = $db->prepare("SELECT role FROM Users WHERE userID = ?");
    $stmt->bind_param('i', $userID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row || $row['role'] !== $role) {
        respond(['error' => 'Forbidden'], 403);
    }
}