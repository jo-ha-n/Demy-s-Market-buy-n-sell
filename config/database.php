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
    $hash = password_hash($password, PASSWORD_BCRYPT);

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


// ============================================================
// CATEGORY
// ============================================================

function createCategory(string $name): int {
    $db   = getDB();
    $stmt = $db->prepare("INSERT INTO Category (category_name) VALUES (?)");
    $stmt->bind_param('s', $name);
    $stmt->execute();
    return $db->insert_id;
}

function getAllCategories(): array {
    return getDB()->query("SELECT * FROM Category")->fetch_all(MYSQLI_ASSOC);
}

function updateCategory(int $categoryID, string $name): bool {
    $db   = getDB();
    $stmt = $db->prepare("UPDATE Category SET category_name = ? WHERE categoryID = ?");
    $stmt->bind_param('si', $name, $categoryID);
    return $stmt->execute();
}

function deleteCategory(int $categoryID): bool {
    $db   = getDB();
    $stmt = $db->prepare("DELETE FROM Category WHERE categoryID = ?");
    $stmt->bind_param('i', $categoryID);
    return $stmt->execute();
}


// ============================================================
// TAG
// ============================================================

function createTag(string $name): int {
    $db   = getDB();
    $stmt = $db->prepare("INSERT IGNORE INTO Tag (name) VALUES (?)");
    $stmt->bind_param('s', $name);
    $stmt->execute();
    // If the tag already existed, fetch its ID
    if ($db->insert_id === 0) {
        $s = $db->prepare("SELECT tagID FROM Tag WHERE name = ?");
        $s->bind_param('s', $name);
        $s->execute();
        return (int)$s->get_result()->fetch_assoc()['tagID'];
    }
    return $db->insert_id;
}

function getAllTags(): array {
    return getDB()->query("SELECT * FROM Tag")->fetch_all(MYSQLI_ASSOC);
}

function deleteTag(int $tagID): bool {
    $db   = getDB();
    $stmt = $db->prepare("DELETE FROM Tag WHERE tagID = ?");
    $stmt->bind_param('i', $tagID);
    return $stmt->execute();
}


// ============================================================
// ITEM
// ============================================================

/**
 * Create a new listing.
 *
 * @param int    $sellerID
 * @param int    $categoryID
 * @param string $title
 * @param float  $price
 * @param string $description
 * @param string $address
 * @return int  New itemID
 */
function createItem(
    int $sellerID,
    int $categoryID,
    string $title,
    float $price,
    string $description = '',
    string $address = ''
): int {
    $db   = getDB();
    $stmt = $db->prepare(
        "INSERT INTO Item (sellerID, categoryID, title, price, description, address)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('iisdss', $sellerID, $categoryID, $title, $price, $description, $address);
    $stmt->execute();
    return $db->insert_id;
}

/**
 * Fetch active items with optional filters.
 *
 * @param int|null    $categoryID  Filter by category
 * @param string|null $search      Search in title / description
 * @param string      $sort        'newest' | 'price_asc' | 'price_desc'
 * @param int         $limit
 * @param int         $offset      For pagination
 */
function getItems(
    ?int $categoryID = null,
    ?string $search = null,
    string $sort = 'newest',
    int $limit = 20,
    int $offset = 0
): array {
    $db     = getDB();
    $where  = ["i.status = 'active'"];
    $types  = '';
    $values = [];

    if ($categoryID !== null) {
        $where[]  = 'i.categoryID = ?';
        $types   .= 'i';
        $values[] = $categoryID;
    }
    if ($search !== null) {
        $like     = "%$search%";
        $where[]  = '(i.title LIKE ? OR i.description LIKE ?)';
        $types   .= 'ss';
        $values[] = $like;
        $values[] = $like;
    }

    $orderMap = [
        'newest'     => 'i.created_at DESC',
        'price_asc'  => 'i.price ASC',
        'price_desc' => 'i.price DESC',
    ];
    $order = $orderMap[$sort] ?? 'i.created_at DESC';

    $sql = "SELECT i.*, u.username AS seller_name,
                   c.category_name,
                   ROUND(AVG(r.rating), 1) AS avg_rating,
                   COUNT(r.reviewID)       AS review_count
            FROM Item i
            JOIN Users    u ON u.userID     = i.sellerID
            JOIN Category c ON c.categoryID = i.categoryID
            LEFT JOIN Reviews r ON r.itemID = i.itemID
            WHERE " . implode(' AND ', $where) . "
            GROUP BY i.itemID
            ORDER BY $order
            LIMIT ? OFFSET ?";

    $types   .= 'ii';
    $values[] = $limit;
    $values[] = $offset;

    $stmt = $db->prepare($sql);
    if (!empty($values)) $stmt->bind_param($types, ...$values);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Get a single item with seller info, category, average rating, and images. */
function getItem(int $itemID): ?array {
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT i.*, u.username AS seller_name, u.contact_number AS seller_contact,
                c.category_name,
                ROUND(AVG(r.rating), 1) AS avg_rating,
                COUNT(r.reviewID)       AS review_count
         FROM Item i
         JOIN Users    u ON u.userID     = i.sellerID
         JOIN Category c ON c.categoryID = i.categoryID
         LEFT JOIN Reviews r ON r.itemID = i.itemID
         WHERE i.itemID = ?
         GROUP BY i.itemID"
    );
    $stmt->bind_param('i', $itemID);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    if (!$item) return null;

    // Attach images
    $item['images'] = getItemImages($itemID);
    // Attach tags
    $item['tags'] = getItemTags($itemID);

    return $item;
}

/** Update item fields (only non-null values are changed). */
function updateItem(
    int $itemID,
    ?int $categoryID = null,
    ?string $title = null,
    ?float $price = null,
    ?string $description = null,
    ?string $address = null,
    ?string $status = null
): bool {
    $db     = getDB();
    $fields = [];
    $types  = '';
    $values = [];

    if ($categoryID  !== null) { $fields[] = 'categoryID = ?';  $types .= 'i'; $values[] = $categoryID; }
    if ($title       !== null) { $fields[] = 'title = ?';       $types .= 's'; $values[] = $title; }
    if ($price       !== null) { $fields[] = 'price = ?';       $types .= 'd'; $values[] = $price; }
    if ($description !== null) { $fields[] = 'description = ?'; $types .= 's'; $values[] = $description; }
    if ($address     !== null) { $fields[] = 'address = ?';     $types .= 's'; $values[] = $address; }
    if ($status      !== null) { $fields[] = 'status = ?';      $types .= 's'; $values[] = $status; }

    if (empty($fields)) return false;

    $sql    = "UPDATE Item SET " . implode(', ', $fields) . " WHERE itemID = ?";
    $types .= 'i';
    $values[] = $itemID;

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$values);
    return $stmt->execute();
}

/**
 * Search items by keyword and/or tags.
 *
 * @param string|null $search      Searches title & description
 * @param array       $tagIDs      Filter by tag IDs
 * @param string      $tagLogic    'AND' — item must have ALL tags | 'OR' — item must have ANY tag
 * @param int|null    $categoryID  Optional category filter
 * @param string      $sort        'newest' | 'price_asc' | 'price_desc'
 * @param int         $limit
 * @param int         $offset
 */
function searchItems(
    ?string $search = null,
    array $tagIDs = [],
    string $tagLogic = 'AND',
    ?int $categoryID = null,
    string $sort = 'newest',
    int $limit = 20,
    int $offset = 0
): array {
    $db     = getDB();
    $where  = ["i.status = 'active'"];
    $types  = '';
    $values = [];

    if ($search !== null) {
        $like     = "%$search%";
        $where[]  = '(i.title LIKE ? OR i.description LIKE ?)';
        $types   .= 'ss';
        $values[] = $like;
        $values[] = $like;
    }

    if ($categoryID !== null) {
        $where[]  = 'i.categoryID = ?';
        $types   .= 'i';
        $values[] = $categoryID;
    }

    $tagJoin   = '';
    $having    = '';
    $tagSelect = '';

    if (!empty($tagIDs)) {
        $tagLogic  = strtoupper($tagLogic) === 'OR' ? 'OR' : 'AND';
        $placeholders = implode(',', array_fill(0, count($tagIDs), '?'));

        $tagJoin   = "JOIN Item_Tag it ON it.itemID = i.itemID AND it.tagID IN ($placeholders)";
        $tagSelect = 'COUNT(DISTINCT it.tagID) AS matched_tags,';
        $types    .= str_repeat('i', count($tagIDs));
        $values    = array_merge($values, $tagIDs);

        $having = $tagLogic === 'AND'
            ? 'HAVING matched_tags = ' . count($tagIDs)  // must have ALL tags
            : 'HAVING matched_tags >= 1';                // must have ANY tag
    }

    $orderMap = [
        'newest'     => 'i.created_at DESC',
        'price_asc'  => 'i.price ASC',
        'price_desc' => 'i.price DESC',
    ];
    $order = $orderMap[$sort] ?? 'i.created_at DESC';

    $sql = "
        SELECT i.*,
               u.username AS seller_name,
               c.category_name,
               ROUND(AVG(r.rating), 1)     AS avg_rating,
               COUNT(DISTINCT r.reviewID)  AS review_count,
               $tagSelect
               GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ') AS tags
        FROM Item i
        JOIN Users    u   ON u.userID     = i.sellerID
        JOIN Category c   ON c.categoryID = i.categoryID
        LEFT JOIN Reviews  r   ON r.itemID  = i.itemID
        LEFT JOIN Item_Tag ita ON ita.itemID = i.itemID
        LEFT JOIN Tag      t   ON t.tagID   = ita.tagID
        $tagJoin
        WHERE " . implode(' AND ', $where) . "
        GROUP BY i.itemID
        $having
        ORDER BY $order
        LIMIT ? OFFSET ?
    ";

    $types   .= 'ii';
    $values[] = $limit;
    $values[] = $offset;

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Soft-delete: mark item as archived. */
function archiveItem(int $itemID): bool {
    return updateItem($itemID, status: 'archived');
}

/** Hard delete an item (cascades to images, tags, reviews, wishlist). */
function deleteItem(int $itemID): bool {
    $db   = getDB();
    $stmt = $db->prepare("DELETE FROM Item WHERE itemID = ?");
    $stmt->bind_param('i', $itemID);
    return $stmt->execute();
}

/** Get all items by a specific seller. */
function getItemsBySeller(int $sellerID): array {
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT i.*, c.category_name FROM Item i
         JOIN Category c ON c.categoryID = i.categoryID
         WHERE i.sellerID = ?
         ORDER BY i.created_at DESC"
    );
    $stmt->bind_param('i', $sellerID);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}


// ============================================================
// ITEM TAGS  (Item_Tag junction)
// ============================================================

/** Attach a tag to an item. */
function addTagToItem(int $itemID, int $tagID): bool {
    $db   = getDB();
    $stmt = $db->prepare("INSERT IGNORE INTO Item_Tag (itemID, tagID) VALUES (?, ?)");
    $stmt->bind_param('ii', $itemID, $tagID);
    return $stmt->execute();
}

/** Remove a tag from an item. */
function removeTagFromItem(int $itemID, int $tagID): bool {
    $db   = getDB();
    $stmt = $db->prepare("DELETE FROM Item_Tag WHERE itemID = ? AND tagID = ?");
    $stmt->bind_param('ii', $itemID, $tagID);
    return $stmt->execute();
}

/** Replace all tags on an item with a new set. */
function syncItemTags(int $itemID, array $tagIDs): void {
    $db   = getDB();
    $stmt = $db->prepare("DELETE FROM Item_Tag WHERE itemID = ?");
    $stmt->bind_param('i', $itemID);
    $stmt->execute();
    foreach ($tagIDs as $tagID) {
        addTagToItem($itemID, (int)$tagID);
    }
}

/** Get all tags for an item. */
function getItemTags(int $itemID): array {
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT t.* FROM Tag t
         JOIN Item_Tag it ON it.tagID = t.tagID
         WHERE it.itemID = ?"
    );
    $stmt->bind_param('i', $itemID);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}


// ============================================================
// IMAGES
// ============================================================

/** Add one or more image paths/URLs to an item. */
function addItemImages(int $itemID, array $imagePaths): void {
    $db   = getDB();
    $stmt = $db->prepare("INSERT INTO Image (itemID, images) VALUES (?, ?)");
    foreach ($imagePaths as $path) {
        $stmt->bind_param('is', $itemID, $path);
        $stmt->execute();
    }
}

/** Get all images for an item. */
function getItemImages(int $itemID): array {
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM Image WHERE itemID = ?");
    $stmt->bind_param('i', $itemID);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Delete a specific image by ID. */
function deleteImage(int $imageID): bool {
    $db   = getDB();
    $stmt = $db->prepare("DELETE FROM Image WHERE imageID = ?");
    $stmt->bind_param('i', $imageID);
    return $stmt->execute();
}

/** Delete all images for an item. */
function deleteAllItemImages(int $itemID): bool {
    $db   = getDB();
    $stmt = $db->prepare("DELETE FROM Image WHERE itemID = ?");
    $stmt->bind_param('i', $itemID);
    return $stmt->execute();
}


// ============================================================
// REVIEWS
// ============================================================

/**
 * Create or update a review (one per user per item enforced by DB UNIQUE).
 *
 * @param int    $itemID
 * @param int    $userID
 * @param int    $rating  1–5
 * @param string $body
 */
function upsertReview(int $itemID, int $userID, int $rating, string $body = ''): bool {
    $db   = getDB();
    $stmt = $db->prepare(
        "INSERT INTO Reviews (itemID, userID, rating, body)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE rating = VALUES(rating), body = VALUES(body)"
    );
    $stmt->bind_param('iiis', $itemID, $userID, $rating, $body);
    return $stmt->execute();
}

/** Get all reviews for an item. */
function getItemReviews(int $itemID): array {
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT r.*, u.username FROM Reviews r
         JOIN Users u ON u.userID = r.userID
         WHERE r.itemID = ?
         ORDER BY r.created_at DESC"
    );
    $stmt->bind_param('i', $itemID);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Delete a review. */
function deleteReview(int $reviewID): bool {
    $db   = getDB();
    $stmt = $db->prepare("DELETE FROM Reviews WHERE reviewID = ?");
    $stmt->bind_param('i', $reviewID);
    return $stmt->execute();
}


// ============================================================
// TRANSACTION
// ============================================================

/**
 * Create a transaction and mark the item as sold.
 *
 * @return int transactionID
 */
function createTransaction(int $sellerID, int $buyerID, int $itemID, float $price): int {
    $db = getDB();
    $db->begin_transaction();
    try {
        $stmt = $db->prepare(
            "INSERT INTO Transaction (sellerID, buyerID, itemID, price) VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param('iiid', $sellerID, $buyerID, $itemID, $price);
        $stmt->execute();
        $txID = $db->insert_id;

        // Mark item as sold
        $upd = $db->prepare("UPDATE Item SET status = 'sold' WHERE itemID = ?");
        $upd->bind_param('i', $itemID);
        $upd->execute();

        $db->commit();
        return $txID;
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

/** Get a single transaction with full details. */
function getTransaction(int $transactionID): ?array {
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT t.*,
                s.username AS seller_name,
                b.username AS buyer_name,
                i.title    AS item_title
         FROM Transaction t
         JOIN Users u1 ON u1.userID = t.sellerID
         JOIN Users u2 ON u2.userID = t.buyerID
         JOIN Item  i  ON i.itemID  = t.itemID
         LEFT JOIN Users s ON s.userID = t.sellerID
         LEFT JOIN Users b ON b.userID = t.buyerID
         WHERE t.transactionID = ?"
    );
    $stmt->bind_param('i', $transactionID);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/** Get all transactions for a user (as buyer or seller). */
function getUserTransactions(int $userID): array {
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT t.*, i.title AS item_title,
                s.username AS seller_name,
                b.username AS buyer_name
         FROM Transaction t
         JOIN Item  i ON i.itemID  = t.itemID
         JOIN Users s ON s.userID  = t.sellerID
         JOIN Users b ON b.userID  = t.buyerID
         WHERE t.sellerID = ? OR t.buyerID = ?
         ORDER BY t.created_at DESC"
    );
    $stmt->bind_param('ii', $userID, $userID);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}


// ============================================================
// PAYMENT
// ============================================================

/**
 * Record a payment for a transaction.
 *
 * @param int    $transactionID
 * @param string $method  e.g. 'GCash', 'Cash on Delivery', 'Bank Transfer'
 * @param float  $amount
 * @return int   paymentID
 */
function createPayment(int $transactionID, string $method, float $amount): int {
    $db   = getDB();
    $stmt = $db->prepare(
        "INSERT INTO Payment (transactionID, payment_method, amount) VALUES (?, ?, ?)"
    );
    $stmt->bind_param('isd', $transactionID, $method, $amount);
    $stmt->execute();
    return $db->insert_id;
}

/** Get all payments for a transaction. */
function getTransactionPayments(int $transactionID): array {
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM Payment WHERE transactionID = ?");
    $stmt->bind_param('i', $transactionID);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}


// ============================================================
// WISHLIST
// ============================================================

/** Add an item to a user's wishlist. */
function addToWishlist(int $userID, int $itemID): bool {
    $db   = getDB();
    $stmt = $db->prepare("INSERT IGNORE INTO Wishlist (userID, itemID) VALUES (?, ?)");
    $stmt->bind_param('ii', $userID, $itemID);
    return $stmt->execute();
}

/** Remove an item from a user's wishlist. */
function removeFromWishlist(int $userID, int $itemID): bool {
    $db   = getDB();
    $stmt = $db->prepare("DELETE FROM Wishlist WHERE userID = ? AND itemID = ?");
    $stmt->bind_param('ii', $userID, $itemID);
    return $stmt->execute();
}

/** Get all wishlist items for a user. */
function getUserWishlist(int $userID): array {
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT i.*, c.category_name
         FROM Wishlist w
         JOIN Item     i ON i.itemID     = w.itemID
         JOIN Category c ON c.categoryID = i.categoryID
         WHERE w.userID = ?
         ORDER BY w.wishID DESC"
    );
    $stmt->bind_param('i', $userID);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Check if an item is in a user's wishlist. */
function isInWishlist(int $userID, int $itemID): bool {
    $db   = getDB();
    $stmt = $db->prepare("SELECT 1 FROM Wishlist WHERE userID = ? AND itemID = ?");
    $stmt->bind_param('ii', $userID, $itemID);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}


// ============================================================
// CONVERSATION & MESSAGES
// ============================================================

/**
 * Get or create a conversation between two users.
 * Enforces userID_1 < userID_2 to respect the UNIQUE constraint.
 *
 * @return string UUID conversation ID
 */
function getOrCreateConversation(int $userA, int $userB): string {
    // Always store smaller ID as userID_1
    [$u1, $u2] = $userA < $userB ? [$userA, $userB] : [$userB, $userA];

    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT conversationID FROM Conversation WHERE userID_1 = ? AND userID_2 = ?"
    );
    $stmt->bind_param('ii', $u1, $u2);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) return $row['conversationID'];

    // Create new conversation with a UUID
    $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
    $ins = $db->prepare(
        "INSERT INTO Conversation (conversationID, userID_1, userID_2) VALUES (?, ?, ?)"
    );
    $ins->bind_param('sii', $uuid, $u1, $u2);
    $ins->execute();
    return $uuid;
}

/** Get all conversations for a user (with last message preview). */
function getUserConversations(int $userID): array {
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT c.conversationID,
                IF(c.userID_1 = ?, c.userID_2, c.userID_1) AS otherUserID,
                u.username AS other_username,
                m.body     AS last_message,
                m.sent_at  AS last_sent_at
         FROM Conversation c
         JOIN Users u ON u.userID = IF(c.userID_1 = ?, c.userID_2, c.userID_1)
         LEFT JOIN Messages m ON m.messageID = (
             SELECT MAX(messageID) FROM Messages WHERE conversationID = c.conversationID
         )
         WHERE c.userID_1 = ? OR c.userID_2 = ?
         ORDER BY last_sent_at DESC"
    );
    $stmt->bind_param('iiii', $userID, $userID, $userID, $userID);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Send a message in a conversation.
 *
 * @return int messageID
 */
function sendMessage(string $conversationID, int $senderID, int $receiverID, string $body): int {
    $db   = getDB();
    $stmt = $db->prepare(
        "INSERT INTO Messages (conversationID, senderID, receiverID, body)
         VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param('siis', $conversationID, $senderID, $receiverID, $body);
    $stmt->execute();
    return $db->insert_id;
}

/** Get all messages in a conversation (chronological order). */
function getMessages(string $conversationID, int $limit = 50, int $offset = 0): array {
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT m.*, u.username AS sender_name
         FROM Messages m
         JOIN Users u ON u.userID = m.senderID
         WHERE m.conversationID = ?
         ORDER BY m.sent_at ASC
         LIMIT ? OFFSET ?"
    );
    $stmt->bind_param('sii', $conversationID, $limit, $offset);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Delete a message (sender only, typically). */
function deleteMessage(int $messageID): bool {
    $db   = getDB();
    $stmt = $db->prepare("DELETE FROM Messages WHERE messageID = ?");
    $stmt->bind_param('i', $messageID);
    return $stmt->execute();
}