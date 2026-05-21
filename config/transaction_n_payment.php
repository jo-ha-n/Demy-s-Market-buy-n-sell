<?php

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