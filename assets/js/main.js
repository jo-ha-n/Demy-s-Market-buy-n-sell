// ── Demy's — Main JS ──────────────────────────────────────────────────────────
// Shared utilities used by all HTML pages.

// ── Theme ─────────────────────────────────────────────────────────────────────
const html   = document.documentElement;
const stored = localStorage.getItem('demys-theme') || 'light';
html.setAttribute('data-theme', stored);

// ── Session helpers ───────────────────────────────────────────────────────────
function getSession() {
  try { return JSON.parse(localStorage.getItem('demys-session') || 'null'); }
  catch { return null; }
}
function setSession(user) {
  localStorage.setItem('demys-session', JSON.stringify(user));
}
function clearSession() {
  localStorage.removeItem('demys-session');
}

// ── Auth overlay helpers ────────────────────────────────────────────────────
async function fetchAuthCsrf() {
  if (window.authCsrfToken) return window.authCsrfToken;
  try {
    const res = await fetch('../pages/login.php?ajax=1');
    const data = await res.json();
    window.authCsrfToken = data.csrfToken;
    window.authUser = data.user || null;
    return data.csrfToken;
  } catch (err) {
    return null;
  }
}

async function initAuthState() {
  if (getSession()) return;
  try {
    const res = await fetch('../pages/login.php?ajax=1');
    if (!res.ok) return;
    const data = await res.json();
    if (data.user) {
      setSession(data.user);
    }
  } catch (err) {
    // ignore
  }
}

function openAuthOverlay(mode = 'login') {
  const overlay = document.getElementById('authOverlay');
  if (!overlay) return;
  overlay.classList.add('open');
  switchAuthTab(mode);
  fetchAuthCsrf().then(token => {
    if (token) {
      document.getElementById('loginCsrf')?.setAttribute('value', token);
      document.getElementById('registerCsrf')?.setAttribute('value', token);
    }
  });
}

function closeAuthOverlay() {
  const overlay = document.getElementById('authOverlay');
  if (!overlay) return;
  overlay.classList.remove('open');
  clearAuthMessages();
}

function switchAuthTab(mode) {
  const loginTab = document.getElementById('loginTab');
  const registerTab = document.getElementById('registerTab');
  const loginPanel = document.getElementById('loginPanel');
  const registerPanel = document.getElementById('registerPanel');
  const authTitle = document.getElementById('authTitle');
  if (!loginTab || !registerTab || !loginPanel || !registerPanel) return;

  loginTab.classList.toggle('active', mode === 'login');
  registerTab.classList.toggle('active', mode === 'register');
  loginPanel.style.display = mode === 'login' ? 'block' : 'none';
  registerPanel.style.display = mode === 'register' ? 'block' : 'none';
  if (authTitle) authTitle.textContent = mode === 'login' ? 'Log In' : 'Sign Up';
  clearAuthMessages();
}

function clearAuthMessages() {
  const err = document.getElementById('authError');
  if (err) {
    err.style.display = 'none';
    err.textContent = '';
  }
}

async function submitAuthForm(event, mode) {
  event.preventDefault();
  const form = document.getElementById(`${mode}Form`);
  if (!form) return;

  const url = mode === 'login'
    ? '../pages/login.php?ajax=1'
    : '../pages/register.php?ajax=1';

  const data = new FormData(form);
  data.append('ajax', '1');

  const response = await fetch(url, {
    method: 'POST',
    body: data,
  });

  if (!response.ok) {
    showAuthError('Server error. Please try again.');
    return;
  }

  const result = await response.json();
  if (!result.success) {
    showAuthError(result.error || 'Authentication failed.');
    return;
  }

  if (result.user) {
    setSession(result.user);
    renderTopbarNav();
    if (typeof loadHome === 'function') loadHome();
  }

  closeAuthOverlay();
  showFlash('success', result.message || 'Logged in successfully.');
}

function showAuthError(message) {
  const err = document.getElementById('authError');
  if (!err) return;
  err.textContent = message;
  err.style.display = 'block';
}

// ── Wishlist helpers (localStorage) ──────────────────────────────────────────
function getWishlist() {
  try { return JSON.parse(localStorage.getItem('demys-wish') || '[]'); }
  catch { return []; }
}
function isWished(itemID) {
  return getWishlist().includes(itemID);
}
function toggleWish(itemID, btn) {
  const session = getSession();
  if (!session) {
    const path    = window.location.pathname;
    const isPages = path.includes('/pages/');
    const isSrc   = path.includes('/src/');
    window.location.href = (isPages ? '' : isSrc ? '../pages/' : 'pages/') + 'login.php';
    return;
  }
  let list = getWishlist();
  const idx = list.indexOf(itemID);
  if (idx > -1) {
    list.splice(idx, 1);
    if (btn) { btn.textContent = '🤍'; btn.classList.remove('wished'); }
  } else {
    list.push(itemID);
    if (btn) { btn.textContent = '❤️'; btn.classList.add('wished'); }
  }
  localStorage.setItem('demys-wish', JSON.stringify(list));
}
function applyWishState() {
  document.querySelectorAll('.wish-btn[data-item]').forEach(btn => {
    const id = parseInt(btn.dataset.item);
    if (isWished(id)) { btn.textContent = '❤️'; btn.classList.add('wished'); }
    else              { btn.textContent = '🤍'; btn.classList.remove('wished'); }
  });
}

// ── Format helpers ────────────────────────────────────────────────────────────
function formatPrice(p) {
  return '₱' + Number(p).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function timeAgo(datetime) {
  const diff = (Date.now() - new Date(datetime).getTime()) / 1000;
  if (diff < 60)     return 'just now';
  if (diff < 3600)   return Math.floor(diff / 60)    + 'm ago';
  if (diff < 86400)  return Math.floor(diff / 3600)  + 'h ago';
  if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
  return new Date(datetime).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

// ── Flash ─────────────────────────────────────────────────────────────────────
function showFlash(type, msg) {
  const container = document.getElementById('flashContainer');
  if (!container) return;
  const el = document.createElement('div');
  el.className = `flash flash--${type}`;
  el.innerHTML = `${msg} <button onclick="this.parentElement.remove()" class="flash-close">✕</button>`;
  container.innerHTML = '';
  container.appendChild(el);
  setTimeout(() => el.remove(), 4000);
}
// PHP-rendered flash auto-dismiss
setTimeout(() => document.getElementById('flashMsg')?.remove(), 4000);

// ── Item card template ────────────────────────────────────────────────────────
function itemCard(item, base = '') {
  const img = item.images?.[0]
    ? `<img class="item-card-img" src="${item.images[0]}" alt="${item.title}" loading="lazy"/>`
    : `<div class="item-card-img" style="height:100%;display:flex;align-items:center;justify-content:center;font-size:40px;background:var(--surface2)">📦</div>`;

  const session = getSession();
  const isOwner = session && session.userID === item.sellerID;

  const wishBtn = !isOwner ? `
    <button class="wish-btn ${isWished(item.itemID) ? 'wished' : ''}"
      data-item="${item.itemID}"
      onclick="event.preventDefault();toggleWish(${item.itemID},this)"
      style="position:absolute;top:10px;right:10px;width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,0.92);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:14px;box-shadow:var(--shadow-sm)">
      ${isWished(item.itemID) ? '❤️' : '🤍'}
    </button>` : '';

  return `
    <div style="position:relative">
      <a href="${base}pages/item.html?id=${item.itemID}" class="card item-card">
        <div class="item-card-img-wrap">${img}</div>
        <div class="card-body">
          <p class="item-card-title">${item.title}</p>
          <p class="item-card-price">${formatPrice(item.price)}</p>
          <div class="item-card-meta">
            <span class="badge">${item.category_name}</span>
            <span>·</span><span>${timeAgo(item.created_at)}</span>
          </div>
        </div>
      </a>
      ${wishBtn}
    </div>`;
}

// ── Topbar nav injection (HTML pages only) ────────────────────────────────────
function renderTopbarNav() {
  const nav = document.getElementById('topbarNav');
  if (!nav || nav.dataset.serverRendered === 'true') return;

  const session = getSession();
  const path    = window.location.pathname;
  const rootPath = path.includes('/pages/')
    ? path.slice(0, path.indexOf('/pages/'))
    : path.includes('/src/')
      ? path.slice(0, path.indexOf('/src/'))
      : '';
  const base = `${rootPath}/pages/`;
  const root = `${rootPath}/src/`;

  const themeBtn = `
    <button class="theme-toggle" id="themeToggle" title="Toggle theme">
      <svg class="icon-sun" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="5"/>
        <line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
        <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
      </svg>
      <svg class="icon-moon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
      </svg>
    </button>`;

  if (session) {
    nav.innerHTML = `
      <a href="${base}sell.php" class="btn-accent">+ Sell</a>
      <a href="${base}messages.php" class="topbar-icon" title="Messages">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
      </a>
      <a href="${base}wishlist.html" class="topbar-icon" title="Wishlist">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
        </svg>
      </a>
      <div class="topbar-profile-wrap">
        <button class="topbar-avatar" id="profileBtn">
          ${session.username[0].toUpperCase()}
        </button>
        <div class="profile-dropdown" id="profileDropdown">
          <div class="profile-dropdown-header">
            <span class="pd-name">${session.username}</span>
            <span class="pd-role">${session.role}</span>
          </div>
          <a href="${base}profile.php">My Profile</a>
          <a href="${base}my-listings.html">My Listings</a>
          <a href="${base}transactions.html">Transactions</a>
          <div class="pd-divider"></div>
          <a href="#" class="pd-danger" onclick="logOut('${root}')">Sign Out</a>
        </div>
      </div>
      ${themeBtn}`;

    const pb = document.getElementById('profileBtn');
    const pd = document.getElementById('profileDropdown');
    pb?.addEventListener('click', e => { e.stopPropagation(); pd.classList.toggle('open'); });
    document.addEventListener('click', () => pd?.classList.remove('open'));
  } else {
    nav.innerHTML = `
      <a href="${base}login.php" class="btn-ghost">Log In</a>
      <a href="${base}register.php" class="btn-accent">Sign Up</a>
      ${themeBtn}`;
  }

  // Re-bind theme toggle after injection
  document.getElementById('themeToggle')?.addEventListener('click', () => {
    const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    localStorage.setItem('demys-theme', next);
  });
}

function logOut(root = '') {
  clearSession();
  showFlash('success', 'Signed out.');
  setTimeout(() => window.location.href = root + 'index.html', 600);
}

// ── PHP page: profile dropdown bind ──────────────────────────────────────────
// (for header.php which renders its own nav HTML)
document.getElementById('profileBtn')?.addEventListener('click', e => {
  e.stopPropagation();
  document.getElementById('profileDropdown')?.classList.toggle('open');
});

// ── Init nav on HTML pages ────────────────────────────────────────────────────
initAuthState().then(renderTopbarNav);
