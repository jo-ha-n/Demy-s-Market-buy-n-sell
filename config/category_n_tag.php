<?php

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