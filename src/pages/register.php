<?php
require_once __DIR__ . '/../includes/helpers.php';
$csrf = csrfToken();

$ajax = $_GET['ajax'] ?? $_POST['ajax'] ?? null;
if ($ajax === '1' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = currentUser();
    respond([
        'csrfToken' => csrfToken(),
        'user'      => $user ? ['userID' => $user['userID'], 'username' => $user['username'], 'role' => $user['role']] : null,
    ]);
}

// isLoggedIn guard removed — PHP session may linger after JS logout.

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $email    = trim($_POST['email']    ?? '');
    $username = trim($_POST['username'] ?? '');
    $password =      $_POST['password'] ?? '';
    $confirm  =      $_POST['confirm']  ?? '';
    $role     =      $_POST['role']     ?? 'buyer';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
    if (strlen($username) < 3)   $errors[] = 'Username must be at least 3 characters.';
    if (strlen($password) < 8)   $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm)  $errors[] = 'Passwords do not match.';
    if (!in_array($role, ['buyer','seller'])) $role = 'buyer';

    if (empty($errors)) {
        $db   = getDB();
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $db->prepare('INSERT INTO Users (email,username,password,role) VALUES (?,?,?,?)');
        $stmt->bind_param('ssss', $email, $username, $hash, $role);
        // Save coordinates if provided
        $lat = isset($_POST['lat']) ? (float)$_POST['lat'] : null;
        $lng = isset($_POST['lng']) ? (float)$_POST['lng'] : null;
        if ($lat !== null && $lng !== null && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
            $newUserID = $db->insert_id;
            $coordStmt = $db->prepare(
                "UPDATE Users SET coordinates = ST_GeomFromText(?) WHERE userID = ?"
            );
            $wkt = "POINT({$lng} {$lat})"; // MySQL POINT is (lng lat) / (X Y)
            $coordStmt->bind_param('si', $wkt, $newUserID);
            $coordStmt->execute();
        }
        if ($stmt->execute()) {
            $_SESSION['userID'] = $db->insert_id;
            $user = ['userID' => $db->insert_id, 'username' => $username, 'role' => $role];
            if ($ajax === '1') {
                respond(['success' => true, 'message' => "Welcome to Demy's Buy and Sell, {$username}!", 'user' => $user]);
            }
            $sessionParam = urlencode(json_encode($user));
            header('Location: ../index.html?session=' . $sessionParam . '&welcome=1'); exit;
        } else {
            $errors[] = $db->errno === 1062 ? 'Email or username already taken.' : 'Registration failed. Try again.';
        }
    }
}

$pageTitle = "Sign Up — Demy's";
$hideHeader = true;
$hideFooter = true;
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.reg-wrap {
  min-height: calc(100vh - var(--topbar-h));
  display: flex; align-items: center; justify-content: center;
  padding: 40px 20px;
}
.reg-modal {
  width: 100%; max-width: 860px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 20px;
  box-shadow: 0 24px 64px rgba(0,0,0,0.18);
  display: grid;
  grid-template-columns: 1fr 1.15fr;
  overflow: hidden;
  animation: slideUp 0.35s cubic-bezier(0.16,1,0.3,1);
}
@keyframes slideUp {
  from{opacity:0;transform:translateY(20px) scale(0.97)}
  to{opacity:1;transform:translateY(0) scale(1)}
}

/* Left panel */
.reg-side {
  background: linear-gradient(145deg, #1a0e09 0%, #2d1208 50%, #1a0e09 100%);
  padding: 48px 36px;
  display: flex; flex-direction: column;
  justify-content: space-between;
  position: relative; overflow: hidden;
}
[data-theme="dark"] .reg-side { background: linear-gradient(145deg, #0f0c0b 0%, #1c1008 100%); }
.reg-side::before {
  content:''; position:absolute; width:280px; height:280px; border-radius:50%;
  background:var(--accent); opacity:0.15; top:-80px; right:-80px;
}
.reg-side::after {
  content:''; position:absolute; width:180px; height:180px; border-radius:50%;
  background:var(--accent); opacity:0.08; bottom:-40px; left:-40px;
}
.reg-side-brand {
  font-family:'Syne',sans-serif; font-weight:800; font-size:22px;
  color:#fff; letter-spacing:-0.8px; position:relative; z-index:1;
}
.reg-side-brand span { color:var(--accent); }
.reg-side-content { position:relative; z-index:1; }
.reg-side-title {
  font-family:'Syne',sans-serif; font-weight:800; font-size:30px;
  color:#fff; line-height:1.1; letter-spacing:-1px; margin-bottom:16px;
}
.reg-side-title em { color:var(--accent); font-style:normal; }
.reg-side-desc { font-size:13.5px; color:rgba(255,255,255,0.5); line-height:1.7; margin-bottom:28px; }
.reg-perk {
  display:flex; align-items:center; gap:10px;
  margin-bottom:12px; font-size:13px; color:rgba(255,255,255,0.7);
}
.reg-perk-icon {
  width:28px; height:28px; border-radius:8px;
  background:rgba(232,65,10,0.2); border:1px solid rgba(232,65,10,0.3);
  display:flex; align-items:center; justify-content:center; flex-shrink:0;
  color:var(--accent);
}
.reg-side-footer { font-size:12px; color:rgba(255,255,255,0.3); position:relative; z-index:1; }

/* Right form */
.reg-form-panel { padding: 44px 48px; display:flex; flex-direction:column; justify-content:center; }
.reg-title {
  font-family:'Syne',sans-serif; font-weight:800; font-size:26px;
  letter-spacing:-0.7px; color:var(--text); margin-bottom:6px;
}
.reg-sub { font-size:13px; color:var(--muted); margin-bottom:28px; }
.reg-errors {
  background:var(--danger-light); color:var(--danger);
  border:1px solid #f0b8c0; border-radius:var(--radius);
  padding:12px 14px; margin-bottom:20px; font-size:13px;
}
.reg-errors div { display:flex; align-items:center; gap:6px; margin-bottom:3px; }
.reg-errors div:last-child { margin-bottom:0; }

.rfield { display:flex; flex-direction:column; gap:5px; margin-bottom:16px; }
.rlabel {
  font-size:11px; font-weight:700; text-transform:uppercase;
  letter-spacing:0.08em; color:var(--muted);
}
.rinput {
  background:var(--bg); border:1.5px solid var(--border);
  border-radius:var(--radius); padding:10px 13px;
  font-size:14px; color:var(--text); outline:none;
  transition:border-color 0.2s,background 0.2s; width:100%;
  font-family:'DM Sans',sans-serif;
}
.rinput:focus { border-color:var(--accent); background:var(--surface); }
.rinput::placeholder { color:var(--muted2); }
select.rinput { appearance:none; cursor:pointer; }

.reg-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }

/* Role toggle */
.role-toggle { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:16px; }
.role-opt { position:relative; }
.role-opt input[type="radio"] { position:absolute; opacity:0; width:0; height:0; }
.role-opt label {
  display:flex; align-items:center; gap:10px;
  padding:12px 14px; border:1.5px solid var(--border);
  border-radius:var(--radius); cursor:pointer; font-size:13.5px;
  color:var(--text-2); font-weight:500; background:var(--bg);
  transition:border-color 0.2s,background 0.2s,color 0.2s;
  user-select:none;
}
.role-opt label:hover { border-color:var(--border2); background:var(--surface); }
.role-opt input:checked + label {
  border-color:var(--accent); background:var(--accent-light); color:var(--accent);
}
.role-icon { font-size:18px; }

.reg-submit {
  width:100%; padding:13px; background:var(--accent); color:#fff;
  border:none; border-radius:var(--radius); font-size:15px;
  font-weight:700; font-family:'Syne',sans-serif; cursor:pointer;
  transition:background 0.2s,transform 0.15s; letter-spacing:-0.2px;
  margin-top:4px;
}
.reg-submit:hover { background:var(--accent-h); transform:translateY(-1px); }

.reg-footer { text-align:center; margin-top:16px; font-size:13px; color:var(--muted); }
.reg-footer a { color:var(--accent); font-weight:600; }
.reg-footer a:hover { text-decoration:underline; }

@media(max-width:740px){
  .reg-modal{grid-template-columns:1fr; max-width:480px;}
  .reg-side{display:none;}
  .reg-form-panel{padding:36px 28px;}
}
@media(max-width:480px){ .reg-row{grid-template-columns:1fr;} }

/* Location picker */
.loc-field { margin-bottom: 16px; }
.loc-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; color:var(--muted); display:block; margin-bottom:6px; }
.loc-trigger {
  width:100%; padding:10px 13px;
  background:var(--bg); border:1.5px solid var(--border);
  border-radius:var(--radius); font-size:14px; color:var(--text);
  font-family:'DM Sans',sans-serif; cursor:pointer; text-align:left;
  display:flex; align-items:center; gap:9px;
  transition:border-color 0.2s, background 0.2s;
}
.loc-trigger:hover { border-color:var(--border2); background:var(--surface); }
.loc-trigger.has-location { border-color:var(--accent); color:var(--accent); }
.loc-trigger svg { flex-shrink:0; opacity:0.6; }
.loc-trigger.has-location svg { opacity:1; }

.loc-modal-overlay {
  position:fixed; inset:0; background:rgba(0,0,0,0.6);
  z-index:9999; display:flex; align-items:center; justify-content:center;
  padding:20px; opacity:0; pointer-events:none;
  transition:opacity 0.25s;
}
.loc-modal-overlay.open { opacity:1; pointer-events:all; }

.loc-modal {
  background:var(--surface); border:1px solid var(--border);
  border-radius:16px; width:100%; max-width:560px;
  box-shadow:0 24px 64px rgba(0,0,0,0.3);
  overflow:hidden; transform:translateY(12px) scale(0.98);
  transition:transform 0.25s cubic-bezier(0.16,1,0.3,1);
}
.loc-modal-overlay.open .loc-modal { transform:translateY(0) scale(1); }

.loc-modal-header {
  padding:16px 20px; border-bottom:1px solid var(--border);
  display:flex; align-items:center; justify-content:space-between;
}
.loc-modal-title { font-family:'Syne',sans-serif; font-weight:800; font-size:16px; color:var(--text); }
.loc-modal-close {
  background:none; border:none; cursor:pointer; color:var(--muted);
  padding:4px; border-radius:6px; line-height:1;
  transition:color 0.15s, background 0.15s;
}
.loc-modal-close:hover { color:var(--text); background:var(--bg); }

#loc-map { width:100%; height:300px; }

.loc-modal-footer {
  padding:14px 20px; border-top:1px solid var(--border);
  display:flex; align-items:center; gap:10px;
}
.loc-coords-display {
  flex:1; font-size:12px; color:var(--muted);
  font-family:'DM Mono', monospace;
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.loc-use-btn {
  padding:9px 20px; background:var(--accent); color:#fff;
  border:none; border-radius:var(--radius); font-size:13px;
  font-weight:700; font-family:'Syne',sans-serif; cursor:pointer;
  transition:background 0.2s; white-space:nowrap; flex-shrink:0;
}
.loc-use-btn:hover { background:var(--accent-h); }
.loc-use-btn:disabled { opacity:0.45; cursor:not-allowed; }
.loc-gps-btn {
  padding:9px 14px; background:var(--bg); color:var(--text);
  border:1.5px solid var(--border); border-radius:var(--radius);
  font-size:13px; cursor:pointer; display:flex; align-items:center; gap:6px;
  transition:border-color 0.2s, background 0.2s; flex-shrink:0;
}
.loc-gps-btn:hover { border-color:var(--accent); background:var(--accent-light); color:var(--accent); }
</style>
<link rel="stylesheet" href="../assets/css/main.css"/>
<div class="reg-wrap">
  <div class="reg-modal">

    <!-- Left branding panel -->
    <div class="reg-side">
      <div class="reg-side-brand">Demy<span>'s</span></div>

      <div class="reg-side-content">
        <h2 class="reg-side-title">Join the<br><em>community</em><br>today</h2>
        <p class="reg-side-desc">Buy and sell items locally. Connect with people near you.</p>

        <div class="reg-perk">
          <div class="reg-perk-icon">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="2"/></svg>
          </div>
          Free to list your items
        </div>
        <div class="reg-perk">
          <div class="reg-perk-icon">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          </div>
          Direct chat with buyers & sellers
        </div>
        <div class="reg-perk">
          <div class="reg-perk-icon">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          </div>
          Ratings &amp; verified reviews
        </div>
      </div>

      <div class="reg-side-footer">© <?= date('Y') ?> Demy's Marketplace</div>
    </div>

    <!-- Right form -->
    <div class="reg-form-panel">
      <h1 class="reg-title">Create account</h1>
      <p class="reg-sub">Join the community. Buy and sell with ease.</p>

      <?php if ($errors): ?>
      <div class="reg-errors">
        <?php foreach ($errors as $e): ?>
        <div>
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <?= h($e) ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"/>

        <div class="rfield">
          <label class="rlabel">Email</label>
          <input type="email" name="email" class="rinput" placeholder="you@example.com"
                  value="<?= h($_POST['email'] ?? '') ?>" required/>
        </div>

        <div class="rfield">
          <label class="rlabel">Username</label>
          <input type="text" name="username" class="rinput" placeholder="your_handle"
                  value="<?= h($_POST['username'] ?? '') ?>" required/>
        </div>

        <div class="reg-row">
          <div class="rfield">
            <label class="rlabel">Password</label>
            <input type="password" name="password" class="rinput" placeholder="min. 8 chars" required/>
          </div>
          <div class="rfield">
            <label class="rlabel">Confirm</label>
            <input type="password" name="confirm" class="rinput" placeholder="repeat" required/>
          </div>
        </div>

        <div class="rfield">
          <label class="rlabel">Account type</label>
          <div class="role-toggle">
            <div class="role-opt">
              <input type="radio" id="role-buyer" name="role" value="buyer" <?= (($_POST['role'] ?? 'buyer') === 'buyer') ? 'checked' : '' ?> />
              <label for="role-buyer">
                <span class="role-icon">🛒</span>
                Buyer
              </label>
            </div>
            <div class="role-opt">
              <input type="radio" id="role-seller" name="role" value="seller" <?= (($_POST['role'] ?? '') === 'seller') ? 'checked' : '' ?> />
              <label for="role-seller">
                <span class="role-icon">🏷️</span>
                Seller
              </label>
            </div>
          </div>
        </div>
        <!-- Location picker -->
        <input type="hidden" name="lat" id="loc-lat" />
        <input type="hidden" name="lng" id="loc-lng" />

        <div class="loc-field">
          <label class="loc-label">Your Location <span style="color:var(--muted);font-weight:400;text-transform:none">(optional)</span></label>
          <button type="button" class="loc-trigger" id="loc-open-btn">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
              <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>
            </svg>
            <span id="loc-btn-text">Set your location on map</span>
          </button>
        </div>

        <!-- Map modal -->
        <div class="loc-modal-overlay" id="loc-overlay">
          <div class="loc-modal">
            <div class="loc-modal-header">
              <span class="loc-modal-title">📍 Set your location</span>
              <button type="button" class="loc-modal-close" id="loc-close-btn">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                  <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
              </button>
            </div>
            <div id="loc-map"></div>
            <div class="loc-modal-footer">
              <button type="button" class="loc-gps-btn" id="loc-gps-btn">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                  <circle cx="12" cy="12" r="3"/><path d="M12 2v3m0 14v3M2 12h3m14 0h3"/>
                  <circle cx="12" cy="12" r="8" stroke-dasharray="2 3"/>
                </svg>
                Use GPS
              </button>
              <span class="loc-coords-display" id="loc-coords-text">Click the map to drop a pin</span>
              <button type="button" class="loc-use-btn" id="loc-confirm-btn" disabled>Use this location</button>
            </div>
          </div>
        </div>
        <button type="submit" class="reg-submit">Create Account</button>
      </form>

      <p class="reg-footer">Already have an account? <a href="../login.php">Log in</a></p>
    </div>

  </div>
</div>

<script>
(function () {
  const overlay    = document.getElementById('loc-overlay');
  const openBtn    = document.getElementById('loc-open-btn');
  const closeBtn   = document.getElementById('loc-close-btn');
  const gpsBtn     = document.getElementById('loc-gps-btn');
  const confirmBtn = document.getElementById('loc-confirm-btn');
  const coordsText = document.getElementById('loc-coords-text');
  const btnText    = document.getElementById('loc-btn-text');
  const latInput   = document.getElementById('loc-lat');
  const lngInput   = document.getElementById('loc-lng');
  let map, marker, pendingLat = null, pendingLng = null, pendingLabel = null;

  function initMap() {
    if (map) return;
    // Default center: Philippines
    map = L.map('loc-map').setView([12.8797, 121.7740], 6);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
      maxZoom: 19,
    }).addTo(map);

    // Custom accent-colored pin icon
    const pinIcon = L.divIcon({
      className: '',
      html: `<div style="
        width:28px;height:28px;background:var(--accent);border:3px solid #fff;
        border-radius:50% 50% 50% 0;transform:rotate(-45deg);
        box-shadow:0 3px 12px rgba(232,65,10,0.5);
      "></div>`,
      iconSize: [28, 28],
      iconAnchor: [14, 28],
    });

    map.on('click', function (e) {
      const { lat, lng } = e.latlng;
      setPin(lat, lng);
    });

    // Fix Leaflet tile render when modal opens
    setTimeout(() => map.invalidateSize(), 10);
  }

  async function setPin(lat, lng) {
    pendingLat = lat.toFixed(6);
    pendingLng = lng.toFixed(6);

    if (marker) {
      marker.setLatLng([lat, lng]);
    } else {
      const pinIcon = L.divIcon({
        className: '',
        html: `<div style="
          width:26px;height:26px;background:var(--accent,#e8410a);border:3px solid #fff;
          border-radius:50% 50% 50% 0;transform:rotate(-45deg);
          box-shadow:0 3px 14px rgba(232,65,10,0.55);
        "></div>`,
        iconSize: [26, 26],
        iconAnchor: [13, 26],
      });
      marker = L.marker([lat, lng], { icon: pinIcon, draggable: true }).addTo(map);
      marker.on('dragend', function () {
        const pos = marker.getLatLng();
        setPin(pos.lat, pos.lng);
      });
    }

    // Reverse geocode via your PHP helper
    coordsText.textContent = 'Looking up address…';
    confirmBtn.disabled = true;

    try {
      const res = await fetch(`/src/api/reverse-geocode.php?lat=${pendingLat}&lng=${pendingLng}`);
      const data = await res.json();
      pendingLabel = data.label || `${pendingLat}, ${pendingLng}`;
    } catch {
      pendingLabel = `${pendingLat}, ${pendingLng}`;
    }

    coordsText.textContent = pendingLabel;
    confirmBtn.disabled = false;
  }

  function openModal() {
    overlay.classList.add('open');
    initMap();
    document.body.style.overflow = 'hidden';

    // If coords already saved, re-show pin and stop
    if (latInput.value && lngInput.value) {
      const lat = parseFloat(latInput.value);
      const lng = parseFloat(lngInput.value);
      map.setView([lat, lng], 15);
      setPin(lat, lng);
      return;
    }

    // Auto-request GPS on open
    if (navigator.geolocation) {
      gpsBtn.innerHTML = 'Locating…';
      gpsBtn.disabled = true;
      navigator.geolocation.getCurrentPosition(
        function (pos) {
          const lat = pos.coords.latitude;
          const lng = pos.coords.longitude;
          map.setView([lat, lng], 15);
          setPin(lat, lng);
          gpsBtn.innerHTML = `<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2v3m0 14v3M2 12h3m14 0h3"/><circle cx="12" cy="12" r="8" stroke-dasharray="2 3"/></svg> Use GPS`;
          gpsBtn.disabled = false;
        },
        function () {
          // Silently fall back — user can click manually
          gpsBtn.innerHTML = `<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2v3m0 14v3M2 12h3m14 0h3"/><circle cx="12" cy="12" r="8" stroke-dasharray="2 3"/></svg> Use GPS`;
          gpsBtn.disabled = false;
        },
        { timeout: 8000 }
      );
    }
  }

  function closeModal() {
    overlay.classList.remove('open');
    document.body.style.overflow = '';
  }

  openBtn.addEventListener('click', openModal);
  closeBtn.addEventListener('click', closeModal);
  overlay.addEventListener('click', function (e) {
    if (e.target === overlay) closeModal();
  });

  gpsBtn.addEventListener('click', function () {
    if (!navigator.geolocation) {
      alert('Geolocation is not supported by your browser.');
      return;
    }
    gpsBtn.textContent = 'Locating…';
    gpsBtn.disabled = true;
    navigator.geolocation.getCurrentPosition(
      function (pos) {
        const lat = pos.coords.latitude;
        const lng = pos.coords.longitude;
        map.setView([lat, lng], 15);
        setPin(lat, lng);
        gpsBtn.innerHTML = `<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2v3m0 14v3M2 12h3m14 0h3"/><circle cx="12" cy="12" r="8" stroke-dasharray="2 3"/></svg> Use GPS`;
        gpsBtn.disabled = false;
      },
      function () {
        alert('Could not get your location. Please click on the map instead.');
        gpsBtn.innerHTML = `<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2v3m0 14v3M2 12h3m14 0h3"/><circle cx="12" cy="12" r="8" stroke-dasharray="2 3"/></svg> Use GPS`;
        gpsBtn.disabled = false;
      }
    );
  });

  confirmBtn.addEventListener('click', function () {
    latInput.value = pendingLat;
    lngInput.value = pendingLng;
    openBtn.classList.add('has-location');
    btnText.textContent = `📍 ${pendingLabel}`;
    closeModal();
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
