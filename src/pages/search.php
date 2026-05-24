<?php
require_once __DIR__ . '/../includes/helpers.php';

$db = getDB();
$search = trim($_GET['q'] ?? '');
$category = max(0, (int)($_GET['category'] ?? 0));
$sort = $_GET['sort'] ?? 'newest';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 12;
$offset = ($page - 1) * $limit;

$categoryResult = $db->query('SELECT categoryID, category_name FROM Category ORDER BY category_name');
$categories = $categoryResult ? $categoryResult->fetch_all(MYSQLI_ASSOC) : [];
// Tags and price range for filters
$tagResult = $db->query('SELECT tagID, name FROM Tag ORDER BY name');
$tags = $tagResult ? $tagResult->fetch_all(MYSQLI_ASSOC) : [];
$minMax = $db->query("SELECT MIN(price) AS minp, MAX(price) AS maxp FROM Item WHERE status='available'")->fetch_assoc();
$globalMin = $minMax['minp'] !== null ? (float)$minMax['minp'] : 0.0;
$globalMax = $minMax['maxp'] !== null ? (float)$minMax['maxp'] : 0.0;

$where = ["i.status = 'available'"];
if ($search !== '') {
    $searchSafe = $db->real_escape_string($search);
    $where[] = "(i.title LIKE '%{$searchSafe}%' OR i.description LIKE '%{$searchSafe}%')";
}
if ($category > 0) {
    $where[] = 'i.categoryID = ' . $category;
}

// Tags filter (multiple)
$selectedTags = array_map('intval', (array)($_GET['tags'] ?? []));
if (!empty($selectedTags)) {
  $ids = implode(',', $selectedTags);
  $where[] = "EXISTS (SELECT 1 FROM Item_Tag it WHERE it.itemID = i.itemID AND it.tagID IN ({$ids}))";
}

// Price range
$minPrice = is_numeric($_GET['min_price'] ?? null) ? (float)$_GET['min_price'] : null;
$maxPrice = is_numeric($_GET['max_price'] ?? null) ? (float)$_GET['max_price'] : null;
if ($minPrice !== null) {
  $where[] = 'i.price >= ' . $db->real_escape_string((string)$minPrice);
}
if ($maxPrice !== null) {
  $where[] = 'i.price <= ' . $db->real_escape_string((string)$maxPrice);
}

// Has image filter
$hasImage = isset($_GET['has_image']) ? 1 : 0;
if ($hasImage) {
  $where[] = "EXISTS (SELECT 1 FROM Image im WHERE im.itemID = i.itemID)";
}

switch ($sort) {
    case 'price_asc':
        $order = 'i.price ASC';
        break;
    case 'price_desc':
        $order = 'i.price DESC';
        break;
    default:
        $order = 'i.created_at DESC';
        break;
}
$whereSql = implode(' AND ', $where);

$countSql = "SELECT COUNT(*) AS total FROM Item i WHERE {$whereSql}";
$countResult = $db->query($countSql);
$total = $countResult ? (int) $countResult->fetch_assoc()['total'] : 0;
$totalPages = max(1, (int) ceil($total / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

$sql = "SELECT i.*, c.category_name, (SELECT images FROM Image im WHERE im.itemID = i.itemID ORDER BY im.imageID LIMIT 1) AS image
        FROM Item i
        LEFT JOIN Category c ON c.categoryID = i.categoryID
        WHERE {$whereSql}
        ORDER BY {$order}
        LIMIT {$limit} OFFSET {$offset}";
$result = $db->query($sql);
$items = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

$pageTitle = "Browse — Demy's";
require_once __DIR__ . '/../includes/header.php';

function buildQuery(array $overrides = []): string {
    $params = array_filter([
        'q' => ($overrides['q'] ?? $_GET['q'] ?? ''),
        'category' => ($overrides['category'] ?? $_GET['category'] ?? ''),
        'sort' => ($overrides['sort'] ?? $_GET['sort'] ?? ''),
    'page' => ($overrides['page'] ?? $_GET['page'] ?? ''),
    'tags' => ($overrides['tags'] ?? $_GET['tags'] ?? ''),
    'min_price' => ($overrides['min_price'] ?? $_GET['min_price'] ?? ''),
    'max_price' => ($overrides['max_price'] ?? $_GET['max_price'] ?? ''),
    'has_image' => ($overrides['has_image'] ?? $_GET['has_image'] ?? ''),
    ], function ($value) {
        return $value !== '' && $value !== null;
    });
    return http_build_query($params);
}
?>

<div class="container">
  <div class="section" style="max-width:1200px;margin:0 auto;display:flex;gap:24px">
    <!-- Filters sidebar -->
    <aside style="width:260px">
      <div class="page-card">
        <h3 class="page-card-title">Filters</h3>
        <form id="filtersForm" action="search.php" method="GET">
          <div style="margin-bottom:12px">
            <label class="form-label">Keyword</label>
            <input type="text" name="q" class="form-control" value="<?= h($search) ?>" placeholder="Search listings…" />
          </div>
          <div style="margin-bottom:12px">
            <label class="form-label">Category</label>
            <select name="category" class="form-control">
              <option value="0">All categories</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= h($cat['categoryID']) ?>" <?= $category === (int) $cat['categoryID'] ? 'selected' : '' ?>>
                  <?= h($cat['category_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div style="margin-bottom:12px">
            <label class="form-label">Tags</label>
            <div style="max-height:160px;overflow:auto;padding-right:6px">
              <?php foreach ($tags as $t): ?>
                <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px"><input type="checkbox" name="tags[]" value="<?= h($t['tagID']) ?>" <?= in_array((int)$t['tagID'],$selectedTags) ? 'checked' : '' ?>/> <?= h($t['name']) ?></label>
              <?php endforeach; ?>
            </div>
          </div>

          <div style="margin-bottom:12px">
            <label class="form-label">Price range</label>
            <div style="display:flex;gap:8px">
              <input type="number" name="min_price" class="form-control" placeholder="Min" step="0.01" value="<?= h($minPrice ?? '') ?>" min="<?= h($globalMin) ?>" />
              <input type="number" name="max_price" class="form-control" placeholder="Max" step="0.01" value="<?= h($maxPrice ?? '') ?>" max="<?= h($globalMax) ?>" />
            </div>
            <div style="font-size:12px;color:var(--muted);margin-top:6px">Range: <?= formatPrice($globalMin) ?> — <?= formatPrice($globalMax) ?></div>
          </div>

          <div style="margin-bottom:12px">
            <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="has_image" value="1" <?= $hasImage ? 'checked' : '' ?>/> Has photo</label>
          </div>

          <div style="display:flex;gap:8px;margin-top:8px">
            <button class="btn-accent" type="submit">Apply</button>
            <a class="btn-ghost" href="search.php">Clear</a>
          </div>
        </form>
      </div>
    </aside>

    <!-- Results -->
    <div style="flex:1">
      <div class="search-results-inner">
      <div class="section-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <div>
          <h1 class="section-title">Browse listings</h1>
          <p class="section-count"><?= number_format($total) ?> item<?= $total === 1 ? '' : 's' ?> found</p>
        </div>
        <div>
          <label class="form-label">Sort</label>
          <select id="sortSelect" name="sort" form="filtersForm" class="form-control" style="min-width:160px">
            <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
            <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price ↑</option>
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
        <p>Try a different keyword or category.</p>
      </div>
      </div>
    <?php else: ?>
      <div class="grid-4">
        <?php foreach ($items as $item): ?>
          <a class="card item-card" href="../templates/item.html?id=<?= h($item['itemID']) ?>">
            <?php if (!empty($item['image'])): ?>
              <div class="item-card-img-wrap">
                <img class="item-card-img" src="../uploads/<?= h($item['image']) ?>" alt="<?= h($item['title']) ?>" loading="lazy" />
              </div>
            <?php else: ?>
              <div class="item-card-img-wrap">
                <div class="item-card-img" style="display:flex;align-items:center;justify-content:center;font-size:40px;background:var(--surface2)">📦</div>
              </div>
            <?php endif; ?>
            <div style="padding:16px">
              <p class="item-card-title"><?= h($item['title']) ?></p>
              <p class="item-card-price"><?= formatPrice((float) $item['price']) ?></p>
              <div class="item-card-meta">
                <?= h($item['category_name'] ?? 'Uncategorized') ?>
                <?= $item['address'] ? '&middot; ' . h($item['address']) : '' ?>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
      <div class="pagination" style="justify-content:center;margin-top:28px">
        <?php if ($page > 1): ?>
          <a class="pagination-link" href="search.php?<?= buildQuery(['page' => $page - 1]) ?>">‹ Previous</a>
        <?php endif; ?>

        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
          <a class="pagination-link<?= $i === $page ? ' active' : '' ?>" href="search.php?<?= buildQuery(['page' => $i]) ?>"><?= $i ?></a>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
          <a class="pagination-link" href="search.php?<?= buildQuery(['page' => $page + 1]) ?>">Next ›</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
