<?php

// ============================================================
// AUTH
// ============================================================

/**
 * Register a new user.
 *
 * @param string $email
 * @param string $username
 * @param string $password  Plain-text; will be bcrypt-hashed.
 * @param string $role      'buyer' | 'seller' | 'admin'
 * @param string $address   Optional
 * @param string $contact   Optional
 * @return array ['userID' => int] on success, ['error' => string] on failure
 */

function registerUser(
    string $email,
    string $username,
    string $password,
    string $role = 'buyer',
    string $address = '',
    string $contact = ''
): array {
    $db   = getDB();
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $db->prepare(
        "INSERT INTO Users (email, username, password, role, address, contact_number)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('ssssss', $email, $username, $hash, $role, $address, $contact);

    if (!$stmt->execute()) {
        // Duplicate email or username
        return ['error' => 'Email or username already taken.'];
    }
    return ['userID' => $db->insert_id];
}

/**
 * Log in a user by email + password.
 * Starts a session and stores userID on success.
 *
 * @return array ['userID' => int, 'role' => string] or ['error' => string]
 */
function loginUser(string $email, string $password): array {
    $db   = getDB();
    $stmt = $db->prepare("SELECT userID, password, role FROM Users WHERE email = ?");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row || !password_verify($password, $row['password'])) {
        return ['error' => 'Invalid credentials.'];
    }

    session_start();
    $_SESSION['userID'] = $row['userID'];
    $_SESSION['role']   = $row['role'];

    return ['userID' => $row['userID'], 'role' => $row['role']];
}

/** Destroy the current session (logout). */
function logoutUser(): void {
    session_start();
    session_destroy();
}


// ============================================================
// USERS
// ============================================================

/** Get a single user by ID (password excluded). */
function getUser(int $userID): ?array {
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT userID, email, username, date_joined, address, contact_number, role
         FROM Users WHERE userID = ?"
    );
    $stmt->bind_param('i', $userID);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/** Get all users (admin use). */
function getAllUsers(): array {
    $db = getDB();
    $result = $db->query(
        "SELECT userID, email, username, date_joined, address, contact_number, role FROM Users"
    );
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Update a user's profile fields.
 * Only the fields passed (non-null) will be updated.
 */
function updateUser(
    int $userID,
    ?string $email = null,
    ?string $username = null,
    ?string $password = null,
    ?string $address = null,
    ?string $contact = null,
    ?string $role = null
): bool {
    $db = getDB();
    $fields = [];
    $types = '';
    $values = [];

    if ($email !== null) 
    { 
        $fields[] = 'email = ?';
        $types   .= 's';
        $values[] = $email;
    }
    if ($username !== null)
    {
        $fields[] = 'username = ?';
        $types   .= 's';
        $values[] = $username;
    }
    if ($password !== null)
    {
        $fields[] = 'password = ?';
        $types   .= 's';
        $values[] = password_hash($password, PASSWORD_BCRYPT);
    }
    if ($address !== null)
    {
        $fields[] = 'address = ?';
        $types   .= 's';
        $values[] = $address;
    }
    if ($contact  !== null) 
    {
        $fields[] = 'contact_number = ?';
        $types   .= 's';
        $values[] = $contact;
    }
    if ($role !== null)
    {
        $fields[] = 'role = ?';
        $types   .= 's';
        $values[] = $role;
    }

    if (empty($fields)) return false;

    $sql      = "UPDATE Users SET " . implode(', ', $fields) . " WHERE userID = ?";
    $types   .= 'i';
    $values[] = $userID;

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$values);
    return $stmt->execute();
}

/** Delete a user (cascades to their items, reviews, messages, etc.). */
function deleteUser(int $userID): bool {
    $db   = getDB();
    $stmt = $db->prepare("DELETE FROM Users WHERE userID = ?");
    $stmt->bind_param('i', $userID);
    return $stmt->execute();
}
