<?php
require_once __DIR__ . '/../includes/helpers.php';

$db = getDB();
$search   = trim($_GET['q'] ?? '');
$category = max(0, (int)($_GET['category'] ?? 0));
$sort     = $_GET['sort'] ?? 'newest';
$page     = max(1, (int)($_GET['page'] ?? 1));
$limit    = 12;
$offset   = ($page - 1) * $limit;

/* ── Lookup data ── */
$categoryResult = $db->query('SELECT categoryID, category_name FROM Category ORDER BY category_name');
$categories     = $categoryResult ? $categoryResult->fetch_all(MYSQLI_ASSOC) : [];

$tagResult = $db->query('SELECT tagID, name FROM Tag ORDER BY name');
$tags      = $tagResult ? $tagResult->fetch_all(MYSQLI_ASSOC) : [];

$minMax    = $db->query("SELECT MIN(price) AS minp, MAX(price) AS maxp FROM Item WHERE status='available'")->fetch_assoc();
$globalMin = $minMax['minp'] !== null ? (float)$minMax['minp'] : 0.0;
$globalMax = $minMax['maxp'] !== null ? (float)$minMax['maxp'] : 0.0;

/* ── Filter inputs ── */
$selectedTags = array_map('intval', (array)($_GET['tags'] ?? []));
$minPrice     = is_numeric($_GET['min_price'] ?? null) ? (float)$_GET['min_price'] : null;
$maxPrice     = is_numeric($_GET['max_price'] ?? null) ? (float)$_GET['max_price'] : null;
$hasImage     = isset($_GET['has_image']) ? 1 : 0;
$userLat      = is_numeric($_GET['lat']      ?? null) ? (float)$_GET['lat']      : null;
$userLng      = is_numeric($_GET['lng']      ?? null) ? (float)$_GET['lng']      : null;
$userDist     = is_numeric($_GET['distance'] ?? null) ? (int)$_GET['distance']   : 25;

$types  = '';          // bind_param type string
$params = [];          // bind_param values (by reference below)

$where = ["i.status = 'available'"];

/* ── Keyword (the only user string — bind it) ── */
if ($search !== '') {
    $where[] = "(i.title LIKE ? OR i.description LIKE ?)";
    $like    = '%' . $search . '%';
    $types  .= 'ss';
    $params[] = $like;
    $params[] = $like;
}

/* ── Category ── */
if ($category > 0) {
    $where[] = 'i.categoryID = ' . $category;          // already cast to int
}

/* ── Tags ── */
if (!empty($selectedTags)) {
    $ids     = implode(',', $selectedTags);             // all ints — safe
    $where[] = "EXISTS (
                    SELECT 1 FROM Item_Tag it
                    WHERE it.itemID = i.itemID
                      AND it.tagID IN ({$ids}))";
}

/* ── Price range ── */
if ($minPrice !== null) {
    $where[]  = 'i.price >= ?';
    $types   .= 'd';
    $params[] = $minPrice;
}
if ($maxPrice !== null) {
    $where[]  = 'i.price <= ?';
    $types   .= 'd';
    $params[] = $maxPrice;
}

/* ── Has image ── */
if ($hasImage) {
    $where[] = "EXISTS (SELECT 1 FROM Image im WHERE im.itemID = i.itemID)";
}

/* ── Location: filter by SELLER coordinates (Users.coordinates POINT)
      Haversine in km using ST_X / ST_Y to unpack the POINT column.
      ST_X = longitude, ST_Y = latitude in MySQL's default axis order.  ── */
if ($userLat !== null && $userLng !== null) {
    $where[]  = "(
        6371 * ACOS(
            GREATEST(-1, LEAST(1,
                COS(RADIANS(?)) * COS(RADIANS(ST_Y(u.coordinates))) *
                COS(RADIANS(ST_X(u.coordinates)) - RADIANS(?)) +
                SIN(RADIANS(?)) * SIN(RADIANS(ST_Y(u.coordinates)))
            ))
        )
    ) <= ?";
    $types   .= 'dddi';
    $params[] = $userLat;
    $params[] = $userLng;
    $params[] = $userLat;
    $params[] = $userDist;

    /* Also exclude sellers who have no coordinates set */
    $where[] = "u.coordinates IS NOT NULL";
}

/* ── ORDER BY ── */
switch ($sort) {
    case 'price_asc':  $order = 'i.price ASC';       break;
    case 'price_desc': $order = 'i.price DESC';       break;
    default:           $order = 'i.created_at DESC';  break;
}

$whereSql = implode(' AND ', $where);

/* ── Helper: bind & execute a prepared statement ── */
function runStmt(mysqli $db, string $sql, string $types, array $params): mysqli_result|bool
{
    if ($types === '') {
        // No user-supplied params — plain query is fine
        return $db->query($sql);
    }
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result();
}

/* ── COUNT for pagination ── */
$countSql    = "
    SELECT COUNT(*) AS total
    FROM Item i
    JOIN Users u ON u.userID = i.sellerID
    WHERE {$whereSql}";
$countResult = runStmt($db, $countSql, $types, $params);
$total       = $countResult ? (int)$countResult->fetch_assoc()['total'] : 0;
$totalPages  = max(1, (int)ceil($total / $limit));
$page        = min($page, $totalPages);
$offset      = ($page - 1) * $limit;

/* ── Main query ── */
$mainSql = "
    SELECT
        i.*,
        c.category_name,
        im.images AS image,
        /* Expose seller coordinates as plain floats for JS if needed */
        ST_Y(u.coordinates) AS seller_lat,
        ST_X(u.coordinates) AS seller_lng
    FROM Item i
    JOIN Users u ON u.userID = i.sellerID
    LEFT JOIN Category c ON c.categoryID = i.categoryID
    LEFT JOIN (
        SELECT itemID, images
        FROM Image
        WHERE imageID IN (SELECT MIN(imageID) FROM Image GROUP BY itemID)
    ) im ON im.itemID = i.itemID
    WHERE {$whereSql}
    ORDER BY {$order}
    LIMIT {$limit} OFFSET {$offset}";

/* Append LIMIT/OFFSET params */
$mainTypes  = $types;
$mainParams = $params;
$result       = runStmt($db, $mainSql, $mainTypes, $mainParams);
$items        = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$geoLabels = reverseGeocodeMany($items);


/* ── Page setup ── */
$pageTitle = "Browse — Demy's";
require_once __DIR__ . '/../includes/header.php';

/* ── URL helper ──
   Correctly handles tags[] arrays so pagination preserves tag filters. ── */
function buildQuery(array $overrides = []): string
{
    // Start from current GET params, apply overrides
    $base = array_merge($_GET, $overrides);

    // Reset page to 1 for everything except explicit page overrides
    if (!isset($overrides['page'])) {
        unset($base['page']);
    }

    // Remove empty scalars; keep arrays (tags)
    $clean = [];
    foreach ($base as $k => $v) {
        if (is_array($v)) {
            $filtered = array_filter($v, fn($x) => $x !== '' && $x !== null);
            if (!empty($filtered)) {
                $clean[$k] = array_values($filtered);
            }
        } elseif ($v !== '' && $v !== null) {
            $clean[$k] = $v;
        }
    }

    return http_build_query($clean);
}
?>

<!-- ═══════════════════════════ LEAFLET CDN ═══════════════════════════ -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV/XN/WLs=" crossorigin=""></script>

<!-- ═══════════════════════════ MAP MODAL STYLES ════════════════════════ -->
<style>
  #locationOverlay {
    position: fixed; inset: 0; z-index: 9999;
    background: rgba(0, 0, 0, .52);
    display: none; align-items: center; justify-content: center;
  }
  #locationOverlay.open { display: flex; }

  #locationModal {
    background: #fff;
    border-radius: 12px;
    width: 560px; max-width: 96vw;
    overflow: hidden;
    box-shadow: 0 12px 48px rgba(0, 0, 0, .22);
    display: flex; flex-direction: column;
  }
  #locationModal header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 18px;
    border-bottom: 1px solid #e8e8e8;
    flex-shrink: 0;
  }
  #locationModal header h3 {
    margin: 0; font-size: 15px; font-weight: 600; color: #111;
  }
  .map-close-btn {
    background: none; border: none; font-size: 22px; line-height: 1;
    color: #888; cursor: pointer; padding: 0 4px;
  }
  .map-close-btn:hover { color: #333; }

  .map-hint {
    font-size: 12px; color: #666;
    padding: 7px 18px;
    background: #f7f7f7;
    border-bottom: 1px solid #eee;
    flex-shrink: 0;
  }

  #leafletMap { height: 360px; width: 100%; flex-shrink: 0; }

  #locationModal footer {
    display: flex; justify-content: space-between; align-items: center;
    padding: 12px 18px;
    border-top: 1px solid #e8e8e8;
    flex-shrink: 0;
  }
  /* Clear location button (left side of modal footer) */
  .map-btn-clear {
    padding: 8px 14px; background: none;
    border: 1px solid #e0444466; border-radius: 6px;
    cursor: pointer; font-size: 13px; color: #c0392b;
    display: <?= ($userLat !== null) ? 'inline-flex' : 'none' ?>;
    align-items: center; gap: 5px;
  }
  .map-btn-clear:hover { background: #fdf2f2; }
  .map-footer-right { display: flex; gap: 8px; }
  .map-btn-cancel {
    padding: 8px 16px; background: none;
    border: 1px solid #ccc; border-radius: 6px;
    cursor: pointer; font-size: 13px; color: #555;
  }
  .map-btn-cancel:hover { background: #f5f5f5; }
  .map-btn-confirm {
    padding: 8px 22px;
    background: #1D9E75; color: #fff;
    border: none; border-radius: 6px;
    cursor: pointer; font-size: 13px; font-weight: 600;
  }
  .map-btn-confirm:hover { background: #178c66; }

  /* Location button inside sidebar */
  .loc-picker-btn {
    display: flex; align-items: center; gap: 8px;
    width: 100%; padding: 9px 12px;
    background: var(--surface2, #f5f5f5);
    border: 1px solid var(--border, #ddd);
    border-radius: 6px; cursor: pointer;
    font-size: 13px; color: var(--text, #333);
    text-align: left; transition: background .15s;
  }
  .loc-picker-btn:hover { background: #ebebeb; }
  .loc-picker-btn.is-set { border-color: #1D9E75; color: #0c5c45; }

  .loc-pill {
    display: none; align-items: center; gap: 5px;
    margin-top: 6px; padding: 3px 10px;
    background: #e1f5ee; color: #0F6E56;
    border-radius: 99px; font-size: 11px; font-weight: 500;
  }
  .loc-pill.visible { display: inline-flex; }

  /* Distance slider section */
  #distanceSection {
    display: <?= ($userLat !== null) ? 'block' : 'none' ?>;
    margin-top: 14px; padding-top: 14px;
    border-top: 1px solid var(--border, #eee);
  }
  .dist-range-row {
    display: flex; align-items: center; gap: 10px; margin-top: 6px;
  }
  .dist-range-row input[type=range] { flex: 1; accent-color: #1D9E75; }
  .dist-val {
    font-size: 13px; font-weight: 600;
    min-width: 52px; color: var(--text, #222);
  }
</style>

<!-- ════════════════════════════ PAGE LAYOUT ════════════════════════════ -->
<div class="container">
  <?php var_dump($geoLabels); ?>
  <div class="section" style="max-width:1200px;margin:0 auto;display:flex;gap:24px">

    <!-- ─── Filters sidebar ─── -->
    <aside style="width:260px">
      <div class="page-card">
        <h3 class="page-card-title">Filters</h3>

        <form id="filtersForm" action="search.php" method="GET">

          <!-- Preserves sort when form submits -->
          <input type="hidden" name="sort"     id="sortHidden"  value="<?= h($sort) ?>" />
          <!-- Location hidden inputs — populated by JS on confirm -->
          <input type="hidden" name="lat"      id="latInput"    value="<?= h($_GET['lat']      ?? '') ?>" />
          <input type="hidden" name="lng"      id="lngInput"    value="<?= h($_GET['lng']      ?? '') ?>" />
          <input type="hidden" name="distance" id="distInput"   value="<?= h($_GET['distance'] ?? '') ?>" />

          <!-- Keyword -->
          <div style="margin-bottom:12px">
            <label class="form-label">Keyword</label>
            <input type="text" name="q" class="form-control"
                   value="<?= h($search) ?>" placeholder="Search listings…" />
          </div>

          <!-- Category -->
          <div style="margin-bottom:12px">
            <label class="form-label">Category</label>
            <select name="category" class="form-control">
              <option value="0">All categories</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= h($cat['categoryID']) ?>"
                  <?= $category === (int)$cat['categoryID'] ? 'selected' : '' ?>>
                  <?= h($cat['category_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Tags -->
          <div style="margin-bottom:12px">
            <label class="form-label">Tags</label>
            <div style="max-height:160px;overflow:auto;padding-right:6px">
              <?php foreach ($tags as $t): ?>
                <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                  <input type="checkbox" name="tags[]"
                         value="<?= h($t['tagID']) ?>"
                         <?= in_array((int)$t['tagID'], $selectedTags) ? 'checked' : '' ?> />
                  <?= h($t['name']) ?>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Price range -->
          <div style="margin-bottom:12px">
            <label class="form-label">Price range</label>
            <div style="display:flex;gap:8px">
              <input type="number" name="min_price" class="form-control"
                     placeholder="Min" step="0.01"
                     value="<?= h($minPrice ?? '') ?>"
                     min="<?= h($globalMin) ?>" />
              <input type="number" name="max_price" class="form-control"
                     placeholder="Max" step="0.01"
                     value="<?= h($maxPrice ?? '') ?>"
                     max="<?= h($globalMax) ?>" />
            </div>
            <div style="font-size:12px;color:var(--muted);margin-top:6px">
              Range: <?= formatPrice($globalMin) ?> — <?= formatPrice($globalMax) ?>
            </div>
          </div>

          <!-- Has photo -->
          <div style="margin-bottom:14px">
            <label style="display:flex;align-items:center;gap:8px">
              <input type="checkbox" name="has_image" value="1"
                     <?= $hasImage ? 'checked' : '' ?> />
              Has photo
            </label>
          </div>

          <!-- ── Location picker ── -->
          <div style="margin-bottom:4px">
            <label class="form-label">Location</label>

            <button type="button" id="openMapBtn"
                    class="loc-picker-btn <?= ($userLat !== null) ? 'is-set' : '' ?>"
                    onclick="openLocationMap()">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="12" cy="10" r="3"/>
                <path d="M12 2a8 8 0 0 1 8 8c0 5.25-8 13-8 13S4 15.25 4 10a8 8 0 0 1 8-8z"/>
              </svg>
              <span id="mapBtnLabel">
                <?= ($userLat !== null) ? 'Change location' : 'Set location' ?>
              </span>
            </button>

            <div class="loc-pill <?= ($userLat !== null) ? 'visible' : '' ?>" id="locPill">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                <circle cx="12" cy="10" r="3"/>
                <path d="M12 2a8 8 0 0 1 8 8c0 5.25-8 13-8 13S4 15.25 4 10a8 8 0 0 1 8-8z"/>
              </svg>
              <span id="locCoords">
                <?= ($userLat !== null) ?  reverseGeocode($userLat, $userLng) : '' ?>
              </span>
            </div>
          </div>

          <!-- ── Distance slider (visible only when location is set) ── -->
          <div id="distanceSection">
            <label class="form-label">Distance</label>
            <div class="dist-range-row">
              <input type="range" id="distSlider"
                     min="1" max="100" step="1"
                     value="<?= h($userDist) ?>"
                     oninput="syncDist(this.value)" />
              <span class="dist-val" id="distLabel"><?= h($userDist) ?> km</span>
            </div>
          </div>

          <!-- Apply / Clear -->
          <div style="display:flex;gap:8px;margin-top:16px">
            <button class="btn-accent" type="submit">Apply</button>
            <a class="btn-ghost" href="search.php">Clear</a>
          </div>

        </form>
      </div>
    </aside>

    <!-- ─── Results ─── -->
    <div style="flex:1">
      <div class="search-results-inner">
        <div class="section-header"
             style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
          <div>
            <h1 class="section-title">Browse listings</h1>
            <p class="section-count">
              <?= number_format($total) ?> item<?= $total === 1 ? '' : 's' ?> found
              <?php if ($userLat !== null): ?>
                <span style="font-size:12px;color:var(--muted)">
                  within <?= h($userDist) ?> km of your location
                </span>
              <?php endif; ?>
            </p>
          </div>
          <div>
            <label class="form-label">Sort</label>
            <select id="sortSelect" class="form-control" style="min-width:160px"
                    onchange="document.getElementById('sortHidden').value=this.value;
                              document.getElementById('filtersForm').submit();">
              <option value="newest"     <?= $sort === 'newest'     ? 'selected' : '' ?>>Newest</option>
              <option value="price_asc"  <?= $sort === 'price_asc'  ? 'selected' : '' ?>>Price ↑</option>
              <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price ↓</option>
            </select>
          </div>
        </div>
      </div>

      <?php if (empty($items)): ?>
        <div class="search-results-inner">
          <div class="empty" style="padding:40px;text-align:center;width:100%">
            <div class="empty-icon">🔍</div>
            <h3>No listings match your search.</h3>
            <p>Try a different keyword, category, or increase the distance radius.</p>
          </div>
        </div>
      <?php else: ?>
        <div class="grid-4">
          <?php foreach ($items as $key => $item): ?>
            <a class="card item-card" href="../templates/item.html?id=<?= h($item['itemID']) ?>">
              <?php if (!empty($item['image'])): ?>
                <div class="item-card-img-wrap">
                  <img class="item-card-img"
                       src="<?= BASE_URL ?>/<?= h($item['image']) ?>"
                       alt="<?= h($item['title']) ?>"
                       loading="lazy" />
                </div>
              <?php else: ?>
                <div class="item-card-img-wrap">
                  <div class="item-card-img"
                       style="display:flex;align-items:center;justify-content:center;
                              font-size:40px;background:var(--surface2)">📦</div>
                </div>
              <?php endif; ?>
              <div style="padding:16px">
                <p class="item-card-title"><?= h($item['title']) ?></p>
                <p class="item-card-price"><?= formatPrice((float)$item['price']) ?></p>
                <div class="item-card-meta">
                  <?= h($item['category_name'] ?? 'Uncategorized') ?>
                  <?php if (!empty($geoLabels[$key])): ?>
                    &middot; <?= h($geoLabels[$key]) ?>
                  <?php endif; ?>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
        <div class="pagination" style="justify-content:center;margin-top:28px">
          <?php if ($page > 1): ?>
            <a class="pagination-link"
               href="search.php?<?= buildQuery(['page' => $page - 1]) ?>">‹ Previous</a>
          <?php endif; ?>

          <?php
          /* Show a sensible window of page numbers rather than all of them */
          $window = 2;
          $first  = max(1, $page - $window);
          $last   = min($totalPages, $page + $window);
          if ($first > 1): ?>
            <a class="pagination-link" href="search.php?<?= buildQuery(['page' => 1]) ?>">1</a>
            <?php if ($first > 2): ?><span class="pagination-ellipsis">…</span><?php endif; ?>
          <?php endif; ?>

          <?php for ($i = $first; $i <= $last; $i++): ?>
            <a class="pagination-link<?= $i === $page ? ' active' : '' ?>"
               href="search.php?<?= buildQuery(['page' => $i]) ?>"><?= $i ?></a>
          <?php endfor; ?>

          <?php if ($last < $totalPages): ?>
            <?php if ($last < $totalPages - 1): ?>
              <span class="pagination-ellipsis">…</span>
            <?php endif; ?>
            <a class="pagination-link"
               href="search.php?<?= buildQuery(['page' => $totalPages]) ?>"><?= $totalPages ?></a>
          <?php endif; ?>

          <?php if ($page < $totalPages): ?>
            <a class="pagination-link"
               href="search.php?<?= buildQuery(['page' => $page + 1]) ?>">Next ›</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>

    </div><!-- /results -->
  </div>
</div>

<!-- ═══════════════════════ LEAFLET MAP MODAL ═══════════════════════════ -->
<div id="locationOverlay" role="dialog" aria-modal="true" aria-label="Set your location">
  <div id="locationModal">
    <header>
      <h3>Set your location</h3>
      <button class="map-close-btn" type="button"
              onclick="closeLocationMap()" aria-label="Close map">×</button>
    </header>
    <div class="map-hint">
      Drag the pin to your location, or click anywhere on the map to move it there.
    </div>
    <div id="leafletMap"></div>
    <footer>
      <!-- Clear location: strips lat/lng from the query and resubmits -->
      <button class="map-btn-clear" type="button" id="clearLocBtn" onclick="clearLocation()">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" aria-hidden="true">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
        Clear location
      </button>
      <div class="map-footer-right">
        <button class="map-btn-cancel" type="button" onclick="closeLocationMap()">Cancel</button>
        <button class="map-btn-confirm" type="button" onclick="confirmLocation()">
          Use this location
        </button>
      </div>
    </footer>
  </div>
</div>

<!-- ═══════════════════════ MAP JAVASCRIPT ══════════════════════════════ -->
<script>
(function () {
  'use strict';

  /* ── State ── */
  var _map    = null;
  var _marker = null;
  var _lat    = <?= json_encode($userLat) ?>;
  var _lng    = <?= json_encode($userLng) ?>;

  // Default centre: Bacoor, Cavite (adjust to your region)
  var DEFAULT_LAT  = 14.4624;
  var DEFAULT_LNG  = 120.9645;
  var DEFAULT_ZOOM = 13;

  /* ── Distance slider sync ── */
  window.syncDist = function (val) {
    document.getElementById('distLabel').textContent = val + ' km';
    document.getElementById('distInput').value       = val;
  };

  /* ── Open modal ── */
  window.openLocationMap = function () {
    document.getElementById('locationOverlay').classList.add('open');
    setTimeout(function () {
      if (!_map) {
        _initMap();
      } else {
        _map.invalidateSize();
      }
    }, 80);
  };

  /* ── Close modal ── */
  window.closeLocationMap = function () {
    document.getElementById('locationOverlay').classList.remove('open');
  };

  /* ── Confirm chosen location ── */
  window.confirmLocation = function () {
    var pos = _marker.getLatLng();
    _lat = pos.lat;
    _lng = pos.lng;

    document.getElementById('latInput').value  = _lat.toFixed(6);
    document.getElementById('lngInput').value  = _lng.toFixed(6);
    document.getElementById('distInput').value = document.getElementById('distSlider').value;

    // Update sidebar UI
    document.getElementById('locCoords').textContent =
      _lat.toFixed(4) + ', ' + _lng.toFixed(4);
    document.getElementById('locPill').classList.add('visible');
    document.getElementById('mapBtnLabel').textContent = 'Change location';
    document.getElementById('openMapBtn').classList.add('is-set');
    document.getElementById('distanceSection').style.display = 'block';
    document.getElementById('clearLocBtn').style.display     = 'inline-flex';

    closeLocationMap();
    document.getElementById('filtersForm').submit();
  };

  /* ── Clear location entirely ── */
  window.clearLocation = function () {
    document.getElementById('latInput').value  = '';
    document.getElementById('lngInput').value  = '';
    document.getElementById('distInput').value = '';
    closeLocationMap();
    document.getElementById('filtersForm').submit();
  };

  /* ── Initialise Leaflet map ── */
  function _initMap() {
    var startLat = _lat !== null ? _lat : DEFAULT_LAT;
    var startLng = _lng !== null ? _lng : DEFAULT_LNG;

    _map = L.map('leafletMap', { zoomControl: true })
             .setView([startLat, startLng], DEFAULT_ZOOM);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
      maxZoom: 19
    }).addTo(_map);

    var pinIcon = L.icon({
      iconUrl:       'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
      iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
      shadowUrl:     'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
      iconSize:      [25, 41],
      iconAnchor:    [12, 41],
      popupAnchor:   [1, -34],
      shadowSize:    [41, 41]
    });

    _marker = L.marker([startLat, startLng], { draggable: true, icon: pinIcon }).addTo(_map);

    _marker.on('dragend', function (e) {
      var p = e.target.getLatLng();
      _lat = p.lat;
      _lng = p.lng;
    });

    _map.on('click', function (e) {
      _lat = e.latlng.lat;
      _lng = e.latlng.lng;
      _marker.setLatLng([_lat, _lng]);
    });

    // Browser geolocation only when no location is already stored
    if (_lat === null && navigator.geolocation) {
      navigator.geolocation.getCurrentPosition(
        function (pos) {
          _lat = pos.coords.latitude;
          _lng = pos.coords.longitude;
          _map.setView([_lat, _lng], 14);
          _marker.setLatLng([_lat, _lng]);
        },
        function () { /* permission denied — keep default */ }
      );
    }
  }

  /* ── Close on backdrop click ── */
  document.getElementById('locationOverlay').addEventListener('click', function (e) {
    if (e.target === this) { closeLocationMap(); }
  });

  /* ── Close on Escape ── */
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeLocationMap(); }
  });

}());
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>