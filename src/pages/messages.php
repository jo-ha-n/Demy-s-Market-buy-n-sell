<?php
require_once __DIR__ . '/../src/includes/helpers.php';
requireLogin();

$db  = getDB();
$uid = $_SESSION['userID'];
$csrf = csrfToken();

// If starting a new convo from item page
$withID = (int) ($_GET['with'] ?? 0);
$itemID = (int) ($_GET['item'] ?? 0);
$activeConvID = '';

if ($withID && $withID !== $uid) {
    // Find or create conversation
    $f = $db->prepare('
        SELECT conversationID FROM Conversation
        WHERE (userID_1=? AND userID_2=?) OR (userID_1=? AND userID_2=?)
    ');
    $f->bind_param('iiii', $uid, $withID, $withID, $uid);
    $f->execute();
    $row = $f->get_result()->fetch_row();
    if ($row) {
        $activeConvID = $row[0];
    } else {
        $newUUID = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
            mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
        $ins = $db->prepare('INSERT INTO Conversation (conversationID, userID_1, userID_2) VALUES (?,?,?)');
        $ins->bind_param('sii', $newUUID, $uid, $withID);
        $ins->execute();
        $activeConvID = $newUUID;
    }
}

// Send message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['msg'])) {
    verifyCsrf();
    $convID  = trim($_POST['convID'] ?? '');
    $txt     = trim($_POST['msg']    ?? '');
    $recv    = (int)($_POST['receiverID'] ?? 0);
    if ($convID && $txt && $recv) {
        $now  = date('Y-m-d');
        $time = date('H:i:s');
        $ms   = $db->prepare('INSERT INTO Messages (conversationID,text,date,time,senderID,receiverID) VALUES (?,?,?,?,?,?)');
        $ms->bind_param('ssssii', $convID, $txt, $now, $time, $uid, $recv);
        $ms->execute();
    }
    header("Location: /demys/pages/messages.php?conv={$convID}"); exit;
}

$activeConvID = $activeConvID ?: ($_GET['conv'] ?? '');

// All conversations
$convs = $db->prepare('
    SELECT c.conversationID,
           IF(c.userID_1=?, u2.userID, u1.userID) AS other_id,
           IF(c.userID_1=?, u2.username, u1.username) AS other_name,
           (SELECT text FROM Messages WHERE conversationID=c.conversationID ORDER BY messageID DESC LIMIT 1) AS last_msg
    FROM Conversation c
    JOIN Users u1 ON u1.userID = c.userID_1
    JOIN Users u2 ON u2.userID = c.userID_2
    WHERE c.userID_1=? OR c.userID_2=?
    ORDER BY (SELECT messageID FROM Messages WHERE conversationID=c.conversationID ORDER BY messageID DESC LIMIT 1) DESC
');
$convs->bind_param('iiii', $uid, $uid, $uid, $uid);
$convs->execute();
$conversations = $convs->get_result()->fetch_all(MYSQLI_ASSOC);

// Active conversation messages
$messages = [];
$otherUser = null;
if ($activeConvID) {
    $ms = $db->prepare('SELECT * FROM Messages WHERE conversationID=? ORDER BY messageID ASC');
    $ms->bind_param('s', $activeConvID);
    $ms->execute();
    $messages = $ms->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get other user
    $cu = $db->prepare('
        SELECT IF(userID_1=?, userID_2, userID_1) AS other_id FROM Conversation WHERE conversationID=?
    ');
    $cu->bind_param('is', $uid, $activeConvID);
    $cu->execute();
    $otherID = $cu->get_result()->fetch_row()[0] ?? 0;
    if ($otherID) {
        $ou = $db->prepare('SELECT userID, username FROM Users WHERE userID=?');
        $ou->bind_param('i', $otherID);
        $ou->execute();
        $otherUser = $ou->get_result()->fetch_assoc();
    }
}

$pageTitle = "Messages — Demy's";
// Override main wrapping for full-height layout
$extraHead = '<style>.page-main{padding:0}</style>';
require_once __DIR__ . '/../src/includes/header.php';
?>
<script>window.__csrf = "<?= h($csrf) ?>";</script>

<div class="msg-layout">
  <!-- Conversation list -->
  <div class="msg-list">
    <div style="padding:16px 18px;border-bottom:1px solid var(--border);font-family:'Syne',sans-serif;font-weight:700;font-size:16px">
      Messages
    </div>
    <?php if (empty($conversations)): ?>
      <div style="padding:24px 18px;font-size:13px;color:var(--muted)">No conversations yet.</div>
    <?php endif; ?>
    <?php foreach ($conversations as $conv): ?>
    <a href="/demys/pages/messages.php?conv=<?= h($conv['conversationID']) ?>"
       class="msg-list-item <?= $conv['conversationID']===$activeConvID?'active':'' ?>">
      <div class="msg-list-avatar"><?= strtoupper(substr($conv['other_name'],0,1)) ?></div>
      <div>
        <div class="msg-list-name"><?= h($conv['other_name']) ?></div>
        <div class="msg-list-preview"><?= h($conv['last_msg'] ?? '…') ?></div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Message panel -->
  <?php if ($activeConvID && $otherUser): ?>
  <div class="msg-panel">
    <div class="msg-panel-header">
      <div class="msg-list-avatar" style="width:36px;height:36px;font-size:14px">
        <?= strtoupper(substr($otherUser['username'],0,1)) ?>
      </div>
      <?= h($otherUser['username']) ?>
    </div>
    <div class="msg-panel-body" id="msgBody">
      <?php if (empty($messages)): ?>
        <div style="text-align:center;color:var(--muted);font-size:13px;padding-top:40px">Start the conversation!</div>
      <?php endif; ?>
      <?php foreach ($messages as $msg): ?>
      <div class="msg-bubble <?= $msg['senderID']==$uid?'me':'them' ?>">
        <?= nl2br(h($msg['text'])) ?>
        <div style="font-size:10px;opacity:0.6;margin-top:4px;text-align:right">
          <?= h($msg['time']) ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <form class="msg-panel-input" method="POST">
      <input type="hidden" name="csrf_token"  value="<?= h($csrf) ?>"/>
      <input type="hidden" name="convID"      value="<?= h($activeConvID) ?>"/>
      <input type="hidden" name="receiverID"  value="<?= $otherUser['userID'] ?>"/>
      <input type="text" name="msg" class="form-control" placeholder="Type a message…" autocomplete="off" required/>
      <button type="submit" class="btn-accent">Send</button>
    </form>
  </div>
  <?php else: ?>
  <div class="msg-panel" style="display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:14px">
    Select a conversation to start messaging
  </div>
  <?php endif; ?>
</div>

<script>
  // Scroll to bottom
  const body = document.getElementById('msgBody');
  if (body) body.scrollTop = body.scrollHeight;
</script>

<?php require_once __DIR__ . '/../src/includes/footer.php'; ?>
