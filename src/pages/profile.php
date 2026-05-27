<?php
require_once __DIR__ . '/../includes/helpers.php';
requireLogin();

$db      = getDB();
$me      = currentUser();
$errors  = [];
$success = '';
$csrf = csrfToken();

/* ── Which profile are we viewing? ── */
$viewID  = isset($_GET['id']) ? (int)$_GET['id'] : $me['userID'];
$isOwner = ($viewID === (int)$me['userID']);

/* ── Load the profile user ── */
$ustmt = $db->prepare('SELECT userID,email,username,role,contact_number,date_joined, coordinates,ST_Y(coordinates) AS lat, ST_X(coordinates) AS lng FROM Users WHERE userID=?');
$ustmt->bind_param('i', $viewID);
$ustmt->execute();
$profile = $ustmt->get_result()->fetch_assoc();
if (!$profile) { http_response_code(404); die('User not found.'); }

/* ── Active tab ── */
$tab = $_GET['tab'] ?? 'deals';   // deals | reviews

/* ── Handle edit form (owner only) ── */
$editMode = ($isOwner && isset($_GET['edit']));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isOwner) {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'profile') {
        $username = trim($_POST['username'] ?? '');
        $address  = trim($_POST['address']  ?? '');
        $contact  = trim($_POST['contact']  ?? '');
        $lat      = isset($_POST['coord_lat']) && $_POST['coord_lat'] !== '' ? (float)$_POST['coord_lat'] : null;
        $lng      = isset($_POST['coord_lng']) && $_POST['coord_lng'] !== '' ? (float)$_POST['coord_lng'] : null;

        if (strlen($username) < 3) $errors[] = 'Username must be at least 3 characters.';

        if (empty($errors)) {
            if ($lat !== null && $lng !== null) {
                // Save address + coordinates POINT
                $upd = $db->prepare('UPDATE Users SET username=?, contact_number=?, coordinates=ST_GeomFromText(?) WHERE userID=?');
                $point = "POINT($lng $lat)"; // WKT: X=lng, Y=lat
                $upd->bind_param('sssi', $username, $contact, $point, $me['userID']);
            } else {
                $upd = $db->prepare('UPDATE Users SET username=?, contact_number=?, coordinates=? WHERE userID=?');
                $upd->bind_param('sssi', $username, $contact, $coordinates, $me['userID']);
            }
            $upd->execute();
            setFlash('success', 'Profile updated.');
            header('Location: ../pages/profile.php'); exit;
        }
    }

    if ($action === 'password') {
        $newP = $_POST['new_password'] ?? '';
        $conf = $_POST['confirm_pass']  ?? '';
        if (strlen($newP) < 8) $errors[] = 'Password must be at least 8 characters.';
        elseif ($newP !== $conf) $errors[] = 'Passwords do not match.';
        if (empty($errors)) {
            $hash = password_hash($newP, PASSWORD_BCRYPT);
            $upd  = $db->prepare('UPDATE Users SET password=? WHERE userID=?');
            $upd->bind_param('si', $hash, $me['userID']);
            $upd->execute();
            setFlash('success', 'Password updated.');
            header('Location: ../pages/profile.php'); exit;
        }
    }

    if ($action === 'switch_role') {
        $newRole = ($profile['role'] === 'buyer') ? 'seller' : 'buyer';
        $upd = $db->prepare('UPDATE Users SET role=? WHERE userID=?');
        $upd->bind_param('si', $newRole, $me['userID']);
        $upd->execute();
        setFlash('info', "Switched to {$newRole} profile.");
        header('Location: ../pages/profile.php'); exit;
    }
}

/* ── Stats: avg rating, review count, listing count ── */
$statsQ = $db->prepare(
    'SELECT
      COALESCE(ROUND(AVG(r.rating),1), 0) AS avg_rating,
      COUNT(DISTINCT r.reviewID)          AS review_count,
      COUNT(DISTINCT i.itemID)            AS listing_count
    FROM Users u
    LEFT JOIN Item    i ON i.sellerID = u.userID AND i.status = "active"
    LEFT JOIN Reviews r ON r.itemID   = i.itemID
    WHERE u.userID = ?'
);
$statsQ->bind_param('i', $viewID);
$statsQ->execute();
$stats = $statsQ->get_result()->fetch_assoc();

/* ── Listings ── */
$sort    = $_GET['sort'] ?? 'newest';
$orderBy = match($sort) {
    'price_asc'  => 'i.price ASC',
    'price_desc' => 'i.price DESC',
    default      => 'i.created_at DESC',
};

$listQ = $db->prepare(
    "SELECT i.itemID, i.title, i.price, i.created_at, i.status,
            (SELECT images FROM Image WHERE itemID=i.itemID LIMIT 1) AS thumb,
            c.category_name
    FROM Item i
    LEFT JOIN Category c ON c.categoryID = i.categoryID
    WHERE i.sellerID = ? AND i.status = 'active'
    ORDER BY {$orderBy} LIMIT 20"
);
$listQ->bind_param('i', $viewID);
$listQ->execute();
$listings = $listQ->get_result()->fetch_all(MYSQLI_ASSOC);

/* ── Reviews of this seller's items ── */
$revQ = $db->prepare(
    'SELECT r.reviewID, r.rating, r.body, r.created_at,
            i.title AS item_title, i.price AS item_price, i.itemID,
            u.username AS reviewer_name, u.userID AS reviewer_id
    FROM Reviews r
    JOIN Item i ON i.itemID = r.itemID
    JOIN Users u ON u.userID = r.userID
    WHERE i.sellerID = ?
    ORDER BY r.created_at DESC LIMIT 30'
);
$revQ->bind_param('i', $viewID);
$revQ->execute();
$reviews = $revQ->get_result()->fetch_all(MYSQLI_ASSOC);

/* ── Pagination ── */
$totalListings = count($listings);
$totalReviews  = count($reviews);

$pageTitle = h($profile['username']) . "'s Profile — Demy's";
require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Profile Layout ─────────────────────────────── */
.prof-wrap { max-width:1060px; margin:0 auto; padding:36px 28px; }

/* Header card */
.prof-header {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  padding: 28px 32px;
  display: flex;
  align-items: flex-start;
  gap: 24px;
  margin-bottom: 24px;
  box-shadow: var(--shadow-sm);
  position: relative;
}

.prof-avatar-wrap { position: relative; flex-shrink: 0; }
.prof-avatar {
  width: 80px; height: 80px; border-radius: 50%;
  background: var(--accent); color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-family: 'Syne',sans-serif; font-weight: 800; font-size: 32px;
  border: 3px solid var(--bg);
  box-shadow: 0 0 0 2px var(--accent);
}
.prof-role-badge {
  position: absolute; bottom: -4px; right: -4px;
  background: var(--surface2); border: 2px solid var(--bg);
  border-radius: 20px; padding: 2px 8px;
  font-size: 10px; font-weight: 700; text-transform: uppercase;
  letter-spacing: 0.06em; color: var(--muted);
}
.prof-role-badge.seller { background: var(--accent-light); color: var(--accent); }

.prof-info { flex: 1; min-width: 0; }
.prof-name-row {
  display: flex; align-items: center; gap: 12px;
  flex-wrap: wrap; margin-bottom: 6px;
}
.prof-username {
  font-family: 'Syne',sans-serif; font-weight: 800; font-size: 24px;
  letter-spacing: -0.6px; color: var(--text);
}
.prof-email { font-size: 13px; color: var(--muted); }

.prof-stats {
  display: flex; align-items: center; gap: 28px;
  margin: 12px 0;
}
.pstat { text-align: center; }
.pstat-val {
  font-family: 'Syne',sans-serif; font-weight: 800; font-size: 22px;
  color: var(--text); line-height: 1;
}
.pstat-val.accent { color: var(--accent); }
.pstat-label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.06em; margin-top: 2px; }
.pstat-divider { width: 1px; height: 36px; background: var(--border); }

.prof-meta { font-size: 13px; color: var(--muted); display: flex; flex-wrap: wrap; gap: 14px; }
.prof-meta-item { display: flex; align-items: center; gap: 5px; }

/* Owner actions */
.prof-actions {
  display: flex; flex-direction: column; gap: 8px;
  flex-shrink: 0;
}
.btn-edit-prof {
  display: flex; align-items: center; gap: 7px;
  padding: 8px 16px; background: var(--surface2);
  border: 1.5px solid var(--border); border-radius: var(--radius);
  font-size: 13px; font-weight: 600; color: var(--text);
  cursor: pointer; transition: background 0.2s, border-color 0.2s;
  font-family: 'DM Sans',sans-serif;
  white-space: nowrap;
}
.btn-edit-prof:hover { background: var(--surface); border-color: var(--border2); }
.btn-switch-role {
  display: flex; align-items: center; gap: 7px;
  padding: 8px 16px; background: var(--accent-light);
  border: 1.5px solid transparent; border-radius: var(--radius);
  font-size: 13px; font-weight: 600; color: var(--accent);
  cursor: pointer; transition: background 0.2s;
  font-family: 'DM Sans',sans-serif;
  white-space: nowrap;
}
.btn-switch-role:hover { background: #fdd9cd; }

/* ── Edit Drawer ─────────────────────────────────── */
.edit-drawer {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  padding: 28px 32px;
  margin-bottom: 24px;
  box-shadow: var(--shadow);
  animation: slideDown 0.25s ease;
}
@keyframes slideDown { from{opacity:0;transform:translateY(-10px)} to{opacity:1;transform:translateY(0)} }
.edit-drawer-title {
  font-family:'Syne',sans-serif; font-weight:700; font-size:18px;
  margin-bottom:24px; display:flex; align-items:center; gap:10px;
}
.edit-drawer-title a { font-size:13px; font-weight:400; color:var(--muted); margin-left:auto; }
.edit-drawer-title a:hover { color:var(--accent); }
.edit-section-label {
  font-size:11px; font-weight:700; text-transform:uppercase;
  letter-spacing:0.08em; color:var(--muted); margin-bottom:16px;
  padding-bottom:8px; border-bottom:1px solid var(--border);
}
.edit-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.edit-field { display:flex; flex-direction:column; gap:5px; margin-bottom:0; }
.edit-label {
  font-size:11px; font-weight:700; text-transform:uppercase;
  letter-spacing:0.07em; color:var(--muted);
}
.edit-input {
  background:var(--bg); border:1.5px solid var(--border);
  border-radius:var(--radius); padding:10px 13px;
  font-size:14px; color:var(--text); outline:none;
  transition:border-color 0.2s; width:100%;
  font-family:'DM Sans',sans-serif;
}
.edit-input:focus { border-color:var(--accent); }
.edit-input::placeholder { color:var(--muted2); }
textarea.edit-input { resize:vertical; min-height:80px; }
.edit-actions { display:flex; gap:10px; margin-top:20px; }
.btn-save {
  padding:10px 24px; background:var(--accent); color:#fff; border:none;
  border-radius:var(--radius); font-size:14px; font-weight:600;
  font-family:'Syne',sans-serif; cursor:pointer; transition:background 0.2s,transform 0.15s;
}
.btn-save:hover { background:var(--accent-h); transform:translateY(-1px); }
.btn-discard {
  padding:10px 20px; background:transparent; color:var(--muted);
  border:1.5px solid var(--border); border-radius:var(--radius);
  font-size:14px; font-weight:500; cursor:pointer; transition:background 0.2s;
  font-family:'DM Sans',sans-serif; text-decoration:none; display:inline-flex;
  align-items:center;
}
.btn-discard:hover { background:var(--surface2); color:var(--text); }

/* ── Tabs ────────────────────────────────────────── */
.prof-tabs {
  display: flex; align-items: center; gap: 0;
  border-bottom: 2px solid var(--border);
  margin-bottom: 24px;
}
.prof-tab {
  padding: 10px 20px; font-size: 14px; font-weight: 600;
  color: var(--muted); cursor: pointer; border: none; background: none;
  font-family: 'DM Sans',sans-serif; border-bottom: 2px solid transparent;
  margin-bottom: -2px; transition: color 0.2s, border-color 0.2s;
  text-decoration: none; display: inline-flex; align-items: center; gap: 7px;
}
.prof-tab:hover { color: var(--text); }
.prof-tab.active { color: var(--accent); border-bottom-color: var(--accent); }
.tab-count {
  background: var(--surface2); color: var(--muted);
  border-radius: 20px; padding: 1px 7px; font-size: 11px; font-weight: 700;
}
.prof-tab.active .tab-count { background: var(--accent-light); color: var(--accent); }

/* Sort bar */
.sort-bar {
  display: flex; align-items: center; gap: 10px;
  margin-bottom: 20px; flex-wrap: wrap;
}
.sort-bar-label { font-size:12px; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:0.06em; }
.sort-btn {
  padding: 5px 13px; border:1.5px solid var(--border); border-radius:20px;
  font-size:13px; font-weight:500; color:var(--text-2);
  background:var(--surface); cursor:pointer;
  transition:background 0.15s,border-color 0.15s,color 0.15s;
  text-decoration:none;
}
.sort-btn:hover { border-color:var(--border2); background:var(--surface2); }
.sort-btn.active { background:var(--accent); border-color:var(--accent); color:#fff; }

/* ── Listings grid ───────────────────────────────── */
.listings-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
}
@media(max-width:900px){ .listings-grid{grid-template-columns:repeat(3,1fr);} }
@media(max-width:640px){ .listings-grid{grid-template-columns:repeat(2,1fr);} }
@media(max-width:380px){ .listings-grid{grid-template-columns:1fr;} }

.listing-card {
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--radius-lg); overflow:hidden;
  transition:transform 0.2s,box-shadow 0.2s;
  cursor:pointer; text-decoration:none; display:block; color:inherit;
}
.listing-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-lg); }
.listing-img {
  aspect-ratio:4/3; background:var(--surface2);
  display:flex; align-items:center; justify-content:center;
  overflow:hidden;
}
.listing-img img { width:100%; height:100%; object-fit:cover; transition:transform 0.3s; }
.listing-card:hover .listing-img img { transform:scale(1.05); }
.listing-img-placeholder {
  color:var(--border2); font-size:32px;
}
.listing-body { padding:12px 14px; }
.listing-title {
  font-size:13px; font-weight:500; color:var(--text);
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
  margin-bottom:4px;
}
.listing-price {
  font-family:'Syne',sans-serif; font-weight:800; font-size:16px;
  color:var(--accent);
}
.listing-date { font-size:11px; color:var(--muted); margin-top:4px; }

/* ── Reviews ─────────────────────────────────────── */
.review-list { display:flex; flex-direction:column; gap:20px; }
.review-card {
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--radius-lg); padding:20px 24px;
}
.review-header { display:flex; align-items:flex-start; gap:12px; margin-bottom:12px; }
.review-avatar {
  width:38px; height:38px; border-radius:50%; flex-shrink:0;
  background:var(--accent-light); color:var(--accent);
  display:flex; align-items:center; justify-content:center;
  font-family:'Syne',sans-serif; font-weight:700; font-size:15px;
}
.review-meta { flex:1; min-width:0; }
.review-reviewer {
  font-weight:600; font-size:14px; color:var(--text); margin-bottom:2px;
}
.review-item-info {
  font-size:12px; color:var(--muted); white-space:nowrap;
  overflow:hidden; text-overflow:ellipsis;
}
.review-right { text-align:right; flex-shrink:0; }
.review-stars { display:flex; justify-content:flex-end; gap:2px; margin-bottom:3px; }
.star { color:var(--border2); font-size:13px; }
.star.on { color:#f59e0b; }
.review-date { font-size:11px; color:var(--muted); }
.review-body { font-size:14px; color:var(--text-2); line-height:1.65; }

.review-comments { margin-top:14px; padding-top:12px; border-top:1px solid var(--border); }
.comment-item { display:flex; gap:10px; margin-bottom:10px; }
.comment-avatar {
  width:28px; height:28px; border-radius:50%; flex-shrink:0;
  background:var(--surface2); color:var(--muted);
  display:flex; align-items:center; justify-content:center;
  font-family:'Syne',sans-serif; font-weight:700; font-size:11px;
}
.comment-bubble {
  background:var(--surface2); border:1px solid var(--border);
  border-radius:var(--radius); padding:8px 12px; flex:1;
  font-size:13px; color:var(--text-2); line-height:1.5;
}
.comment-username { font-weight:600; font-size:12px; color:var(--muted); margin-bottom:3px; }

.show-comments-btn {
  background:none; border:none; cursor:pointer;
  font-size:12px; font-weight:600; color:var(--muted);
  padding:0; margin-top:10px; font-family:'DM Sans',sans-serif;
  transition:color 0.15s;
}
.show-comments-btn:hover { color:var(--accent); }
.comments-section { display:none; }
.comments-section.open { display:block; }

/* pagination */
.prof-pagination { display:flex; gap:6px; align-items:center; justify-content:center; padding:32px 0 8px; font-size:13px; color:var(--muted); }
.prof-pagination strong { color:var(--text); }

/* empty state */
.prof-empty { padding:48px 24px; text-align:center; color:var(--muted); }
.prof-empty-icon { font-size:40px; margin-bottom:12px; }
.prof-empty h3 { font-size:17px; color:var(--text); margin-bottom:6px; }
.prof-empty p { font-size:13px; }

.errors-box {
  background:var(--danger-light); color:var(--danger);
  border:1px solid #f0b8c0; border-radius:var(--radius);
  padding:12px 14px; margin-bottom:20px; font-size:13px;
}

@media(max-width:600px){
  .prof-header{flex-direction:column;}
  .prof-actions{flex-direction:row; flex-wrap:wrap;}
  .edit-grid{grid-template-columns:1fr;}
}

/* ── Map Picker Modal ── */
.map-modal-overlay {
  display: none; position: fixed; inset: 0; z-index: 9999;
  background: rgba(0,0,0,0.55); backdrop-filter: blur(3px);
  align-items: center; justify-content: center;
}
.map-modal-overlay.open { display: flex; }
.map-modal {
  background: var(--surface); border-radius: var(--radius-lg);
  box-shadow: var(--shadow-lg); width: min(680px, 95vw);
  overflow: hidden; display: flex; flex-direction: column;
}
.map-modal-header {
  padding: 16px 20px; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
}
.map-modal-title {
  font-family: 'Syne', sans-serif; font-weight: 700; font-size: 16px;
}
.map-modal-close {
  background: none; border: none; cursor: pointer; color: var(--muted);
  font-size: 20px; line-height: 1; padding: 2px 6px; border-radius: 6px;
  transition: background 0.15s, color 0.15s;
}
.map-modal-close:hover { background: var(--surface2); color: var(--text); }
#map-container { height: 360px; width: 100%; }
.map-modal-footer {
  padding: 14px 20px; border-top: 1px solid var(--border);
  display: flex; align-items: center; gap: 12px;
}
.map-address-preview {
  flex: 1; font-size: 13px; color: var(--muted);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.map-address-preview.resolved { color: var(--text); }
.btn-map-confirm {
  padding: 9px 20px; background: var(--accent); color: #fff;
  border: none; border-radius: var(--radius); font-size: 13px;
  font-weight: 600; font-family: 'Syne', sans-serif;
  cursor: pointer; transition: background 0.2s; white-space: nowrap;
}
.btn-map-confirm:hover { background: var(--accent-h); }
.btn-map-confirm:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-pick-map {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 9px 14px; background: var(--surface2);
  border: 1.5px solid var(--border); border-radius: var(--radius);
  font-size: 13px; font-weight: 600; color: var(--text);
  cursor: pointer; transition: background 0.2s, border-color 0.2s;
  font-family: 'DM Sans', sans-serif; white-space: nowrap;
  margin-top: 6px; width: 100%;
  justify-content: center;
}
.btn-pick-map:hover { background: var(--surface); border-color: var(--accent); color: var(--accent); }
</style>

<div class="prof-wrap">

  <?php if (!empty($errors)): ?>
  <div class="errors-box">
    <?php foreach($errors as $e): ?><div>• <?= h($e) ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ── Profile Header ── -->
  <div class="prof-header">
    <div class="prof-avatar-wrap">
      <div class="prof-avatar"><?= strtoupper(substr($profile['username'],0,1)) ?></div>
      <span class="prof-role-badge <?= $profile['role'] === 'seller' ? 'seller' : '' ?>">
        <?= h($profile['role']) ?>
      </span>
    </div>

    <div class="prof-info">
      <div class="prof-name-row">
        <span class="prof-username"><?= h($profile['username']) ?></span>
        <span class="prof-email"><?= h($profile['email']) ?></span>
      </div>

      <div class="prof-stats">
        <div class="pstat">
          <div class="pstat-val accent"><?= $stats['avg_rating'] > 0 ? $stats['avg_rating'] : '—' ?></div>
          <div class="pstat-label">Rating</div>
        </div>
        <div class="pstat-divider"></div>
        <div class="pstat">
          <div class="pstat-val"><?= number_format($stats['review_count']) ?></div>
          <div class="pstat-label">Reviews</div>
        </div>
        <div class="pstat-divider"></div>
        <div class="pstat">
          <div class="pstat-val"><?= number_format($stats['listing_count']) ?></div>
          <div class="pstat-label">Listings</div>
        </div>
      </div>

      <div class="prof-meta">
        <?php if ($profile['coordinates']): ?>
          <span class="prof-meta-item">
            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
            <?= reverseGeocode($profile['lat'], $profile['lng']) ?>
        </span>
        <?php endif; ?>
        <?php if ($profile['contact_number']): ?>
        <span class="prof-meta-item">
          <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.4 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L7.91 8.8a16 16 0 0 0 7.29 7.29l.95-.95a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
          <?= h($profile['contact_number']) ?>
        </span>
        <?php endif; ?>
        <span class="prof-meta-item">
          <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          Joined <?= date('M Y', strtotime($profile['date_joined'])) ?>
        </span>
      </div>
    </div>

    <?php if ($isOwner): ?>
    <div class="prof-actions">
      <a href="?edit=1" class="btn-edit-prof">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        Edit Profile
      </a>
      <form method="POST" style="margin:0">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"/>
        <input type="hidden" name="action" value="switch_role"/>
        <button type="submit" class="btn-switch-role">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
          Switch to <?= $profile['role'] === 'buyer' ? 'Seller' : 'Buyer' ?>
        </button>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Edit Drawer ── -->
  <?php if ($editMode && $isOwner): ?>
  <div class="edit-drawer">
    <div class="edit-drawer-title">
      Edit Profile
      <a href="profile.php">✕ Discard</a>
    </div>

  <div class="edit-section-label">Profile Info</div>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"/>
    <input type="hidden" name="action" value="profile"/>
    <input type="hidden" name="coord_lat" id="coord-lat" value=""/>
    <input type="hidden" name="coord_lng" id="coord-lng" value=""/>

    <div class="edit-grid">
      <div class="edit-field">
        <label class="edit-label">Username</label>
        <input type="text" name="username" class="edit-input"
              value="<?= h($me['username']) ?>" required/>
      </div>
      <div class="edit-field">
        <label class="edit-label">Email</label>
        <input type="email" class="edit-input" value="<?= h($me['email']) ?>" disabled style="opacity:0.55"/>
      </div>
      <div class="edit-field">
        <label class="edit-label">Address / City</label>
        <input type="text" name="address" id="address-input" class="edit-input" placeholder="e.g. Quezon City"
              value="<?= h($me['label'] ?? '') ?>"/>
        <button type="button" class="btn-pick-map" onclick="openMapPicker()">
          📍 Pick from Map
        </button>
      </div>
      <div class="edit-field">
        <label class="edit-label">Contact Number</label>
        <input type="text" name="contact" class="edit-input" placeholder="+63 9xx xxx xxxx"
              value="<?= h($me['contact_number'] ?? '') ?>"/>
      </div>
    </div>
    <div class="edit-actions">
      <button type="submit" class="btn-save">Save Changes</button>
      <a href="profile.php" class="btn-discard">Discard</a>
    </div>
  </form>

    <div style="margin-top:28px">
      <div class="edit-section-label">Change Password</div>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"/>
        <input type="hidden" name="action" value="password"/>
        <div class="edit-grid">
          <div class="edit-field">
            <label class="edit-label">New Password</label>
            <input type="password" name="new_password" class="edit-input" placeholder="min. 8 chars"/>
          </div>
          <div class="edit-field">
            <label class="edit-label">Confirm Password</label>
            <input type="password" name="confirm_pass" class="edit-input" placeholder="repeat"/>
          </div>
        </div>
        <div class="edit-actions">
          <button type="submit" class="btn-save">Update Password</button>
        </div>
      </form>
    </div>
    <!-- ── Map Picker Modal ── -->
    <div class="map-modal-overlay" id="map-modal-overlay">
      <div class="map-modal">
        <div class="map-modal-header">
          <span class="map-modal-title">📍 Pick Your Location</span>
          <button class="map-modal-close" onclick="closeMapPicker()" title="Close">✕</button>
        </div>
        <div id="map-container"></div>
        <div class="map-modal-footer">
          <span class="map-address-preview" id="map-address-preview">Click on the map to set your location…</span>
          <button class="btn-map-confirm" id="btn-map-confirm" onclick="confirmLocation()" disabled>
            Use This Location
          </button>
        </div>
      </div>
    </div>
    </div>
  <?php endif; ?>

  <!-- ── Tabs ── -->
  <div class="prof-tabs">
    <a class="prof-tab <?= $tab==='deals'?'active':'' ?>"
      href="?tab=deals<?= $isOwner && $editMode ? '&edit=1' : '' ?>">
      Deals
      <span class="tab-count"><?= $stats['listing_count'] ?></span>
    </a>
    <a class="prof-tab <?= $tab==='reviews'?'active':'' ?>"
      href="?tab=reviews<?= $isOwner && $editMode ? '&edit=1' : '' ?>">
      Reviews
      <span class="tab-count"><?= $stats['review_count'] ?></span>
    </a>
  </div>

  <!-- ── Deals Tab ── -->
  <?php if ($tab === 'deals'): ?>

  <div class="sort-bar">
    <span class="sort-bar-label">Sort</span>
    <a href="?tab=deals&sort=newest" class="sort-btn <?= ($sort==='newest')?'active':'' ?>">Newest</a>
    <a href="?tab=deals&sort=price_asc" class="sort-btn <?= ($sort==='price_asc')?'active':'' ?>">Price ↑</a>
    <a href="?tab=deals&sort=price_desc" class="sort-btn <?= ($sort==='price_desc')?'active':'' ?>">Price ↓</a>
  </div>

  <?php if (empty($listings)): ?>
  <div class="prof-empty">
    <div class="prof-empty-icon">📦</div>
    <h3>No active listings</h3>
    <p><?= $isOwner ? 'Start selling something!' : 'This user has no active listings.' ?></p>
    <?php if ($isOwner): ?>
    <a href="sell.php" class="btn-accent" style="margin-top:16px;display:inline-flex">+ Post a Listing</a>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="listings-grid">
    <?php foreach($listings as $item): ?>
    <a href="../pages/item.php?id=<?= $item['itemID'] ?>" class="listing-card">
      <div class="listing-img">
        <?php if ($item['thumb']): ?>
          <img src="<?= h($item['thumb']) ?>" alt="<?= h($item['title']) ?>" loading="lazy"/>
        <?php else: ?>
          <div class="listing-img-placeholder">🖼️</div>
        <?php endif; ?>
      </div>
      <div class="listing-body">
        <div class="listing-title"><?= h($item['title']) ?></div>
        <div class="listing-price"><?= formatPrice((float)$item['price']) ?></div>
        <div class="listing-date"><?= timeAgo($item['created_at']) ?></div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <div class="prof-pagination">
    Showing <strong><?= count($listings) ?></strong> listing<?= count($listings) !== 1 ? 's' : '' ?>
  </div>
  <?php endif; ?>

  <!-- ── Reviews Tab ── -->
  <?php elseif ($tab === 'reviews'): ?>

  <?php if (empty($reviews)): ?>
  <div class="prof-empty">
    <div class="prof-empty-icon">⭐</div>
    <h3>No reviews yet</h3>
    <p>Reviews will appear here after completed transactions.</p>
  </div>
  <?php else: ?>

  <div class="sort-bar">
    <span class="sort-bar-label">Sort</span>
    <a href="?tab=reviews&sort=newest" class="sort-btn active">Newest</a>
  </div>

  <div class="review-list">
    <?php foreach($reviews as $rev): ?>
    <div class="review-card">
      <div class="review-header">
        <div class="review-avatar">
          <?= strtoupper(substr($rev['reviewer_name'],0,1)) ?>
        </div>
        <div class="review-meta">
          <div class="review-reviewer"><?= h($rev['reviewer_name']) ?></div>
          <div class="review-item-info">
            <?= h($rev['item_title']) ?> · <?= formatPrice((float)$rev['item_price']) ?>
          </div>
        </div>
        <div class="review-right">
          <div class="review-stars">
            <?php for($s=1;$s<=5;$s++): ?>
            <span class="star <?= $s<=$rev['rating']?'on':'' ?>">★</span>
            <?php endfor; ?>
          </div>
          <div class="review-date"><?= timeAgo($rev['created_at']) ?></div>
        </div>
      </div>
      <?php if ($rev['body']): ?>
      <div class="review-body"><?= h($rev['body']) ?></div>
      <?php endif; ?>

      <!-- Show N Comments toggle -->
      <button class="show-comments-btn" onclick="
        var c=this.nextElementSibling;
        var open=c.classList.toggle('open');
        this.textContent=open?'Hide comments':'Show comments';
      ">Show comments</button>
      <div class="comments-section review-comments">
        <div class="comment-item">
          <div class="comment-avatar">
            <?= strtoupper(substr($rev['reviewer_name'],0,1)) ?>
          </div>
          <div class="comment-bubble">
            <div class="comment-username"><?= h($rev['reviewer_name']) ?></div>
            <?= h($rev['body'] ?: '(no comment text)') ?>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="prof-pagination">
    <span>Page <strong>1</strong> / <?= ceil(count($reviews)/10) ?></span>
  </div>
  <?php endif; ?>
  <?php endif; ?>

</div>

<script>
  let _map = null, _marker = null, _pickedLat = null, _pickedLng = null, _pickedLabel = '';

  function openMapPicker() {
    document.getElementById('map-modal-overlay').classList.add('open');
    // Init map once
    if (!_map) {
      _map = L.map('map-container').setView([14.5995, 120.9842], 12); // Manila default
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors', maxZoom: 19
      }).addTo(_map);
      _map.on('click', onMapClick);

      // Try to center on existing coords
      const lat = document.getElementById('coord-lat').value;
      const lng = document.getElementById('coord-lng').value;
      if (lat && lng) {
        _map.setView([lat, lng], 15);
        placeMarker(parseFloat(lat), parseFloat(lng));
        fetchAddress(parseFloat(lat), parseFloat(lng));
      } else if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(pos => {
          _map.setView([pos.coords.latitude, pos.coords.longitude], 14);
        });
      }
    }
    // Leaflet needs a size invalidation after modal opens
    setTimeout(() => _map.invalidateSize(), 120);
  }

  function closeMapPicker() {
    document.getElementById('map-modal-overlay').classList.remove('open');
  }

  function onMapClick(e) {
    placeMarker(e.latlng.lat, e.latlng.lng);
    fetchAddress(e.latlng.lat, e.latlng.lng);
  }

  function placeMarker(lat, lng) {
    _pickedLat = lat; _pickedLng = lng;
    if (_marker) {
      _marker.setLatLng([lat, lng]);
    } else {
      _marker = L.marker([lat, lng], { draggable: true }).addTo(_map);
      _marker.on('dragend', e => {
        const pos = e.target.getLatLng();
        _pickedLat = pos.lat; _pickedLng = pos.lng;
        fetchAddress(pos.lat, pos.lng);
      });
    }
  }

  function fetchAddress(lat, lng) {
    const preview = document.getElementById('map-address-preview');
    const btn     = document.getElementById('btn-map-confirm');
    preview.textContent = 'Resolving address…';
    preview.classList.remove('resolved');
    btn.disabled = true;
    _pickedLabel = '';

    fetch(`../api/reverse-geocode.php?lat=${lat}&lng=${lng}`)
      .then(r => r.json())
      .then(data => {
        if (data.label) {
          _pickedLabel = data.label;
          preview.textContent = data.label;
          preview.classList.add('resolved');
          btn.disabled = false;
        } else {
          preview.textContent = 'Could not resolve address. You can still use this location.';
          _pickedLabel = `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
          btn.disabled = false;
        }
      })
      .catch(() => {
        preview.textContent = 'Error fetching address.';
        btn.disabled = true;
      });
  }

  function fetchCurrentAddress(lat, lng) {
    preview.textContent = 'Resolving address…';
    preview.classList.remove('resolved');
    btn.disabled = true;
    _pickedLabel = '';

    fetch(`../api/reverse-geocode.php?lat=${lat}&lng=${lng}`)
      .then(r => r.json())
      .then(data => {
        if (data.label) {
          _pickedLabel = data.label;
          preview.textContent = data.label;
          preview.classList.add('resolved');
          btn.disabled = false;
        } else {
          preview.textContent = 'Could not resolve address. You can still use this location.';
          _pickedLabel = `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
          btn.disabled = false;
        }
      })
      .catch(() => {
        preview.textContent = 'Error fetching address.';
        btn.disabled = true;
      });
  }

  function confirmLocation() {
    if (_pickedLat === null) return;
    document.getElementById('coord-lat').value   = _pickedLat;
    document.getElementById('coord-lng').value   = _pickedLng;
    document.getElementById('address-input').value = _pickedLabel;
    closeMapPicker();
  }

  // Close on overlay click
  document.getElementById('map-modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeMapPicker();
  });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
