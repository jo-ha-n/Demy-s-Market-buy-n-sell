<?php
// ── Demy's — messages.php ──────────────────────────────────────────────────
// Fully DB-backed messaging page.
// Requires: messages_migration.sql has been run (adds is_deleted, is_edited,
//           edited_at columns to the Messages table).
//
// AJAX actions (POST with ?action=...):
//   send    – insert a new message
//   edit    – update body, set is_edited=1
//   delete  – soft-delete (is_deleted=1, body cleared)
//   load    – fetch all messages for a conversationID
//   convos  – fetch all conversations for the logged-in user
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/../includes/helpers.php';
requireLogin();

$db  = getDB();
$me  = currentUser();
$uid = (int) $me['userID'];
$csrf = csrfToken();

// ── AJAX handler ─────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? null;

if ($action) {
    header('Content-Type: application/json');

    // ── convos: list all conversations for current user ───────────────────────
    if ($action === 'convos') {
        $stmt = $db->prepare("
            SELECT
                c.conversationID,
                IF(c.userID_1 = ?, c.userID_2, c.userID_1) AS otherUserID,
                u.username AS otherUsername,
                (
                    SELECT m.body
                    FROM Messages m
                    WHERE m.conversationID = c.conversationID
                      AND m.is_deleted = 0
                    ORDER BY m.messageID DESC
                    LIMIT 1
                ) AS lastBody,
                (
                    SELECT m.sent_at
                    FROM Messages m
                    WHERE m.conversationID = c.conversationID
                    ORDER BY m.messageID DESC
                    LIMIT 1
                ) AS lastSentAt,
                (
                    SELECT COUNT(*)
                    FROM Messages m
                    WHERE m.conversationID = c.conversationID
                      AND m.receiverID = ?
                      AND m.is_deleted = 0
                ) AS totalMessages
            FROM Conversation c
            JOIN Users u ON u.userID = IF(c.userID_1 = ?, c.userID_2, c.userID_1)
            WHERE c.userID_1 = ? OR c.userID_2 = ?
            ORDER BY lastSentAt DESC
        ");
        $stmt->bind_param('iiiii', $uid, $uid, $uid, $uid, $uid);
        $stmt->execute();
        $convos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['ok' => true, 'convos' => $convos]);
        exit;
    }

    // ── load: fetch messages in a conversation ────────────────────────────────
    if ($action === 'load') {
        $convID = trim($_GET['conv'] ?? '');
        if (!$convID) { echo json_encode(['ok' => false, 'error' => 'Missing conv']); exit; }

        // Auth: verify current user belongs to this conversation
        $chk = $db->prepare("SELECT 1 FROM Conversation WHERE conversationID=? AND (userID_1=? OR userID_2=?)");
        $chk->bind_param('sii', $convID, $uid, $uid);
        $chk->execute();
        if (!$chk->get_result()->fetch_row()) { echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }

        $stmt = $db->prepare("
            SELECT messageID, senderID, receiverID, body, sent_at, is_edited, is_deleted, edited_at
            FROM Messages
            WHERE conversationID = ?
            ORDER BY messageID ASC
        ");
        $stmt->bind_param('s', $convID);
        $stmt->execute();
        $msgs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['ok' => true, 'messages' => $msgs]);
        exit;
    }

    // All mutating actions require POST + CSRF
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false, 'error' => 'Method not allowed']); exit;
    }
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']); exit;
    }

    // ── send ──────────────────────────────────────────────────────────────────
    if ($action === 'send') {
        $convID     = trim($_POST['conv']       ?? '');
        $receiverID = (int)($_POST['receiver']  ?? 0);
        $body       = trim($_POST['body']       ?? '');

        if (!$convID || !$receiverID || $body === '') {
            echo json_encode(['ok' => false, 'error' => 'Missing fields']); exit;
        }

        // Verify membership & receiver is the other party
        $chk = $db->prepare("SELECT userID_1, userID_2 FROM Conversation WHERE conversationID=?");
        $chk->bind_param('s', $convID);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        if (!$row || !in_array($uid, [(int)$row['userID_1'], (int)$row['userID_2']])) {
            echo json_encode(['ok' => false, 'error' => 'Forbidden']); exit;
        }

        $ins = $db->prepare("
            INSERT INTO Messages (conversationID, senderID, receiverID, body)
            VALUES (?, ?, ?, ?)
        ");
        $ins->bind_param('siis', $convID, $uid, $receiverID, $body);
        $ins->execute();
        $newID = $db->insert_id;

        // Return the full message row
        $sel = $db->prepare("
            SELECT messageID, senderID, receiverID, body, sent_at, is_edited, is_deleted, edited_at
            FROM Messages WHERE messageID = ?
        ");
        $sel->bind_param('i', $newID);
        $sel->execute();
        $msg = $sel->get_result()->fetch_assoc();
        echo json_encode(['ok' => true, 'message' => $msg]);
        exit;
    }

    // ── edit ──────────────────────────────────────────────────────────────────
    if ($action === 'edit') {
        $msgID = (int)($_POST['messageID'] ?? 0);
        $body  = trim($_POST['body']       ?? '');

        if (!$msgID || $body === '') {
            echo json_encode(['ok' => false, 'error' => 'Missing fields']); exit;
        }

        // Verify ownership
        $chk = $db->prepare("SELECT senderID, conversationID FROM Messages WHERE messageID=?");
        $chk->bind_param('i', $msgID);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        if (!$row || (int)$row['senderID'] !== $uid) {
            echo json_encode(['ok' => false, 'error' => 'Forbidden']); exit;
        }

        $upd = $db->prepare("
            UPDATE Messages SET body=?, is_edited=1, edited_at=NOW()
            WHERE messageID=?
        ");
        $upd->bind_param('si', $body, $msgID);
        $upd->execute();

        $sel = $db->prepare("
            SELECT messageID, senderID, receiverID, body, sent_at, is_edited, is_deleted, edited_at
            FROM Messages WHERE messageID = ?
        ");
        $sel->bind_param('i', $msgID);
        $sel->execute();
        $msg = $sel->get_result()->fetch_assoc();
        echo json_encode(['ok' => true, 'message' => $msg]);
        exit;
    }

    // ── delete (soft) ─────────────────────────────────────────────────────────
    if ($action === 'delete') {
        $msgID = (int)($_POST['messageID'] ?? 0);
        if (!$msgID) { echo json_encode(['ok' => false, 'error' => 'Missing messageID']); exit; }

        // Verify ownership
        $chk = $db->prepare("SELECT senderID FROM Messages WHERE messageID=?");
        $chk->bind_param('i', $msgID);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        if (!$row || (int)$row['senderID'] !== $uid) {
            echo json_encode(['ok' => false, 'error' => 'Forbidden']); exit;
        }

        $upd = $db->prepare("UPDATE Messages SET is_deleted=1, body='' WHERE messageID=?");
        $upd->bind_param('i', $msgID);
        $upd->execute();
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

// ── Handle ?with=<userID> — create/open conversation ─────────────────────────
$withID  = (int)($_GET['with']  ?? 0);
$convParam = $_GET['conv'] ?? '';

if ($withID && $withID !== $uid) {
    [$u1, $u2] = $withID > $uid ? [$uid, $withID] : [$withID, $uid];
    $chk = $db->prepare("SELECT conversationID FROM Conversation WHERE userID_1=? AND userID_2=?");
    $chk->bind_param('ii', $u1, $u2);
    $chk->execute();
    $existing = $chk->get_result()->fetch_assoc();
    if ($existing) {
        $convParam = $existing['conversationID'];
    } else {
        $newUUID = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
            mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
        $ins = $db->prepare("INSERT INTO Conversation (conversationID,userID_1,userID_2) VALUES (?,?,?)");
        $ins->bind_param('sii', $newUUID, $u1, $u2);
        $ins->execute();
        $convParam = $newUUID;
    }
    // Redirect to clean URL
    header("Location: messages.php?conv=" . urlencode($convParam));
    exit;
}

// ── Render HTML ───────────────────────────────────────────────────────────────
$pageTitle = "Messages — Demy's";
require_once __DIR__ . '/../includes/header.php';
?>
<style>
  /* ── Design tokens — mirrors main.css so no double-import ─────── */
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  html { scroll-behavior: smooth; }
  body {
    font-family: 'DM Sans', sans-serif;
    background: var(--bg); color: var(--text);
    height: 100vh; overflow: hidden;
    display: flex; flex-direction: column;
  }
  a { color: inherit; text-decoration: none; }
  img { display: block; max-width: 100%; }
  button { cursor: pointer; font-family: inherit; }
  input, textarea { font-family: inherit; }

  /* ── Override page-main padding for full-height layout ────────── */
  .page-main { padding-bottom: 0 !important; flex: 1; overflow: hidden; display: flex; flex-direction: column; }

  /* ── Messages layout ───────────────────────────────────────────── */
  .msg-app {
    flex: 1; display: flex; overflow: hidden;
  }

  /* ── Sidebar ───────────────────────────────────────────────────── */
  .msg-sidebar {
    width: 320px; flex-shrink: 0;
    border-right: 1px solid var(--border);
    display: flex; flex-direction: column;
    background: var(--surface); overflow: hidden;
  }
  .sidebar-header {
    padding: 20px 20px 16px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
  }
  .sidebar-title {
    font-family: 'Syne', sans-serif; font-weight: 800;
    font-size: 18px; letter-spacing: -0.5px;
  }
  .sidebar-count {
    font-size: 11px; font-weight: 700;
    background: var(--accent); color: #fff;
    border-radius: 20px; padding: 2px 8px;
    font-family: 'Syne', sans-serif;
    display: none;
  }
  .sidebar-search {
    padding: 12px 16px; border-bottom: 1px solid var(--border);
  }
  .sidebar-search-inner {
    display: flex; align-items: center; gap: 8px;
    background: var(--bg); border: 1.5px solid var(--border);
    border-radius: var(--radius); padding: 7px 11px;
    transition: border-color 0.2s;
  }
  .sidebar-search-inner:focus-within { border-color: var(--accent); }
  .sidebar-search-inner svg { color: var(--muted); flex-shrink: 0; }
  .sidebar-search-inner input {
    flex: 1; border: none; background: transparent;
    font-size: 13px; color: var(--text); outline: none;
  }
  .sidebar-search-inner input::placeholder { color: var(--muted2); }

  .conv-list { flex: 1; overflow-y: auto; scroll-behavior: smooth; }
  .conv-list::-webkit-scrollbar { width: 4px; }
  .conv-list::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 4px; }

  .conv-item {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 16px; border-bottom: 1px solid var(--border);
    cursor: pointer; transition: background 0.15s; position: relative;
  }
  .conv-item:hover { background: var(--surface2); }
  .conv-item.active {
    background: var(--accent-light);
    border-left: 3px solid var(--accent); padding-left: 13px;
  }
  .conv-item.active .conv-last { color: var(--accent); }

  .conv-avatar {
    width: 44px; height: 44px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), var(--accent-h));
    color: #fff; display: flex; align-items: center; justify-content: center;
    font-family: 'Syne', sans-serif; font-weight: 800; font-size: 16px;
    flex-shrink: 0;
  }
  .conv-info { flex: 1; min-width: 0; }
  .conv-name {
    font-weight: 600; font-size: 14px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 3px;
  }
  .conv-last {
    font-size: 12.5px; color: var(--muted);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .conv-meta {
    display: flex; flex-direction: column; align-items: flex-end; gap: 4px; flex-shrink: 0;
  }
  .conv-time { font-size: 11px; color: var(--muted2); }
  .empty-convs { padding: 48px 20px; text-align: center; color: var(--muted); }
  .empty-convs .empty-icon { font-size: 40px; margin-bottom: 12px; }
  .empty-convs h3 { font-size: 15px; color: var(--text); margin-bottom: 6px; }
  .empty-convs p { font-size: 13px; }

  /* ── Chat panel ────────────────────────────────────────────────── */
  .msg-main { flex: 1; display: flex; flex-direction: column; overflow: hidden; background: var(--bg); }

  .chat-header {
    padding: 0 24px; height: 64px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 14px;
    background: color-mix(in srgb, var(--surface) 80%, transparent);
    backdrop-filter: blur(8px); flex-shrink: 0;
  }
  .chat-header-avatar {
    width: 40px; height: 40px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), var(--accent-h));
    color: #fff; display: flex; align-items: center; justify-content: center;
    font-family: 'Syne', sans-serif; font-weight: 800; font-size: 15px;
    flex-shrink: 0;
  }
  .chat-header-info { flex: 1; }
  .chat-header-name {
    font-family: 'Syne', sans-serif; font-weight: 700;
    font-size: 15px; letter-spacing: -0.3px;
  }
  .chat-header-status { font-size: 12px; color: var(--muted); }
  .icon-btn {
    width: 36px; height: 36px;
    border: 1.5px solid var(--border); border-radius: 8px;
    background: transparent; color: var(--muted);
    display: flex; align-items: center; justify-content: center;
    transition: background 0.2s, color 0.2s, border-color 0.2s;
  }
  .icon-btn:hover { background: var(--surface2); color: var(--text); border-color: var(--border2); }
  .chat-header-back { display: none; }

  /* ── Chat body ─────────────────────────────────────────────────── */
  .chat-body {
    flex: 1; overflow-y: auto; padding: 20px 24px;
    display: flex; flex-direction: column; gap: 2px; scroll-behavior: smooth;
  }
  .chat-body::-webkit-scrollbar { width: 4px; }
  .chat-body::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 4px; }

  .msg-date-sep {
    display: flex; align-items: center; gap: 12px; margin: 16px 0 8px;
  }
  .msg-date-sep::before, .msg-date-sep::after { content: ''; flex: 1; height: 1px; background: var(--border); }
  .msg-date-sep span {
    font-size: 11px; color: var(--muted2);
    text-transform: uppercase; letter-spacing: 0.06em;
    font-weight: 600; white-space: nowrap;
  }

  .msg-row {
    display: flex; align-items: flex-end; gap: 8px;
    margin-bottom: 2px; animation: msgIn 0.2s ease;
  }
  @keyframes msgIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
  .msg-row.me { flex-direction: row-reverse; }
  .msg-row.consecutive .bubble-avatar { visibility: hidden; }

  .bubble-avatar {
    width: 28px; height: 28px; border-radius: 50%;
    background: linear-gradient(135deg, #6b48ff, #9b7fff);
    color: #fff; display: flex; align-items: center; justify-content: center;
    font-family: 'Syne', sans-serif; font-weight: 800; font-size: 10px;
    flex-shrink: 0; margin-bottom: 2px;
  }
  .bubble-avatar.me-av { background: linear-gradient(135deg, var(--accent), var(--accent-h)); }

  .bubble-wrap { max-width: 68%; display: flex; flex-direction: column; gap: 2px; }
  .msg-row.me   .bubble-wrap { align-items: flex-end; }
  .msg-row.them .bubble-wrap { align-items: flex-start; }

  .bubble {
    position: relative; padding: 10px 14px; border-radius: 18px;
    font-size: 14px; line-height: 1.55; word-break: break-word;
    cursor: default; transition: filter 0.15s;
  }
  .bubble:hover .bubble-actions { opacity: 1; pointer-events: all; }
  .msg-row.me   .bubble { background: var(--accent); color: #fff; border-bottom-right-radius: 5px; }
  .msg-row.me   .bubble.consecutive { border-top-right-radius: 5px; }
  .msg-row.them .bubble {
    background: var(--surface); color: var(--text);
    border: 1px solid var(--border); border-bottom-left-radius: 5px;
  }
  .msg-row.them .bubble.consecutive { border-top-left-radius: 5px; }

  /* Deleted bubble */
  .bubble.deleted {
    background: transparent !important; color: var(--muted2) !important;
    border: 1.5px dashed var(--border2) !important; font-style: italic;
    font-size: 13px;
  }

  /* Bubble action tray */
  .bubble-actions {
    position: absolute; top: -34px; display: flex; gap: 4px;
    opacity: 0; pointer-events: none; transition: opacity 0.15s;
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 20px; padding: 4px 6px;
    box-shadow: var(--shadow); white-space: nowrap; z-index: 10;
  }
  .msg-row.me   .bubble-actions { right: 0; }
  .msg-row.them .bubble-actions { left: 0; }
  .ba-btn {
    width: 24px; height: 24px; border: none; background: transparent;
    border-radius: 6px; display: flex; align-items: center; justify-content: center;
    color: var(--muted); transition: background 0.15s, color 0.15s; font-size: 12px;
  }
  .ba-btn:hover { background: var(--surface2); color: var(--text); }
  .ba-btn.del:hover { color: var(--danger); }

  .bubble-time {
    font-size: 10.5px; color: var(--muted2); padding: 0 4px;
    display: flex; align-items: center; gap: 5px;
  }
  .msg-row.me .bubble-time { justify-content: flex-end; }
  .edited-badge { font-size: 10px; color: var(--muted2); font-style: italic; margin-left: 4px; }
  .msg-row.me .edited-badge { color: rgba(255,255,255,0.55); }

  /* Typing indicator */
  .typing-indicator { display: flex; align-items: center; gap: 8px; padding: 4px 0; }
  .typing-dots {
    display: flex; gap: 3px; align-items: center;
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 16px; padding: 8px 14px;
  }
  .typing-dots span {
    width: 7px; height: 7px; background: var(--muted2); border-radius: 50%;
    animation: typingBounce 1.2s infinite ease-in-out;
  }
  .typing-dots span:nth-child(2) { animation-delay: 0.2s; }
  .typing-dots span:nth-child(3) { animation-delay: 0.4s; }
  @keyframes typingBounce { 0%,60%,100% { transform: translateY(0); } 30% { transform: translateY(-5px); } }

  /* ── Input area ────────────────────────────────────────────────── */
  .chat-input-area {
    padding: 16px 24px 20px; border-top: 1px solid var(--border);
    background: var(--surface); flex-shrink: 0;
  }
  .edit-banner {
    display: none; background: var(--accent-light);
    border-bottom: 1px solid rgba(232,65,10,0.2);
    padding: 8px 16px; font-size: 12.5px; color: var(--accent);
    align-items: center; gap: 10px;
    border-radius: var(--radius) var(--radius) 0 0;
    margin-bottom: -2px;
  }
  .edit-banner.visible { display: flex; }
  .edit-banner-text { flex: 1; font-weight: 500; }
  .edit-cancel { border: none; background: transparent; color: var(--accent); font-size: 18px; line-height: 1; padding: 0 4px; }

  .chat-input-wrap {
    display: flex; align-items: flex-end; gap: 10px;
    background: var(--bg); border: 1.5px solid var(--border);
    border-radius: 20px; padding: 8px 8px 8px 16px;
    transition: border-color 0.2s, box-shadow 0.2s;
  }
  .chat-input-wrap:focus-within {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(232,65,10,0.1);
  }
  .chat-textarea {
    flex: 1; border: none; background: transparent;
    font-size: 14px; color: var(--text); outline: none;
    resize: none; max-height: 120px; line-height: 1.5; padding: 4px 0;
  }
  .chat-textarea::placeholder { color: var(--muted2); }
  .send-btn {
    width: 38px; height: 38px; border-radius: 50%;
    background: var(--accent); color: #fff; border: none;
    display: flex; align-items: center; justify-content: center;
    transition: background 0.15s, transform 0.15s; flex-shrink: 0;
  }
  .send-btn:hover { background: var(--accent-h); transform: scale(1.06); }
  .send-btn:disabled { background: var(--border2); cursor: not-allowed; transform: none; }

  /* ── No chat selected ──────────────────────────────────────────── */
  .no-chat {
    flex: 1; display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    gap: 16px; color: var(--muted); padding: 40px; text-align: center;
  }
  .no-chat-icon {
    width: 80px; height: 80px; background: var(--surface2);
    border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 36px;
  }
  .no-chat h2 { font-family: 'Syne', sans-serif; font-size: 20px; color: var(--text-2); }
  .no-chat p { font-size: 14px; max-width: 260px; line-height: 1.6; }

  /* ── Delete modal ──────────────────────────────────────────────── */
  .overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.55); backdrop-filter: blur(4px);
    z-index: 500; align-items: center; justify-content: center;
  }
  .overlay.open { display: flex; animation: overlayIn 0.2s ease; }
  @keyframes overlayIn { from { opacity: 0; } to { opacity: 1; } }
  .modal {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-lg); padding: 28px 32px;
    max-width: 400px; width: 90%; box-shadow: var(--shadow-lg);
    animation: modalIn 0.25s cubic-bezier(0.16,1,0.3,1);
  }
  @keyframes modalIn { from { opacity:0; transform:translateY(16px) scale(0.96); } to { opacity:1; transform:translateY(0) scale(1); } }
  .modal h3 { font-family: 'Syne', sans-serif; font-size: 17px; margin-bottom: 8px; }
  .modal p { font-size: 13.5px; color: var(--muted); margin-bottom: 24px; line-height: 1.6; }
  .modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
  .btn-danger {
    background: var(--danger); color: #fff; border: none;
    border-radius: var(--radius); padding: 9px 18px;
    font-size: 13.5px; font-weight: 600; font-family: 'Syne', sans-serif;
    transition: opacity 0.2s; cursor: pointer;
  }
  .btn-danger:hover { opacity: 0.88; }

  /* ── Flash ─────────────────────────────────────────────────────── */
  #flashContainer {
    position: fixed; bottom: 24px; right: 24px;
    z-index: 999; display: flex; flex-direction: column; gap: 8px;
  }
  .flash {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 16px; border-radius: var(--radius);
    font-size: 13.5px; box-shadow: var(--shadow-lg);
    animation: flashIn 0.3s ease;
  }
  @keyframes flashIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }
  .flash--success { background: var(--surface); border: 1px solid var(--success); color: var(--success); }
  .flash--info    { background: var(--surface); border: 1px solid var(--border2); color: var(--text); }
  .flash--danger  { background: var(--danger-light); border: 1px solid var(--danger); color: var(--danger); }
  .flash-close { background: none; border: none; color: inherit; opacity: 0.6; font-size: 16px; margin-left: auto; }

  /* ── Responsive ────────────────────────────────────────────────── */
  @media (max-width: 720px) {
    .msg-sidebar { width: 100%; display: none; }
    .msg-sidebar.mobile-open { display: flex; position: absolute; inset: var(--topbar-h) 0 0 0; z-index: 100; }
    .chat-header-back { display: flex !important; }
  }

  /* Skeleton */
  .skeleton-line {
    height: 13px; border-radius: 6px;
    background: linear-gradient(90deg, var(--border) 25%, var(--surface2) 50%, var(--border) 75%);
    background-size: 200% 100%;
    animation: shimmer 1.2s infinite;
    margin-bottom: 6px;
  }
  @keyframes shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
</style>

<div class="msg-app">

  <!-- ── Sidebar ─────────────────────────────────────────────────────── -->
  <aside class="msg-sidebar" id="msgSidebar">
    <div class="sidebar-header">
      <span class="sidebar-title">Messages</span>
      <span class="sidebar-count" id="unreadCount"></span>
    </div>
    <div class="sidebar-search">
      <div class="sidebar-search-inner">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
        </svg>
        <input type="text" id="convSearch" placeholder="Search conversations…" oninput="filterConvs(this.value)"/>
      </div>
    </div>
    <div class="conv-list" id="convList">
      <!-- skeleton while loading -->
      <?php for ($i = 0; $i < 4; $i++): ?>
      <div style="display:flex;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid var(--border)">
        <div style="width:44px;height:44px;border-radius:50%;background:var(--surface2);flex-shrink:0"></div>
        <div style="flex:1">
          <div class="skeleton-line" style="width:60%"></div>
          <div class="skeleton-line" style="width:80%"></div>
        </div>
      </div>
      <?php endfor; ?>
    </div>
  </aside>

  <!-- ── Main chat panel ─────────────────────────────────────────────── -->
  <div class="msg-main" id="msgMain">

    <div class="no-chat" id="noChat">
      <div class="no-chat-icon">💬</div>
      <h2>Your Messages</h2>
      <p>Select a conversation to start chatting, or reach out to a seller from any listing.</p>
    </div>

    <div id="chatView" style="display:none;flex-direction:column;flex:1;overflow:hidden">

      <div class="chat-header">
        <button class="icon-btn chat-header-back" id="backBtn" onclick="goBack()">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
            <path d="M19 12H5M12 5l-7 7 7 7"/>
          </svg>
        </button>
        <div class="chat-header-avatar" id="chatAvatar">?</div>
        <div class="chat-header-info">
          <div class="chat-header-name" id="chatName">—</div>
          <div class="chat-header-status" id="chatStatus">Member</div>
        </div>
      </div>

      <div class="chat-body" id="chatBody"></div>

      <div class="edit-banner" id="editBanner">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
          <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
        </svg>
        <span class="edit-banner-text" id="editBannerText">Editing message…</span>
        <button class="edit-cancel" onclick="cancelEdit()">✕</button>
      </div>

      <div class="chat-input-area">
        <div class="chat-input-wrap">
          <textarea class="chat-textarea" id="msgInput" placeholder="Type a message…" rows="1"
            onkeydown="handleKey(event)" oninput="autoResize(this)"></textarea>
          <div style="display:flex;align-items:flex-end">
            <button class="send-btn" id="sendBtn" onclick="handleSend()" disabled>
              <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <line x1="22" y1="2" x2="11" y2="13"/>
                <polygon points="22 2 15 22 11 13 2 9 22 2"/>
              </svg>
            </button>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<div id="flashContainer"></div>

<!-- Delete modal -->
<div class="overlay" id="deleteOverlay">
  <div class="modal">
    <h3>Delete message?</h3>
    <p>This message will be permanently removed from the conversation for everyone.</p>
    <div class="modal-actions">
      <button class="btn-ghost" onclick="closeDeleteModal()">Cancel</button>
      <button class="btn-danger" onclick="confirmDelete()">Delete</button>
    </div>
  </div>
</div>

<script>
// ── Config ─────────────────────────────────────────────────────────────────
const ME   = { userID: <?= (int)$me['userID'] ?>, username: <?= json_encode($me['username']) ?> };
const CSRF = <?= json_encode($csrf) ?>;
const INITIAL_CONV = <?= json_encode($convParam ?: null) ?>;

// Avatar colour palette — deterministic by userID
const AVATAR_COLORS = ['#e8410a','#6b48ff','#1a8a4a','#0070f3','#d97706','#db2777'];
function avatarColor(id) { return AVATAR_COLORS[id % AVATAR_COLORS.length]; }

// ── State ──────────────────────────────────────────────────────────────────
let allConvos     = [];
let activeConvID  = null;
let activeOtherID = null;
let editingMsgID  = null;
let deletingMsgID = null;

// ── Boot ───────────────────────────────────────────────────────────────────
(async function boot() {
  await loadConvos();
  if (INITIAL_CONV) openConvo(INITIAL_CONV);
})();

// ── AJAX helpers ───────────────────────────────────────────────────────────
async function api(action, params = {}, method = 'GET') {
  const url = `messages.php?action=${action}`;
  const opts = { headers: {} };
  if (method === 'POST') {
    opts.method = 'POST';
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', action);
    Object.entries(params).forEach(([k,v]) => fd.append(k,v));
    opts.body = fd;
  } else {
    const qs = new URLSearchParams(params).toString();
    return fetch(url + (qs ? '&' + qs : '')).then(r => r.json());
  }
  return fetch(url, opts).then(r => r.json());
}

// ── Load all conversations ─────────────────────────────────────────────────
async function loadConvos() {
  const res = await api('convos');
  if (!res.ok) return;
  allConvos = res.convos || [];
  renderConvList();
}

function renderConvList(filter = '') {
  const list = document.getElementById('convList');
  const filtered = allConvos.filter(c =>
    c.otherUsername.toLowerCase().includes(filter.toLowerCase())
  );

  if (!filtered.length) {
    list.innerHTML = `<div class="empty-convs">
      <div class="empty-icon">${filter ? '🔍' : '💬'}</div>
      <h3>${filter ? 'No results' : 'No conversations'}</h3>
      <p>${filter ? 'Try a different name.' : 'Message a seller from any listing to get started.'}</p>
    </div>`;
    return;
  }

  list.innerHTML = filtered.map(c => {
    const isActive = c.conversationID === activeConvID ? ' active' : '';
    const lastText = c.lastBody
      ? escHtml(c.lastBody.substring(0, 42)) + (c.lastBody.length > 42 ? '…' : '')
      : '<em style="color:var(--muted2)">No messages yet</em>';
    const lastTime = c.lastSentAt ? formatTime(c.lastSentAt) : '';
    const color    = avatarColor(parseInt(c.otherUserID));
    return `
      <div class="conv-item${isActive}" onclick="openConvo('${escHtml(c.conversationID)}')">
        <div class="conv-avatar" style="background:${color}">${c.otherUsername[0].toUpperCase()}</div>
        <div class="conv-info">
          <div class="conv-name">${escHtml(c.otherUsername)}</div>
          <div class="conv-last">${lastText}</div>
        </div>
        <div class="conv-meta">
          <span class="conv-time">${lastTime}</span>
        </div>
      </div>`;
  }).join('');
}

function filterConvs(val) { renderConvList(val); }

// ── Open a conversation ────────────────────────────────────────────────────
async function openConvo(convID) {
  activeConvID = convID;
  const meta = allConvos.find(c => c.conversationID === convID);
  if (meta) {
    activeOtherID = parseInt(meta.otherUserID);
    const color = avatarColor(activeOtherID);
    const av = document.getElementById('chatAvatar');
    av.textContent = meta.otherUsername[0].toUpperCase();
    av.style.background = color;
    document.getElementById('chatName').textContent = meta.otherUsername;
    document.getElementById('chatStatus').textContent = 'Demy\'s member';
  }

  renderConvList(document.getElementById('convSearch').value);
  document.getElementById('noChat').style.display = 'none';
  const cv = document.getElementById('chatView');
  cv.style.display = 'flex';
  document.getElementById('chatBody').innerHTML = buildSkeleton();

  const res = await api('load', { conv: convID });
  if (!res.ok) { showFlash('danger', 'Could not load messages.'); return; }
  renderMessages(res.messages || []);
  document.getElementById('msgSidebar').classList.remove('mobile-open');
}

function buildSkeleton() {
  return `<div style="padding:16px;display:flex;flex-direction:column;gap:16px">
    <div style="display:flex;gap:8px"><div style="width:28px;height:28px;border-radius:50%;background:var(--surface2);flex-shrink:0"></div><div style="flex:1"><div class="skeleton-line" style="width:55%"></div><div class="skeleton-line" style="width:40%"></div></div></div>
    <div style="display:flex;gap:8px;flex-direction:row-reverse"><div style="width:28px;height:28px;border-radius:50%;background:var(--surface2);flex-shrink:0"></div><div style="flex:1;display:flex;flex-direction:column;align-items:flex-end"><div class="skeleton-line" style="width:60%"></div></div></div>
    <div style="display:flex;gap:8px"><div style="width:28px;height:28px;border-radius:50%;background:var(--surface2);flex-shrink:0"></div><div style="flex:1"><div class="skeleton-line" style="width:70%"></div><div class="skeleton-line" style="width:50%"></div></div></div>
  </div>`;
}

// ── Render messages ────────────────────────────────────────────────────────
function renderMessages(msgs) {
  const body = document.getElementById('chatBody');
  const meta = allConvos.find(c => c.conversationID === activeConvID);
  const otherName = meta?.otherUsername ?? 'User';
  const otherColor = avatarColor(parseInt(meta?.otherUserID ?? 0));

  if (!msgs.length) {
    body.innerHTML = `<div style="text-align:center;color:var(--muted);padding:48px 20px">
      <div style="font-size:40px;margin-bottom:12px">👋</div>
      <div style="font-family:'Syne',sans-serif;font-size:16px;font-weight:700;color:var(--text);margin-bottom:6px">Start the conversation</div>
      <div style="font-size:13px">Send your first message to ${escHtml(otherName)}.</div>
    </div>`;
    return;
  }

  let html = '';
  let lastSenderID = null;
  let lastDateLabel = null;

  msgs.forEach(msg => {
    const isMe = parseInt(msg.senderID) === ME.userID;
    const dateLabel = formatDateLabel(msg.sent_at);
    if (dateLabel !== lastDateLabel) {
      html += `<div class="msg-date-sep"><span>${dateLabel}</span></div>`;
      lastDateLabel = dateLabel;
      lastSenderID = null;
    }

    const isConsecutive = parseInt(msg.senderID) === lastSenderID;
    const rowClass    = `msg-row ${isMe ? 'me' : 'them'}${isConsecutive ? ' consecutive' : ''}`;
    const bubClass    = `bubble${isConsecutive ? ' consecutive' : ''}${msg.is_deleted == 1 ? ' deleted' : ''}`;
    const initials    = isMe ? ME.username[0].toUpperCase() : otherName[0].toUpperCase();
    const avColor     = isMe ? avatarColor(ME.userID) : otherColor;
    const avClass     = isMe ? 'me-av' : '';
    const editedBadge = (!msg.is_deleted && msg.is_edited == 1)
      ? `<span class="edited-badge">(edited)</span>` : '';
    const bodyText    = msg.is_deleted == 1 ? '🚫 Message deleted' : escHtml(msg.body).replace(/\n/g,'<br>');
    const timeStr     = formatMsgTime(msg.sent_at);

    const actions = !msg.is_deleted ? `
      <div class="bubble-actions">
        ${isMe ? `<button class="ba-btn" title="Edit" onclick="startEdit(${msg.messageID},event)">
          <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
          </svg>
        </button>` : ''}
        ${isMe ? `<button class="ba-btn del" title="Delete" onclick="openDeleteModal(${msg.messageID},event)">
          <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/>
            <path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/>
          </svg>
        </button>` : ''}
      </div>` : '';

    html += `
      <div class="${rowClass}" data-id="${msg.messageID}">
        ${!isMe ? `<div class="bubble-avatar ${avClass}" style="background:${avColor}">${initials}</div>` : ''}
        <div class="bubble-wrap">
          <div class="${bubClass}" id="bubble-${msg.messageID}">
            ${bodyText}${editedBadge}
            ${actions}
          </div>
          <div class="bubble-time">${timeStr}</div>
        </div>
        ${isMe ? `<div class="bubble-avatar me-av" style="background:${avColor};visibility:hidden">${initials}</div>` : ''}
      </div>`;

    lastSenderID = parseInt(msg.senderID);
  });

  body.innerHTML = html;
  scrollToBottom();
}

// ── Send ───────────────────────────────────────────────────────────────────
const msgInput = document.getElementById('msgInput');
const sendBtn  = document.getElementById('sendBtn');
msgInput.addEventListener('input', () => { sendBtn.disabled = !msgInput.value.trim(); });

function handleKey(e) {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); if (!sendBtn.disabled) handleSend(); }
}

async function handleSend() {
  if (editingMsgID !== null) { await commitEdit(); return; }
  await sendMessage();
}

async function sendMessage() {
  const body = msgInput.value.trim();
  if (!body || !activeConvID || !activeOtherID) return;

  msgInput.value = '';
  sendBtn.disabled = true;
  autoResize(msgInput);

  const res = await api('send', { conv: activeConvID, receiver: activeOtherID, body }, 'POST');
  if (!res.ok) { showFlash('danger', res.error || 'Failed to send.'); return; }

  // Optimistically append to DOM and update sidebar preview
  const meta = allConvos.find(c => c.conversationID === activeConvID);
  if (meta) { meta.lastBody = body; meta.lastSentAt = res.message.sent_at; }

  // Re-fetch & render so we stay in sync
  const msgs = await api('load', { conv: activeConvID });
  if (msgs.ok) renderMessages(msgs.messages);
  renderConvList(document.getElementById('convSearch').value);
}

// ── Edit ───────────────────────────────────────────────────────────────────
function startEdit(msgID, e) {
  e.stopPropagation();
  const bubble = document.getElementById(`bubble-${msgID}`);
  if (!bubble || bubble.classList.contains('deleted')) return;
  // Extract plain text (strip tags)
  const tmp = document.createElement('div');
  tmp.innerHTML = bubble.innerHTML.split('<span class="edited-badge">')[0]
                                  .split('<div class="bubble-actions">')[0];
  const rawText = tmp.textContent.trim();

  editingMsgID = msgID;
  msgInput.value = rawText;
  sendBtn.disabled = false;
  autoResize(msgInput);
  document.getElementById('editBannerText').textContent =
    `Editing: "${rawText.substring(0,40)}${rawText.length > 40 ? '…' : ''}"`;
  document.getElementById('editBanner').classList.add('visible');
  msgInput.focus();
}

async function commitEdit() {
  const body = msgInput.value.trim();
  if (!body) { cancelEdit(); return; }
  const res = await api('edit', { messageID: editingMsgID, body }, 'POST');
  cancelEdit();
  if (!res.ok) { showFlash('danger', res.error || 'Edit failed.'); return; }

  const meta = allConvos.find(c => c.conversationID === activeConvID);
  if (meta) meta.lastBody = body;

  const msgs = await api('load', { conv: activeConvID });
  if (msgs.ok) renderMessages(msgs.messages);
  renderConvList(document.getElementById('convSearch').value);
  showFlash('success', 'Message updated.');
}

function cancelEdit() {
  editingMsgID = null;
  msgInput.value = '';
  sendBtn.disabled = true;
  autoResize(msgInput);
  document.getElementById('editBanner').classList.remove('visible');
}

// ── Delete ─────────────────────────────────────────────────────────────────
function openDeleteModal(msgID, e) {
  e.stopPropagation();
  deletingMsgID = msgID;
  document.getElementById('deleteOverlay').classList.add('open');
}
function closeDeleteModal() {
  deletingMsgID = null;
  document.getElementById('deleteOverlay').classList.remove('open');
}
async function confirmDelete() {
  if (!deletingMsgID) { closeDeleteModal(); return; }
  const res = await api('delete', { messageID: deletingMsgID }, 'POST');
  closeDeleteModal();
  if (!res.ok) { showFlash('danger', res.error || 'Delete failed.'); return; }

  const msgs = await api('load', { conv: activeConvID });
  if (msgs.ok) {
    renderMessages(msgs.messages);
    // Update sidebar last message
    const meta = allConvos.find(c => c.conversationID === activeConvID);
    if (meta) {
      const lastVisible = [...msgs.messages].reverse().find(m => !m.is_deleted);
      if (meta) meta.lastBody = lastVisible ? lastVisible.body : null;
    }
  }
  renderConvList(document.getElementById('convSearch').value);
  showFlash('success', 'Message deleted.');
}
document.getElementById('deleteOverlay').addEventListener('click', function(e) {
  if (e.target === this) closeDeleteModal();
});

// ── Helpers ────────────────────────────────────────────────────────────────
function scrollToBottom() {
  const body = document.getElementById('chatBody');
  requestAnimationFrame(() => { body.scrollTop = body.scrollHeight; });
}
function autoResize(el) {
  el.style.height = 'auto';
  el.style.height = Math.min(el.scrollHeight, 120) + 'px';
}
function goBack() {
  document.getElementById('chatView').style.display = 'none';
  document.getElementById('noChat').style.display   = 'flex';
}
function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                  .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function formatTime(iso) {
  const d = new Date(iso); const now = new Date(); const diff = (now - d) / 1000;
  if (diff < 60)     return 'just now';
  if (diff < 3600)   return Math.floor(diff/60) + 'm';
  if (diff < 86400)  return d.toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit' });
  if (diff < 604800) return Math.floor(diff/86400) + 'd';
  return d.toLocaleDateString('en-US', { month:'short', day:'numeric' });
}
function formatMsgTime(iso) {
  return new Date(iso).toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit' });
}
function formatDateLabel(iso) {
  const d = new Date(iso); const now = new Date();
  const diff = Math.floor((now - d) / 86400000);
  if (diff === 0) return 'Today';
  if (diff === 1) return 'Yesterday';
  if (diff < 7)   return d.toLocaleDateString('en-US', { weekday:'long' });
  return d.toLocaleDateString('en-US', { month:'long', day:'numeric', year:'numeric' });
}
function showFlash(type, msg) {
  const c = document.getElementById('flashContainer');
  const el = document.createElement('div');
  el.className = `flash flash--${type}`;
  el.innerHTML = `${escHtml(msg)} <button class="flash-close" onclick="this.parentElement.remove()">✕</button>`;
  c.appendChild(el);
  setTimeout(() => el.remove(), 3500);
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>