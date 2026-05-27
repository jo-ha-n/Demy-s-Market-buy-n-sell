<?php
// ── Demy's — messages.php ─────────────────────────────────────────────────────
// Schema-accurate messaging page.
//
// Table references (from schema):
//   Conversation   – conversationID CHAR(36) UUID, userID_1, userID_2
//   Messages       – messageID, conversationID, senderID, receiverID, body, sent_at
//   Transaction    – sellerID, buyerID, itemID, price, seller_agreement, buyer_agreement, payment_status
//   Item           – itemID, sellerID, title, price, status
//
// AJAX actions (GET ?action= or POST with action field):
//   convos         – list all conversations for current user
//   load           – fetch messages for a conversationID
//   send           – insert a new message
//   bid            – create a Transaction (buyer → seller offer)
//   accept_bid     – seller sets seller_agreement = 'agreed'; triggers payment_status if both agreed
//   reject_bid     – seller sets seller_agreement = 'rejected', payment_status = 'cancelled'
//   pending_bids   – list incoming pending transactions for the logged-in seller
// ──────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/../includes/helpers.php';
requireLogin();

$db   = getDB();
$me   = currentUser();
$uid  = (int) $me['userID'];
$csrf = csrfToken();

// ── AJAX handler ──────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? null;

if ($action) {
    header('Content-Type: application/json');

    // ── convos ────────────────────────────────────────────────────────────────
    if ($action === 'convos') {
        $stmt = $db->prepare("
            SELECT
                c.conversationID,
                IF(c.userID_1 = ?, c.userID_2, c.userID_1) AS otherUserID,
                u.username AS otherUsername,
                (
                    SELECT m2.body
                    FROM Messages m2
                    WHERE m2.conversationID = c.conversationID
                    ORDER BY m2.sent_at DESC
                    LIMIT 1
                ) AS lastMessage,
                (
                    SELECT m3.sent_at
                    FROM Messages m3
                    WHERE m3.conversationID = c.conversationID
                    ORDER BY m3.sent_at DESC
                    LIMIT 1
                ) AS lastActive
            FROM Conversation c
            JOIN Users u ON u.userID = IF(c.userID_1 = ?, c.userID_2, c.userID_1)
            WHERE c.userID_1 = ? OR c.userID_2 = ?
            ORDER BY lastActive DESC
        ");
        $stmt->bind_param('iiii', $uid, $uid, $uid, $uid);
        $stmt->execute();
        $res = $stmt->get_result();

        $convos = [];
        while ($row = $res->fetch_assoc()) {
            $convos[] = [
                'conversationID' => $row['conversationID'],
                'otherUserID'    => (int) $row['otherUserID'],
                'otherUsername'  => $row['otherUsername'],
                'lastMessage'    => $row['lastMessage'] ?? 'No messages yet…',
                'lastActive'     => $row['lastActive']  ?? null,
            ];
        }
        echo json_encode(['success' => true, 'conversations' => $convos]);
        exit;
    }

    // ── load ──────────────────────────────────────────────────────────────────
    if ($action === 'load') {
        $cid = $_GET['conversationID'] ?? '';

        // Auth check
        $chk = $db->prepare("SELECT userID_1, userID_2 FROM Conversation WHERE conversationID = ? AND (userID_1 = ? OR userID_2 = ?)");
        $chk->bind_param('sii', $cid, $uid, $uid);
        $chk->execute();
        $conv = $chk->get_result()->fetch_assoc();
        if (!$conv) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit; }

        $otherID = ((int)$conv['userID_1'] === $uid) ? (int)$conv['userID_2'] : (int)$conv['userID_1'];

        // Fetch messages
        $stmt = $db->prepare("
            SELECT messageID, senderID, receiverID, body, sent_at
            FROM Messages
            WHERE conversationID = ?
            ORDER BY sent_at ASC
        ");
        $stmt->bind_param('s', $cid);
        $stmt->execute();
        $res = $stmt->get_result();

        $msgs = [];
        while ($row = $res->fetch_assoc()) {
            $msgs[] = [
                'messageID'  => (int) $row['messageID'],
                'senderID'   => (int) $row['senderID'],
                'receiverID' => (int) $row['receiverID'],
                'body'       => $row['body'],
                'sent_at'    => $row['sent_at'],
            ];
        }

        // Fetch item for this conversation (find an item where one user is seller
        // and the other is the buyer who initiated the conversation)
        $itemStmt = $db->prepare("
            SELECT i.itemID, i.title, i.price, i.status, i.sellerID
            FROM Item i
            WHERE i.sellerID = ?
              AND i.status = 'available'
            ORDER BY i.created_at DESC
        ");
        $itemStmt->bind_param('i', $otherID);
        $itemStmt->execute();
        $items = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Also check reverse (maybe current user is the seller)
        $itemStmt2 = $db->prepare("
            SELECT i.itemID, i.title, i.price, i.status, i.sellerID
            FROM Item i
            WHERE i.sellerID = ?
              AND i.status = 'available'
            ORDER BY i.created_at DESC
        ");
        $itemStmt2->bind_param('i', $uid);
        $itemStmt2->execute();
        $myItems = $itemStmt2->get_result()->fetch_all(MYSQLI_ASSOC);

        // Pending bids in this conversation context (transactions between these two users)
        $bidStmt = $db->prepare("
            SELECT t.transactionID, t.itemID, t.price, t.seller_agreement,
                   t.buyer_agreement, t.payment_status, i.title,
                   t.sellerID, t.buyerID
            FROM Transaction t
            JOIN Item i ON i.itemID = t.itemID
            WHERE ((t.sellerID = ? AND t.buyerID = ?) OR (t.sellerID = ? AND t.buyerID = ?))
              AND t.payment_status NOT IN ('completed','cancelled')
            ORDER BY t.created_at DESC
        ");
        $bidStmt->bind_param('iiii', $uid, $otherID, $otherID, $uid);
        $bidStmt->execute();
        $bids = $bidStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        echo json_encode([
            'success'  => true,
            'messages' => $msgs,
            'otherID'  => $otherID,
            'items'    => $items,      // other user's items (I can bid on these)
            'myItems'  => $myItems,    // my items (other user might bid on these — shown for context)
            'bids'     => $bids,
        ]);
        exit;
    }

    // ── send ──────────────────────────────────────────────────────────────────
    if ($action === 'send') {
        $cid  = $_POST['conversationID'] ?? '';
        $body = trim($_POST['body'] ?? '');

        if (!$cid || $body === '') { echo json_encode(['success' => false, 'error' => 'Invalid parameters']); exit; }

        $chk = $db->prepare("SELECT userID_1, userID_2 FROM Conversation WHERE conversationID = ? AND (userID_1 = ? OR userID_2 = ?)");
        $chk->bind_param('sii', $cid, $uid, $uid);
        $chk->execute();
        $conv = $chk->get_result()->fetch_assoc();
        if (!$conv) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit; }

        $receiverID = ((int)$conv['userID_1'] === $uid) ? (int)$conv['userID_2'] : (int)$conv['userID_1'];

        $stmt = $db->prepare("INSERT INTO Messages (conversationID, senderID, receiverID, body, sent_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param('siis', $cid, $uid, $receiverID, $body);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'messageID' => $db->insert_id]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Database error']);
        }
        exit;
    }

    // ── bid ───────────────────────────────────────────────────────────────────
    // Creates a Transaction. Current user = buyer, item's sellerID = seller.
    if ($action === 'bid') {
        $itemID   = (int)($_POST['itemID']   ?? 0);
        $bidPrice = (float)($_POST['price']  ?? 0);

        if ($itemID <= 0 || $bidPrice <= 0) { echo json_encode(['success' => false, 'error' => 'Invalid bid parameters']); exit; }

        // Verify item exists, is available, and buyer is not the seller
        $iStmt = $db->prepare("SELECT itemID, sellerID, price, title, status FROM Item WHERE itemID = ?");
        $iStmt->bind_param('i', $itemID);
        $iStmt->execute();
        $item = $iStmt->get_result()->fetch_assoc();

        if (!$item)                           { echo json_encode(['success' => false, 'error' => 'Item not found']); exit; }
        if ($item['status'] !== 'available')  { echo json_encode(['success' => false, 'error' => 'Item is no longer available']); exit; }
        if ((int)$item['sellerID'] === $uid)  { echo json_encode(['success' => false, 'error' => 'You cannot bid on your own item']); exit; }

        // Check no active transaction already exists for this item+buyer
        $dupStmt = $db->prepare("SELECT transactionID FROM Transaction WHERE itemID = ? AND buyerID = ? AND payment_status NOT IN ('completed','cancelled') LIMIT 1");
        $dupStmt->bind_param('ii', $itemID, $uid);
        $dupStmt->execute();
        if ($dupStmt->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'error' => 'You already have an active bid on this item']); exit;
        }

        $sellerID = (int)$item['sellerID'];
        $tStmt = $db->prepare("INSERT INTO Transaction (sellerID, buyerID, itemID, price, seller_agreement, buyer_agreement, payment_status, created_at, updated_at) VALUES (?, ?, ?, ?, 'pending', 'agreed', 'pending', NOW(), NOW())");
        $tStmt->bind_param('iiid', $sellerID, $uid, $itemID, $bidPrice);

        if ($tStmt->execute()) {
            echo json_encode(['success' => true, 'transactionID' => $db->insert_id, 'itemTitle' => $item['title']]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to create transaction']);
        }
        exit;
    }

    // ── accept_bid ────────────────────────────────────────────────────────────
    if ($action === 'accept_bid') {
        $txID = (int)($_POST['transactionID'] ?? 0);
        if ($txID <= 0) { echo json_encode(['success' => false, 'error' => 'Invalid transaction']); exit; }

        // Must be the seller
        $txStmt = $db->prepare("SELECT transactionID, sellerID, buyerID, buyer_agreement, payment_status FROM Transaction WHERE transactionID = ? AND sellerID = ?");
        $txStmt->bind_param('ii', $txID, $uid);
        $txStmt->execute();
        $tx = $txStmt->get_result()->fetch_assoc();
        if (!$tx) { echo json_encode(['success' => false, 'error' => 'Transaction not found or unauthorized']); exit; }

        // Determine new payment_status
        $newPayStatus = ($tx['buyer_agreement'] === 'agreed') ? 'ready_for_payment' : 'pending';
        
        $upd = $db->prepare("UPDATE Transaction SET seller_agreement = 'agreed', payment_status = ?, updated_at = NOW() WHERE transactionID = ?");
        $upd->bind_param('si', $newPayStatus, $txID);

        if ($upd->execute()) {
            // If both agreed, update the item's price to the negotiated bid price
            if ($newPayStatus === 'ready_for_payment') {
                $priceUpd = $db->prepare("UPDATE Item i JOIN Transaction t ON t.itemID = i.itemID SET i.price = t.price WHERE t.transactionID = ?");
                $priceUpd->bind_param('i', $txID);
                $priceUpd->execute();
            }
            echo json_encode(['success' => true, 'payment_status' => $newPayStatus]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Update failed']);
        }
        exit;
    }

    // ── reject_bid ────────────────────────────────────────────────────────────
    if ($action === 'reject_bid') {
        $txID = (int)($_POST['transactionID'] ?? 0);
        if ($txID <= 0) { echo json_encode(['success' => false, 'error' => 'Invalid transaction']); exit; }

        $txStmt = $db->prepare("SELECT transactionID FROM Transaction WHERE transactionID = ? AND sellerID = ?");
        $txStmt->bind_param('ii', $txID, $uid);
        $txStmt->execute();
        if (!$txStmt->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'error' => 'Transaction not found or unauthorized']); exit;
        }

        $upd = $db->prepare("UPDATE Transaction SET seller_agreement = 'rejected', payment_status = 'cancelled', updated_at = NOW() WHERE transactionID = ?");
        $upd->bind_param('i', $txID);

        if ($upd->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Update failed']);
        }
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

$pageTitle = "Messages — Demy's";
require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/main.css"/>
<style>
  /* ── Layout ── */
  .msg-shell {
    display: flex;
    height: calc(100vh - 64px); /* subtract header height */
    overflow: hidden;
    background: var(--bg, #f7f7f5);
  }

  /* ── Sidebar ── */
  .msg-sidebar {
    width: 300px;
    min-width: 300px;
    background: #fff;
    border-right: 1px solid var(--border, #e4e0d8);
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }
  .sidebar-head {
    padding: 18px 16px 14px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--muted2, #9e9a94);
    border-bottom: 1px solid var(--border, #e4e0d8);
  }
  .convo-list { flex: 1; overflow-y: auto; }
  .convo-item {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 13px 16px;
    cursor: pointer;
    border-bottom: 1px solid var(--border, #e4e0d8);
    transition: background .12s;
  }
  .convo-item:hover   { background: var(--bg, #f7f7f5); }
  .convo-item.active  { background: #fdf0e8; border-left: 3px solid var(--accent, #e05c1a); padding-left: 13px; }
  .convo-avatar {
    width: 38px; height: 38px; border-radius: 50%;
    background: var(--accent, #e05c1a);
    color: #fff; font-weight: 700; font-size: 15px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
  }
  .convo-info { flex: 1; min-width: 0; }
  .convo-name  { font-weight: 600; font-size: 13.5px; color: var(--text, #1a1a18); }
  .convo-preview {
    font-size: 12px; color: var(--muted2, #9e9a94);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    margin-top: 2px;
  }
  .empty-sidebar { padding: 24px 16px; font-size: 13px; color: var(--muted2, #9e9a94); text-align: center; }

  /* ── Chat pane ── */
  .msg-main {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: #fafaf8;
  }
  .chat-splash {
    flex: 1; display: flex; align-items: center; justify-content: center;
    flex-direction: column; gap: 10px;
    color: var(--muted2, #9e9a94); font-size: 14px;
  }
  .chat-splash span { font-size: 36px; }

  .chat-header {
    background: #fff;
    border-bottom: 1px solid var(--border, #e4e0d8);
    padding: 14px 20px;
    display: flex; align-items: center; gap: 12px;
  }
  .chat-header-avatar {
    width: 36px; height: 36px; border-radius: 50%;
    background: var(--accent, #e05c1a); color: #fff;
    font-weight: 700; font-size: 14px;
    display: flex; align-items: center; justify-content: center;
  }
  .chat-header-name { font-weight: 700; font-size: 15px; color: var(--text, #1a1a18); }

  /* ── Bid bar (below header, above messages) ── */
  .bid-bar {
    background: #fffbf5;
    border-bottom: 1px solid #f0e8d8;
    padding: 10px 20px;
    display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
  }
  .bid-bar-label { font-size: 12px; font-weight: 600; color: var(--muted2, #9e9a94); margin-right: 4px; }
  .bid-select {
    font-size: 13px; padding: 5px 9px;
    border: 1px solid var(--border, #e4e0d8); border-radius: 6px;
    background: #fff; color: var(--text, #1a1a18); outline: none;
    max-width: 200px;
  }
  .bid-price-wrap { display: flex; align-items: center; gap: 4px; }
  .bid-price-prefix {
    font-size: 13px; font-weight: 600; color: var(--muted2, #9e9a94);
    background: var(--bg, #f7f7f5); border: 1px solid var(--border, #e4e0d8);
    border-right: none; border-radius: 6px 0 0 6px; padding: 5px 8px;
  }
  .bid-price-input {
    font-size: 13px; padding: 5px 9px;
    border: 1px solid var(--border, #e4e0d8); border-radius: 0 6px 6px 0;
    outline: none; width: 100px;
  }
  .bid-price-input:focus { border-color: var(--accent, #e05c1a); }
  .btn-bid {
    background: var(--accent, #e05c1a); color: #fff;
    border: none; border-radius: 7px; padding: 6px 16px;
    font-size: 13px; font-weight: 700; cursor: pointer;
    transition: opacity .15s;
  }
  .btn-bid:hover { opacity: .85; }

  /* ── Active bids panel ── */
  .bids-panel {
    background: #fff; border-bottom: 1px solid var(--border, #e4e0d8);
    padding: 10px 20px; display: none;
  }
  .bids-panel.has-bids { display: block; }
  .bids-panel-title { font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--muted2, #9e9a94); margin-bottom: 8px; }
  .bid-card {
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    background: var(--bg, #f7f7f5); border: 1px solid var(--border, #e4e0d8);
    border-radius: 8px; padding: 9px 13px; margin-bottom: 6px; font-size: 13px;
  }
  .bid-card:last-child { margin-bottom: 0; }
  .bid-card-title { font-weight: 600; flex: 1; color: var(--text, #1a1a18); }
  .bid-card-price { color: var(--accent, #e05c1a); font-weight: 700; }
  .bid-status {
    font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 20px;
    text-transform: uppercase; letter-spacing: .06em;
  }
  .bid-status.pending          { background: #fff7e0; color: #b07a00; }
  .bid-status.ready_for_payment{ background: #e0f7ec; color: #0a7a45; }
  .bid-status.cancelled        { background: #fde8e8; color: #b00000; }
  .btn-accept {
    background: #1a9e5c; color: #fff; border: none; border-radius: 6px;
    padding: 5px 13px; font-size: 12px; font-weight: 700; cursor: pointer;
    transition: opacity .15s;
  }
  .btn-accept:hover { opacity: .85; }
  .btn-reject {
    background: #fff; color: #c0392b; border: 1px solid #c0392b; border-radius: 6px;
    padding: 5px 12px; font-size: 12px; font-weight: 700; cursor: pointer;
    transition: background .12s, color .12s;
  }
  .btn-reject:hover { background: #c0392b; color: #fff; }

  .btn-pay {
    background: #1a9e5c; color: #fff; border: none; border-radius: 6px;
    padding: 5px 16px; font-size: 12px; font-weight: 700; cursor: pointer;
    text-decoration: none; display: inline-flex; align-items: center;
    transition: opacity .15s;
  }
  .btn-pay:hover { opacity: .85; }

  /* ── Messages ── */
  .chat-messages {
    flex: 1; overflow-y: auto;
    padding: 20px 20px 10px;
    display: flex; flex-direction: column; gap: 10px;
  }
  .bubble-row { display: flex; }
  .bubble-row.me    { justify-content: flex-end; }
  .bubble-row.other { justify-content: flex-start; }
  .bubble {
    max-width: 62%; padding: 10px 14px;
    border-radius: 18px; font-size: 14px; line-height: 1.45;
    word-break: break-word;
  }
  .bubble-row.me    .bubble { background: var(--accent, #e05c1a); color: #fff; border-bottom-right-radius: 5px; }
  .bubble-row.other .bubble { background: #ebebea; color: var(--text, #1a1a18); border-bottom-left-radius: 5px; }
  .bubble-time { font-size: 10px; color: var(--muted2, #9e9a94); margin-top: 4px; text-align: right; }
  .bubble-row.other .bubble-time { text-align: left; }

  /* ── Input area ── */
  .chat-input-area {
    background: #fff; border-top: 1px solid var(--border, #e4e0d8);
    padding: 14px 20px;
    display: flex; gap: 10px; align-items: flex-end;
  }
  .chat-textarea {
    flex: 1; padding: 10px 14px; border: 1px solid var(--border, #e4e0d8);
    border-radius: 10px; resize: none; font-family: inherit; font-size: 14px;
    line-height: 1.4; min-height: 42px; max-height: 120px; outline: none;
    transition: border-color .15s;
  }
  .chat-textarea:focus { border-color: var(--accent, #e05c1a); }
  .btn-send {
    background: var(--accent, #e05c1a); color: #fff; border: none;
    border-radius: 10px; padding: 10px 20px; font-weight: 700; font-size: 14px;
    cursor: pointer; transition: opacity .15s; white-space: nowrap;
  }
  .btn-send:hover { opacity: .88; }

  /* ── Responsive ── */
  @media (max-width: 640px) {
    .msg-sidebar { width: 220px; min-width: 220px; }
    .bubble { max-width: 80%; }
  }
</style>

<div class="msg-shell">
  <!-- Sidebar -->
  <div class="msg-sidebar">
    <div class="sidebar-head">Messages</div>
    <div class="convo-list" id="convoList">
      <div class="empty-sidebar">Loading conversations…</div>
    </div>
  </div>

  <!-- Main chat area -->
  <div class="msg-main" id="msgMain">
    <div class="chat-splash">
      <span>💬</span>
      <div>Select a conversation to start chatting</div>
    </div>
  </div>
</div>

<script>
const ME = <?= $uid ?>;
let activeCID   = null;
let activeOther = null;    // { id, username }
let pollTimer   = null;

// ── Bootstrap ─────────────────────────────────────────────────────────────────
loadSidebar();

// ── Sidebar ───────────────────────────────────────────────────────────────────
async function loadSidebar() {
  try {
    const data = await apiFetch('messages.php?action=convos');
    if (!data.success) return;

    const el = document.getElementById('convoList');
    if (!data.conversations.length) {
      el.innerHTML = '<div class="empty-sidebar">No conversations yet.</div>';
      return;
    }
    el.innerHTML = '';
    data.conversations.forEach(c => {
      const d = document.createElement('div');
      d.className = 'convo-item' + (c.conversationID === activeCID ? ' active' : '');
      d.dataset.cid = c.conversationID;
      d.innerHTML = `
        <div class="convo-avatar">${esc(c.otherUsername).charAt(0).toUpperCase()}</div>
        <div class="convo-info">
          <div class="convo-name">${esc(c.otherUsername)}</div>
          <div class="convo-preview">${esc(c.lastMessage)}</div>
        </div>`;
      d.onclick = () => openConversation(c.conversationID, c.otherUserID, c.otherUsername);
      el.appendChild(d);
    });
  } catch(e) { console.error(e); }
}

// ── Open conversation ─────────────────────────────────────────────────────────
function openConversation(cid, otherID, otherName) {
  activeCID   = cid;
  activeOther = { id: otherID, username: otherName };

  // Highlight sidebar
  document.querySelectorAll('.convo-item').forEach(el => {
    el.classList.toggle('active', el.dataset.cid === cid);
  });

  // Render shell
  const main = document.getElementById('msgMain');
  main.innerHTML = `
    <div class="chat-header">
      <div class="chat-header-avatar">${esc(otherName).charAt(0).toUpperCase()}</div>
      <div class="chat-header-name">${esc(otherName)}</div>
    </div>
    <div class="bid-bar" id="bidBar" style="display:none"></div>
    <div class="bids-panel" id="bidsPanel">
      <div class="bids-panel-title">Active Offers</div>
      <div id="bidsInner"></div>
    </div>
    <div class="chat-messages" id="chatMessages"></div>
    <div class="chat-input-area">
      <textarea class="chat-textarea" id="msgInput" placeholder="Type a message…" rows="1"
        onkeydown="handleEnter(event)" oninput="autoResize(this)"></textarea>
      <button class="btn-send" onclick="sendMessage()">Send</button>
    </div>`;

  loadMessages();
  clearInterval(pollTimer);
  pollTimer = setInterval(loadMessages, 3000);
}

// ── Load messages + context ───────────────────────────────────────────────────
async function loadMessages() {
  if (!activeCID) return;
  try {
    const data = await apiFetch(`messages.php?action=load&conversationID=${encodeURIComponent(activeCID)}`);
    if (!data.success) return;

    renderMessages(data.messages);
    renderBidBar(data.items, data.otherID);   // items = seller's available items
    renderBidsPanel(data.bids);
  } catch(e) { console.error(e); }
}

// ── Render messages ───────────────────────────────────────────────────────────
function renderMessages(msgs) {
  const container = document.getElementById('chatMessages');
  if (!container) return;
  const atBottom = container.scrollHeight - container.scrollTop <= container.clientHeight + 60;

  container.innerHTML = '';
  if (!msgs.length) {
    container.innerHTML = '<div style="text-align:center;color:var(--muted2);font-size:13px;margin-top:20px">No messages yet. Say hello!</div>';
    return;
  }
  msgs.forEach(m => {
    const isMe = m.senderID === ME;
    const row  = document.createElement('div');
    row.className = `bubble-row ${isMe ? 'me' : 'other'}`;
    row.innerHTML = `
      <div>
        <div class="bubble">${esc(m.body)}</div>
        <div class="bubble-time">${fmtTime(m.sent_at)}</div>
      </div>`;
    container.appendChild(row);
  });

  if (atBottom) container.scrollTop = container.scrollHeight;
}

// ── Bid bar (buyer side: send an offer on seller's item) ──────────────────────
function renderBidBar(items, otherID) {
  const bar = document.getElementById('bidBar');
  if (!bar) return;

  // Only show bid bar if the other person has available items (i.e. I am the potential buyer)
  if (!items || !items.length) {
    bar.style.display = 'none';
    return;
  }

  bar.style.display = 'flex';
  bar.innerHTML = `
    <span class="bid-bar-label">Make an offer:</span>
    <select class="bid-select" id="bidItemSelect">
      ${items.map(i => `<option value="${i.itemID}" data-price="${i.price}">${esc(i.title)} — ₱${parseFloat(i.price).toLocaleString()}</option>`).join('')}
    </select>
    <div class="bid-price-wrap">
      <span class="bid-price-prefix">₱</span>
      <input type="number" class="bid-price-input" id="bidPriceInput" min="1" step="0.01" placeholder="Your offer"/>
    </div>
    <button class="btn-bid" onclick="submitBid()">Send Offer</button>`;

  // Pre-fill price when item changes
  const sel = document.getElementById('bidItemSelect');
  const inp = document.getElementById('bidPriceInput');
  const prefill = () => { inp.value = parseFloat(sel.selectedOptions[0].dataset.price).toFixed(2); };
  sel.addEventListener('change', prefill);
  prefill();
}

// ── Bids panel (both sides see active offers; seller sees accept/reject) ───────
function renderBidsPanel(bids) {
  const panel = document.getElementById('bidsPanel');
  const inner = document.getElementById('bidsInner');
  if (!panel || !inner) return;

  if (!bids || !bids.length) {
    panel.classList.remove('has-bids');
    return;
  }
  panel.classList.add('has-bids');
  inner.innerHTML = '';

  bids.forEach(b => {
    const iAmSeller = parseInt(b.sellerID) === ME;
    const statusLabel = {
      pending:           'Pending',
      ready_for_payment: 'Ready to Pay',
      cancelled:         'Cancelled',
    }[b.payment_status] || b.payment_status;

    let actions = '';
    // Seller sees accept/reject only when their agreement is still pending
    if (iAmSeller && b.seller_agreement === 'pending') {
      actions = `
        <button class="btn-accept" onclick="acceptBid(${b.transactionID})">Accept</button>
        <button class="btn-reject" onclick="rejectBid(${b.transactionID})">Reject</button>`;
    } else if (!iAmSeller && b.payment_status === 'ready_for_payment') {
      actions = `
    <a class="btn-pay" href="transaction.php?transactionID=${b.transactionID}">Proceed to Payment →</a>`;
  } 

    const card = document.createElement('div');
    card.className = 'bid-card';
    card.innerHTML = `
      <span class="bid-card-title">${esc(b.title)}</span>
      <span class="bid-card-price">₱${parseFloat(b.price).toLocaleString(undefined,{minimumFractionDigits:2})}</span>
      <span class="bid-status ${b.payment_status}">${statusLabel}</span>
      ${iAmSeller ? '<span style="font-size:11px;color:var(--muted2)">You\'re the seller</span>' : '<span style="font-size:11px;color:var(--muted2)">Your offer</span>'}
      ${actions}`;
    inner.appendChild(card);
  });
}

// ── Actions ───────────────────────────────────────────────────────────────────
async function sendMessage() {
  const inp  = document.getElementById('msgInput');
  const body = inp?.value.trim();
  if (!body || !activeCID) return;

  const fd = new FormData();
  fd.append('action', 'send');
  fd.append('conversationID', activeCID);
  fd.append('body', body);

  inp.value = '';
  inp.style.height = '';

  try {
    const data = await apiFetch('messages.php', { method: 'POST', body: fd });
    if (data.success) {
      loadMessages();
      loadSidebar();
    } else {
      alert(data.error || 'Failed to send message');
    }
  } catch(e) { console.error(e); }
}

async function submitBid() {
  const sel   = document.getElementById('bidItemSelect');
  const prInp = document.getElementById('bidPriceInput');
  if (!sel || !prInp) return;

  const itemID = parseInt(sel.value);
  const price  = parseFloat(prInp.value);

  if (!itemID || isNaN(price) || price <= 0) {
    alert('Please select an item and enter a valid offer price.'); return;
  }
  if (!confirm(`Send an offer of ₱${price.toLocaleString()} for "${sel.selectedOptions[0].text.split(' — ')[0]}"?`)) return;

  const fd = new FormData();
  fd.append('action', 'bid');
  fd.append('itemID', itemID);
  fd.append('price', price);

  try {
    const data = await apiFetch('messages.php', { method: 'POST', body: fd });
    if (data.success) {
      alert(`Offer sent for "${data.itemTitle}"!`);
      loadMessages();
    } else {
      alert(data.error || 'Failed to submit offer');
    }
  } catch(e) { console.error(e); }
}

async function acceptBid(txID) {
  if (!confirm('Accept this offer? The buyer will be notified to proceed with payment.')) return;
  const fd = new FormData();
  fd.append('action', 'accept_bid');
  fd.append('transactionID', txID);

  try {
    const data = await apiFetch('messages.php', { method: 'POST', body: fd });
    if (data.success) {
      loadMessages();
    } else {
      alert(data.error || 'Failed to accept offer');
    }
  } catch(e) { console.error(e); }
}

async function rejectBid(txID) {
  if (!confirm('Reject and cancel this offer?')) return;
  const fd = new FormData();
  fd.append('action', 'reject_bid');
  fd.append('transactionID', txID);

  try {
    const data = await apiFetch('messages.php', { method: 'POST', body: fd });
    if (data.success) {
      loadMessages();
    } else {
      alert(data.error || 'Failed to reject offer');
    }
  } catch(e) { console.error(e); }
}

// ── Utilities ─────────────────────────────────────────────────────────────────
async function apiFetch(url, opts = {}) {
  const res = await fetch(url, opts);
  return res.json();
}

function handleEnter(e) {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
}

function autoResize(el) {
  el.style.height = 'auto';
  el.style.height = Math.min(el.scrollHeight, 120) + 'px';
}

function esc(s) {
  return String(s ?? '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function fmtTime(iso) {
  const d = new Date(iso), now = new Date(), diff = (now - d) / 1000;
  if (diff < 60)    return 'just now';
  if (diff < 3600)  return Math.floor(diff / 60) + 'm ago';
  if (diff < 86400) return d.toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit' });
  return d.toLocaleDateString('en-US', { month:'short', day:'numeric' });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>