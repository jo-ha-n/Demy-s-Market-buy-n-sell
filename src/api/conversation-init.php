<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin();

header('Content-Type: application/json');

$me       = currentUser();
$uid      = (int)$me['userID'];
$otherID  = (int)($_POST['otherID'] ?? 0);

if (!$otherID || $otherID === $uid) {
    echo json_encode(['success' => false, 'error' => 'Invalid user']); exit;
}

$db = getDB();

// Always store lower ID in userID_1 (matches your schema constraint)
$u1 = min($uid, $otherID);
$u2 = max($uid, $otherID);

// Try to find existing conversation
$stmt = $db->prepare("SELECT conversationID FROM Conversation WHERE userID_1 = ? AND userID_2 = ?");
$stmt->bind_param('ii', $u1, $u2);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if ($row) {
    echo json_encode(['success' => true, 'conversationID' => $row['conversationID']]); exit;
}

// Create new conversation with UUID
$uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0,0xffff), mt_rand(0,0xffff),
    mt_rand(0,0xffff),
    mt_rand(0,0x0fff)|0x4000,
    mt_rand(0,0x3fff)|0x8000,
    mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)
);

$stmt = $db->prepare("INSERT INTO Conversation (conversationID, userID_1, userID_2) VALUES (?, ?, ?)");
$stmt->bind_param('sii', $uuid, $u1, $u2);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'conversationID' => $uuid]);
} else {
    echo json_encode(['success' => false, 'error' => 'Could not create conversation']);
}