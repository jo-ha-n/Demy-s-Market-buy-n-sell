<?php
// ── Demy's — transaction.php ──────────────────────────────────────────────────
// Transaction detail page shown to the buyer after seller accepts their offer.
// Also handles payment recording (POST action=pay).
//
// GET  ?transactionID=N  → show transaction detail
// POST action=pay        → record Payment row, set Transaction.payment_status = 'completed',
//                          set Item.status = 'sold'
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/../includes/helpers.php';
requireLogin();

$db  = getDB();
$me  = currentUser();
$uid = (int) $me['userID'];

// ── AJAX: record payment ──────────────────────────────────────────────────────
$action = $_POST['action'] ?? null;
if ($action === 'pay') {
    header('Content-Type: application/json');

    $txID   = (int)($_POST['transactionID'] ?? 0);
    $method = trim($_POST['payment_method'] ?? '');

    if ($txID <= 0 || $method === '') {
        echo json_encode(['success' => false, 'error' => 'Invalid parameters']); exit;
    }

    // Load transaction — only the buyer may pay
    $stmt = $db->prepare("
        SELECT t.transactionID, t.buyerID, t.sellerID, t.itemID, t.price,
               t.payment_status, t.seller_agreement, t.buyer_agreement
        FROM Transaction t
        WHERE t.transactionID = ? AND t.buyerID = ?
    ");
    $stmt->bind_param('ii', $txID, $uid);
    $stmt->execute();
    $tx = $stmt->get_result()->fetch_assoc();

    if (!$tx) { echo json_encode(['success' => false, 'error' => 'Transaction not found or unauthorized']); exit; }
    if ($tx['payment_status'] !== 'ready_for_payment') {
        echo json_encode(['success' => false, 'error' => 'Transaction is not ready for payment']); exit;
    }

    // Insert Payment record
    $pStmt = $db->prepare("
        INSERT INTO Payment (transactionID, payment_method, amount, status, paid_at)
        VALUES (?, ?, ?, 'completed', NOW())
    ");
    $pStmt->bind_param('isd', $txID, $method, $tx['price']);

    if (!$pStmt->execute()) {
        echo json_encode(['success' => false, 'error' => 'Failed to record payment']); exit;
    }

    // Mark transaction completed
    $db->prepare("UPDATE Transaction SET payment_status = 'completed', updated_at = NOW() WHERE transactionID = ?")
       ->bind_param('i', $txID) ?: null;
    $db->prepare("UPDATE Transaction SET payment_status = 'completed', updated_at = NOW() WHERE transactionID = ?")
       ->execute() ?: null;

    // Use a proper prepared statement execute
    $updTx = $db->prepare("UPDATE Transaction SET payment_status = 'completed', updated_at = NOW() WHERE transactionID = ?");
    $updTx->bind_param('i', $txID);
    $updTx->execute();

    // Mark item as sold
    $updItem = $db->prepare("UPDATE Item SET status = 'sold' WHERE itemID = ?");
    $updItem->bind_param('i', $tx['itemID']);
    $updItem->execute();

    echo json_encode(['success' => true]);
    exit;
}

// ── Load transaction data ─────────────────────────────────────────────────────
$txID = (int)($_GET['transactionID'] ?? 0);
if ($txID <= 0) { header('Location: messages.php'); exit; }

$stmt = $db->prepare("
    SELECT
        t.transactionID, t.price, t.payment_status,
        t.seller_agreement, t.buyer_agreement,
        t.created_at, t.updated_at,
        t.sellerID, t.buyerID, t.itemID,
        seller.username AS seller_username,
        buyer.username  AS buyer_username,
        i.title         AS item_title,
        i.description   AS item_description,
        i.status        AS item_status,
        c.category_name,
        (SELECT img.images FROM Image img WHERE img.itemID = i.itemID LIMIT 1) AS item_image
    FROM Transaction t
    JOIN Users seller ON seller.userID = t.sellerID
    JOIN Users buyer  ON buyer.userID  = t.buyerID
    JOIN Item i       ON i.itemID      = t.itemID
    JOIN Category c   ON c.categoryID  = i.categoryID
    WHERE t.transactionID = ?
      AND (t.buyerID = ? OR t.sellerID = ?)
");
$stmt->bind_param('iii', $txID, $uid, $uid);
$stmt->execute();
$tx = $stmt->get_result()->fetch_assoc();

if (!$tx) { header('Location: messages.php'); exit; }

$isBuyer  = ((int)$tx['buyerID']  === $uid);
$isSeller = ((int)$tx['sellerID'] === $uid);

// Fetch existing payment record if any
$payStmt = $db->prepare("SELECT paymentID, payment_method, amount, status, paid_at FROM Payment WHERE transactionID = ? LIMIT 1");
$payStmt->bind_param('i', $txID);
$payStmt->execute();
$payment = $payStmt->get_result()->fetch_assoc();

$pageTitle = "Transaction #$txID — Demy's";
require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/main.css"/>
<style>
  /* ── Page shell ── */
  .tx-page {
    max-width: 780px;
    margin: 0 auto;
    padding: 36px 20px 60px;
  }

  .tx-back {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 13px; font-weight: 600; color: var(--muted2, #9e9a94);
    text-decoration: none; margin-bottom: 28px;
    transition: color .15s;
  }
  .tx-back:hover { color: var(--accent, #e05c1a); }

  /* ── Status banner ── */
  .tx-banner {
    border-radius: 12px; padding: 18px 22px;
    display: flex; align-items: center; gap: 14px;
    margin-bottom: 28px;
  }
  .tx-banner.pending          { background: #fff7e0; border: 1px solid #ffe58a; }
  .tx-banner.ready_for_payment{ background: #e8f7f0; border: 1px solid #7addb1; }
  .tx-banner.completed        { background: #eaf4ff; border: 1px solid #90c6f5; }
  .tx-banner.cancelled        { background: #fde8e8; border: 1px solid #f5a0a0; }

  .tx-banner-icon { font-size: 28px; line-height: 1; }
  .tx-banner-body { flex: 1; }
  .tx-banner-title { font-weight: 700; font-size: 15px; color: var(--text, #1a1a18); }
  .tx-banner-sub   { font-size: 13px; color: var(--muted2, #9e9a94); margin-top: 3px; }

  /* ── Cards ── */
  .tx-card {
    background: #fff;
    border: 1px solid var(--border, #e4e0d8);
    border-radius: 14px;
    overflow: hidden;
    margin-bottom: 20px;
  }
  .tx-card-head {
    padding: 14px 20px;
    background: var(--bg, #f7f7f5);
    border-bottom: 1px solid var(--border, #e4e0d8);
    font-size: 11px; font-weight: 700; letter-spacing: .1em;
    text-transform: uppercase; color: var(--muted2, #9e9a94);
  }
  .tx-card-body { padding: 20px; }

  /* ── Item row ── */
  .item-row {
    display: flex; gap: 16px; align-items: flex-start;
  }
  .item-thumb {
    width: 90px; height: 90px; border-radius: 10px;
    object-fit: cover; background: var(--bg, #f7f7f5);
    border: 1px solid var(--border, #e4e0d8);
    flex-shrink: 0;
  }
  .item-thumb-placeholder {
    width: 90px; height: 90px; border-radius: 10px;
    background: var(--bg, #f7f7f5); border: 1px solid var(--border, #e4e0d8);
    display: flex; align-items: center; justify-content: center;
    font-size: 28px; flex-shrink: 0;
  }
  .item-details { flex: 1; }
  .item-title   { font-size: 17px; font-weight: 700; color: var(--text, #1a1a18); margin-bottom: 5px; }
  .item-cat     { font-size: 12px; color: var(--muted2, #9e9a94); margin-bottom: 10px; }
  .item-desc    { font-size: 13px; color: var(--muted2, #9e9a94); line-height: 1.5; }

  /* ── Price highlight ── */
  .price-agreed {
    display: flex; align-items: baseline; gap: 6px; margin-top: 14px;
  }
  .price-label  { font-size: 12px; color: var(--muted2, #9e9a94); font-weight: 600; }
  .price-value  {
    font-size: 28px; font-weight: 800; color: var(--accent, #e05c1a);
    letter-spacing: -.01em;
  }

  /* ── Detail rows ── */
  .detail-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 0;
  }
  .detail-row {
    display: flex; flex-direction: column; padding: 13px 0;
    border-bottom: 1px solid var(--border, #e4e0d8);
  }
  .detail-row:nth-child(odd)  { padding-right: 20px; border-right: 1px solid var(--border, #e4e0d8); }
  .detail-row:nth-child(even) { padding-left: 20px; }
  .detail-row:nth-last-child(-n+2) { border-bottom: none; }
  .detail-label { font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--muted2, #9e9a94); margin-bottom: 5px; }
  .detail-value { font-size: 14px; color: var(--text, #1a1a18); font-weight: 500; }

  .agreement-pill {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 12px; font-weight: 700; padding: 3px 10px;
    border-radius: 20px; text-transform: uppercase; letter-spacing: .05em;
  }
  .agreement-pill.agreed   { background: #e0f7ec; color: #0a7a45; }
  .agreement-pill.pending  { background: #fff7e0; color: #b07a00; }
  .agreement-pill.rejected { background: #fde8e8; color: #b00000; }

  /* ── Payment form ── */
  .pay-form { display: flex; flex-direction: column; gap: 16px; }
  .pay-form-label { font-size: 13px; font-weight: 600; color: var(--text, #1a1a18); margin-bottom: 6px; display: block; }
  .pay-methods {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px;
  }
  .pay-method-opt { display: none; }
  .pay-method-label {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 7px; padding: 14px 10px;
    border: 2px solid var(--border, #e4e0d8); border-radius: 10px;
    cursor: pointer; transition: border-color .15s, background .15s;
    font-size: 13px; font-weight: 600; color: var(--muted2, #9e9a94);
    text-align: center;
  }
  .pay-method-label .pm-icon { font-size: 24px; }
  .pay-method-opt:checked + .pay-method-label {
    border-color: var(--accent, #e05c1a);
    background: #fff5f0; color: var(--accent, #e05c1a);
  }
  .btn-confirm-pay {
    background: var(--accent, #e05c1a); color: #fff;
    border: none; border-radius: 10px; padding: 14px 28px;
    font-size: 15px; font-weight: 800; cursor: pointer;
    width: 100%; transition: opacity .15s, transform .1s;
    letter-spacing: .01em;
  }
  .btn-confirm-pay:hover   { opacity: .88; }
  .btn-confirm-pay:active  { transform: scale(.98); }
  .btn-confirm-pay:disabled{ opacity: .45; cursor: not-allowed; }

  /* ── Payment receipt ── */
  .receipt-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 11px 0; border-bottom: 1px solid var(--border, #e4e0d8);
    font-size: 14px;
  }
  .receipt-row:last-child { border-bottom: none; }
  .receipt-label { color: var(--muted2, #9e9a94); }
  .receipt-value { font-weight: 600; color: var(--text, #1a1a18); }
  .receipt-total .receipt-label { font-weight: 700; color: var(--text, #1a1a18); font-size: 15px; }
  .receipt-total .receipt-value { font-weight: 800; color: var(--accent, #e05c1a); font-size: 18px; }

  .success-badge {
    display: flex; align-items: center; gap: 10px;
    background: #e8f7f0; border: 1px solid #7addb1; border-radius: 10px;
    padding: 14px 18px; font-size: 14px; font-weight: 700; color: #0a7a45;
    margin-bottom: 16px;
  }
  .success-badge .sb-icon { font-size: 22px; }

  @media (max-width: 560px) {
    .detail-grid { grid-template-columns: 1fr; }
    .detail-row:nth-child(odd) { border-right: none; padding-right: 0; }
    .detail-row:nth-child(even) { padding-left: 0; }
    .detail-row:nth-last-child(-n+2) { border-bottom: 1px solid var(--border, #e4e0d8); }
    .detail-row:last-child { border-bottom: none; }
  }
</style>

<div class="tx-page">

  <a href="messages.php" class="tx-back">← Back to Messages</a>

  <?php
  // ── Status banner ──────────────────────────────────────────────────────────
  $statusMeta = [
    'pending'           => ['icon' => '⏳', 'title' => 'Waiting for seller',       'sub' => 'The seller has not yet accepted your offer.'],
    'ready_for_payment' => ['icon' => '✅', 'title' => 'Offer accepted!',           'sub' => 'The seller agreed to your price. Complete your payment below.'],
    'completed'         => ['icon' => '🎉', 'title' => 'Payment complete',          'sub' => 'This transaction has been successfully completed.'],
    'cancelled'         => ['icon' => '❌', 'title' => 'Offer rejected / cancelled','sub' => 'This transaction was cancelled. You may return to messages.'],
  ];
  $meta = $statusMeta[$tx['payment_status']] ?? ['icon' => '❓', 'title' => ucfirst($tx['payment_status']), 'sub' => ''];
  ?>
  <div class="tx-banner <?= htmlspecialchars($tx['payment_status']) ?>">
    <div class="tx-banner-icon"><?= $meta['icon'] ?></div>
    <div class="tx-banner-body">
      <div class="tx-banner-title"><?= htmlspecialchars($meta['title']) ?></div>
      <div class="tx-banner-sub"><?= htmlspecialchars($meta['sub']) ?></div>
    </div>
  </div>

  <!-- ── Item card ──────────────────────────────────────────────────────────── -->
  <div class="tx-card">
    <div class="tx-card-head">Item</div>
    <div class="tx-card-body">
      <div class="item-row">
        <?php if (!empty($tx['item_image'])): ?>
          <img src="<?= htmlspecialchars($tx['item_image']) ?>" alt="Item" class="item-thumb">
        <?php else: ?>
          <div class="item-thumb-placeholder">📦</div>
        <?php endif; ?>
        <div class="item-details">
          <div class="item-title"><?= htmlspecialchars($tx['item_title']) ?></div>
          <div class="item-cat"><?= htmlspecialchars($tx['category_name']) ?></div>
          <?php if (!empty($tx['item_description'])): ?>
            <div class="item-desc"><?= nl2br(htmlspecialchars(mb_substr($tx['item_description'], 0, 180))) ?><?= strlen($tx['item_description']) > 180 ? '…' : '' ?></div>
          <?php endif; ?>
          <div class="price-agreed">
            <span class="price-label">Agreed Price</span>
            <span class="price-value">₱<?= number_format((float)$tx['price'], 2) ?></span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Transaction details card ───────────────────────────────────────────── -->
  <div class="tx-card">
    <div class="tx-card-head">Transaction Details</div>
    <div class="tx-card-body">
      <div class="detail-grid">
        <div class="detail-row">
          <span class="detail-label">Transaction ID</span>
          <span class="detail-value">#<?= $txID ?></span>
        </div>
        <div class="detail-row">
          <span class="detail-label">Status</span>
          <span class="detail-value"><?= ucwords(str_replace('_', ' ', $tx['payment_status'])) ?></span>
        </div>
        <div class="detail-row">
          <span class="detail-label">Seller</span>
          <span class="detail-value"><?= htmlspecialchars($tx['seller_username']) ?></span>
        </div>
        <div class="detail-row">
          <span class="detail-label">Buyer</span>
          <span class="detail-value"><?= htmlspecialchars($tx['buyer_username']) ?></span>
        </div>
        <div class="detail-row">
          <span class="detail-label">Seller Agreement</span>
          <span class="detail-value">
            <span class="agreement-pill <?= $tx['seller_agreement'] ?>">
              <?= $tx['seller_agreement'] === 'agreed' ? '✓ ' : ($tx['seller_agreement'] === 'rejected' ? '✕ ' : '· ') ?><?= ucfirst($tx['seller_agreement']) ?>
            </span>
          </span>
        </div>
        <div class="detail-row">
          <span class="detail-label">Buyer Agreement</span>
          <span class="detail-value">
            <span class="agreement-pill <?= $tx['buyer_agreement'] ?>">
              <?= $tx['buyer_agreement'] === 'agreed' ? '✓ ' : ($tx['buyer_agreement'] === 'rejected' ? '✕ ' : '· ') ?><?= ucfirst($tx['buyer_agreement']) ?>
            </span>
          </span>
        </div>
        <div class="detail-row">
          <span class="detail-label">Created</span>
          <span class="detail-value"><?= date('M j, Y g:i A', strtotime($tx['created_at'])) ?></span>
        </div>
        <div class="detail-row">
          <span class="detail-label">Last Updated</span>
          <span class="detail-value"><?= date('M j, Y g:i A', strtotime($tx['updated_at'])) ?></span>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Payment section ────────────────────────────────────────────────────── -->
  <?php if ($tx['payment_status'] === 'completed' && $payment): ?>
    <!-- Receipt -->
    <div class="tx-card">
      <div class="tx-card-head">Payment Receipt</div>
      <div class="tx-card-body">
        <div class="success-badge"><span class="sb-icon">✅</span> Payment successfully recorded</div>
        <div class="receipt-row">
          <span class="receipt-label">Payment ID</span>
          <span class="receipt-value">#<?= $payment['paymentID'] ?></span>
        </div>
        <div class="receipt-row">
          <span class="receipt-label">Method</span>
          <span class="receipt-value"><?= htmlspecialchars($payment['payment_method']) ?></span>
        </div>
        <div class="receipt-row">
          <span class="receipt-label">Paid At</span>
          <span class="receipt-value"><?= date('M j, Y g:i A', strtotime($payment['paid_at'])) ?></span>
        </div>
        <div class="receipt-row receipt-total">
          <span class="receipt-label">Total Paid</span>
          <span class="receipt-value">₱<?= number_format((float)$payment['amount'], 2) ?></span>
        </div>
      </div>
    </div>

  <?php elseif ($isBuyer && $tx['payment_status'] === 'ready_for_payment'): ?>
    <!-- Payment form — buyer only -->
    <div class="tx-card">
      <div class="tx-card-head">Complete Payment</div>
      <div class="tx-card-body">
        <div class="pay-form">
          <div>
            <label class="pay-form-label">Choose Payment Method</label>
            <div class="pay-methods" id="payMethods">
              <?php
              $methods = [
                ['id' => 'gcash',    'icon' => '💙', 'label' => 'GCash'],
                ['id' => 'maya',     'icon' => '💚', 'label' => 'Maya'],
                ['id' => 'cod',      'icon' => '💵', 'label' => 'Cash on Delivery'],
                ['id' => 'bank',     'icon' => '🏦', 'label' => 'Bank Transfer'],
                ['id' => 'meetup',   'icon' => '🤝', 'label' => 'Meet-up / Cash'],
              ];
              foreach ($methods as $m): ?>
                <input type="radio" name="pay_method" id="pm_<?= $m['id'] ?>" value="<?= $m['label'] ?>" class="pay-method-opt">
                <label for="pm_<?= $m['id'] ?>" class="pay-method-label">
                  <span class="pm-icon"><?= $m['icon'] ?></span>
                  <?= $m['label'] ?>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Order summary -->
          <div style="background:var(--bg,#f7f7f5);border-radius:10px;padding:14px 18px;margin-top:4px;">
            <div class="receipt-row">
              <span class="receipt-label">Item</span>
              <span class="receipt-value"><?= htmlspecialchars($tx['item_title']) ?></span>
            </div>
            <div class="receipt-row">
              <span class="receipt-label">Seller</span>
              <span class="receipt-value"><?= htmlspecialchars($tx['seller_username']) ?></span>
            </div>
            <div class="receipt-row receipt-total">
              <span class="receipt-label">Total</span>
              <span class="receipt-value">₱<?= number_format((float)$tx['price'], 2) ?></span>
            </div>
          </div>

          <button class="btn-confirm-pay" id="btnPay" onclick="confirmPayment()" disabled>
            Confirm Payment — ₱<?= number_format((float)$tx['price'], 2) ?>
          </button>
        </div>
      </div>
    </div>

  <?php elseif ($isSeller && $tx['payment_status'] === 'ready_for_payment'): ?>
    <!-- Seller view: waiting for buyer payment -->
    <div class="tx-card">
      <div class="tx-card-head">Payment Status</div>
      <div class="tx-card-body" style="color:var(--muted2);font-size:14px;text-align:center;padding:28px 20px;">
        ⏳ Waiting for the buyer to complete payment.<br>
        <span style="font-size:12px;margin-top:8px;display:block;">You'll see the receipt here once payment is recorded.</span>
      </div>
    </div>
  <?php endif; ?>

</div><!-- /.tx-page -->

<?php if ($isBuyer && $tx['payment_status'] === 'ready_for_payment'): ?>
<script>
// Enable pay button only after method selected
document.querySelectorAll('input[name="pay_method"]').forEach(r => {
  r.addEventListener('change', () => {
    document.getElementById('btnPay').disabled = false;
  });
});

async function confirmPayment() {
  const method = document.querySelector('input[name="pay_method"]:checked')?.value;
  if (!method) { alert('Please select a payment method.'); return; }

  if (!confirm(`Confirm payment of ₱<?= number_format((float)$tx['price'], 2) ?> via ${method}?`)) return;

  const btn = document.getElementById('btnPay');
  btn.disabled = true;
  btn.textContent = 'Processing…';

  const fd = new FormData();
  fd.append('action', 'pay');
  fd.append('transactionID', '<?= $txID ?>');
  fd.append('payment_method', method);

  try {
    const res  = await fetch('transaction.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      window.location.reload();
    } else {
      alert(data.error || 'Payment failed. Please try again.');
      btn.disabled = false;
      btn.textContent = 'Confirm Payment — ₱<?= number_format((float)$tx['price'], 2) ?>';
    }
  } catch(e) {
    console.error(e);
    alert('Network error. Please try again.');
    btn.disabled = false;
    btn.textContent = 'Confirm Payment — ₱<?= number_format((float)$tx['price'], 2) ?>';
  }
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>