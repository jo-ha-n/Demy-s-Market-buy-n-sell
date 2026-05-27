<?php
// ── Standalone Admin Panel — no login required ────────────────────────────────
// Direct DB connection (no helpers.php / session dependency)

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'dummy_demys_db');

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($db->connect_error) {
    die('<p style="font-family:sans-serif;color:red;padding:40px">Database connection failed: ' . $db->connect_error . '</p>');
}
$db->set_charset('utf8mb4');

// ── Handle POST actions ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'update_user') {
        $stmt = $db->prepare("UPDATE Users SET username=?, email=?, role=?, contact_number=? WHERE userID=?");
        $stmt->bind_param('ssssi', $_POST['username'], $_POST['email'], $_POST['role'], $_POST['contact_number'], $_POST['userID']);
        echo json_encode(['ok' => $stmt->execute()]);

    } elseif ($action === 'update_item') {
        $stmt = $db->prepare("UPDATE Item SET title=?, price=?, categoryID=?, status=?, description=? WHERE itemID=?");
        $stmt->bind_param('sdissi', $_POST['title'], $_POST['price'], $_POST['categoryID'], $_POST['status'], $_POST['description'], $_POST['itemID']);
        echo json_encode(['ok' => $stmt->execute()]);

    } elseif ($action === 'update_category') {
        $stmt = $db->prepare("UPDATE Category SET category_name=? WHERE categoryID=?");
        $stmt->bind_param('si', $_POST['category_name'], $_POST['categoryID']);
        echo json_encode(['ok' => $stmt->execute()]);

    } elseif ($action === 'delete_record') {
        $allowed = ['users' => 'Users', 'items' => 'Item', 'reviews' => 'Reviews', 'categories' => 'Category', 'tags' => 'Tag'];
        $keys    = ['users' => 'userID', 'items' => 'itemID', 'reviews' => 'reviewID', 'categories' => 'categoryID', 'tags' => 'tagID'];
        $table   = $_POST['table'] ?? '';
        if (isset($allowed[$table])) {
            $col  = $keys[$table];
            $stmt = $db->prepare("DELETE FROM {$allowed[$table]} WHERE {$col}=?");
            $stmt->bind_param('i', $_POST['id']);
            echo json_encode(['ok' => $stmt->execute()]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Unknown table']);
        }
    } else {
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    }
    exit;
}

// ── Fetch all data ─────────────────────────────────────────────────────────────
$users        = $db->query("SELECT userID, email, username, role, contact_number, date_joined FROM Users ORDER BY userID")->fetch_all(MYSQLI_ASSOC);
$items        = $db->query("SELECT i.*, c.category_name, u.username AS seller_name FROM Item i JOIN Category c ON c.categoryID = i.categoryID JOIN Users u ON u.userID = i.sellerID ORDER BY i.itemID")->fetch_all(MYSQLI_ASSOC);
$reviews      = $db->query("SELECT r.*, u.username FROM Reviews r JOIN Users u ON u.userID = r.userID ORDER BY r.reviewID")->fetch_all(MYSQLI_ASSOC);
$transactions = $db->query("SELECT t.*, i.title AS item_title, s.username AS seller_name, b.username AS buyer_name FROM Transaction t JOIN Item i ON i.itemID = t.itemID JOIN Users s ON s.userID = t.sellerID JOIN Users b ON b.userID = t.buyerID ORDER BY t.transactionID")->fetch_all(MYSQLI_ASSOC);
$categories   = $db->query("SELECT * FROM Category ORDER BY categoryID")->fetch_all(MYSQLI_ASSOC);
$tags         = $db->query("SELECT * FROM Tag ORDER BY tagID")->fetch_all(MYSQLI_ASSOC);

// Admin comment count
$adminIDs = array_column(array_filter($users, fn($u) => $u['role'] === 'admin'), 'userID');
$adminCommentCount = count(array_filter($reviews, fn($r) => in_array($r['userID'], $adminIDs)));

// Pass to JS
$jsUsers        = json_encode($users,        JSON_HEX_TAG);
$jsItems        = json_encode($items,        JSON_HEX_TAG);
$jsReviews      = json_encode($reviews,      JSON_HEX_TAG);
$jsTransactions = json_encode($transactions, JSON_HEX_TAG);
$jsCategories   = json_encode($categories,   JSON_HEX_TAG);
$jsTags         = json_encode($tags,         JSON_HEX_TAG);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin — Demy's</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,300&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../assets/css/main.css"/>
  <link rel="stylesheet" href="../assets/css/admin.css"/>
  <script>
    (function(){
      try {
        var t = localStorage.getItem('demys-theme');
        if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t);
      } catch(e) {}
    })();
  </script>
</head>
<body>

<!-- TOPBAR -->
<header class="topbar">
  <a href="#" class="topbar-logo">Demy's</a>
  <span class="topbar-title">Admin Panel</span>
  <div class="topbar-spacer"></div>
  <div class="admin-badge-count">
    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
    </svg>
    Admin reviews: <strong><?= $adminCommentCount ?></strong>
  </div>
  <button class="theme-toggle" onclick="toggleTheme()" title="Toggle theme">
    <svg class="icon-sun" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
      <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
      <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
    </svg>
    <svg class="icon-moon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
    </svg>
  </button>
</header>

<div class="admin-shell">

  <!-- SIDEBAR -->
  <aside class="admin-sidebar">
    <div class="sidebar-section-label">Overview</div>
    <div class="sidebar-stat-box">
      <div class="sidebar-stat-row">Users        <strong><?= count($users) ?></strong></div>
      <div class="sidebar-stat-row">Deals        <strong><?= count($items) ?></strong></div>
      <div class="sidebar-stat-row">Reviews      <strong><?= count($reviews) ?></strong></div>
      <div class="sidebar-stat-row">Transactions <strong><?= count($transactions) ?></strong></div>
    </div>
    <div class="sidebar-divider"></div>
    <div class="sidebar-section-label">Browse</div>
    <a class="sidebar-link active" data-type="users" href="#" onclick="setSidebarTab('users',this);return false">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
      </svg>
      Users
    </a>
    <a class="sidebar-link" data-type="deals" href="#" onclick="setSidebarTab('deals',this);return false">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
        <line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>
      </svg>
      Deals
    </a>
    <a class="sidebar-link" data-type="reviews" href="#" onclick="setSidebarTab('reviews',this);return false">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
      </svg>
      Reviews
    </a>
    <a class="sidebar-link" data-type="transactions" href="#" onclick="setSidebarTab('transactions',this);return false">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
        <line x1="1" y1="10" x2="23" y2="10"/>
      </svg>
      Transactions
    </a>
    <div class="sidebar-divider"></div>
    <div class="sidebar-section-label">Manage</div>
    <a class="sidebar-link" data-type="categories" href="#" onclick="setSidebarTab('categories',this);return false">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
        <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
      </svg>
      Categories
    </a>
    <a class="sidebar-link" data-type="tags" href="#" onclick="setSidebarTab('tags',this);return false">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/>
        <line x1="7" y1="7" x2="7.01" y2="7"/>
      </svg>
      Tags
    </a>
  </aside>

  <!-- MAIN -->
  <div class="admin-main">
    <div class="panel-left">
      <div class="panel-left-head">
        <div class="panel-left-title">
          <svg id="panelIcon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
          </svg>
          <span id="panelTitle">Users</span>
        </div>
        <div class="search-box">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
          </svg>
          <input type="text" id="searchInput" placeholder="Search…" oninput="applySearch()"/>
        </div>
        <div class="filter-tags" id="filterTags"></div>
      </div>
      <div class="results-count" id="resultsCount">Loading…</div>
      <div class="panel-left-results" id="resultsList"></div>
    </div>

    <div class="panel-right" id="panelRight">
      <div class="panel-right-empty" id="rightEmpty">
        <div class="panel-right-empty-icon">👆</div>
        <div>Select an item from the list<br>to view details</div>
      </div>
      <div id="rightContent" style="display:none;flex-direction:column;flex:1"></div>
    </div>
  </div>
</div>

<div class="flash-bar" id="flashBar"></div>

<div class="confirm-overlay" id="confirmOverlay">
  <div class="confirm-dialog">
    <div class="confirm-title" id="confirmTitle">Are you sure?</div>
    <div class="confirm-msg"   id="confirmMsg">This action cannot be undone.</div>
    <div class="confirm-btns">
      <button class="btn-cancel" onclick="closeConfirm()">Cancel</button>
      <button class="btn-sm btn-danger" id="confirmOk" onclick="doConfirm()">Delete</button>
    </div>
  </div>
</div>

<script>
// ── Data ──────────────────────────────────────────────────────────────────────
const DB = {
  users:        <?= $jsUsers ?>,
  items:        <?= $jsItems ?>,
  reviews:      <?= $jsReviews ?>,
  transactions: <?= $jsTransactions ?>,
  categories:   <?= $jsCategories ?>,
  tags:         <?= $jsTags ?>,
};

// ── Helpers ───────────────────────────────────────────────────────────────────
function catName(id)   { return (DB.categories.find(c=>c.categoryID==id)||{}).category_name||'—'; }
function userName(id)  { return (DB.users.find(u=>u.userID==id)||{}).username||'Unknown'; }
function itemTitle(id) { return (DB.items.find(i=>i.itemID==id)||{}).title||'Unknown'; }
function priceF(p)     { return '₱'+Number(p).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function timeAgo(d) {
  if (!d) return '—';
  const diff=(Date.now()-new Date(d).getTime())/1000;
  if(diff<60)     return 'just now';
  if(diff<3600)   return Math.floor(diff/60)+'m ago';
  if(diff<86400)  return Math.floor(diff/3600)+'h ago';
  if(diff<604800) return Math.floor(diff/86400)+'d ago';
  return new Date(d).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
}
function starsText(r) { return '★'.repeat(r)+'☆'.repeat(5-r); }
function statusBadgeClass(s) {
  if(s==='available') return 'badge-active';
  if(s==='sold')      return 'badge-sold';
  if(s==='archived')  return 'badge-archived';
  return 'badge-pending';
}
const adminIDs = DB.users.filter(u=>u.role==='admin').map(u=>parseInt(u.userID));

// ── State ─────────────────────────────────────────────────────────────────────
let currentType     = 'users';
let activeFilter    = 'all';
let searchQ         = '';
let selectedItem    = null;
let confirmCallback = null;

// ── Theme ─────────────────────────────────────────────────────────────────────
function toggleTheme() {
  const cur = document.documentElement.getAttribute('data-theme');
  const nxt = cur === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', nxt);
  try { localStorage.setItem('demys-theme', nxt); } catch(e) {}
}

// ── Sidebar ───────────────────────────────────────────────────────────────────
function setSidebarTab(type, el) {
  currentType  = type;
  activeFilter = 'all';
  searchQ      = '';
  document.getElementById('searchInput').value = '';
  document.querySelectorAll('.sidebar-link').forEach(a=>a.classList.remove('active'));
  el.classList.add('active');
  renderFilterTags();
  renderResults();
  clearDetail();
}

function setSidebarTabAndFilter(type) {
  currentType=type; activeFilter='all'; searchQ='';
  document.getElementById('searchInput').value='';
  document.querySelectorAll('.sidebar-link').forEach(a=>a.classList.remove('active'));
  document.querySelector(`[data-type="${type}"]`)?.classList.add('active');
  renderFilterTags(); renderResults();
}

// ── Filters ───────────────────────────────────────────────────────────────────
const FILTERS = {
  users:        [{id:'all',label:'All'},{id:'user',label:'Users'},{id:'admin',label:'Admins'}],
  deals:        [{id:'all',label:'All'},{id:'available',label:'Available'},{id:'sold',label:'Sold'},{id:'archived',label:'Archived'},{id:'pending',label:'Pending'}],
  reviews:      [{id:'all',label:'All'},{id:'5',label:'★5'},{id:'4',label:'★4'},{id:'3',label:'★3'},{id:'2',label:'★2'},{id:'1',label:'★1'}],
  transactions: [{id:'all',label:'All'},{id:'completed',label:'Completed'},{id:'pending',label:'Pending'},{id:'cancelled',label:'Cancelled'}],
  categories:   [{id:'all',label:'All'}],
  tags:         [{id:'all',label:'All'}],
};
const TAG_CLASSES = { deals:'tag-deals', reviews:'tag-reviews', transactions:'tag-transactions' };

function renderFilterTags() {
  const container = document.getElementById('filterTags');
  const filters   = FILTERS[currentType] || [{id:'all',label:'All'}];
  container.innerHTML = filters.map(f=>`
    <button class="filter-tag ${TAG_CLASSES[currentType]||''} ${f.id===activeFilter?'active':''}"
      onclick="setFilter('${f.id}')">${f.label}</button>
  `).join('');
  const titles = {users:'Users',deals:'Deals / Items',reviews:'Reviews',transactions:'Transactions',categories:'Categories',tags:'Tags'};
  document.getElementById('panelTitle').textContent = titles[currentType]||currentType;
}

function setFilter(id) { activeFilter=id; renderFilterTags(); renderResults(); }
function applySearch() { searchQ=document.getElementById('searchInput').value.toLowerCase().trim(); renderResults(); }

// ── Render list ───────────────────────────────────────────────────────────────
function renderResults() {
  const list    = document.getElementById('resultsList');
  const countEl = document.getElementById('resultsCount');
  let items     = [];

  if (currentType === 'users') {
    items = DB.users.filter(u=>{
      if (activeFilter!=='all' && u.role!==activeFilter) return false;
      if (searchQ && !u.username.toLowerCase().includes(searchQ) && !u.email.toLowerCase().includes(searchQ)) return false;
      return true;
    });
    countEl.textContent = `${items.length} user${items.length!==1?'s':''} found`;
    list.innerHTML = items.map(u=>`
      <div class="result-item ${selectedItem?.type==='user'&&selectedItem?.data?.userID==u.userID?'active':''}"
           onclick="selectUser(${u.userID})">
        <div class="result-avatar">${u.username[0].toUpperCase()}</div>
        <div class="result-info">
          <div class="result-name">@${u.username}</div>
          <div class="result-sub">${u.email}</div>
          <div class="result-meta">
            <span class="badge ${u.role==='admin'?'badge-admin':'badge-user'}">${u.role}</span>
            <span class="badge">${DB.items.filter(i=>i.sellerID==u.userID).length} listings</span>
          </div>
        </div>
        <div class="result-right">${timeAgo(u.date_joined)}</div>
      </div>`).join('') || emptyState('No users match your search.');

  } else if (currentType === 'deals') {
    items = DB.items.filter(i=>{
      if (activeFilter!=='all' && i.status!==activeFilter) return false;
      if (searchQ && !i.title.toLowerCase().includes(searchQ) && !(i.category_name||'').toLowerCase().includes(searchQ)) return false;
      return true;
    });
    countEl.textContent = `${items.length} deal${items.length!==1?'s':''} found`;
    list.innerHTML = items.map(i=>`
      <div class="result-item ${selectedItem?.type==='deal'&&selectedItem?.data?.itemID==i.itemID?'active':''}"
           onclick="selectDeal(${i.itemID})">
        <div class="result-avatar item-av">📦</div>
        <div class="result-info">
          <div class="result-name">${i.title}</div>
          <div class="result-sub">by @${i.seller_name} · ${i.category_name}</div>
          <div class="result-meta">
            <span class="badge ${statusBadgeClass(i.status)}">${i.status}</span>
            <span class="badge badge-user">${priceF(i.price)}</span>
          </div>
        </div>
        <div class="result-right">${timeAgo(i.created_at)}</div>
      </div>`).join('') || emptyState('No deals match.');

  } else if (currentType === 'reviews') {
    items = DB.reviews.filter(r=>{
      if (activeFilter!=='all' && String(r.rating)!==activeFilter) return false;
      if (searchQ && !(r.body||'').toLowerCase().includes(searchQ) && !userName(r.userID).toLowerCase().includes(searchQ)) return false;
      return true;
    });
    countEl.textContent = `${items.length} review${items.length!==1?'s':''} found`;
    list.innerHTML = items.map(r=>`
      <div class="result-item ${selectedItem?.type==='review'&&selectedItem?.data?.reviewID==r.reviewID?'active':''}"
           onclick="selectReview(${r.reviewID})">
        <div class="result-avatar review-av">★</div>
        <div class="result-info">
          <div class="result-name">${starsText(r.rating)} by @${r.username||userName(r.userID)}</div>
          <div class="result-sub">on "${itemTitle(r.itemID)}"</div>
          <div class="result-meta">
            <span class="badge badge-review">Rating ${r.rating}/5</span>
            ${adminIDs.includes(parseInt(r.userID))?'<span class="badge badge-admin">admin</span>':''}
          </div>
        </div>
        <div class="result-right">${timeAgo(r.created_at)}</div>
      </div>`).join('') || emptyState('No reviews match.');

  } else if (currentType === 'transactions') {
    items = DB.transactions.filter(t=>{
      if (activeFilter!=='all' && t.payment_status!==activeFilter) return false;
      if (searchQ && !t.item_title.toLowerCase().includes(searchQ) && !t.seller_name.toLowerCase().includes(searchQ) && !t.buyer_name.toLowerCase().includes(searchQ)) return false;
      return true;
    });
    countEl.textContent = `${items.length} transaction${items.length!==1?'s':''} found`;
    list.innerHTML = items.map(t=>`
      <div class="result-item ${selectedItem?.type==='txn'&&selectedItem?.data?.transactionID==t.transactionID?'active':''}"
           onclick="selectTxn(${t.transactionID})">
        <div class="result-avatar txn-av">💳</div>
        <div class="result-info">
          <div class="result-name">${t.item_title}</div>
          <div class="result-sub">@${t.seller_name} → @${t.buyer_name}</div>
          <div class="result-meta">
            <span class="badge ${t.payment_status==='completed'?'badge-sold':t.payment_status==='cancelled'?'badge-archived':'badge-pending'}">${t.payment_status}</span>
            <span class="badge badge-user">${priceF(t.price)}</span>
          </div>
        </div>
        <div class="result-right">${timeAgo(t.created_at)}</div>
      </div>`).join('') || emptyState('No transactions match.');

  } else if (currentType === 'categories') {
    items = DB.categories.filter(c=>!searchQ||c.category_name.toLowerCase().includes(searchQ));
    countEl.textContent = `${items.length} categor${items.length!==1?'ies':'y'}`;
    list.innerHTML = items.map(c=>`
      <div class="result-item ${selectedItem?.type==='cat'&&selectedItem?.data?.categoryID==c.categoryID?'active':''}"
           onclick="selectCat(${c.categoryID})">
        <div class="result-avatar item-av">🏷️</div>
        <div class="result-info">
          <div class="result-name">${c.category_name}</div>
          <div class="result-sub">${DB.items.filter(i=>i.categoryID==c.categoryID).length} deals</div>
        </div>
      </div>`).join('') || emptyState('No categories.');

  } else if (currentType === 'tags') {
    items = DB.tags.filter(t=>!searchQ||t.name.toLowerCase().includes(searchQ));
    countEl.textContent = `${items.length} tag${items.length!==1?'s':''}`;
    list.innerHTML = items.map(t=>`
      <div class="result-item ${selectedItem?.type==='tag'&&selectedItem?.data?.tagID==t.tagID?'active':''}"
           onclick="selectTag(${t.tagID})">
        <div class="result-avatar item-av" style="border-radius:6px;font-size:14px">#</div>
        <div class="result-info"><div class="result-name">#${t.name}</div></div>
      </div>`).join('') || emptyState('No tags.');
  }
}

function emptyState(msg) {
  return `<div class="results-empty"><div class="results-empty-icon">🔍</div><div>${msg}</div></div>`;
}

// ── Detail panel ──────────────────────────────────────────────────────────────
function clearDetail() {
  selectedItem = null;
  document.getElementById('rightEmpty').style.display   = 'flex';
  document.getElementById('rightContent').style.display = 'none';
  document.getElementById('rightContent').innerHTML     = '';
}
function showDetail(html) {
  document.getElementById('rightEmpty').style.display = 'none';
  const rc = document.getElementById('rightContent');
  rc.style.display = 'flex';
  rc.innerHTML = html;
}

// ── Select: User ──────────────────────────────────────────────────────────────
function selectUser(id) {
  const u = DB.users.find(x=>x.userID==id);
  if(!u) return;
  selectedItem = {type:'user', data:u};
  renderResults();
  const listings = DB.items.filter(i=>i.sellerID==id);
  const reviews  = DB.reviews.filter(r=>r.userID==id);
  const txns     = DB.transactions.filter(t=>t.sellerID==id||t.buyerID==id);

  showDetail(`
    <div class="detail-topbar">
      <div class="detail-breadcrumb">
        <span>Users</span><span class="breadcrumb-sep">›</span>
        <span class="breadcrumb-current">@${u.username}</span>
      </div>
      <div class="detail-actions-bar">
        <button class="btn-sm btn-edit" onclick="toggleEdit('userEdit-${u.userID}','userRead-${u.userID}')">✏️ Edit</button>
        <button class="btn-sm btn-danger" onclick="confirmDelete('Delete @${u.username}?','This removes the user and all their data.',()=>deleteRecord('users','userID',${u.userID}))">🗑 Delete</button>
      </div>
    </div>
    <div class="detail-body">
      <div>
        <div class="kv-label" style="margin-bottom:8px">Relationships</div>
        <div class="rel-tree">
          <div class="rel-node root">👤 @${u.username}</div>
          <div class="rel-arrow">→</div>
          <div class="rel-node" onclick="setSidebarTabAndFilter('deals')">📦 ${listings.length} Deal${listings.length!==1?'s':''}</div>
          <div class="rel-arrow">→</div>
          <div class="rel-node" onclick="setSidebarTabAndFilter('reviews')">★ ${reviews.length} Review${reviews.length!==1?'s':''}</div>
          <div class="rel-arrow">→</div>
          <div class="rel-node" onclick="setSidebarTabAndFilter('transactions')">💳 ${txns.length} Txn${txns.length!==1?'s':''}</div>
        </div>
      </div>
      <div class="info-card">
        <div class="info-card-header">
          <div class="info-card-title">
            <div style="width:28px;height:28px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:700;font-size:12px">${u.username[0].toUpperCase()}</div>
            User Info
          </div>
          <span class="badge ${u.role==='admin'?'badge-admin':'badge-user'}">${u.role}</span>
        </div>
        <div class="info-card-body">
          <div id="userRead-${u.userID}">
            <div class="kv-grid">
              <div class="kv-item"><div class="kv-label">Username</div><div class="kv-value">@${u.username}</div></div>
              <div class="kv-item"><div class="kv-label">Email</div><div class="kv-value">${u.email}</div></div>
              <div class="kv-item"><div class="kv-label">Role</div><div class="kv-value">${u.role}</div></div>
              <div class="kv-item"><div class="kv-label">Contact</div><div class="kv-value ${!u.contact_number?'muted':''}">${u.contact_number||'—'}</div></div>
              <div class="kv-item"><div class="kv-label">Joined</div><div class="kv-value muted">${u.date_joined||'—'}</div></div>
            </div>
          </div>
          <div class="inline-edit" id="userEdit-${u.userID}">
            <div class="edit-grid">
              <div class="edit-field"><label class="edit-label">Username</label><input class="edit-input" id="eu-un-${u.userID}" value="${u.username}"/></div>
              <div class="edit-field"><label class="edit-label">Email</label><input class="edit-input" id="eu-em-${u.userID}" value="${u.email}"/></div>
              <div class="edit-field"><label class="edit-label">Role</label>
                <select class="edit-input" id="eu-ro-${u.userID}">
                  <option ${u.role==='user'?'selected':''}>user</option>
                  <option ${u.role==='admin'?'selected':''}>admin</option>
                </select>
              </div>
              <div class="edit-field"><label class="edit-label">Contact</label><input class="edit-input" id="eu-co-${u.userID}" value="${u.contact_number||''}"/></div>
            </div>
            <div class="edit-actions">
              <button class="btn-save" onclick="saveUser(${u.userID})">Save Changes</button>
              <button class="btn-cancel" onclick="toggleEdit('userEdit-${u.userID}','userRead-${u.userID}')">Discard</button>
            </div>
          </div>
        </div>
      </div>
      ${listings.length ? `
      <div class="info-card">
        <div class="info-card-header"><div class="info-card-title">📦 Listings (${listings.length})</div></div>
        <div class="sub-list">
          ${listings.map(i=>`<div class="sub-list-item" onclick="selectDeal(${i.itemID});setSidebarTabAndFilter('deals')">
            <div class="sub-list-avatar">📦</div>
            <div class="sub-list-info"><div class="sub-list-name">${i.title}</div><div class="sub-list-sub">${i.category_name} · ${priceF(i.price)}</div></div>
            <div class="sub-list-right"><span class="badge ${statusBadgeClass(i.status)}">${i.status}</span></div>
          </div>`).join('')}
        </div>
      </div>` : ''}
      ${reviews.length ? `
      <div class="info-card">
        <div class="info-card-header"><div class="info-card-title">★ Reviews left (${reviews.length})</div></div>
        <div class="sub-list">
          ${reviews.map(r=>`<div class="sub-list-item" onclick="selectReview(${r.reviewID});setSidebarTabAndFilter('reviews')">
            <div class="sub-list-avatar" style="background:var(--warning-light);color:var(--warning)">★</div>
            <div class="sub-list-info">
              <div class="sub-list-name">${starsText(r.rating)} on "${itemTitle(r.itemID)}"</div>
              <div class="sub-list-sub">"${(r.body||'').slice(0,60)}${(r.body||'').length>60?'…':''}"</div>
            </div>
            <div class="sub-list-right">${timeAgo(r.created_at)}</div>
          </div>`).join('')}
        </div>
      </div>` : ''}
    </div>
  `);
}

function saveUser(id) {
  const u = DB.users.find(x=>x.userID==id);
  if(!u) return;
  u.username       = document.getElementById(`eu-un-${id}`).value.trim();
  u.email          = document.getElementById(`eu-em-${id}`).value.trim();
  u.role           = document.getElementById(`eu-ro-${id}`).value;
  u.contact_number = document.getElementById(`eu-co-${id}`).value.trim();
  apiPost('update_user', {userID:id, username:u.username, email:u.email, role:u.role, contact_number:u.contact_number});
  flash('User saved!');
  selectUser(id); renderResults();
}

// ── Select: Deal ──────────────────────────────────────────────────────────────
function selectDeal(id) {
  const item = DB.items.find(x=>x.itemID==id);
  if(!item) return;
  selectedItem = {type:'deal', data:item};
  renderResults();
  const reviews = DB.reviews.filter(r=>r.itemID==id);

  showDetail(`
    <div class="detail-topbar">
      <div class="detail-breadcrumb">
        <span class="breadcrumb-link" onclick="selectUser(${item.sellerID});setSidebarTabAndFilter('users')">@${item.seller_name}</span>
        <span class="breadcrumb-sep">›</span><span>Deals</span>
        <span class="breadcrumb-sep">›</span>
        <span class="breadcrumb-current">${item.title}</span>
      </div>
      <div class="detail-actions-bar">
        <button class="btn-sm btn-edit" onclick="toggleEdit('dealEdit-${item.itemID}','dealRead-${item.itemID}')">✏️ Edit</button>
        <button class="btn-sm btn-danger" onclick="confirmDelete('Delete &quot;${item.title}&quot;?','This removes the deal and its reviews.',()=>deleteRecord('items','itemID',${item.itemID}))">🗑 Delete</button>
      </div>
    </div>
    <div class="detail-body">
      <div>
        <div class="kv-label" style="margin-bottom:8px">Relationship</div>
        <div class="rel-tree">
          <div class="rel-node" onclick="selectUser(${item.sellerID});setSidebarTabAndFilter('users')">👤 @${item.seller_name}</div>
          <div class="rel-arrow">→</div>
          <div class="rel-node root">📦 ${item.title}</div>
          <div class="rel-arrow">→</div>
          <div class="rel-node" onclick="setSidebarTabAndFilter('reviews')">★ ${reviews.length} Review${reviews.length!==1?'s':''}</div>
        </div>
      </div>
      <div class="info-card">
        <div class="info-card-header">
          <div class="info-card-title">📦 Deal Info</div>
          <span class="badge ${statusBadgeClass(item.status)}">${item.status}</span>
        </div>
        <div class="info-card-body">
          <div id="dealRead-${item.itemID}">
            <div class="kv-grid">
              <div class="kv-item" style="grid-column:1/-1"><div class="kv-label">Title</div><div class="kv-value">${item.title}</div></div>
              <div class="kv-item"><div class="kv-label">Price</div><div class="kv-value accent">${priceF(item.price)}</div></div>
              <div class="kv-item"><div class="kv-label">Category</div><div class="kv-value">${item.category_name}</div></div>
              <div class="kv-item"><div class="kv-label">Status</div><div class="kv-value">${item.status}</div></div>
              <div class="kv-item"><div class="kv-label">Seller</div><div class="kv-value">@${item.seller_name}</div></div>
              <div class="kv-item" style="grid-column:1/-1"><div class="kv-label">Description</div><div class="kv-value" style="font-weight:400;font-size:13px;line-height:1.6">${item.description||'—'}</div></div>
              <div class="kv-item"><div class="kv-label">Posted</div><div class="kv-value muted">${timeAgo(item.created_at)}</div></div>
            </div>
          </div>
          <div class="inline-edit" id="dealEdit-${item.itemID}">
            <div class="edit-grid">
              <div class="edit-field" style="grid-column:1/-1"><label class="edit-label">Title</label><input class="edit-input" id="ed-ti-${item.itemID}" value="${item.title}"/></div>
              <div class="edit-field"><label class="edit-label">Price (₱)</label><input class="edit-input" type="number" id="ed-pr-${item.itemID}" value="${item.price}"/></div>
              <div class="edit-field"><label class="edit-label">Category</label>
                <select class="edit-input" id="ed-ca-${item.itemID}">
                  ${DB.categories.map(c=>`<option value="${c.categoryID}" ${item.categoryID==c.categoryID?'selected':''}>${c.category_name}</option>`).join('')}
                </select>
              </div>
              <div class="edit-field"><label class="edit-label">Status</label>
                <select class="edit-input" id="ed-st-${item.itemID}">
                  ${['available','pending','sold','archived'].map(s=>`<option ${item.status===s?'selected':''}>${s}</option>`).join('')}
                </select>
              </div>
              <div class="edit-field" style="grid-column:1/-1"><label class="edit-label">Description</label><textarea class="edit-input" rows="3" id="ed-de-${item.itemID}">${item.description||''}</textarea></div>
            </div>
            <div class="edit-actions">
              <button class="btn-save" onclick="saveDeal(${item.itemID})">Save Changes</button>
              <button class="btn-cancel" onclick="toggleEdit('dealEdit-${item.itemID}','dealRead-${item.itemID}')">Discard</button>
            </div>
          </div>
        </div>
      </div>
      ${reviews.length ? `
      <div class="info-card">
        <div class="info-card-header"><div class="info-card-title">★ Reviews (${reviews.length})</div></div>
        <div class="sub-list">
          ${reviews.map(r=>`<div class="sub-list-item" onclick="selectReview(${r.reviewID});setSidebarTabAndFilter('reviews')">
            <div class="sub-list-avatar" style="background:var(--warning-light);color:var(--warning)">★</div>
            <div class="sub-list-info">
              <div class="sub-list-name">${starsText(r.rating)} — @${r.username||userName(r.userID)}</div>
              <div class="sub-list-sub">"${(r.body||'').slice(0,70)}${(r.body||'').length>70?'…':''}"</div>
            </div>
            <div class="sub-list-right">${timeAgo(r.created_at)}</div>
          </div>`).join('')}
        </div>
      </div>` : ''}
    </div>
  `);
}

function saveDeal(id) {
  const item = DB.items.find(x=>x.itemID==id);
  if(!item) return;
  item.title       = document.getElementById(`ed-ti-${id}`).value.trim();
  item.price       = parseFloat(document.getElementById(`ed-pr-${id}`).value);
  item.categoryID  = parseInt(document.getElementById(`ed-ca-${id}`).value);
  item.category_name = catName(item.categoryID);
  item.status      = document.getElementById(`ed-st-${id}`).value;
  item.description = document.getElementById(`ed-de-${id}`).value.trim();
  apiPost('update_item', {itemID:id, title:item.title, price:item.price, categoryID:item.categoryID, status:item.status, description:item.description});
  flash('Deal saved!');
  selectDeal(id); renderResults();
}

// ── Select: Review ────────────────────────────────────────────────────────────
function selectReview(id) {
  const r = DB.reviews.find(x=>x.reviewID==id);
  if(!r) return;
  selectedItem = {type:'review', data:r};
  renderResults();
  const deal   = DB.items.find(i=>i.itemID==r.itemID);
  const seller = DB.users.find(u=>u.userID==deal?.sellerID);

  showDetail(`
    <div class="detail-topbar">
      <div class="detail-breadcrumb">
        <span class="breadcrumb-link" onclick="selectDeal(${r.itemID});setSidebarTabAndFilter('deals')">${deal?.title||'Deal'}</span>
        <span class="breadcrumb-sep">›</span><span>Reviews</span>
        <span class="breadcrumb-sep">›</span>
        <span class="breadcrumb-current">by @${r.username||userName(r.userID)}</span>
      </div>
      <div class="detail-actions-bar">
        <button class="btn-sm btn-danger" onclick="confirmDelete('Delete this review?','This permanently removes the review.',()=>deleteRecord('reviews','reviewID',${r.reviewID}))">🗑 Delete</button>
      </div>
    </div>
    <div class="detail-body">
      <div>
        <div class="kv-label" style="margin-bottom:8px">Relationship</div>
        <div class="rel-tree">
          <div class="rel-node" onclick="selectUser(${r.userID});setSidebarTabAndFilter('users')">👤 @${r.username||userName(r.userID)}</div>
          <div class="rel-arrow">→</div>
          <div class="rel-node" onclick="selectDeal(${r.itemID});setSidebarTabAndFilter('deals')">📦 ${deal?.title||'Deal'}</div>
          <div class="rel-arrow">→</div>
          <div class="rel-node root">★ Review</div>
        </div>
      </div>
      <div class="info-card">
        <div class="info-card-header">
          <div class="info-card-title">★ Review Detail</div>
          <div style="display:flex;gap:6px;align-items:center">
            ${adminIDs.includes(parseInt(r.userID))?'<span class="badge badge-admin">Admin</span>':''}
            <span class="badge badge-review">Rating ${r.rating}/5</span>
          </div>
        </div>
        <div class="info-card-body">
          <div class="kv-grid">
            <div class="kv-item"><div class="kv-label">Rating</div><div class="kv-value" style="font-size:18px">${starsText(r.rating)}</div></div>
            <div class="kv-item"><div class="kv-label">Posted</div><div class="kv-value muted">${timeAgo(r.created_at)}</div></div>
            <div class="kv-item"><div class="kv-label">Reviewer</div><div class="kv-value">@${r.username||userName(r.userID)}</div></div>
            <div class="kv-item"><div class="kv-label">Seller</div><div class="kv-value">@${seller?.username||'—'}</div></div>
            <div class="kv-item" style="grid-column:1/-1"><div class="kv-label">On deal</div><div class="kv-value">${deal?.title||'—'} · ${priceF(deal?.price||0)}</div></div>
            <div class="kv-item" style="grid-column:1/-1">
              <div class="kv-label">Review text</div>
              <div style="margin-top:6px;background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:12px 14px;font-size:14px;line-height:1.7;color:var(--text-2)">"${r.body||'(no text)'}"</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  `);
}

// ── Select: Transaction ───────────────────────────────────────────────────────
function selectTxn(id) {
  const t = DB.transactions.find(x=>x.transactionID==id);
  if(!t) return;
  selectedItem = {type:'txn', data:t};
  renderResults();

  showDetail(`
    <div class="detail-topbar">
      <div class="detail-breadcrumb">
        <span>Transactions</span>
        <span class="breadcrumb-sep">›</span>
        <span class="breadcrumb-current">#${t.transactionID} — ${t.item_title}</span>
      </div>
    </div>
    <div class="detail-body">
      <div>
        <div class="kv-label" style="margin-bottom:8px">Relationship</div>
        <div class="rel-tree">
          <div class="rel-node" onclick="selectUser(${t.sellerID});setSidebarTabAndFilter('users')">👤 @${t.seller_name} (Seller)</div>
          <div class="rel-arrow">→</div>
          <div class="rel-node root">💳 Transaction #${t.transactionID}</div>
          <div class="rel-arrow">→</div>
          <div class="rel-node" onclick="selectUser(${t.buyerID});setSidebarTabAndFilter('users')">👤 @${t.buyer_name} (Buyer)</div>
        </div>
      </div>
      <div class="info-card">
        <div class="info-card-header">
          <div class="info-card-title">💳 Transaction #${t.transactionID}</div>
          <span class="badge ${t.payment_status==='completed'?'badge-sold':t.payment_status==='cancelled'?'badge-archived':'badge-pending'}">${t.payment_status}</span>
        </div>
        <div class="info-card-body">
          <div class="kv-grid">
            <div class="kv-item" style="grid-column:1/-1"><div class="kv-label">Deal</div><div class="kv-value">${t.item_title}</div></div>
            <div class="kv-item"><div class="kv-label">Amount</div><div class="kv-value accent">${priceF(t.price)}</div></div>
            <div class="kv-item"><div class="kv-label">Payment Status</div><div class="kv-value">${t.payment_status}</div></div>
            <div class="kv-item"><div class="kv-label">Seller</div><div class="kv-value">@${t.seller_name}</div></div>
            <div class="kv-item"><div class="kv-label">Buyer</div><div class="kv-value">@${t.buyer_name}</div></div>
            <div class="kv-item"><div class="kv-label">Seller Agreement</div><div class="kv-value success">${t.seller_agreement}</div></div>
            <div class="kv-item"><div class="kv-label">Buyer Agreement</div><div class="kv-value success">${t.buyer_agreement}</div></div>
            <div class="kv-item"><div class="kv-label">Created</div><div class="kv-value muted">${timeAgo(t.created_at)}</div></div>
          </div>
        </div>
      </div>
    </div>
  `);
}

// ── Select: Category ──────────────────────────────────────────────────────────
function selectCat(id) {
  const c = DB.categories.find(x=>x.categoryID==id);
  if(!c) return;
  selectedItem = {type:'cat', data:c};
  renderResults();
  const deals = DB.items.filter(i=>i.categoryID==id);

  showDetail(`
    <div class="detail-topbar">
      <div class="detail-breadcrumb">
        <span>Categories</span><span class="breadcrumb-sep">›</span>
        <span class="breadcrumb-current">${c.category_name}</span>
      </div>
      <div class="detail-actions-bar">
        <button class="btn-sm btn-edit" onclick="toggleEdit('catEdit-${c.categoryID}','catRead-${c.categoryID}')">✏️ Edit</button>
        <button class="btn-sm btn-danger" onclick="confirmDelete('Delete &quot;${c.category_name}&quot;?','Deals in this category will lose their category.',()=>deleteRecord('categories','categoryID',${c.categoryID}))">🗑 Delete</button>
      </div>
    </div>
    <div class="detail-body">
      <div class="info-card">
        <div class="info-card-header"><div class="info-card-title">🏷️ Category</div></div>
        <div class="info-card-body">
          <div id="catRead-${id}">
            <div class="kv-item"><div class="kv-label">Name</div><div class="kv-value">${c.category_name}</div></div>
            <div class="kv-item" style="margin-top:10px"><div class="kv-label">Total Deals</div><div class="kv-value">${deals.length}</div></div>
          </div>
          <div class="inline-edit" id="catEdit-${id}">
            <div class="edit-field"><label class="edit-label">Category Name</label><input class="edit-input" id="ec-na-${id}" value="${c.category_name}"/></div>
            <div class="edit-actions">
              <button class="btn-save" onclick="saveCat(${id})">Save</button>
              <button class="btn-cancel" onclick="toggleEdit('catEdit-${id}','catRead-${id}')">Discard</button>
            </div>
          </div>
        </div>
      </div>
      ${deals.length ? `
      <div class="info-card">
        <div class="info-card-header"><div class="info-card-title">📦 Deals (${deals.length})</div></div>
        <div class="sub-list">
          ${deals.map(i=>`<div class="sub-list-item" onclick="selectDeal(${i.itemID});setSidebarTabAndFilter('deals')">
            <div class="sub-list-avatar">📦</div>
            <div class="sub-list-info"><div class="sub-list-name">${i.title}</div><div class="sub-list-sub">${priceF(i.price)}</div></div>
            <div class="sub-list-right"><span class="badge ${statusBadgeClass(i.status)}">${i.status}</span></div>
          </div>`).join('')}
        </div>
      </div>` : ''}
    </div>
  `);
}

function saveCat(id) {
  const c = DB.categories.find(x=>x.categoryID==id);
  if(!c) return;
  c.category_name = document.getElementById(`ec-na-${id}`).value.trim();
  apiPost('update_category', {categoryID:id, category_name:c.category_name});
  flash('Category saved!');
  selectCat(id); renderResults();
}

// ── Select: Tag ───────────────────────────────────────────────────────────────
function selectTag(id) {
  const t = DB.tags.find(x=>x.tagID==id);
  if(!t) return;
  selectedItem = {type:'tag', data:t};
  renderResults();

  showDetail(`
    <div class="detail-topbar">
      <div class="detail-breadcrumb">
        <span>Tags</span><span class="breadcrumb-sep">›</span>
        <span class="breadcrumb-current">#${t.name}</span>
      </div>
      <div class="detail-actions-bar">
        <button class="btn-sm btn-danger" onclick="confirmDelete('Delete &quot;#${t.name}&quot;?','This tag will be removed from all items.',()=>deleteRecord('tags','tagID',${t.tagID}))">🗑 Delete</button>
      </div>
    </div>
    <div class="detail-body">
      <div class="info-card">
        <div class="info-card-header"><div class="info-card-title"># Tag</div></div>
        <div class="info-card-body">
          <div class="kv-item"><div class="kv-label">Name</div><div class="kv-value">#${t.name}</div></div>
          <div class="kv-item" style="margin-top:10px"><div class="kv-label">Tag ID</div><div class="kv-value muted">${t.tagID}</div></div>
        </div>
      </div>
    </div>
  `);
}

// ── Inline edit toggle ────────────────────────────────────────────────────────
function toggleEdit(editID, readID) {
  document.getElementById(readID)?.classList.toggle('hidden');
  document.getElementById(editID)?.classList.toggle('open');
}

// ── Delete record ─────────────────────────────────────────────────────────────
function deleteRecord(table, key, id) {
  DB[table] = DB[table].filter(r=>r[key]!=id);
  apiPost('delete_record', {table, key, id});
  clearDetail(); renderResults();
  flash('Deleted successfully.', 'danger');
}

// ── API POST ──────────────────────────────────────────────────────────────────
async function apiPost(action, data) {
  try {
    const fd = new FormData();
    fd.append('action', action);
    for (const [k,v] of Object.entries(data)) fd.append(k, v);
    await fetch(window.location.href, {method:'POST', body:fd});
  } catch(e) { console.warn('API error', e); }
}

// ── Confirm dialog ────────────────────────────────────────────────────────────
function confirmDelete(title, msg, cb) {
  confirmCallback = cb;
  document.getElementById('confirmTitle').textContent = title;
  document.getElementById('confirmMsg').textContent   = msg;
  document.getElementById('confirmOverlay').classList.add('open');
}
function closeConfirm() { document.getElementById('confirmOverlay').classList.remove('open'); }
function doConfirm()    { closeConfirm(); if(confirmCallback) confirmCallback(); }

// ── Flash bar ─────────────────────────────────────────────────────────────────
let flashTimer;
function flash(msg, type='success') {
  const el = document.getElementById('flashBar');
  el.textContent = msg;
  el.style.background = type==='danger' ? 'var(--danger)' : 'var(--success)';
  el.style.display = 'block';
  clearTimeout(flashTimer);
  flashTimer = setTimeout(()=>{ el.style.display='none'; }, 3000);
}

// ── Init ──────────────────────────────────────────────────────────────────────
renderFilterTags();
renderResults();
</script>
</body>
</html>