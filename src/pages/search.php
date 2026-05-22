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

$where = ["i.status = 'available'"];
if ($search !== '') {
    $searchSafe = $db->real_escape_string($search);
    $where[] = "(i.title LIKE '%{$searchSafe}%' OR i.description LIKE '%{$searchSafe}%')";
}
if ($category > 0) {
    $where[] = 'i.categoryID = ' . $category;
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
    ], function ($value) {
        return $value !== '' && $value !== null;
    });
    return http_build_query($params);
}
?>

<div class="container">
  <div class="section" style="max-width:1040px;margin:0 auto">
    <div class="section-header" style="justify-content:space-between;flex-wrap:wrap;gap:18px">
      <div>
        <h1 class="section-title">Browse listings</h1>
        <p class="section-count"><?= number_format($total) ?> item<?= $total === 1 ? '' : 's' ?> found</p>
      </div>
    </div>

    <form action="search.php" method="GET" style="display:flex;flex-wrap:wrap;gap:12px;margin:18px 0 24px;align-items:flex-end">
      <div class="topbar-search" style="flex:2;min-width:220px">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
        </svg>
        <input type="text" name="q" placeholder="Search listings…" value="<?= h($search) ?>" />
      </div>
      <div style="flex:1;min-width:180px">
        <label class="form-label" for="categorySelect">Category</label>
        <select id="categorySelect" name="category" class="form-control">
          <option value="0">All categories</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= h($cat['categoryID']) ?>" <?= $category === (int) $cat['categoryID'] ? 'selected' : '' ?>>
              <?= h($cat['category_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="flex:1;min-width:180px">
        <label class="form-label" for="sortSelect">Sort</label>
        <select id="sortSelect" name="sort" class="form-control">
          <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
          <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price ↑</option>
          <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price ↓</option>
        </select>
      </div>
      <div style="display:flex;gap:10px">
        <button class="btn-accent" type="submit">Apply</button>
        <a class="btn-ghost" href="search.php">Clear</a>
      </div>
    </form>

    <?php if (empty($items)): ?>
      <div class="empty" style="padding:40px;text-align:center;grid-column:1/-1">
        <div class="empty-icon">🔍</div>
        <h3>No listings match your search.</h3>
        <p>Try a different keyword or category.</p>
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
