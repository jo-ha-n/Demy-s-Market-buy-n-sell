<?php
require_once __DIR__ . '/../includes/helpers.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$db   = getDB();
$cats = $db->query('SELECT * FROM Category')->fetch_all(MYSQLI_ASSOC);
$user = currentUser();
$errors = [];
$success = false;
$csrf = csrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $title   = trim($_POST['title']       ?? '');
    $price   = (float)($_POST['price']    ?? 0);
    $desc    = trim($_POST['description'] ?? '');
    $address = trim($_POST['address']     ?? '');
    $catID   = (int)($_POST['categoryID'] ?? 0);
    $sellerID= $_SESSION['userID'];

    if (strlen($title) < 3)   $errors[] = 'Title must be at least 3 characters.';
    if ($price <= 0)           $errors[] = 'Price must be greater than zero.';
    if ($catID === 0)          $errors[] = 'Please select a category.';

    // Handle file uploads
    $uploadedPaths = [];
    if (!empty($_FILES['images']['name'][0])) {
        $uploadDir = __DIR__ . '/../uploads/';
        foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
            if (!$tmp || $_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $ext  = strtolower(pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp'])) { $errors[] = 'Only JPG/PNG/WEBP images allowed.'; continue; }
            $name = uniqid('img_', true) . '.' . $ext;
            if (move_uploaded_file($tmp, $uploadDir . $name)) {
                $uploadedPaths[] = $name;
            }
        }
    }

    if (empty($errors)) {
        $stmt = $db->prepare('INSERT INTO Item (sellerID,title,price,description,address,categoryID) VALUES (?,?,?,?,?,?)');
        $stmt->bind_param('isdssi', $sellerID, $title, $price, $desc, $address, $catID);
        $stmt->execute();
        $newItemID = $db->insert_id;

        foreach ($uploadedPaths as $path) {
            $iStmt = $db->prepare('INSERT INTO Image (itemID, images) VALUES (?,?)');
            $iStmt->bind_param('is', $newItemID, $path);
            $iStmt->execute();
        }

        setFlash('success', 'Listing posted successfully!');
        header("Location: ../pages/item.php?id={$newItemID}"); exit;
    }
}

$pageTitle = "Sell an Item — Demy's";
require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/main.css"/>
<div class="container">
  <div class="section" style="max-width:700px;margin:0 auto">
    <div class="page-card">
      <h1 class="page-card-title">Post a listing</h1>

      <?php if ($errors): ?>
      <div style="background:var(--danger-light);color:var(--danger);border:1px solid #f0b8c0;border-radius:var(--radius);padding:12px 16px;margin-bottom:24px;font-size:13.5px">
        <?php foreach ($errors as $e): ?><div>• <?= h($e) ?></div><?php endforeach; ?>
      </div>
      <?php endif; ?>

      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"/>

        <div class="form-group">
          <label class="form-label">Title *</label>
          <input type="text" name="title" class="form-control" placeholder="e.g. iPhone 13 Pro 256GB" value="<?= h($_POST['title']??'') ?>" required/>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Price (₱) *</label>
            <input type="number" name="price" class="form-control" placeholder="0.00" min="1" step="0.01" value="<?= h($_POST['price']??'') ?>" required/>
          </div>
          <div class="form-group">
            <label class="form-label">Category *</label>
            <select name="categoryID" class="form-control" required>
              <option value="">Select category…</option>
              <?php foreach ($cats as $c): ?>
              <option value="<?= $c['categoryID'] ?>" <?= ($_POST['categoryID']??'')==$c['categoryID']?'selected':'' ?>>
                <?= h($c['category_name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" placeholder="Describe your item — condition, specs, reason for selling…" rows="5"><?= h($_POST['description']??'') ?></textarea>
        </div>

        <div class="form-group">
          <label class="form-label">Location / Address</label>
          <input type="text" name="address" class="form-control" placeholder="e.g. Quezon City, Metro Manila" value="<?= h($_POST['address']??$user['address']??'') ?>"/>
        </div>

        <div class="form-group">
          <label class="form-label">Photos</label>
          <div class="upload-zone" id="uploadZone">
            <div style="font-size:32px;margin-bottom:8px">📷</div>
            <div>Click or drag photos here</div>
            <div style="font-size:12px;margin-top:4px;color:var(--muted2)">JPG, PNG, WEBP — up to 5 images</div>
          </div>
          <input type="file" id="itemImages" name="images[]" accept="image/*" multiple style="display:none"/>
          <div class="upload-previews" id="uploadPreviews"></div>
        </div>

        <div style="display:flex;gap:12px;margin-top:8px">
          <button type="submit" class="btn-accent btn-lg" style="flex:1">Post Listing</button>
          <a href="../index.html" class="btn-ghost btn-lg">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
