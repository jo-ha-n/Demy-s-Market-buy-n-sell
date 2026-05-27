<?php
// ── Bootstrap ────────────────────────────────────────────────────────────────
session_start();
require_once __DIR__ . '/../../config/database.php';   // getDB(), currentUserID()
require_once __DIR__ . '/../../config/item_n_img.php'; // getItem(), getItems(), getItemImages(), getItemTags()
require_once __DIR__ . '/../includes/helpers.php';  // h(), setFlash(), getFlash(), csrfToken(), verifyCsrf()

// ── Resolve item ──────────────────────────────────────────────────────────────
$itemID = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$item   = $itemID ? getItem($itemID) : null;   // getItem() already calls getItemImages() + getItemTags()
$csrf   = csrfToken();

// ── Project-root URL path (used for DB-stored asset paths) ───────────────────
// SCRIPT_NAME = "/GitHub/Demy-s-Market-buy-n-sell/src/pages/item.php"
// Go up 3 segments → "/GitHub/Demy-s-Market-buy-n-sell"
$_projectRoot = implode('/', array_slice(
    explode('/', rtrim(str_replace('\\', '/', $_SERVER['SCRIPT_NAME']), '/')),
    0, -3   // strip /src/pages/item.php
));

// Only show available items publicly (admins can see any status)
$currentUserData = currentUser();                          // returns array or null
$currentUserID   = $currentUserData ? (int) $currentUserData['userID'] : 0;
$isAdmin         = $currentUserData && ($currentUserData['role'] ?? '') === 'admin';
$db              = getDB();

$notFound = !$item || ($item['status'] !== 'available' && !$isAdmin);

// ── Page meta (consumed by header.php) ───────────────────────────────────────
$pageTitle  = $notFound ? "Item not found — Demy's" : h($item['title']) . " — Demy's";
$extraHead  = <<<HTML
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<link rel="stylesheet" href="../../assets/css/item.css"/>
<link rel="stylesheet" href="../../assets/css/main.css"/>
HTML;

// ── Seller data ───────────────────────────────────────────────────────────────
$seller = null;
if (!$notFound) {
    $stmt = $db->prepare(
        "SELECT userID, username, date_joined, contact_number,
                ST_Y(coordinates) AS lat, ST_X(coordinates) AS lng
        FROM Users WHERE userID = ?"
    );
    $stmt->bind_param('i', $item['sellerID']);
    $stmt->execute();
    $seller = $stmt->get_result()->fetch_assoc();
}

// ── Reviews ───────────────────────────────────────────────────────────────────
$reviews = [];
if (!$notFound) {
    $stmt = $db->prepare(
        "SELECT r.*, u.username
         FROM Reviews r
         JOIN Users u ON u.userID = r.userID
         WHERE r.itemID = ?
         ORDER BY r.created_at DESC
         LIMIT 8"
    );
    $stmt->bind_param('i', $itemID);
    $stmt->execute();
    $reviews = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ── More from same category ───────────────────────────────────────────────────
$moreItems = [];
if (!$notFound) {
    $stmt = $db->prepare(
        "SELECT i.itemID, i.title, i.price,
                (SELECT img.images FROM Image img WHERE img.itemID = i.itemID LIMIT 1) AS thumb,
                u.username AS seller_name
         FROM Item i
         JOIN Users u ON u.userID = i.sellerID
         WHERE i.categoryID = ? AND i.itemID != ? AND i.status = 'available'
         ORDER BY i.created_at DESC
         LIMIT 4"
    );
    $stmt->bind_param('ii', $item['categoryID'], $itemID);
    $stmt->execute();
    $moreItems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ── Wishlist state ────────────────────────────────────────────────────────────
$isWished = false;
if ($currentUserID && !$notFound) {
    $stmt = $db->prepare("SELECT 1 FROM Wishlist WHERE userID = ? AND itemID = ?");
    $stmt->bind_param('ii', $currentUserID, $itemID);
    $stmt->execute();
    $isWished = (bool) $stmt->get_result()->fetch_row();
}

// ── Ownership ─────────────────────────────────────────────────────────────────
$isOwner = $currentUserID && !$notFound && $currentUserID === (int) $item['sellerID'];

// ── Existing transaction (buyer checking) ─────────────────────────────────────
$existingTxn = null;
if ($currentUserID && !$isOwner && !$notFound) {
    $stmt = $db->prepare(
        "SELECT transactionID, buyer_agreement, seller_agreement, payment_status
         FROM Transaction
         WHERE itemID = ? AND buyerID = ?
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->bind_param('ii', $itemID, $currentUserID);
    $stmt->execute();
    $existingTxn = $stmt->get_result()->fetch_assoc();
}

// ── Helper ────────────────────────────────────────────────────────────────────
function starsHTML(float $avg, int $count): string {
    if (!$count) return '';
    $rounded = round($avg);
    $s = '<div class="stars-row">';
    for ($i = 1; $i <= 5; $i++) {
        $s .= '<span class="star ' . ($i <= $rounded ? 'filled' : '') . '">★</span>';
    }
    $s .= '<span class="rating-label">' . number_format($avg, 1) . ' (' . $count . ' review' . ($count !== 1 ? 's' : '') . ')</span>';
    $s .= '</div>';
    return $s;
}

// ── Include header ────────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($notFound): ?>
<!-- ═══════════════════════════ NOT FOUND ════════════════════════════════════ -->
<div class="container" style="padding: 80px 24px; text-align: center;">
  <div style="font-size:48px;margin-bottom:16px">😕</div>
  <h2 style="font-family:'Syne',sans-serif;font-size:26px;font-weight:800;margin-bottom:8px">Item not found</h2>
  <p style="color:var(--text-2);margin-bottom:24px">It may have been removed, sold, or the link is incorrect.</p>
  <a href="../pages/search.php" class="btn-accent">Browse listings</a>
</div>

<?php else: ?>
<!-- ═══════════════════════════ ITEM PAGE ════════════════════════════════════ -->

<?php
  $images  = $item['images'] ?? [];   // array of ['imageID','itemID','images']
  $tags    = $item['tags']   ?? [];   // array of ['tagID','name']
  $avgRating  = (float)($item['avg_rating']  ?? 0);
  $ratingCount = (int)($item['review_count'] ?? 0);
?>

<div class="container" style="padding-top:20px;padding-bottom:48px">

  <!-- Breadcrumbs -->
  <nav class="breadcrumbs" aria-label="Breadcrumb">
    <a href="../index.html">Home</a>
    <span class="sep">›</span>
    <a href="../pages/search.php">Browse</a>
    <span class="sep">›</span>
    <a href="../pages/search.php?cat=<?= $item['categoryID'] ?>"><?= h($item['category_name']) ?></a>
    <span class="sep">›</span>
    <span class="current"><?= h($item['title']) ?></span>
  </nav>

  <?php if ($item['status'] !== 'available'): ?>
  <div class="status-banner <?= h($item['status']) ?>">
    <?= $item['status'] === 'sold' ? '🏷️ This item has been sold' : '📦 This listing is archived' ?>
  </div>
  <?php endif; ?>

  <!-- Main 2-col layout -->
  <div class="item-layout">

    <!-- ── LEFT ─────────────────────────────────────────────────── -->
    <div>

      <!-- Carousel -->
      <div class="carousel-wrap">
        <div class="carousel-stage" id="carouselStage">
          <?php if ($images): ?>
            <?php foreach ($images as $idx => $img): ?>
              <?php
                $rawSrc = $img['images'];
                // DB stores project-relative paths like "uploads/items/4/photo.jpg".
                // BASE_URL = e.g. "/GitHub/Demy-s-Market-buy-n-sell"
                if (!str_starts_with($rawSrc, 'http')) {
                    $rawSrc = $_projectRoot . '/' . ltrim($rawSrc, '/');
                }
              ?>
              <img src="<?= h($rawSrc) ?>" alt="<?= h($item['title']) ?> image <?= $idx+1 ?>"
                   style="<?= $idx > 0 ? 'display:none' : '' ?>" data-idx="<?= $idx ?>"/>
            <?php endforeach; ?>
          <?php else: ?>
            <img src="https://placehold.co/800x600/e8e6df/9b9891?text=No+Image" alt="No image"/>
          <?php endif; ?>

          <?php if (count($images) > 1): ?>
          <button class="carousel-arrow prev" onclick="prevImg()" aria-label="Previous image">‹</button>
          <button class="carousel-arrow next" onclick="nextImg()" aria-label="Next image">›</button>
          <div class="carousel-count" id="carouselCount">1 / <?= count($images) ?></div>
          <?php endif; ?>
        </div>

        <?php if (count($images) > 1): ?>
        <div class="carousel-dots" id="carouselDots">
          <?php foreach ($images as $idx => $img): ?>
            <div class="carousel-dot <?= $idx===0?'active':'' ?>" onclick="goImg(<?= $idx ?>)"></div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Tags for searching -->
        <?php if ($tags): ?>
        <div class="item-tags">
          <?php foreach ($tags as $tag): ?>
            <a href="../pages/search.php?tag=<?= urlencode($tag['name']) ?>" class="tag-pill">#<?= h($tag['name']) ?></a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div><!-- /carousel-wrap -->

      <!-- Description -->
      <div class="desc-card">
        <h3>About this listing</h3>
        <p><?= nl2br(h($item['description'] ?? 'No description provided.')) ?></p>
      </div>

      <!-- Map (shown when seller has coordinates OR item has address) -->
      <?php
        $hasCoords  = $seller && !empty($seller['lat']) && !empty($seller['lng']);
        // Fall back: try geocoding the address via Nominatim on the client side
        $mapAddress = $item['address'] ?? ($seller['username'] . ' (Quezon City, Metro Manila)');
      ?>
      <div class="map-card">
        <div class="map-card-header">
          <h3>📍 Location</h3>
          <?php if ($mapAddress): ?>
          <div class="map-address-line"><?= h($mapAddress) ?></div>
          <?php endif; ?>
        </div>
        <div id="itemMap"></div>
      </div>

    </div><!-- /left -->

    <!-- ── RIGHT PANEL ───────────────────────────────────────────── -->
    <div class="item-panel">

      <!-- Product info -->
      <div class="panel-card">
        <div class="item-cat-badge"><?= h($item['category_name']) ?></div>
        <h1 class="item-title"><?= h($item['title']) ?></h1>
        <div class="item-price"><?= formatPrice((float)$item['price']) ?></div>

        <?= starsHTML($avgRating, $ratingCount) ?>

        <div class="item-meta">
          <?php if (!empty($item['address'])): ?>
            <span>📍 <?= h($item['address']) ?></span>
          <?php endif; ?>
          <span>🕐 Listed <?= timeAgo($item['created_at']) ?></span>
          <span>🏷️ Status: <?= ucfirst(h($item['status'])) ?></span>
        </div>
      </div>

      <!-- Seller -->
      <?php if ($seller): ?>
      <div class="seller-card">
        <div class="seller-card-label">About the seller</div>
        <div class="seller-card-header">
          <div class="seller-avatar"><?= strtoupper(mb_substr($seller['username'], 0, 1)) ?></div>
          <div>
            <div class="seller-name"><?= h($seller['username']) ?></div>
            <div class="seller-since">Member since <?= date('M Y', strtotime($seller['date_joined'])) ?></div>
          </div>
        </div>
        <?php if ($seller['contact_number'] && $currentUserID && !$isOwner): ?>
          <div style="font-size:13px;color:var(--text-2)">📞 <?= h($seller['contact_number']) ?></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Actions -->
      <div class="action-card">
        <?php if (!$currentUserID): ?>
          <!-- Guest -->
          <a href="../pages/login.php" class="btn-buy">Log in to Buy or Offer</a>

        <?php elseif ($isOwner): ?>
          <!-- Owner -->
          <a href="../pages/sell.php?edit=<?= $itemID ?>" class="btn-buy" style="background:var(--text-1)">✏️ Edit Listing</a>
          <form method="POST" action="../api/item-status.php" onsubmit="return confirm('Mark this item as sold?')">
            <input type="hidden" name="itemID" value="<?= $itemID ?>"/>
            <input type="hidden" name="status" value="sold"/>
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>"/>
            <button type="submit" class="btn-offer">Mark as Sold</button>
          </form>

        <?php elseif ($item['status'] !== 'available'): ?>
          <!-- Item no longer available -->
          <div class="txn-notice">This item is no longer available.</div>

        <?php elseif ($existingTxn): ?>
          <!-- Already has a transaction -->
          <div class="txn-notice">
            <?php
              $ps = $existingTxn['payment_status'];
              $ba = $existingTxn['buyer_agreement'];
              if ($ps === 'completed')          echo '✅ You purchased this item.';
              elseif ($ps === 'cancelled')       echo '❌ This transaction was cancelled.';
              elseif ($ps === 'ready_for_payment') echo '💳 Ready for payment — check your messages.';
              elseif ($ba === 'agreed')          echo '⏳ Waiting for seller to confirm your offer.';
              else                               echo '🤝 Offer sent — awaiting seller response.';
            ?>
          </div>
          <button class="btn-msg" onclick="startConversation(<?= (int)$item['sellerID'] ?>)">💬 Message Seller</button>

        <?php else: ?>
          <!-- Normal buyer actions -->
          <form method="POST" action="../pages/transaction.php">
            <input type="hidden" name="itemID"  value="<?= $itemID ?>"/>
            <input type="hidden" name="action"  value="buy"/>
            <input type="hidden" name="csrf"    value="<?= h($csrf) ?>"/>
            <button type="submit" class="btn-buy">💳 Buy Now — <?= formatPrice((float)$item['price']) ?></button>
          </form>
          <form method="POST" action="../pages/transaction.php" id="offerForm">
            <input type="hidden" name="itemID"  value="<?= $itemID ?>"/>
            <input type="hidden" name="action"  value="offer"/>
            <input type="hidden" name="csrf"    value="<?= h($csrf) ?>"/>
            <button type="button" class="btn-offer" onclick="toggleOfferInput()">🤝 Make an Offer</button>
            <div id="offerInputWrap" style="display:none;margin-top:8px">
              <input type="number" name="offer_price" id="offerPrice" min="1" step="0.01"
                     placeholder="Your offer price (₱)"
                     style="width:100%;border:1.5px solid var(--border);border-radius:8px;padding:10px 12px;font-size:14px;background:var(--bg);color:var(--text-1);outline:none;margin-bottom:8px"/>
              <button type="submit" class="btn-buy" style="font-size:14px;padding:11px">Send Offer</button>
            </div>
          </form>
          <div class="action-row">
            <button class="btn-msg" onclick="startConversation(<?= (int)$item['sellerID'] ?>)">💬 Message Seller</button>
            <form method="POST" action="../config/wishlist.php" style="margin:0">
              <input type="hidden" name="itemID"  value="<?= $itemID ?>"/>
              <input type="hidden" name="action"  value="<?= $isWished ? 'remove' : 'add' ?>"/>
              <input type="hidden" name="csrf"    value="<?= h($csrf) ?>"/>
              <button type="submit" class="btn-wish <?= $isWished ? 'wished' : '' ?>"
                      title="<?= $isWished ? 'Remove from wishlist' : 'Save to wishlist' ?>">
                <?= $isWished ? '❤️' : '🤍' ?>
              </button>
            </form>
          </div>
        <?php endif; ?>
      </div><!-- /action-card -->

    </div><!-- /right panel -->
  </div><!-- /item-layout -->

  <!-- ── REVIEWS ──────────────────────────────────────────────────── -->
  <?php
    // Can this user leave a review?
    // Must be logged in, not the owner, and have a completed transaction for this item
    $canReview = false;
    $alreadyReviewed = false;
    if ($currentUserID && !$isOwner) {
        $stmt = $db->prepare(
            "SELECT 1 FROM Transaction WHERE itemID=? AND buyerID=? AND payment_status='completed' LIMIT 1"
        );
        $stmt->bind_param('ii', $itemID, $currentUserID);
        $stmt->execute();
        $canReview = (bool)$stmt->get_result()->fetch_row();

        $stmt = $db->prepare("SELECT 1 FROM Reviews WHERE itemID=? AND userID=? LIMIT 1");
        $stmt->bind_param('ii', $itemID, $currentUserID);
        $stmt->execute();
        $alreadyReviewed = (bool)$stmt->get_result()->fetch_row();
    }
  ?>

  <div class="section-wrap">
    <div class="section-header">
      <h2 class="section-title">Reviews <?= $ratingCount ? "($ratingCount)" : '' ?></h2>
    </div>

    <?php if ($canReview && !$alreadyReviewed): ?>
    <!-- Write a review -->
    <div class="review-form-card">
      <h3>Leave a Review</h3>
      <form method="POST" action="../config/reviews.php">
        <input type="hidden" name="itemID" value="<?= $itemID ?>"/>
        <input type="hidden" name="csrf"   value="<?= h($csrf) ?>"/>
        <input type="hidden" name="rating" id="ratingInput" value="0"/>
        <div class="star-picker" id="starPicker">
          <?php for ($i=1;$i<=5;$i++): ?>
            <span class="pick" data-val="<?= $i ?>" onclick="setRating(<?= $i ?>)">★</span>
          <?php endfor; ?>
        </div>
        <textarea class="review-body" name="body" placeholder="Share your experience with this item…" rows="3"></textarea>
        <button type="submit" class="btn-buy" style="margin-top:10px;padding:11px;font-size:14px">Submit Review</button>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($reviews): ?>
    <div class="grid-2">
      <?php foreach ($reviews as $rev): ?>
      <div class="review-card">
        <div class="review-header">
          <div class="review-avatar"><?= strtoupper(mb_substr($rev['username'], 0, 1)) ?></div>
          <div>
            <div class="review-author"><?= h($rev['username']) ?></div>
            <div style="display:flex;align-items:center;gap:4px;margin-top:2px">
              <?php for ($i=1;$i<=5;$i++): ?>
                <span class="star <?= $i<=$rev['rating']?'filled':'' ?>">★</span>
              <?php endfor; ?>
            </div>
          </div>
          <div class="review-date" style="margin-left:auto;font-size:12px;color:var(--text-2)"><?= timeAgo($rev['created_at']) ?></div>
        </div>
        <?php if ($rev['body']): ?>
          <p class="review-text"><?= nl2br(h($rev['body'])) ?></p>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
      <p style="color:var(--text-2);font-size:14px">No reviews yet for this listing.</p>
    <?php endif; ?>
  </div>

  <!-- ── MORE FROM SAME CATEGORY ──────────────────────────────────── -->
  <?php if ($moreItems): ?>
  <div class="section-wrap">
    <div class="section-header">
      <h2 class="section-title">More in <?= h($item['category_name']) ?></h2>
      <a href="../pages/search.php?cat=<?= $item['categoryID'] ?>" class="section-link">Browse all →</a>
    </div>
    <div class="grid-4">
      <?php foreach ($moreItems as $mi): ?>
      <a href="../pages/item.php?id=<?= $mi['itemID'] ?>" class="item-card">
        <div class="item-card-img">
          <?php
            $thumbSrc = $mi['thumb'] ?? '';
            if ($thumbSrc && !str_starts_with($thumbSrc, 'http')) {
                $thumbSrc = $_projectRoot . '/' . ltrim($thumbSrc, '/');
            }
          ?>
          <img src="<?= $thumbSrc ? h($thumbSrc) : 'https://placehold.co/400x300/e8e6df/9b9891?text=No+Image' ?>"
               alt="<?= h($mi['title']) ?>" loading="lazy"/>
        </div>
        <div class="item-card-body">
          <div class="item-card-title"><?= h($mi['title']) ?></div>
          <div class="item-card-price"><?= formatPrice((float)$mi['price']) ?></div>
          <div style="font-size:12px;color:var(--text-2);margin-top:3px">by <?= h($mi['seller_name']) ?></div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /container -->

<?php endif; // notFound ?>

<!-- ── JS ─────────────────────────────────────────────────────────── -->
<script>
/* Carousel */
(function(){
  const imgs = document.querySelectorAll('#carouselStage img[data-idx]');
  const dots = document.querySelectorAll('.carousel-dot');
  const countEl = document.getElementById('carouselCount');
  let cur = 0;
  const total = imgs.length;

  function go(n) {
    imgs[cur].style.display = 'none';
    dots[cur]?.classList.remove('active');
    cur = (n + total) % total;
    imgs[cur].style.display = 'block';
    dots[cur]?.classList.add('active');
    if (countEl) countEl.textContent = (cur+1) + ' / ' + total;
  }

  window.prevImg = () => go(cur - 1);
  window.nextImg = () => go(cur + 1);
  window.goImg  = (n) => go(n);

  // Swipe support
  let sx = 0;
  const stage = document.getElementById('carouselStage');
  if (stage) {
    stage.addEventListener('touchstart', e => sx = e.touches[0].clientX, {passive:true});
    stage.addEventListener('touchend',   e => {
      const dx = e.changedTouches[0].clientX - sx;
      if (Math.abs(dx) > 40) dx < 0 ? nextImg() : prevImg();
    });
  }
})();

/* Offer input toggle */
function toggleOfferInput() {
  const w = document.getElementById('offerInputWrap');
  if (!w) return;
  w.style.display = w.style.display === 'none' ? 'block' : 'none';
  if (w.style.display === 'block') document.getElementById('offerPrice')?.focus();
}

/* Star rating picker */
function setRating(n) {
  document.getElementById('ratingInput').value = n;
  document.querySelectorAll('#starPicker .pick').forEach((el, i) => {
    el.classList.toggle('on', i < n);
  });
}

/* Map */
(function(){
  <?php if ($hasCoords): ?>
    const lat = <?= (float)$seller['lat'] ?>;
    const lng = <?= (float)$seller['lng'] ?>;
    initMap(lat, lng);
  <?php else: ?>
    // Geocode the address string via Nominatim
    const query = <?= json_encode($mapAddress) ?>;
    fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&limit=1`)
      .then(r => r.json())
      .then(data => {
        if (data && data[0]) {
          initMap(parseFloat(data[0].lat), parseFloat(data[0].lon));
        } else {
          initMap(14.5995, 120.9842); // fallback: Metro Manila
        }
      })
      .catch(() => initMap(14.5995, 120.9842));
  <?php endif; ?>

  function initMap(lat, lng) {
    const map = L.map('itemMap', { scrollWheelZoom: false }).setView([lat, lng], 14);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '© OpenStreetMap contributors'
    }).addTo(map);

    // Seller marker
    const sellerIcon = L.divIcon({
      className: '',
      html: `<div style="background:var(--accent);color:#fff;border-radius:50%;width:34px;height:34px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;border:2px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.25)"><?= strtoupper(mb_substr($seller['username'] ?? 'S', 0, 1)) ?></div>`,
      iconSize: [34, 34], iconAnchor: [17, 17]
    });
    L.marker([lat, lng], { icon: sellerIcon }).addTo(map)
      .bindPopup(`<strong><?= h(addslashes($seller['username'] ?? 'Seller')) ?></strong><br/><?= h(addslashes($mapAddress)) ?>`);

    <?php if ($currentUserID && !$isOwner && $hasCoords): ?>
    // Show distance from buyer if they also have coords (fetched from their session)
    // You can expand this if Users.coordinates is stored for buyers too
    <?php endif; ?>
  }

  async function startConversation(sellerID) {
    try {
      const fd = new FormData();
      fd.append('otherID', sellerID);

      const res  = await fetch('../api/conversation-init.php', { method: 'POST', body: fd });
      const data = await res.json();

      if (data.success) {
        window.location.href = `../pages/messages.php?open=${encodeURIComponent(data.conversationID)}`;
      } else {
        alert(data.error || 'Could not open conversation.');
      }
    } catch (e) {
      alert('Network error — please try again.');
    }
  }
  // ── Auto-open conversation from ?open= param ──────────────────────────────────
  (async function autoOpen() {
    const params = new URLSearchParams(location.search);
    const cid    = params.get('open');
    if (!cid) return;

    // Wait for sidebar to load, then find the conversation
    await loadSidebar();

    // Fetch conversations to get the other user's info
    try {
      const data = await apiFetch('messages.php?action=convos');
      if (!data.success) return;
      const match = data.conversations.find(c => c.conversationID === cid);
      if (match) {
        openConversation(match.conversationID, match.otherUserID, match.otherUsername);
      }
    } catch(e) { console.error(e); }
  })();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>