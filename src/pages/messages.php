<?php
$pageTitle = "Messages — Demy's";
require_once __DIR__ . '/../includes/header.php';
requireLogin();
?>
  <style>
    /* ── Design Tokens (mirrors main.css) ──────────────────────── */
    :root {
      --bg:            #f5f3ef;
      --bg2:           #eeeae2;
      --surface:       #eeebe4;
      --surface2:      #e5e0d6;
      --border:        #d9d3c5;
      --border2:       #ccc6b6;
      --text:          #1a1814;
      --text-2:        #3d3a34;
      --muted:         #6e6a62;
      --muted2:        #9b9690;
      --accent:        #e8410a;
      --accent-h:      #c93508;
      --accent-light:  #fdeee8;
      --success:       #1a8a4a;
      --danger:        #c0192b;
      --danger-light:  #fce8eb;
      --radius:        10px;
      --radius-lg:     16px;
      --shadow-sm:     0 1px 4px rgba(0,0,0,0.07);
      --shadow:        0 2px 12px rgba(0,0,0,0.09);
      --shadow-lg:     0 6px 28px rgba(0,0,0,0.13);
      --topbar-h:      60px;
    }
    [data-theme="dark"] {
      --bg:            #111010;
      --bg2:           #181715;
      --surface:       #1c1b19;
      --surface2:      #232220;
      --border:        #2e2c27;
      --border2:       #3a3832;
      --text:          #f0ede6;
      --text-2:        #ccc8bf;
      --muted:         #8a8680;
      --muted2:        #605d57;
      --accent:        #f05a28;
      --accent-h:      #ff7144;
      --accent-light:  #2a1a12;
      --danger-light:  #250b0f;
      --shadow-sm:     0 1px 4px rgba(0,0,0,0.3);
      --shadow:        0 2px 14px rgba(0,0,0,0.4);
      --shadow-lg:     0 6px 30px rgba(0,0,0,0.55);
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { scroll-behavior: smooth; }
    body {
      font-family: 'DM Sans', sans-serif;
      background: var(--bg);
      color: var(--text);
      height: 100vh;
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }
    a { color: inherit; text-decoration: none; }
    img { display: block; max-width: 100%; }
    button { cursor: pointer; font-family: inherit; }
    input, textarea { font-family: inherit; }

    /* ── Topbar ───────────────────────────────────────────────── */
    .topbar {
      position: relative;
      height: var(--topbar-h);
      z-index: 200;
      background: color-mix(in srgb, var(--bg) 92%, transparent);
      backdrop-filter: blur(14px);
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 0 28px;
      flex-shrink: 0;
    }
    .topbar-logo {
      display: flex;
      align-items: center;
      flex-shrink: 0;
    }
    .topbar-logo img {
      height: 38px;
      width: auto;
      object-fit: contain;
      transition: opacity 0.2s;
    }
    /* Show black logo in light mode, white logo in dark mode */
    [data-theme="light"] .logo-light { display: block; }
    [data-theme="light"] .logo-dark  { display: none; }
    [data-theme="dark"]  .logo-dark  { display: block; }
    [data-theme="dark"]  .logo-light { display: none; }

    .topbar-logo:hover img { opacity: 0.75; }
    .topbar-search {
      flex: 1;
      max-width: 460px;
      display: flex;
      align-items: center;
      gap: 8px;
      background: var(--surface);
      border: 1.5px solid var(--border);
      border-radius: var(--radius);
      padding: 7px 12px;
      transition: border-color 0.2s;
    }
    .topbar-search:focus-within { border-color: var(--accent); }
    .topbar-search svg { color: var(--muted); flex-shrink: 0; }
    .topbar-search input {
      flex: 1; border: none; background: transparent;
      font-size: 14px; color: var(--text); outline: none;
    }
    .topbar-search input::placeholder { color: var(--muted2); }
    .topbar-nav {
      margin-left: auto;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .topbar-icon {
      width: 36px; height: 36px;
      display: flex; align-items: center; justify-content: center;
      border-radius: 8px;
      color: var(--muted);
      transition: background 0.2s, color 0.2s;
    }
    .topbar-icon:hover { background: var(--surface2); color: var(--text); }
    .topbar-avatar {
      width: 34px; height: 34px;
      border-radius: 50%;
      background: var(--accent);
      color: #fff;
      border: none;
      font-family: 'Syne', sans-serif;
      font-weight: 700;
      font-size: 14px;
      display: flex; align-items: center; justify-content: center;
    }
    .btn-accent {
      display: inline-flex; align-items: center; justify-content: center;
      background: var(--accent); color: #fff;
      border: none; border-radius: var(--radius);
      padding: 8px 16px; font-size: 13.5px; font-weight: 600;
      font-family: 'Syne', sans-serif; letter-spacing: -0.2px;
      transition: background 0.2s, transform 0.15s;
      white-space: nowrap;
    }
    .btn-accent:hover { background: var(--accent-h); transform: translateY(-1px); }
    .btn-ghost {
      display: inline-flex; align-items: center; justify-content: center;
      background: transparent; color: var(--text);
      border: 1.5px solid var(--border); border-radius: var(--radius);
      padding: 7px 15px; font-size: 13.5px; font-weight: 500;
      transition: background 0.2s, border-color 0.2s;
    }
    .btn-ghost:hover { background: var(--surface2); border-color: var(--border2); }
    .theme-toggle {
      width: 36px; height: 36px;
      border: 1.5px solid var(--border); border-radius: 8px;
      background: transparent; color: var(--muted);
      display: flex; align-items: center; justify-content: center;
      transition: background 0.2s, color 0.2s;
    }
    .theme-toggle:hover { background: var(--surface2); color: var(--text); }
    [data-theme="light"] .icon-moon { display: block; }
    [data-theme="light"] .icon-sun  { display: none; }
    [data-theme="dark"]  .icon-sun  { display: block; }
    [data-theme="dark"]  .icon-moon { display: none; }

    /* ── Messages Layout ──────────────────────────────────────── */
    .msg-app {
      flex: 1;
      display: flex;
      overflow: hidden;
    }

    /* ── Sidebar ──────────────────────────────────────────────── */
    .msg-sidebar {
      width: 320px;
      flex-shrink: 0;
      border-right: 1px solid var(--border);
      display: flex;
      flex-direction: column;
      background: var(--surface);
      overflow: hidden;
    }
    .sidebar-header {
      padding: 20px 20px 16px;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .sidebar-title {
      font-family: 'Syne', sans-serif;
      font-weight: 800;
      font-size: 18px;
      letter-spacing: -0.5px;
    }
    .sidebar-count {
      font-size: 11px;
      font-weight: 700;
      background: var(--accent);
      color: #fff;
      border-radius: 20px;
      padding: 2px 8px;
      font-family: 'Syne', sans-serif;
    }
    .sidebar-search {
      padding: 12px 16px;
      border-bottom: 1px solid var(--border);
    }
    .sidebar-search-inner {
      display: flex; align-items: center; gap: 8px;
      background: var(--bg);
      border: 1.5px solid var(--border);
      border-radius: var(--radius);
      padding: 7px 11px;
      transition: border-color 0.2s;
    }
    .sidebar-search-inner:focus-within { border-color: var(--accent); }
    .sidebar-search-inner input {
      flex: 1; border: none; background: transparent;
      font-size: 13px; color: var(--text); outline: none;
    }
    .sidebar-search-inner input::placeholder { color: var(--muted2); }
    .conv-list {
      flex: 1;
      overflow-y: auto;
      scroll-behavior: smooth;
    }
    .conv-list::-webkit-scrollbar { width: 4px; }
    .conv-list::-webkit-scrollbar-track { background: transparent; }
    .conv-list::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 4px; }
    .conv-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 14px 16px;
      border-bottom: 1px solid var(--border);
      cursor: pointer;
      transition: background 0.15s;
      position: relative;
    }
    .conv-item:hover { background: var(--surface2); }
    .conv-item.active {
      background: var(--accent-light);
      border-left: 3px solid var(--accent);
      padding-left: 13px;
    }
    .conv-item.active .conv-last { color: var(--accent); }
    .conv-avatar {
      width: 44px; height: 44px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--accent), var(--accent-h));
      color: #fff;
      display: flex; align-items: center; justify-content: center;
      font-family: 'Syne', sans-serif;
      font-weight: 800;
      font-size: 16px;
      flex-shrink: 0;
      position: relative;
    }
    .conv-avatar.online::after {
      content: '';
      position: absolute;
      bottom: 1px; right: 1px;
      width: 10px; height: 10px;
      background: var(--success);
      border-radius: 50%;
      border: 2px solid var(--surface);
    }
    .conv-avatar-colors {
      background: linear-gradient(135deg, #6b48ff, #9b7fff);
    }
    .conv-avatar-green {
      background: linear-gradient(135deg, #1a8a4a, #2db56b);
    }
    .conv-avatar-blue {
      background: linear-gradient(135deg, #0070f3, #00aaff);
    }
    .conv-info { flex: 1; min-width: 0; }
    .conv-name {
      font-weight: 600; font-size: 14px;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
      margin-bottom: 3px;
    }
    .conv-last {
      font-size: 12.5px; color: var(--muted);
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .conv-meta {
      display: flex; flex-direction: column; align-items: flex-end; gap: 4px;
      flex-shrink: 0;
    }
    .conv-time { font-size: 11px; color: var(--muted2); }
    .conv-unread {
      width: 18px; height: 18px;
      background: var(--accent);
      color: #fff;
      border-radius: 50%;
      font-size: 10px;
      font-weight: 700;
      display: flex; align-items: center; justify-content: center;
    }
    .conv-item-tag {
      font-size: 10px;
      background: var(--accent-light);
      color: var(--accent);
      border-radius: 6px;
      padding: 1px 6px;
      font-weight: 600;
      margin-left: 4px;
    }
    .empty-convs {
      padding: 48px 20px;
      text-align: center;
      color: var(--muted);
    }
    .empty-convs .empty-icon { font-size: 40px; margin-bottom: 12px; }
    .empty-convs h3 { font-size: 15px; color: var(--text); margin-bottom: 6px; }
    .empty-convs p { font-size: 13px; }

    /* ── Chat Panel ───────────────────────────────────────────── */
    .msg-main {
      flex: 1;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      background: var(--bg);
    }
    .chat-header {
      padding: 0 28px;
      height: 64px;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      gap: 14px;
      background: color-mix(in srgb, var(--surface) 80%, transparent);
      backdrop-filter: blur(8px);
      flex-shrink: 0;
    }
    .chat-header-avatar {
      width: 40px; height: 40px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--accent), var(--accent-h));
      color: #fff;
      display: flex; align-items: center; justify-content: center;
      font-family: 'Syne', sans-serif;
      font-weight: 800;
      font-size: 15px;
      position: relative;
      flex-shrink: 0;
    }
    .chat-header-avatar.online::after {
      content: '';
      position: absolute;
      bottom: 1px; right: 1px;
      width: 10px; height: 10px;
      background: var(--success);
      border-radius: 50%;
      border: 2px solid var(--surface);
    }
    .chat-header-info { flex: 1; }
    .chat-header-name {
      font-family: 'Syne', sans-serif;
      font-weight: 700;
      font-size: 15px;
      letter-spacing: -0.3px;
    }
    .chat-header-status {
      font-size: 12px;
      color: var(--success);
      display: flex; align-items: center; gap: 5px;
    }
    .chat-header-status::before {
      content: '';
      width: 6px; height: 6px;
      background: var(--success);
      border-radius: 50%;
      display: inline-block;
    }
    .chat-header-status.offline { color: var(--muted); }
    .chat-header-status.offline::before { background: var(--muted2); }
    .chat-header-actions { display: flex; gap: 8px; }
    .icon-btn {
      width: 36px; height: 36px;
      border: 1.5px solid var(--border); border-radius: 8px;
      background: transparent; color: var(--muted);
      display: flex; align-items: center; justify-content: center;
      transition: background 0.2s, color 0.2s, border-color 0.2s;
    }
    .icon-btn:hover { background: var(--surface2); color: var(--text); border-color: var(--border2); }
    .icon-btn.danger:hover { background: var(--danger-light); color: var(--danger); border-color: var(--danger); }

    /* Item reference card in header */
    .chat-item-ref {
      display: flex; align-items: center; gap: 8px;
      padding: 6px 12px;
      background: var(--surface2);
      border: 1px solid var(--border);
      border-radius: 20px;
      font-size: 12px;
      color: var(--muted);
      transition: background 0.2s;
      cursor: pointer;
      max-width: 220px;
    }
    .chat-item-ref:hover { background: var(--accent-light); color: var(--accent); }
    .chat-item-ref-img {
      width: 24px; height: 24px;
      border-radius: 5px;
      background: var(--border);
      overflow: hidden;
      flex-shrink: 0;
    }
    .chat-item-ref-name {
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
      font-weight: 500;
    }

    /* ── Chat body ────────────────────────────────────────────── */
    .chat-body {
      flex: 1;
      overflow-y: auto;
      padding: 24px 28px;
      display: flex;
      flex-direction: column;
      gap: 4px;
      scroll-behavior: smooth;
    }
    .chat-body::-webkit-scrollbar { width: 4px; }
    .chat-body::-webkit-scrollbar-track { background: transparent; }
    .chat-body::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 4px; }

    /* Date separator */
    .msg-date-sep {
      display: flex; align-items: center; gap: 12px;
      margin: 16px 0 8px;
    }
    .msg-date-sep::before, .msg-date-sep::after {
      content: ''; flex: 1; height: 1px; background: var(--border);
    }
    .msg-date-sep span {
      font-size: 11px; color: var(--muted2);
      text-transform: uppercase; letter-spacing: 0.06em;
      font-weight: 600; white-space: nowrap;
    }

    /* Bubbles */
    .msg-row {
      display: flex;
      align-items: flex-end;
      gap: 8px;
      margin-bottom: 2px;
      animation: msgIn 0.2s ease;
    }
    @keyframes msgIn {
      from { opacity: 0; transform: translateY(8px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    .msg-row.me { flex-direction: row-reverse; }
    .msg-row.them { flex-direction: row; }

    .bubble-avatar {
      width: 28px; height: 28px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--accent), var(--accent-h));
      color: #fff;
      display: flex; align-items: center; justify-content: center;
      font-family: 'Syne', sans-serif;
      font-weight: 800; font-size: 10px;
      flex-shrink: 0;
      margin-bottom: 2px;
    }
    .bubble-avatar.green { background: linear-gradient(135deg, #1a8a4a, #2db56b); }
    .bubble-avatar.purple { background: linear-gradient(135deg, #6b48ff, #9b7fff); }
    .msg-row.consecutive .bubble-avatar { visibility: hidden; }

    .bubble-wrap {
      max-width: 68%;
      display: flex;
      flex-direction: column;
      gap: 2px;
    }
    .msg-row.me .bubble-wrap { align-items: flex-end; }
    .msg-row.them .bubble-wrap { align-items: flex-start; }

    .bubble {
      position: relative;
      padding: 10px 14px;
      border-radius: 18px;
      font-size: 14px;
      line-height: 1.55;
      word-break: break-word;
      cursor: pointer;
      transition: filter 0.15s;
    }
    .bubble:hover { filter: brightness(0.95); }
    .bubble:hover .bubble-actions { opacity: 1; pointer-events: all; }

    .msg-row.me .bubble {
      background: var(--accent);
      color: #fff;
      border-bottom-right-radius: 5px;
    }
    .msg-row.me .bubble.consecutive { border-top-right-radius: 5px; }
    .msg-row.them .bubble {
      background: var(--surface);
      color: var(--text);
      border: 1px solid var(--border);
      border-bottom-left-radius: 5px;
    }
    .msg-row.them .bubble.consecutive { border-top-left-radius: 5px; }

    /* Bubble action tray (edit/delete) */
    .bubble-actions {
      position: absolute;
      top: -32px;
      display: flex;
      gap: 4px;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.15s;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 20px;
      padding: 4px 6px;
      box-shadow: var(--shadow);
      white-space: nowrap;
      z-index: 10;
    }
    .msg-row.me .bubble-actions { right: 0; }
    .msg-row.them .bubble-actions { left: 0; }

    .ba-btn {
      width: 24px; height: 24px;
      border: none; background: transparent;
      border-radius: 6px;
      display: flex; align-items: center; justify-content: center;
      color: var(--muted);
      transition: background 0.15s, color 0.15s;
      font-size: 12px;
    }
    .ba-btn:hover { background: var(--surface2); color: var(--text); }
    .ba-btn.del:hover { color: var(--danger); }

    .bubble-time {
      font-size: 10.5px;
      color: var(--muted2);
      padding: 0 4px;
      display: flex; align-items: center; gap: 5px;
    }
    .msg-row.me .bubble-time { justify-content: flex-end; }

    .tick {
      display: inline-flex; align-items: center;
      color: rgba(255,255,255,0.6);
    }
    .tick.read { color: #7dd3f5; }

    .edited-badge {
      font-size: 10px; color: var(--muted2); font-style: italic;
      margin-left: 4px;
    }

    /* Typing indicator */
    .typing-indicator {
      display: flex; align-items: center; gap: 8px;
      padding: 8px 0 4px;
    }
    .typing-dots {
      display: flex; gap: 3px; align-items: center;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 8px 14px;
    }
    .typing-dots span {
      width: 7px; height: 7px;
      background: var(--muted2);
      border-radius: 50%;
      animation: typingBounce 1.2s infinite ease-in-out;
    }
    .typing-dots span:nth-child(2) { animation-delay: 0.2s; }
    .typing-dots span:nth-child(3) { animation-delay: 0.4s; }
    @keyframes typingBounce {
      0%, 60%, 100% { transform: translateY(0); }
      30% { transform: translateY(-5px); }
    }

    /* ── Input area ───────────────────────────────────────────── */
    .chat-input-area {
      padding: 16px 28px 20px;
      border-top: 1px solid var(--border);
      background: var(--surface);
      flex-shrink: 0;
    }
    .chat-input-wrap {
      display: flex;
      align-items: flex-end;
      gap: 10px;
      background: var(--bg);
      border: 1.5px solid var(--border);
      border-radius: 20px;
      padding: 8px 8px 8px 16px;
      transition: border-color 0.2s, box-shadow 0.2s;
    }
    .chat-input-wrap:focus-within {
      border-color: var(--accent);
      box-shadow: 0 0 0 3px rgba(232,65,10,0.1);
    }
    .chat-textarea {
      flex: 1;
      border: none; background: transparent;
      font-size: 14px; color: var(--text); outline: none;
      resize: none;
      max-height: 120px;
      line-height: 1.5;
      padding: 4px 0;
    }
    .chat-textarea::placeholder { color: var(--muted2); }
    .input-actions { display: flex; gap: 6px; align-items: flex-end; }
    .attach-btn {
      width: 36px; height: 36px;
      border: none; background: transparent;
      color: var(--muted); border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      transition: color 0.15s, background 0.15s;
    }
    .attach-btn:hover { color: var(--text); background: var(--surface2); }
    .send-btn {
      width: 38px; height: 38px;
      border-radius: 50%;
      background: var(--accent); color: #fff; border: none;
      display: flex; align-items: center; justify-content: center;
      transition: background 0.15s, transform 0.15s;
      flex-shrink: 0;
    }
    .send-btn:hover { background: var(--accent-h); transform: scale(1.06); }
    .send-btn:disabled { background: var(--border2); cursor: not-allowed; transform: none; }

    /* Edit mode banner */
    .edit-banner {
      display: none;
      background: var(--accent-light);
      border-top: 1px solid rgba(232,65,10,0.2);
      padding: 8px 16px;
      font-size: 12.5px;
      color: var(--accent);
      align-items: center;
      gap: 10px;
    }
    .edit-banner.visible { display: flex; }
    .edit-banner-text { flex: 1; }
    .edit-cancel {
      border: none; background: transparent;
      color: var(--accent); font-size: 18px; line-height: 1;
      padding: 0 4px;
    }

    /* ── Empty state (no convo selected) ─────────────────────── */
    .no-chat {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 16px;
      color: var(--muted);
      padding: 40px;
      text-align: center;
    }
    .no-chat-icon {
      width: 80px; height: 80px;
      background: var(--surface2);
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 36px;
    }
    .no-chat h2 {
      font-family: 'Syne', sans-serif;
      font-size: 20px; color: var(--text-2);
    }
    .no-chat p { font-size: 14px; max-width: 260px; line-height: 1.6; }

    /* ── Delete confirm overlay ───────────────────────────────── */
    .overlay {
      display: none;
      position: fixed; inset: 0;
      background: rgba(0,0,0,0.55);
      backdrop-filter: blur(4px);
      z-index: 500;
      align-items: center; justify-content: center;
    }
    .overlay.open { display: flex; animation: overlayIn 0.2s ease; }
    @keyframes overlayIn { from { opacity: 0; } to { opacity: 1; } }
    .modal {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      padding: 28px 32px;
      max-width: 400px; width: 90%;
      box-shadow: var(--shadow-lg);
      animation: modalIn 0.25s cubic-bezier(0.16,1,0.3,1);
    }
    @keyframes modalIn {
      from { opacity: 0; transform: translateY(16px) scale(0.96); }
      to   { opacity: 1; transform: translateY(0) scale(1); }
    }
    .modal h3 {
      font-family: 'Syne', sans-serif;
      font-size: 17px; margin-bottom: 8px;
    }
    .modal p { font-size: 13.5px; color: var(--muted); margin-bottom: 24px; line-height: 1.6; }
    .modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
    .btn-danger {
      background: var(--danger); color: #fff; border: none;
      border-radius: var(--radius); padding: 9px 18px;
      font-size: 13.5px; font-weight: 600;
      font-family: 'Syne', sans-serif;
      transition: opacity 0.2s;
    }
    .btn-danger:hover { opacity: 0.88; }

    /* ── Responsive ───────────────────────────────────────────── */
    @media (max-width: 720px) {
      .msg-sidebar { width: 100%; display: none; }
      .msg-sidebar.mobile-open { display: flex; position: absolute; inset: var(--topbar-h) 0 0 0; z-index: 100; }
      .msg-main.hidden { display: none; }
      .chat-header-back { display: flex !important; }
    }
    .chat-header-back { display: none; }

    /* ── Flash ────────────────────────────────────────────────── */
    #flashContainer {
      position: fixed; bottom: 24px; right: 24px;
      z-index: 999; display: flex; flex-direction: column; gap: 8px;
    }
    .flash {
      display: flex; align-items: center; gap: 10px;
      padding: 12px 16px; border-radius: var(--radius);
      font-size: 13.5px; box-shadow: var(--shadow-lg);
      animation: flashIn 0.3s ease;
    }
    @keyframes flashIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
    .flash--success { background: var(--surface); border: 1px solid var(--success); color: var(--success); }
    .flash--info    { background: var(--surface); border: 1px solid var(--border2); color: var(--text); }
    .flash--danger  { background: var(--danger-light); border: 1px solid var(--danger); color: var(--danger); }
    .flash-close { background: none; border: none; color: inherit; opacity: 0.6; font-size: 16px; margin-left: auto; }
  </style>
</head>
<body>

<!-- ── Topbar ────────────────────────────────────────────────────── -->
<header class="topbar">
  <a href="../../index.php" class="topbar-logo" title="Demy's — Home">
    <!-- Black logo for light mode -->
    <img class="logo-light" src="../../assets/img/logo-black.png" alt="Demy's"/>
    <!-- White logo for dark mode -->
    <img class="logo-dark"  src="../../assets/img/logo-white.png" alt="Demy's"/>
  </a>

  <form class="topbar-search" action="/src/pages/search.php" method="GET">
    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
    </svg>
    <input type="text" name="q" id="topbarQ" placeholder="Search deals…"
           value="<?= h($_GET['q'] ?? '') ?>"/>
  </form>

  <nav class="topbar-nav">
    <?php if ($user): ?>
      <a href="../pages/sell.php" class="btn-accent">+ Sell</a>
      <a href="../pages/messages.php" class="topbar-icon" title="Messages" style="color:var(--accent)">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
      </a>
      <a href="../config/wishlist.php" class="topbar-icon" title="Wishlist">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
        </svg>
      </a>
      <div class="topbar-profile-wrap" style="position:relative">
        <button class="topbar-avatar" id="profileBtn" aria-label="Open profile menu">
          <?= strtoupper(substr($user['username'], 0, 1)) ?>
        </button>
        <div class="profile-dropdown" id="profileDropdown"
             style="display:none;position:absolute;top:calc(100% + 8px);right:0;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-lg);min-width:150px;z-index:300;overflow:hidden">
          <a href="../pages/profile.php"
             style="display:block;padding:10px 16px;font-size:13.5px;transition:background 0.15s"
             onmouseover="this.style.background='var(--surface2)'" onmouseout="this.style.background=''">My Profile</a>
          <div style="height:1px;background:var(--border)"></div>
          <a href="../pages/logout.php"
             style="display:block;padding:10px 16px;font-size:13.5px;color:var(--danger);transition:background 0.15s"
             onmouseover="this.style.background='var(--danger-light)'" onmouseout="this.style.background=''">Sign Out</a>
        </div>
      </div>
    <?php else: ?>
      <a href="../pages/login.php" class="btn-ghost">Log In</a>
      <a href="../pages/register.php" class="btn-accent">Sign Up</a>
    <?php endif; ?>

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
    </button>
  </nav>
</header>

<!-- ── Main Layout ───────────────────────────────────────────────── -->
<div class="msg-app">

  <!-- Sidebar: Conversation list -->
  <aside class="msg-sidebar" id="msgSidebar">
    <div class="sidebar-header">
      <span class="sidebar-title">Messages</span>
      <span class="sidebar-count" id="unreadCount">3</span>
    </div>
    <div class="sidebar-search">
      <div class="sidebar-search-inner">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
        </svg>
        <input type="text" id="convSearch" placeholder="Search conversations…" oninput="filterConvs(this.value)"/>
      </div>
    </div>
    <div class="conv-list" id="convList"></div>
  </aside>

  <!-- Main: Chat -->
  <div class="msg-main" id="msgMain">

    <!-- No conversation selected -->
    <div class="no-chat" id="noChat">
      <div class="no-chat-icon">💬</div>
      <h2>Your Messages</h2>
      <p>Select a conversation to start chatting, or reach out to a seller from any listing.</p>
    </div>

    <!-- Active conversation -->
    <div id="chatView" style="display:none;flex-direction:column;flex:1;overflow:hidden">
      <div class="chat-header" id="chatHeader">
        <button class="icon-btn chat-header-back" id="backBtn" onclick="goBack()">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
            <path d="M19 12H5M12 5l-7 7 7 7"/>
          </svg>
        </button>
        <div class="chat-header-avatar online" id="chatAvatar">?</div>
        <div class="chat-header-info">
          <div class="chat-header-name" id="chatName">—</div>
          <div class="chat-header-status" id="chatStatus">Online</div>
        </div>
        <div id="chatItemRef"></div>
        <div class="chat-header-actions">
          <button class="icon-btn" title="View profile" onclick="showFlash('info','Profile view coming soon!')">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
            </svg>
          </button>
          <button class="icon-btn danger" title="Block / report" onclick="showFlash('danger','Feature coming soon.')">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
            </svg>
          </button>
        </div>
      </div>

      <div class="chat-body" id="chatBody"></div>

      <!-- Edit banner -->
      <div class="edit-banner" id="editBanner">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
          <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
        </svg>
        <span class="edit-banner-text" id="editBannerText">Editing message…</span>
        <button class="edit-cancel" onclick="cancelEdit()">✕</button>
      </div>

      <div class="chat-input-area">
        <div class="chat-input-wrap" id="inputWrap">
          <textarea class="chat-textarea" id="msgInput" placeholder="Type a message…" rows="1"
            onkeydown="handleKey(event)" oninput="autoResize(this)"></textarea>
          <div class="input-actions">
            <button class="attach-btn" title="Attach photo" onclick="showFlash('info','File attachment coming soon!')">
              <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
              </svg>
            </button>
            <button class="send-btn" id="sendBtn" onclick="sendMessage()" disabled>
              <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <line x1="22" y1="2" x2="11" y2="13"/>
                <polygon points="22 2 15 22 11 13 2 9 22 2"/>
              </svg>
            </button>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- Flash -->
<div id="flashContainer"></div>

<!-- Delete modal -->
<div class="overlay" id="deleteOverlay">
  <div class="modal">
    <h3>Delete message?</h3>
    <p>This message will be permanently removed from the conversation for everyone.</p>
    <div class="modal-actions">
      <button class="btn-ghost" onclick="closeDeleteModal()">Cancel</button>
      <button class="btn-danger" onclick="confirmDelete()">Delete</button>
    </div>
  </div>
</div>

<script>
// ── Theme ──────────────────────────────────────────────────────────
const html = document.documentElement;
const stored = localStorage.getItem('demys-theme') || 'light';
html.setAttribute('data-theme', stored);

document.getElementById('themeToggle').addEventListener('click', () => {
  const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  html.setAttribute('data-theme', next);
  localStorage.setItem('demys-theme', next);
});

// ── Profile dropdown ───────────────────────────────────────────────
const profileBtn = document.getElementById('profileBtn');
const profileDropdown = document.getElementById('profileDropdown');
if (profileBtn && profileDropdown) {
  profileBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    const isOpen = profileDropdown.style.display === 'block';
    profileDropdown.style.display = isOpen ? 'none' : 'block';
  });
  document.addEventListener('click', () => {
    profileDropdown.style.display = 'none';
  });
}

// ── Data ───────────────────────────────────────────────────────────
// NOTE: In production, replace ME and CONVOS with real data from PHP/API.
const ME = {
  userID: <?= (int)$user['userID'] ?>,
  username: <?= json_encode($user['username']) ?>
};

const CONVOS = [
  {
    id: 'conv-001',
    other: { id: 2, name: 'lawrence', avatarClass: '' },
    online: true,
    unread: 2,
    itemRef: { id: 1, title: 'Samsung Galaxy A54', price: '₱12,500' },
    messages: [
      { id: 1, senderID: 2, text: 'Hi! Is the Samsung phone still available?', ts: '2025-05-20T10:02:00', read: true },
      { id: 2, senderID: 1, text: 'Yes it is! You can pick it up anytime this week.', ts: '2025-05-20T10:05:00', read: true },
      { id: 3, senderID: 2, text: 'Can we meet in Indang town proper?', ts: '2025-05-20T10:07:00', read: true },
      { id: 4, senderID: 1, text: "Sure! How about Saturday morning? I'm free from 9am onwards.", ts: '2025-05-21T09:15:00', read: true },
      { id: 5, senderID: 2, text: "Saturday works perfectly! See you at 9am then. I'll bring cash.", ts: '2025-05-21T09:20:00', read: false },
      { id: 6, senderID: 2, text: "Also, can you hold it for me until then?", ts: '2025-05-21T09:21:00', read: false },
    ]
  },
  {
    id: 'conv-002',
    other: { id: 6, name: 'mara', avatarClass: 'green' },
    online: false,
    unread: 0,
    itemRef: { id: 4, title: 'Honda Beat 2021', price: '₱58,000' },
    messages: [
      { id: 7, senderID: 1, text: 'Hello, is the Honda Beat still for sale?', ts: '2025-05-18T14:30:00', read: true },
      { id: 8, senderID: 6, text: 'Yes! Complete papers pa siya, LTO registered.', ts: '2025-05-18T14:45:00', read: true },
      { id: 9, senderID: 1, text: 'Magkano lowest price mo?', ts: '2025-05-18T15:00:00', read: true },
      { id: 10, senderID: 6, text: '55k last na talaga, bagong gulong pa yan.', ts: '2025-05-18T15:10:00', read: true },
      { id: 11, senderID: 1, text: 'Sige isipin ko muna. Salamat!', ts: '2025-05-18T15:11:00', read: true },
    ]
  },
  {
    id: 'conv-003',
    other: { id: 3, name: 'james', avatarClass: 'purple' },
    online: true,
    unread: 1,
    itemRef: { id: 5, title: 'Badminton Racket Set', price: '₱1,100' },
    messages: [
      { id: 12, senderID: 3, text: 'Pwede pa bawasan yung badminton set?', ts: '2025-05-22T08:00:00', read: true },
      { id: 13, senderID: 1, text: 'Last na po 1000, libre na shuttle.', ts: '2025-05-22T08:10:00', read: true },
      { id: 14, senderID: 3, text: 'Sige deal! Kelan pwedeng pick up?', ts: '2025-05-22T11:30:00', read: false },
    ]
  },
  {
    id: 'conv-004',
    other: { id: 4, name: 'vince', avatarClass: 'blue' },
    online: false,
    unread: 0,
    itemRef: { id: 2, title: 'Wooden Study Desk', price: '₱3,200' },
    messages: [
      { id: 15, senderID: 4, text: 'Is the desk still available?', ts: '2025-05-15T12:00:00', read: true },
      { id: 16, senderID: 1, text: 'Yes, still available!', ts: '2025-05-15T12:05:00', read: true },
      { id: 17, senderID: 4, text: 'What are the exact dimensions?', ts: '2025-05-15T12:07:00', read: true },
      { id: 18, senderID: 1, text: '4 feet wide, about 2 feet deep. Standard height.', ts: '2025-05-15T12:10:00', read: true },
      { id: 19, senderID: 4, text: 'Thank you! Let me check if it fits my room.', ts: '2025-05-15T12:12:00', read: true },
    ]
  }
];

// ── State ──────────────────────────────────────────────────────────
let activeConvID = null;
let editingMsgID = null;
let deletingMsgID = null;
let nextMsgID = 100;

// ── Init ───────────────────────────────────────────────────────────
renderConvList();
updateUnreadBadge();
openConvo(CONVOS[0].id);

function renderConvList(filter = '') {
  const list = document.getElementById('convList');
  const filtered = CONVOS.filter(c =>
    c.other.name.toLowerCase().includes(filter.toLowerCase()) ||
    (c.itemRef?.title || '').toLowerCase().includes(filter.toLowerCase())
  );

  if (!filtered.length) {
    list.innerHTML = `<div class="empty-convs">
      <div class="empty-icon">🔍</div>
      <h3>No conversations found</h3>
      <p>Try a different name or listing.</p>
    </div>`;
    return;
  }

  list.innerHTML = filtered.map(c => {
    const last = c.messages[c.messages.length - 1];
    const lastText = last ? escapeHtml(last.text.substring(0, 40)) + (last.text.length > 40 ? '…' : '') : 'No messages yet';
    const lastTime = last ? formatTime(last.ts) : '';
    const isActive = c.id === activeConvID ? ' active' : '';
    const avatarClass = c.other.avatarClass ? ` conv-avatar-${c.other.avatarClass}` : '';
    const onlineClass = c.online ? ' online' : '';
    return `
      <div class="conv-item${isActive}" onclick="openConvo('${c.id}')">
        <div class="conv-avatar${avatarClass}${onlineClass}">${c.other.name[0].toUpperCase()}</div>
        <div class="conv-info">
          <div class="conv-name">
            ${escapeHtml(c.other.name)}
            ${c.itemRef ? `<span class="conv-item-tag">${escapeHtml(c.itemRef.title)}</span>` : ''}
          </div>
          <div class="conv-last">${lastText}</div>
        </div>
        <div class="conv-meta">
          <span class="conv-time">${lastTime}</span>
          ${c.unread > 0 ? `<span class="conv-unread">${c.unread}</span>` : ''}
        </div>
      </div>`;
  }).join('');
}

function filterConvs(val) {
  renderConvList(val);
}

function openConvo(convID) {
  const conv = CONVOS.find(c => c.id === convID);
  if (!conv) return;

  activeConvID = convID;
  conv.unread = 0;

  // Update sidebar
  renderConvList(document.getElementById('convSearch').value);
  updateUnreadBadge();

  // Update chat header
  const avatarEl = document.getElementById('chatAvatar');
  const avatarClass = conv.other.avatarClass;
  avatarEl.className = `chat-header-avatar${conv.online ? ' online' : ''}`;
  if (avatarClass) {
    const colorMap = { green: '#1a8a4a, #2db56b', purple: '#6b48ff, #9b7fff', blue: '#0070f3, #00aaff' };
    avatarEl.style.background = `linear-gradient(135deg, ${colorMap[avatarClass] || 'var(--accent), var(--accent-h)'})`;
  } else {
    avatarEl.style.background = '';
  }
  avatarEl.textContent = conv.other.name[0].toUpperCase();

  document.getElementById('chatName').textContent = conv.other.name;
  const statusEl = document.getElementById('chatStatus');
  statusEl.textContent = conv.online ? 'Online' : 'Offline';
  statusEl.className = `chat-header-status${conv.online ? '' : ' offline'}`;

  // Item ref
  const refEl = document.getElementById('chatItemRef');
  if (conv.itemRef) {
    refEl.innerHTML = `
      <div class="chat-item-ref" title="View listing">
        <div class="chat-item-ref-img"></div>
        <span class="chat-item-ref-name">${escapeHtml(conv.itemRef.title)} · ${escapeHtml(conv.itemRef.price)}</span>
      </div>`;
  } else {
    refEl.innerHTML = '';
  }

  // Show chat view
  document.getElementById('noChat').style.display = 'none';
  const chatView = document.getElementById('chatView');
  chatView.style.display = 'flex';

  renderMessages(conv);

  // Mobile: show sidebar toggle
  document.getElementById('msgSidebar').classList.remove('mobile-open');
}

function renderMessages(conv) {
  const body = document.getElementById('chatBody');
  let html = '';
  let lastSenderID = null;
  let lastDateLabel = null;

  conv.messages.forEach(msg => {
    const isMe = msg.senderID === ME.userID;
    const dateLabel = formatDateLabel(msg.ts);
    if (dateLabel !== lastDateLabel) {
      html += `<div class="msg-date-sep"><span>${dateLabel}</span></div>`;
      lastDateLabel = dateLabel;
      lastSenderID = null;
    }

    const isConsecutive = msg.senderID === lastSenderID;
    const rowClass = `msg-row ${isMe ? 'me' : 'them'}${isConsecutive ? ' consecutive' : ''}`;
    const bubbleClass = `bubble${isConsecutive ? ' consecutive' : ''}`;
    const avatarClass = conv.other.avatarClass || '';

    const tickHTML = isMe
      ? `<span class="tick${msg.read ? ' read' : ''}">
          <svg width="14" height="10" viewBox="0 0 14 10" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M1 5l3 3 5-5"/><path d="M5 5l3 3 5-5"/>
          </svg>
        </span>`
      : '';

    const editedBadge = msg.edited ? `<span class="edited-badge">(edited)</span>` : '';

    html += `
      <div class="${rowClass}">
        ${!isMe ? `<div class="bubble-avatar ${avatarClass}">${conv.other.name[0].toUpperCase()}</div>` : ''}
        <div class="bubble-wrap">
          <div class="${bubbleClass}">
            ${escapeHtml(msg.text)}${editedBadge}
            <div class="bubble-actions">
              ${isMe ? `<button class="ba-btn" title="Edit" onclick="startEdit(${msg.id}, event)">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                  <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                  <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                </svg>
              </button>` : ''}
              <button class="ba-btn del" title="Delete" onclick="openDeleteModal(${msg.id}, event)">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/>
                  <path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/>
                </svg>
              </button>
            </div>
          </div>
          <div class="bubble-time">${formatMsgTime(msg.ts)} ${tickHTML}</div>
        </div>
        ${isMe ? `<div class="bubble-avatar" style="visibility:hidden"></div>` : ''}
      </div>`;

    lastSenderID = msg.senderID;
  });

  body.innerHTML = html;
  scrollToBottom();
}

// ── Send ───────────────────────────────────────────────────────────
const msgInput = document.getElementById('msgInput');
const sendBtn  = document.getElementById('sendBtn');

msgInput.addEventListener('input', () => {
  sendBtn.disabled = !msgInput.value.trim();
});

function handleKey(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    if (!sendBtn.disabled) sendMessage();
  }
}

function sendMessage() {
  const conv = CONVOS.find(c => c.id === activeConvID);
  if (!conv) return;
  const text = msgInput.value.trim();
  if (!text) return;

  if (editingMsgID !== null) {
    const msg = conv.messages.find(m => m.id === editingMsgID);
    if (msg) { msg.text = text; msg.edited = true; }
    cancelEdit();
    renderMessages(conv);
    renderConvList(document.getElementById('convSearch').value);
    showFlash('success', 'Message updated.');
    return;
  }

  const newMsg = {
    id: nextMsgID++,
    senderID: ME.userID,
    text,
    ts: new Date().toISOString(),
    read: false
  };
  conv.messages.push(newMsg);
  msgInput.value = '';
  sendBtn.disabled = true;
  autoResize(msgInput);
  renderMessages(conv);
  renderConvList(document.getElementById('convSearch').value);
  simulateReply(conv);
}

function simulateReply(conv) {
  const replies = [
    "Sounds good! 👍",
    "Okay, I'll check it out.",
    "Thanks for letting me know!",
    "Got it, will message you if I'm interested.",
    "Sige po, salamat!",
    "Okay noted. See you!",
  ];
  setTimeout(() => {
    const body = document.getElementById('chatBody');
    if (!body) return;
    const typingEl = document.createElement('div');
    typingEl.className = 'msg-row them typing-indicator';
    typingEl.id = 'typingIndicator';
    typingEl.innerHTML = `
      <div class="bubble-avatar ${conv.other.avatarClass || ''}">${conv.other.name[0].toUpperCase()}</div>
      <div class="typing-dots"><span></span><span></span><span></span></div>`;
    body.appendChild(typingEl);
    scrollToBottom();

    setTimeout(() => {
      typingEl.remove();
      if (activeConvID === conv.id) {
        const reply = {
          id: nextMsgID++,
          senderID: conv.other.id,
          text: replies[Math.floor(Math.random() * replies.length)],
          ts: new Date().toISOString(),
          read: true
        };
        conv.messages.push(reply);
        renderMessages(conv);
        renderConvList(document.getElementById('convSearch').value);
      }
    }, 1400);
  }, 800);
}

// ── Edit ───────────────────────────────────────────────────────────
function startEdit(msgID, e) {
  e.stopPropagation();
  const conv = CONVOS.find(c => c.id === activeConvID);
  if (!conv) return;
  const msg = conv.messages.find(m => m.id === msgID);
  if (!msg || msg.senderID !== ME.userID) return;

  editingMsgID = msgID;
  msgInput.value = msg.text;
  sendBtn.disabled = false;
  autoResize(msgInput);

  const banner = document.getElementById('editBanner');
  document.getElementById('editBannerText').textContent = `Editing: "${msg.text.substring(0, 40)}${msg.text.length > 40 ? '…' : ''}"`;
  banner.classList.add('visible');
  msgInput.focus();
}

function cancelEdit() {
  editingMsgID = null;
  msgInput.value = '';
  sendBtn.disabled = true;
  autoResize(msgInput);
  document.getElementById('editBanner').classList.remove('visible');
}

// ── Delete ─────────────────────────────────────────────────────────
function openDeleteModal(msgID, e) {
  e.stopPropagation();
  deletingMsgID = msgID;
  document.getElementById('deleteOverlay').classList.add('open');
}
function closeDeleteModal() {
  deletingMsgID = null;
  document.getElementById('deleteOverlay').classList.remove('open');
}
function confirmDelete() {
  const conv = CONVOS.find(c => c.id === activeConvID);
  if (!conv || deletingMsgID === null) { closeDeleteModal(); return; }
  conv.messages = conv.messages.filter(m => m.id !== deletingMsgID);
  closeDeleteModal();
  renderMessages(conv);
  renderConvList(document.getElementById('convSearch').value);
  showFlash('success', 'Message deleted.');
}

document.getElementById('deleteOverlay').addEventListener('click', function(e) {
  if (e.target === this) closeDeleteModal();
});

// ── Helpers ────────────────────────────────────────────────────────
function scrollToBottom() {
  const body = document.getElementById('chatBody');
  requestAnimationFrame(() => { body.scrollTop = body.scrollHeight; });
}

function autoResize(el) {
  el.style.height = 'auto';
  el.style.height = Math.min(el.scrollHeight, 120) + 'px';
}

function formatTime(iso) {
  const d = new Date(iso);
  const now = new Date();
  const diff = (now - d) / 1000;
  if (diff < 60)     return 'just now';
  if (diff < 3600)   return Math.floor(diff / 60) + 'm';
  if (diff < 86400)  return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  if (diff < 604800) return Math.floor(diff / 86400) + 'd';
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

function formatMsgTime(iso) {
  return new Date(iso).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
}

function formatDateLabel(iso) {
  const d = new Date(iso);
  const now = new Date();
  const diff = Math.floor((now - d) / 86400000);
  if (diff === 0) return 'Today';
  if (diff === 1) return 'Yesterday';
  if (diff < 7)   return d.toLocaleDateString('en-US', { weekday: 'long' });
  return d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
}

function updateUnreadBadge() {
  const total = CONVOS.reduce((s, c) => s + c.unread, 0);
  const badge = document.getElementById('unreadCount');
  badge.textContent = total;
  badge.style.display = total > 0 ? 'inline-block' : 'none';
}

function escapeHtml(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
           .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function goBack() {
  document.getElementById('chatView').style.display = 'none';
  document.getElementById('noChat').style.display = 'flex';
  document.getElementById('msgSidebar').classList.toggle('mobile-open', false);
}

function showFlash(type, msg) {
  const container = document.getElementById('flashContainer');
  const el = document.createElement('div');
  el.className = `flash flash--${type}`;
  el.innerHTML = `${msg} <button class="flash-close" onclick="this.parentElement.remove()">✕</button>`;
  container.appendChild(el);
  setTimeout(() => el.remove(), 3500);
}
</script>
</body>
</html>
