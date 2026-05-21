<?php

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