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
                    SELECT m.body
                    FROM Messages m
                    WHERE m.conversationID = c.conversationID
                      AND m.is_deleted = 0
                    ORDER BY m.created_at DESC
                    LIMIT 1
                ) AS lastBody,
                (
                    SELECT m.created_at
                    FROM Messages m
                    WHERE m.conversationID = c.conversationID
                    ORDER BY m.created_at DESC
                    LIMIT 1
                ) AS lastActive
            FROM Conversations c
            JOIN Users u ON u.userID = IF(c.userID_1 = ?, c.userID_2, c.userID_1)
            WHERE c.userID_1 = ? OR c.userID_2 = ?
            ORDER BY lastSentAt DESC
        ");
        $stmt->bind_param('iiiii', $uid, $uid, $uid, $uid, $uid);
        $stmt->execute();
        $res = $stmt->get_result();

        $convos = [];
        while ($row = $res->fetch_assoc()) {
            $convos[] = [
                'conversationID' => (int)$row['conversationID'],
                'otherUserID'     => (int)$row['otherUserID'],
                'otherUsername'   => htmlspecialchars($row['otherUsername']),
                'lastMessage'     => $row['lastMessage'] ? htmlspecialchars($row['lastMessage']) : 'No messages yet...',
                'lastActive'      => $row['lastActive'] ?: null
            ];
        }
        echo json_encode(['success' => true, 'conversations' => $convos]);
        exit;
    }

    // ── load: fetch all messages for a specific conversation ──────────────────
    if ($action === 'load') {
        $cid = (int)($_GET['conversationID'] ?? 0);

        // Security authorization check: Verify user belongs to conversation
        $chk = $db->prepare("SELECT 1 FROM Conversations WHERE conversationID=? AND (userID_1=? OR userID_2=?)");
        $chk->bind_param('iii', $cid, $uid, $uid);
        $chk->execute();
        if (!$chk->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }

        $stmt = $db->prepare("
            SELECT messageID, senderID, body, created_at, is_deleted, is_edited, edited_at
            FROM Messages
            WHERE conversationID = ?
            ORDER BY created_at ASC
        ");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $res = $stmt->get_result();

        $msgs = [];
        while ($row = $res->fetch_assoc()) {
            $msgs[] = [
                'messageID'  => (int)$row['messageID'],
                'senderID'   => (int)$row['senderID'],
                'body'       => $row['is_deleted'] ? '[This message was deleted]' : htmlspecialchars($row['body']),
                'created_at' => $row['created_at'],
                'is_deleted' => (int)$row['is_deleted'],
                'is_edited'  => (int)$row['is_edited'],
                'edited_at'  => $row['edited_at']
            ];
        }
        echo json_encode(['success' => true, 'messages' => $msgs]);
        exit;
    }

    // ── send: post a new message ──────────────────────────────────────────────
    if ($action === 'send') {
        $cid  = (int)($_POST['conversationID'] ?? 0);
        $body = trim($_POST['body'] ?? '');

        if ($cid <= 0 || $body === '') {
            echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
            exit;
        }

        // Security authorization check
        $chk = $db->prepare("SELECT 1 FROM Conversations WHERE conversationID=? AND (userID_1=? OR userID_2=?)");
        $chk->bind_param('iii', $cid, $uid, $uid);
        $chk->execute();
        if (!$chk->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO Messages (conversationID, senderID, body, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param('iis', $cid, $uid, $body);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'messageID' => $db->insert_id]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Database storage failure']);
        }
        exit;
    }

    // ── edit: modify message details ──────────────────────────────────────────
    if ($action === 'edit') {
        $mid  = (int)($_POST['messageID'] ?? 0);
        $body = trim($_POST['body'] ?? '');

        if ($mid <= 0 || $body === '') {
            echo json_encode(['success' => false, 'error' => 'Invalid inputs']);
            exit;
        }

        // Security authorization check: Must be original message author
        $stmt = $db->prepare("UPDATE Messages SET body = ?, is_edited = 1, edited_at = NOW() WHERE messageID = ? AND senderID = ?");
        $stmt->bind_param('sii', $body, $mid, $uid); // Fixed missing parameters array bind step
        
        if ($stmt->execute() && $db->affected_rows > 0) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Update rejected or context unchanged']);
        }
        exit;
    }

    // ── delete: execute dynamic messaging target clear drop ───────────────────
    if ($action === 'delete') {
        $mid = (int)($_POST['messageID'] ?? 0);

        if ($mid <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid structural identifier target']);
            exit;
        }

        // Security check: Must be original message author
        $stmt = $db->prepare("UPDATE Messages SET body = '', is_deleted = 1 WHERE messageID = ? AND senderID = ?");
        $stmt->bind_param('ii', $mid, $uid); // Fixed missing parameters array bind step
        
        if ($stmt->execute() && $db->affected_rows > 0) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Deletion target failed verification']);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Messages — Demy's</title>
  <style>
    :root {
      --accent: #e05c1a;
      --bg: #f7f7f5;
      --border: #e4e0d8;
      --text: #1a1a18;
      --text-muted: #7a7670;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: sans-serif; background: var(--bg); color: var(--text); height: 100vh; display: flex; flex-direction: column; }
    
    header { background: #fff; border-bottom: 1px solid var(--border); padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
    header h1 { font-size: 18px; color: var(--accent); }
    header a { color: var(--text); text-decoration: none; font-size: 14px; }

    .messaging-container { display: flex; flex: 1; overflow: hidden; }
    
    /* Sidebar list components */
    .convo-sidebar { width: 320px; background: #fff; border-right: 1px solid var(--border); display: flex; flex-direction: column; }
    .sidebar-title { padding: 15px; font-size: 14px; font-weight: bold; border-bottom: 1px solid var(--border); text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); }
    .convo-list { flex: 1; overflow-y: auto; }
    .convo-item { padding: 15px; border-bottom: 1px solid var(--border); cursor: pointer; transition: background 0.1s; }
    .convo-item:hover { background: var(--bg); }
    .convo-item.active { background: #fdf0e8; border-left: 3px solid var(--accent); }
    .convo-name { font-weight: bold; font-size: 14px; margin-bottom: 3px; }
    .convo-lastmsg { font-size: 12px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    /* Active window chat elements */
    .chat-window { flex: 1; display: flex; flex-direction: column; background: #fafafa; }
    .chat-header { padding: 15px 20px; background: #fff; border-bottom: 1px solid var(--border); font-weight: bold; font-size: 15px; }
    .chat-messages { flex: 1; padding: 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; }
    
    /* Chat Bubble Components */
    .bubble-wrapper { display: flex; flex-direction: column; max-width: 65%; width: fit-content; position: relative; }
    .bubble-wrapper.me { align-self: flex-end; align-items: flex-end; }
    .bubble-wrapper.other { align-self: flex-start; align-items: flex-start; }
    
    .bubble { padding: 10px 14px; border-radius: 16px; font-size: 14px; line-height: 1.4; word-break: break-word; }
    .bubble-wrapper.me .bubble { background: var(--accent); color: #fff; border-bottom-right-radius: 4px; }
    .bubble-wrapper.other .bubble { background: #eee; color: var(--text); border-bottom-left-radius: 4px; }
    .bubble.deleted { background: #f0f0f0 !important; color: #b0b0b0 !important; font-style: italic; border: 1px dashed #d0d0d0; }

    .bubble-meta { font-size: 10px; color: var(--text-muted); margin-top: 3px; display: flex; gap: 5px; align-items: center; }
    .bubble-actions { display: none; position: absolute; top: 50%; transform: translateY(-50%); gap: 5px; background: rgba(255,255,255,0.9); padding: 4px; border-radius: 6px; border: 1px solid var(--border); box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
    .bubble-wrapper.me:hover .bubble-actions { display: flex; left: -65px; }
    .bubble-actions button { background: none; border: none; font-size: 11px; cursor: pointer; color: var(--text-muted); }
    .bubble-actions button:hover { color: var(--text); }
    .bubble-actions button.del-btn:hover { color: red; }

    /* Bottom input framework container */
    .chat-input-area { padding: 15px 20px; background: #fff; border-top: 1px solid var(--border); }
    .input-form { display: flex; gap: 10px; }
    .input-form textarea { flex: 1; height: 40px; padding: 10px; border: 1px solid var(--border); border-radius: 8px; resize: none; font-family: inherit; font-size: 13px; outline: none; }
    .input-form textarea:focus { border-color: var(--accent); }
    .input-form button { background: var(--accent); color: #fff; border: none; padding: 0 20px; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 13px; }
    .input-form button:hover { opacity: 0.9; }

    .no-convo-splash { flex: 1; display: flex; align-items: center; justify-content: center; color: var(--text-muted); font-style: italic; font-size: 14px; }
  </style>
</head>
<body>

<header>
  <h1>Demy's Marketplace Messaging</h1>
  <a href="../index.php">← Back to Marketplace</a>
</header>

<div class="messaging-container">
  <div class="convo-sidebar">
    <div class="sidebar-title">Conversations</div>
    <div class="convo-list" id="convoList"></div>
  </div>

  <div class="chat-window" id="chatWindow">
    <div class="no-convo-splash">Select a conversation from the sidebar to begin messaging.</div>
  </div>
</div>

<script>
const myID = <?php echo $uid; ?>;
let activeConversationID = null;
let pollInterval = null;

// Initial sync fetch call running once on view load
loadSidebar();

async function loadSidebar() {
  try {
    const res = await fetch('messages.php?action=convos');
    const data = await res.json();
    if (!data.success) return;

    const container = document.getElementById('convoList');
    container.innerHTML = '';

    if (data.conversations.length === 0) {
      container.innerHTML = '<div style="padding:15px; font-size:13px; color:var(--text-muted); text-align:center;">No active chats</div>';
      return;
    }

    data.conversations.forEach(c => {
      const div = document.createElement('div');
      div.className = `convo-item ${c.conversationID === activeConversationID ? 'active' : ''}`;
      div.onclick = () => selectConversation(c.conversationID, c.otherUsername);
      div.innerHTML = `
        <div class="convo-name">${escapeHtml(c.otherUsername)}</div>
        <div class="convo-lastmsg">${escapeHtml(c.lastMessage)}</div>
      `;
      container.appendChild(div);
    });
  } catch (e) { console.error("Error updating chat layout index definitions", e); }
}

function selectConversation(cid, username) {
  activeConversationID = cid;
  
  // Highlighting active conversation row layout updates inside the DOM
  document.querySelectorAll('.convo-item').forEach(el => el.classList.remove('active'));
  loadSidebar(); // Updates tracking state highlights safely

  const windowEl = document.getElementById('chatWindow');
  windowEl.innerHTML = `
    <div class="chat-header">Chat with ${escapeHtml(username)}</div>
    <div class="chat-messages" id="chatMessages"></div>
    <div class="chat-input-area">
      <form class="input-form" onsubmit="sendMessage(event)">
        <input type="hidden" name="conversationID" value="${cid}">
        <textarea name="body" placeholder="Type a message..." required onkeydown="handleEnterSubmit(event)"></textarea>
        <button type="submit">Send</button>
      </form>
    </div>
  `;

  loadMessages();

  // Polling loop context refreshes
  clearInterval(pollInterval);
  pollInterval = setInterval(loadMessages, 3000);
}

async function loadMessages() {
  if (!activeConversationID) return;
  try {
    const res = await fetch(`messages.php?action=load&conversationID=${activeConversationID}`);
    const data = await res.json();
    if (!data.success) return;

    const container = document.getElementById('chatMessages');
    const wasAtBottom = container.scrollHeight - container.scrollTop <= container.clientHeight + 100;

    container.innerHTML = '';
    data.messages.forEach(m => {
      const isMe = m.senderID === myID;
      const wrapper = document.createElement('div');
      wrapper.className = `bubble-wrapper ${isMe ? 'me' : 'other'}`;

      let bubbleContent = '';
      if (m.is_deleted) {
        bubbleContent = `<div class="bubble deleted">[This message was deleted]</div>`;
      } else {
        bubbleContent = `<div class="bubble">${escapeHtml(m.body)}</div>`;
      }

      let metaStr = formatTime(m.created_at);
      if (m.is_edited && !m.is_deleted) {
        metaStr += ' • Edited';
      }

      let actionButtons = '';
      if (isMe && !m.is_deleted) {
        actionButtons = `
          <div class="bubble-actions">
            <button onclick="editPrompt(${m.messageID}, '${escapeJs(m.body)}')">Edit</button>
            <button class="del-btn" onclick="deleteMessage(${m.messageID})">Delete</button>
          </div>
        `;
      }

      wrapper.innerHTML = `
        ${bubbleContent}
        <div class="bubble-meta">${metaStr}</div>
        ${actionButtons}
      `;
      container.appendChild(wrapper);
    });

    if (wasAtBottom) {
      container.scrollTop = container.scrollHeight;
    }
  } catch(e) { console.error("Message sync routine failed parsing framework rows", e); }
}

async function sendMessage(e) {
  if(e) e.preventDefault();
  const form = document.querySelector('.input-form');
  const txt = form.querySelector('textarea');
  const body = txt.value.trim();
  if(!body) return;

  const fd = new FormData();
  fd.append('action', 'send');
  fd.append('conversationID', activeConversationID);
  fd.append('body', body);

  txt.value = '';

  try {
    const res = await fetch('messages.php', { method: 'POST', body: fd });
    const data = await res.json();
    if(data.success) {
      loadMessages();
      loadSidebar();
    } else {
      alert(data.error || 'Failed to dispatch message context');
    }
  } catch(err) { console.error(err); }
}

function handleEnterSubmit(e) {
  if(e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendMessage();
  }
}

async function editPrompt(mid, currentBody) {
  const newBody = prompt("Edit your message:", currentBody);
  if(newBody === null) return;
  const trimmed = newBody.trim();
  if(!trimmed || trimmed === currentBody) return;

  const fd = new FormData();
  fd.append('action', 'edit');
  fd.append('messageID', mid);
  fd.append('body', trimmed);

  try {
    const res = await fetch('messages.php', { method: 'POST', body: fd });
    const data = await res.json();
    if(data.success) {
      loadMessages();
    } else { alert(data.error || 'Edit rejected'); }
  } catch(e) { console.error(e); }
}

async function deleteMessage(mid) {
  if(!confirm("Are you sure you want to delete this message?")) return;

  const fd = new FormData();
  fd.append('action', 'delete');
  fd.append('messageID', mid);

  try {
    const res = await fetch('messages.php', { method: 'POST', body: fd });
    const data = await res.json();
    if(data.success) {
      loadMessages();
      loadSidebar();
    } else { alert(data.error || 'Deletion execution failed'); }
  } catch(e) { console.error(e); }
}

// Helper utilities
function escapeHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                  .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function escapeJs(s) {
  return String(s).replace(/'/g, "\\'").replace(/"/g, '\\"');
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
</body>
</html>