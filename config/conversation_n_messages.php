<?php


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