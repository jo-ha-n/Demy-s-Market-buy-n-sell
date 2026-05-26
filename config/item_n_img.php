<?php

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