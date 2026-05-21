<?php

// ============================================================
// ENUMS  (use these constants everywhere instead of raw strings)
// ============================================================

const AGREEMENT_PENDING   = 'pending';
const AGREEMENT_AGREED    = 'agreed';
const AGREEMENT_REJECTED  = 'rejected';

const PAYMENT_PENDING     = 'pending';
const PAYMENT_COMPLETED   = 'completed';
const PAYMENT_FAILED      = 'failed';

const ITEM_AVAILABLE      = 'available';
const ITEM_PENDING        = 'pending';       // reserved while both parties agree
const ITEM_SOLD           = 'sold';


// ============================================================
// TRANSACTION
// ============================================================

/**
 * Initiate a transaction.
 * Item is marked 'pending' (reserved) but NOT 'sold' yet.
 * Both seller and buyer agreement flags start as 'pending'.
 *
 * ACID guarantees:
 *  - Atomicity  : item status update + transaction row inserted together or not at all
 *  - Consistency: item must be 'available' before proceeding (checked with SELECT FOR UPDATE)
 *  - Isolation  : SELECT ... FOR UPDATE prevents race conditions (two buyers at once)
 *  - Durability : wrapped in commit; any failure triggers rollback
 *
 * @return int transactionID
 */
function createTransaction(int $sellerID, int $buyerID, int $itemID, float $price): int {
    $db = getDB();
    $db->begin_transaction();

    try {
        // Lock the item row to prevent race conditions
        $check = $db->prepare(
            "SELECT itemID, status, sellerID
             FROM Item
             WHERE itemID = ?
             FOR UPDATE"                  // Isolation: no other transaction can touch this row
        );
        $check->bind_param('i', $itemID);
        $check->execute();
        $item = $check->get_result()->fetch_assoc();

        // Consistency checks before any write
        if (!$item) {
            throw new RuntimeException("Item #$itemID does not exist.");
        }
        if ($item['status'] !== ITEM_AVAILABLE) {
            throw new RuntimeException("Item #$itemID is not available (status: {$item['status']}).");
        }
        if ($item['sellerID'] !== $sellerID) {
            throw new RuntimeException("User #$sellerID is not the owner of item #$itemID.");
        }
        if ($sellerID === $buyerID) {
            throw new RuntimeException("Seller and buyer cannot be the same user.");
        }

        // Insert transaction with both agreement statuses defaulting to 'pending'
        $stmt = $db->prepare(
            "INSERT INTO Transaction
                (sellerID, buyerID, itemID, price, seller_agreement, buyer_agreement, payment_status)
             VALUES
                (?, ?, ?, ?, 'pending', 'pending', 'pending')"
        );
        $stmt->bind_param('iiid', $sellerID, $buyerID, $itemID, $price);
        $stmt->execute();
        $txID = $db->insert_id;

        // Reserve the item so no one else can buy it while awaiting agreement
        $upd = $db->prepare("UPDATE Item SET status = ? WHERE itemID = ?");
        $upd->bind_param('si', ITEM_PENDING, $itemID);
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
    // Fixed: removed the duplicate JOIN on Users that was in the original
    $stmt = $db->prepare(
        "SELECT t.*,
                s.username AS seller_name,
                b.username AS buyer_name,
                i.title    AS item_title
         FROM   Transaction t
         JOIN   Users s ON s.userID = t.sellerID
         JOIN   Users b ON b.userID = t.buyerID
         JOIN   Item  i ON i.itemID = t.itemID
         WHERE  t.transactionID = ?"
    );
    $stmt->bind_param('i', $transactionID);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/** Get all transactions for a user (as buyer or seller). */
function getUserTransactions(int $userID): array {
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT t.*,
                i.title    AS item_title,
                s.username AS seller_name,
                b.username AS buyer_name
         FROM   Transaction t
         JOIN   Item  i ON i.itemID  = t.itemID
         JOIN   Users s ON s.userID  = t.sellerID
         JOIN   Users b ON b.userID  = t.buyerID
         WHERE  t.sellerID = ? OR t.buyerID = ?
         ORDER  BY t.created_at DESC"
    );
    $stmt->bind_param('ii', $userID, $userID);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}


// ============================================================
// AGREEMENT
// ============================================================

/**
 * Record a user's agreement or rejection on a transaction.
 *
 * - If either party rejects  → transaction is cancelled, item returns to 'available'
 * - If both parties agree    → transaction is ready for payment
 *
 * ACID guarantees:
 *  - Atomicity  : agreement update + possible status change are one atomic unit
 *  - Consistency: only valid roles and statuses are allowed
 *  - Isolation  : SELECT FOR UPDATE prevents two simultaneous agreement updates
 *  - Durability : committed before returning; rolled back on any failure
 *
 * @param int    $transactionID
 * @param int    $userID   The user recording their decision
 * @param string $decision 'agreed' | 'rejected'
 * @return array ['status' => 'pending'|'ready_for_payment'|'cancelled', 'message' => string]
 */
function recordAgreement(int $transactionID, int $userID, string $decision): array {
    if (!in_array($decision, [AGREEMENT_AGREED, AGREEMENT_REJECTED], true)) {
        throw new InvalidArgumentException("Decision must be 'agreed' or 'rejected'.");
    }

    $db = getDB();
    $db->begin_transaction();

    try {
        // Lock the transaction row
        $stmt = $db->prepare(
            "SELECT * FROM Transaction
             WHERE transactionID = ?
             FOR UPDATE"
        );
        $stmt->bind_param('i', $transactionID);
        $stmt->execute();
        $tx = $stmt->get_result()->fetch_assoc();

        if (!$tx) {
            throw new RuntimeException("Transaction #$transactionID not found.");
        }

        // Transaction must still be in a state where agreement makes sense
        if ($tx['payment_status'] !== PAYMENT_PENDING) {
            throw new RuntimeException("Transaction is no longer awaiting agreement.");
        }

        // Identify the caller's role
        $isSeller = ((int)$tx['sellerID'] === $userID);
        $isBuyer  = ((int)$tx['buyerID']  === $userID);

        if (!$isSeller && !$isBuyer) {
            throw new RuntimeException("User #$userID is not a party in transaction #$transactionID.");
        }

        // Prevent changing a decision that is already finalised
        $currentDecision = $isSeller ? $tx['seller_agreement'] : $tx['buyer_agreement'];
        if ($currentDecision !== AGREEMENT_PENDING) {
            throw new RuntimeException("Your agreement decision has already been recorded as '$currentDecision'.");
        }

        // Write this party's decision
        $column = $isSeller ? 'seller_agreement' : 'buyer_agreement';
        $upd = $db->prepare("UPDATE Transaction SET $column = ? WHERE transactionID = ?");
        $upd->bind_param('si', $decision, $transactionID);
        $upd->execute();

        // --- Determine overall outcome ---

        if ($decision === AGREEMENT_REJECTED) {
            // Either party rejected → cancel everything and free the item
            $cancel = $db->prepare(
                "UPDATE Transaction
                 SET payment_status = 'cancelled'
                 WHERE transactionID = ?"
            );
            $cancel->bind_param('i', $transactionID);
            $cancel->execute();

            $freeItem = $db->prepare("UPDATE Item SET status = ? WHERE itemID = ?");
            $freeItem->bind_param('si', ITEM_AVAILABLE, $tx['itemID']);
            $freeItem->execute();

            $db->commit();
            return [
                'status'  => 'cancelled',
                'message' => 'Transaction cancelled. The item is available again.',
            ];
        }

        // The current party agreed — check if the other party also agreed
        $otherAgreement = $isSeller ? $tx['buyer_agreement'] : $tx['seller_agreement'];

        if ($otherAgreement === AGREEMENT_AGREED) {
            // Both parties have now agreed → ready for payment
            $ready = $db->prepare(
                "UPDATE Transaction
                 SET payment_status = 'ready_for_payment'
                 WHERE transactionID = ?"
            );
            $ready->bind_param('i', $transactionID);
            $ready->execute();

            $db->commit();
            return [
                'status'  => 'ready_for_payment',
                'message' => 'Both parties agreed. Payment can now be processed.',
            ];
        }

        // Only one party agreed so far
        $db->commit();
        return [
            'status'  => 'pending',
            'message' => 'Agreement recorded. Waiting for the other party.',
        ];

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}


// ============================================================
// PAYMENT
// ============================================================

/**
 * Record a payment for a transaction.
 *
 * Payment is only allowed when both parties have agreed
 * (payment_status = 'ready_for_payment').
 *
 * ACID guarantees:
 *  - Atomicity  : Payment insert + Transaction status update + Item sold update = one unit
 *  - Consistency: Guards prevent double-payment and payment before agreement
 *  - Isolation  : SELECT FOR UPDATE locks the transaction row
 *  - Durability : Only committed if all three writes succeed
 *
 * @param int    $transactionID
 * @param string $method  e.g. 'GCash', 'Cash on Delivery', 'Bank Transfer'
 * @param float  $amount
 * @return int   paymentID
 */
function createPayment(int $transactionID, string $method, float $amount): int {
    $db = getDB();
    $db->begin_transaction();

    try {
        // Lock the transaction row
        $stmt = $db->prepare(
            "SELECT * FROM Transaction
             WHERE transactionID = ?
             FOR UPDATE"
        );
        $stmt->bind_param('i', $transactionID);
        $stmt->execute();
        $tx = $stmt->get_result()->fetch_assoc();

        if (!$tx) {
            throw new RuntimeException("Transaction #$transactionID not found.");
        }

        // Consistency: both parties must have agreed first
        if ($tx['payment_status'] !== 'ready_for_payment') {
            throw new RuntimeException(
                "Payment not allowed. Transaction status is '{$tx['payment_status']}' " .
                "(expected 'ready_for_payment')."
            );
        }

        // Consistency: payment amount must match the agreed price
        if (abs((float)$tx['price'] - $amount) > 0.001) {
            throw new RuntimeException(
                "Payment amount ($amount) does not match transaction price ({$tx['price']})."
            );
        }

        // Insert the payment record
        $pay = $db->prepare(
            "INSERT INTO Payment (transactionID, payment_method, amount, status)
             VALUES (?, ?, ?, 'completed')"
        );
        $pay->bind_param('isd', $transactionID, $method, $amount);
        $pay->execute();
        $paymentID = $db->insert_id;

        // Mark the transaction as completed
        $updTx = $db->prepare(
            "UPDATE Transaction SET payment_status = ? WHERE transactionID = ?"
        );
        $updTx->bind_param('si', PAYMENT_COMPLETED, $transactionID);
        $updTx->execute();

        // Mark the item as sold (only now, after payment is confirmed)
        $updItem = $db->prepare("UPDATE Item SET status = ? WHERE itemID = ?");
        $updItem->bind_param('si', ITEM_SOLD, $tx['itemID']);
        $updItem->execute();

        $db->commit();
        return $paymentID;

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

/** Get all payments for a transaction. */
function getTransactionPayments(int $transactionID): array {
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM Payment WHERE transactionID = ?");
    $stmt->bind_param('i', $transactionID);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}