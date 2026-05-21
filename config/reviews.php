<?php

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
