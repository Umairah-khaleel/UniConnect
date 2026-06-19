<?php
// chatroom.php
session_start();
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: index.php'); exit;
}
$name       = htmlspecialchars($_SESSION['full_name'] ?? 'Student');
$student_id = htmlspecialchars($_SESSION['student_id'] ?? '');
$words      = array_filter(explode(' ', trim($name)));
$initials   = strtoupper(substr($words[0]??'S',0,1) . substr(end($words)??'',0,1));
$first      = explode(' ', trim($name))[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>UniConnect — Student Link</title>
<link rel="icon" type="image/png" href="icbt.png">

<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600&family=Nunito:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --coral:    #EE4540;
  --crimson:  #C72C41;
  --wine:     #801336;
  --plum:     #510A32;
  --midnight: #2D142C;
  --white:    #ffffff;
  --bg:       #f5eeee;
  --card:     #ffffff;
  --text:     #2D142C;
  --muted:    #9a8080;
  --border:   #eedede;
  --sidebar-w: 230px;
}

html, body {
  height: 100%; font-family: 'Nunito', sans-serif;
  background: var(--bg); color: var(--text); overflow: hidden;
}

/* ══ LAYOUT ══ */
.layout {
  display: grid;
  grid-template-columns: var(--sidebar-w) 1fr;
  grid-template-rows: 100vh;
  height: 100vh;
}

/* ══ SIDEBAR ══ */
.sidebar {
  background: linear-gradient(175deg, var(--midnight) 0%, var(--plum) 55%, var(--wine) 100%);
  display: flex; flex-direction: column; height: 100vh; position: relative;
}
.sidebar::after {
  content: '';
  position: absolute; top: 0; right: 0; width: 1.5px; height: 100%;
  background: linear-gradient(to bottom, transparent, rgba(138,43,226,0.5), rgba(238,69,64,0.4), transparent);
}
.sidebar-brand {
  padding: 26px 20px 18px;
  display: flex; align-items: center; gap: 11px;
  border-bottom: 1px solid rgba(255,255,255,0.06); flex-shrink: 0;
}
.brand-img { width: 36px; height: 36px; border-radius: 50%; border: 1.5px solid rgba(255,255,255,0.25); overflow: hidden; background: rgba(255,255,255,0.1); }
.brand-img img { width:100%; height:100%; object-fit:cover; }
.brand-name { font-family: 'Cormorant Garamond', serif; font-size: 16px; font-weight: 600; color: #fff; letter-spacing: .02em; }
.brand-sub  { font-size: 9px; color: rgba(255,255,255,0.35); letter-spacing: .1em; text-transform: uppercase; }

.nav-scroll { flex: 1; overflow-y: auto; padding: 14px 0; }
.nav-scroll::-webkit-scrollbar { display: none; }
.nav-section-label { font-size: 9px; letter-spacing: .2em; text-transform: uppercase; color: rgba(255,255,255,0.25); font-weight: 600; padding: 10px 20px 6px; }
.nav-item {
  display: flex; align-items: center; gap: 11px; padding: 10px 20px;
  color: rgba(255,255,255,0.58); font-size: 12.5px; font-weight: 500;
  text-decoration: none; cursor: pointer; border-left: 2.5px solid transparent;
  transition: all .25s ease;
}
.nav-item:hover { color: #fff; background: rgba(255,255,255,0.06); border-left-color: rgba(238,69,64,0.4); }
.nav-item.active { color: #fff; background: rgba(238,69,64,0.14); border-left-color: var(--coral); }
.nav-item.active .ni { color: var(--coral); }
.ni { font-size: 15px; width: 18px; text-align: center; flex-shrink: 0; }
.nav-pill { margin-left: auto; background: var(--coral); color: #fff; font-size: 8.5px; font-weight: 700; padding: 2px 7px; border-radius: 50px; }

.sidebar-foot { border-top: 1px solid rgba(255,255,255,0.06); padding: 14px 16px 20px; flex-shrink: 0; }
.user-chip { display: flex; align-items: center; gap: 10px; padding: 9px 11px; border-radius: 10px; cursor: pointer; text-decoration: none; transition: background .25s; }
.user-chip:hover { background: rgba(255,255,255,0.08); }
.ava { width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0; background: linear-gradient(135deg, var(--coral), var(--crimson)); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; color: #fff; box-shadow: 0 2px 8px rgba(238,69,64,0.4); }
.user-chip-name { font-size: 11.5px; font-weight: 600; color: #fff; }
.user-chip-id { font-size: 9.5px; color: rgba(255,255,255,0.35); }
.chip-arrow { margin-left: auto; color: rgba(255,255,255,0.3); font-size: 13px; }

/* ══ MAIN CHAT AREA ══ */
.chat-main {
  display: flex; flex-direction: column; height: 100vh; overflow: hidden;
  background: var(--bg);
}

/* Chat header */
.chat-header {
  background: rgba(250,245,245,0.92);
  backdrop-filter: blur(10px);
  border-bottom: 1px solid var(--border);
  padding: 0 28px; height: 64px;
  display: flex; align-items: center; justify-content: space-between;
  flex-shrink: 0;
}
.chat-header-left { display: flex; align-items: center; gap: 14px; }
.chat-room-icon {
  width: 40px; height: 40px; border-radius: 12px; flex-shrink: 0;
  background: linear-gradient(135deg, var(--coral), var(--crimson));
  display: flex; align-items: center; justify-content: center;
  font-size: 18px;
  box-shadow: 0 3px 10px rgba(199,44,65,0.35);
}
.chat-room-name { font-family: 'Cormorant Garamond', serif; font-size: 19px; font-weight: 600; color: var(--midnight); }
.chat-room-sub { font-size: 10px; color: var(--muted); font-weight: 300; display: flex; align-items: center; gap: 5px; }
.online-dot { width: 7px; height: 7px; border-radius: 50%; background: #4caf80; animation: pulse 2s ease-in-out infinite; display: inline-block; }
@keyframes pulse { 0%,100%{opacity:1;} 50%{opacity:.4;} }

.chat-header-right { display: flex; align-items: center; gap: 10px; }
.timer-pill {
  display: flex; align-items: center; gap: 6px;
  padding: 6px 14px; border-radius: 50px;
  background: rgba(238,69,64,0.08);
  border: 1px solid rgba(238,69,64,0.18);
  font-size: 11px; color: var(--crimson); font-weight: 600;
}
.rules-btn {
  padding: 7px 14px; border-radius: 50px;
  border: 1px solid var(--border); background: var(--card);
  font-family: 'Nunito', sans-serif; font-size: 11px; font-weight: 600;
  color: var(--muted); cursor: pointer; transition: all .2s;
}
.rules-btn:hover { border-color: var(--crimson); color: var(--crimson); }

/* ── Notice banner ── */
.chat-notice {
  background: linear-gradient(130deg, var(--midnight), var(--plum));
  padding: 9px 28px;
  display: flex; align-items: center; gap: 10px;
  font-size: 11.5px; color: rgba(255,255,255,0.7); font-weight: 300;
  flex-shrink: 0;
}
.chat-notice strong { color: #fff; font-weight: 600; }
.chat-notice-icon { font-size: 14px; }

/* ── Messages area ── */
.messages-area {
  flex: 1; overflow-y: auto; padding: 20px 28px;
  display: flex; flex-direction: column; gap: 4px;
  scroll-behavior: smooth;
}
.messages-area::-webkit-scrollbar { width: 4px; }
.messages-area::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }

/* Date separator */
.date-sep {
  display: flex; align-items: center; gap: 12px;
  margin: 12px 0 8px; font-size: 10px;
  letter-spacing: .1em; text-transform: uppercase;
  color: var(--muted); font-weight: 600;
}
.date-sep::before, .date-sep::after {
  content: ''; flex: 1; height: 1px; background: var(--border);
}

/* Message row */
.msg-row {
  display: flex; gap: 9px; align-items: flex-end;
  margin-bottom: 2px;
  animation: msgIn .3s ease both;
}
@keyframes msgIn {
  from { opacity: 0; transform: translateY(6px); }
  to   { opacity: 1; transform: translateY(0); }
}
.msg-row.me { flex-direction: row-reverse; }
.msg-row.continuation .msg-avatar { visibility: hidden; }
.msg-row.continuation .msg-bubble { border-radius: 18px 18px 18px 5px; }
.msg-row.me.continuation .msg-bubble { border-radius: 18px 18px 5px 18px; }

/* Avatar */
.msg-avatar {
  width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
  background: linear-gradient(135deg, var(--wine), var(--plum));
  display: flex; align-items: center; justify-content: center;
  font-size: 10px; font-weight: 700; color: #fff;
  box-shadow: 0 2px 6px rgba(45,20,44,0.2);
}
.msg-row.me .msg-avatar { background: linear-gradient(135deg, var(--coral), var(--crimson)); }

/* Bubble */
.msg-content { max-width: 65%; display: flex; flex-direction: column; }
.msg-row.me .msg-content { align-items: flex-end; }

.msg-meta {
  font-size: 9.5px; color: var(--muted); font-weight: 400;
  margin-bottom: 3px; display: flex; gap: 6px; align-items: center;
}
.msg-row.me .msg-meta { flex-direction: row-reverse; }
.msg-sender { font-weight: 600; color: var(--wine); }

.msg-bubble {
  padding: 9px 14px;
  border-radius: 18px 18px 18px 5px;
  font-size: 13px; font-weight: 400; line-height: 1.5;
  word-wrap: break-word; white-space: pre-wrap;
  background: var(--card);
  border: 1px solid var(--border);
  color: var(--text);
  box-shadow: 0 1px 4px rgba(45,20,44,0.06);
  position: relative;
}
.msg-row.me .msg-bubble {
  background: linear-gradient(135deg, var(--coral), var(--crimson));
  color: #fff; border: none;
  border-radius: 18px 18px 5px 18px;
  box-shadow: 0 3px 12px rgba(199,44,65,0.3);
}

.msg-time { font-size: 9px; color: var(--muted); margin-top: 3px; }
.msg-row.me .msg-time { color: rgba(255,255,255,0.6); text-align: right; }

/* Typing indicator */
.typing-indicator {
  display: flex; align-items: center; gap: 8px;
  padding: 8px 0; font-size: 11px; color: var(--muted); font-style: italic;
  min-height: 28px;
}
.typing-dots span {
  display: inline-block; width: 5px; height: 5px; border-radius: 50%;
  background: var(--muted); margin: 0 1px;
  animation: typingBounce 1.2s ease-in-out infinite;
}
.typing-dots span:nth-child(2) { animation-delay: .2s; }
.typing-dots span:nth-child(3) { animation-delay: .4s; }
@keyframes typingBounce {
  0%,100% { transform: translateY(0); opacity:.4; }
  50%      { transform: translateY(-4px); opacity:1; }
}

/* Empty state */
.empty-chat {
  flex: 1; display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  gap: 10px; color: var(--muted); text-align: center; padding: 40px;
}
.empty-chat-icon { font-size: 48px; margin-bottom: 6px; }
.empty-chat-title { font-family: 'Cormorant Garamond', serif; font-size: 20px; color: var(--text); }
.empty-chat-sub { font-size: 12.5px; font-weight: 300; max-width: 280px; line-height: 1.6; }

/* ── Input bar ── */
.input-bar {
  background: var(--card);
  border-top: 1px solid var(--border);
  padding: 14px 28px 18px;
  flex-shrink: 0;
}
.input-wrap {
  display: flex; align-items: flex-end; gap: 10px;
  background: var(--bg); border: 1.5px solid var(--border);
  border-radius: 16px; padding: 10px 12px;
  transition: border-color .3s, box-shadow .3s;
}
.input-wrap:focus-within {
  border-color: var(--crimson);
  box-shadow: 0 0 0 3px rgba(199,44,65,0.09);
}
#msgInput {
  flex: 1; border: none; background: transparent; outline: none;
  font-family: 'Nunito', sans-serif; font-size: 13.5px; font-weight: 400;
  color: var(--text); resize: none; max-height: 120px;
  line-height: 1.5; min-height: 22px;
}
#msgInput::placeholder { color: #c0a8a8; font-weight: 300; }
.char-count { font-size: 9.5px; color: var(--muted); flex-shrink: 0; align-self: flex-end; padding-bottom: 2px; }
.char-count.warn { color: var(--coral); font-weight: 600; }
.send-btn {
  width: 38px; height: 38px; border-radius: 12px; flex-shrink: 0;
  background: linear-gradient(135deg, var(--coral), var(--crimson));
  border: none; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px; color: #fff;
  box-shadow: 0 3px 10px rgba(199,44,65,0.35);
  transition: transform .2s, box-shadow .2s;
}
.send-btn:hover { transform: scale(1.06); box-shadow: 0 5px 16px rgba(199,44,65,0.5); }
.send-btn:active { transform: scale(.96); }
.send-btn:disabled { opacity: .4; cursor: not-allowed; transform: none; }

.input-hint {
  text-align: center; font-size: 10px; color: var(--muted);
  margin-top: 8px; font-weight: 300; letter-spacing: .02em;
}

/* Error toast */
.toast {
  position: fixed; bottom: 90px; left: 50%; transform: translateX(-50%) translateY(20px);
  background: var(--midnight); color: #fff;
  padding: 10px 22px; border-radius: 50px;
  font-size: 12px; font-weight: 500;
  opacity: 0; pointer-events: none;
  transition: all .3s ease;
  z-index: 999; white-space: nowrap;
  box-shadow: 0 6px 24px rgba(45,20,44,0.4);
  border: 1px solid rgba(238,69,64,0.3);
}
.toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

/* Rules modal */
.modal-bg {
  position: fixed; inset: 0; background: rgba(45,20,44,0.5);
  backdrop-filter: blur(6px); z-index: 300;
  display: flex; align-items: center; justify-content: center;
  opacity: 0; pointer-events: none; transition: opacity .3s;
}
.modal-bg.open { opacity: 1; pointer-events: auto; }
.modal {
  background: var(--card); border-radius: 18px; padding: 36px;
  max-width: 420px; width: 90%;
  box-shadow: 0 30px 80px rgba(45,20,44,0.3);
  transform: translateY(20px); transition: transform .35s cubic-bezier(.16,1,.3,1);
  border: 1px solid var(--border);
}
.modal-bg.open .modal { transform: translateY(0); }
.modal-title {
  font-family: 'Cormorant Garamond', serif;
  font-size: 22px; font-weight: 600; color: var(--midnight);
  margin-bottom: 18px;
  display: flex; align-items: center; gap: 10px;
}
.rule-item {
  display: flex; gap: 12px; align-items: flex-start;
  margin-bottom: 13px; font-size: 13px; color: var(--text); line-height: 1.5;
}
.rule-num {
  width: 22px; height: 22px; border-radius: 50%; flex-shrink: 0;
  background: linear-gradient(135deg, var(--coral), var(--crimson));
  color: #fff; font-size: 10px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
}
.modal-close {
  width: 100%; margin-top: 22px; padding: 12px;
  background: linear-gradient(130deg, var(--coral), var(--crimson));
  border: none; border-radius: 10px;
  color: #fff; font-family: 'Nunito', sans-serif;
  font-size: 12px; font-weight: 700; letter-spacing: .1em;
  text-transform: uppercase; cursor: pointer;
  box-shadow: 0 4px 16px rgba(199,44,65,0.35);
  transition: transform .2s;
}
.modal-close:hover { transform: translateY(-2px); }
</style>
</head>
<body>

<!-- Toast -->
<div class="toast" id="toast"></div>

<!-- Rules Modal -->
<div class="modal-bg" id="modalBg" onclick="closeRules(event)">
  <div class="modal">
    <div class="modal-title">📋 Chatroom Rules</div>
    <div class="rule-item"><div class="rule-num">1</div><div>This chatroom is for <strong>educational purposes only</strong>. Keep conversations academic and respectful.</div></div>
    <div class="rule-item"><div class="rule-num">2</div><div><strong>No links, images, videos, or files</strong> can be shared in this chat.</div></div>
    <div class="rule-item"><div class="rule-num">3</div><div>All messages are automatically <strong>deleted after 24 hours</strong> — nothing is permanent.</div></div>
    <div class="rule-item"><div class="rule-num">4</div><div>Be kind and professional. <strong>Harassment, spam, or inappropriate content</strong> will result in access being removed.</div></div>
    <div class="rule-item"><div class="rule-num">5</div><div>You are identifiable by your <strong>Student ID</strong>. All messages are associated with your account.</div></div>
    <button class="modal-close" onclick="closeRules()">I Understand</button>
  </div>
</div>

<!-- ══ LAYOUT ══ -->
<div class="layout">

  <!-- ══ SIDEBAR ══ -->
  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="brand-img"><img src="icbt_logo.png" alt="ICBT"></div>
      <div>
        <div class="brand-name">UniConnect</div>
        <div class="brand-sub">ICBT Campus</div>
      </div>
    </div>
    <div class="nav-scroll">
      <div class="nav-section-label">Main</div>
      <a class="nav-item" href="student_dashboard.php"><span class="ni">🏠</span> Dashboard</a>
      <div class="nav-section-label" style="margin-top:8px">Services</div>
      <a class="nav-item" href="student_link.php"><span class="ni">🔗</span> Student Link</a>
      <a class="nav-item" href="burrow_buddy.php"><span class="ni">📚</span> Burrow Buddy <span class="nav-pill">New</span></a>
      <a class="nav-item" href="reclaim.php"><span class="ni">♻️</span> Reclaim</a>
      <a class="nav-item" href="brain_bridge.php"><span class="ni">🧠</span> Brain Bridge</a>
      <!--<div class="nav-section-label" style="margin-top:8px">Community</div>
      <a class="nav-item active" href="chatroom.php"><span class="ni">💬</span> Study Chat</a>-->
      <div class="nav-section-label" style="margin-top:8px">Account</div>
      <a class="nav-item" href="student_profile.php"><span class="ni">👤</span> My Profile</a>
      <a class="nav-item" href="auth/logout.php"><span class="ni">🚪</span> Sign Out</a>
    </div>
    <div class="sidebar-foot">
      <div class="user-chip">
        <div class="ava"><?= $initials ?></div>
        <div>
          <div class="user-chip-name"><?= $name ?></div>
          <div class="user-chip-id"><?= $student_id ?></div>
        </div>
        <span class="chip-arrow">›</span>
      </div>
    </div>
  </aside>

  <!-- ══ CHAT MAIN ══ -->
  <div class="chat-main">

    <!-- Header -->
    <div class="chat-header">
      <div class="chat-header-left">
        <div class="chat-room-icon">💬</div>
        <div>
          <div class="chat-room-name">Study Chat</div>
          <div class="chat-room-sub">
            <span class="online-dot"></span>
            <span id="onlineCount">— students active</span>
            &nbsp;·&nbsp; Text only · Education purposes
          </div>
        </div>
      </div>
      <div class="chat-header-right">
        <div class="timer-pill">⏱ Messages delete in 24h</div>
        <button class="rules-btn" onclick="openRules()">📋 Rules</button>
      </div>
    </div>

    <!-- Notice -->
    <div class="chat-notice">
      <span class="chat-notice-icon">📌</span>
      <span><strong>Education Only:</strong> This chatroom is for academic discussions. No media, links, or personal content allowed. Messages auto-delete after 24 hours.</span>
    </div>

    <!-- Messages -->
    <div class="messages-area" id="messagesArea">
      <div class="empty-chat" id="emptyState">
        <div class="empty-chat-icon">💬</div>
        <div class="empty-chat-title">Start the conversation</div>
        <div class="empty-chat-sub">Ask a question, share study tips, or help a fellow student. Keep it educational!</div>
      </div>
    </div>

    <!-- Typing indicator -->
    <div style="padding: 0 28px; flex-shrink:0;">
      <div class="typing-indicator" id="typingIndicator" style="display:none;">
        <div class="typing-dots"><span></span><span></span><span></span></div>
        <span id="typingText">Someone is typing…</span>
      </div>
    </div>

    <!-- Input bar -->
    <div class="input-bar">
      <div class="input-wrap">
        <textarea id="msgInput" placeholder="Ask a question or share something educational…" rows="1"
          oninput="onInput()" onkeydown="handleKey(event)"></textarea>
        <span class="char-count" id="charCount">1000</span>
        <button class="send-btn" id="sendBtn" onclick="sendMessage()" disabled title="Send (Enter)">➤</button>
      </div>
      <div class="input-hint">Press <strong>Enter</strong> to send · <strong>Shift+Enter</strong> for new line · No links or media allowed</div>
    </div>

  </div>
</div>

<script>
const ME_ID      = <?= (int)$_SESSION['user_id'] ?>;
const ME_NAME    = <?= json_encode($name) ?>;
const ME_INITIALS= <?= json_encode($initials) ?>;
const API        = 'chat/api.php';

let lastId       = 0;
let pollTimer    = null;
let sending      = false;
const MAX_CHARS  = 1000;

// ── Auto-resize textarea ──
function onInput() {
  const ta   = document.getElementById('msgInput');
  const btn  = document.getElementById('sendBtn');
  const cnt  = document.getElementById('charCount');
  const left = MAX_CHARS - ta.value.length;

  ta.style.height = 'auto';
  ta.style.height = Math.min(ta.scrollHeight, 120) + 'px';

  cnt.textContent = left;
  cnt.classList.toggle('warn', left < 100);
  btn.disabled = ta.value.trim().length === 0 || left < 0;
}

// ── Enter to send ──
function handleKey(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    if (!document.getElementById('sendBtn').disabled) sendMessage();
  }
}

// ── Send message ──
async function sendMessage() {
  if (sending) return;
  const ta  = document.getElementById('msgInput');
  const msg = ta.value.trim();
  if (!msg) return;

  sending = true;
  document.getElementById('sendBtn').disabled = true;

  try {
    const fd = new FormData();
    fd.append('action', 'send');
    fd.append('message', msg);
    const res  = await fetch(API, { method: 'POST', body: fd });
    const json = await res.json();
    if (json.success) {
      ta.value = '';
      ta.style.height = 'auto';
      document.getElementById('charCount').textContent = MAX_CHARS;
      fetchMessages();
    } else {
      showToast(json.message || 'Could not send message.');
    }
  } catch { showToast('Connection error. Please try again.'); }

  sending = false;
  document.getElementById('sendBtn').disabled = false;
  document.getElementById('msgInput').focus();
}

// ── Fetch messages ──
let lastSender = null;
let lastTime   = null;

async function fetchMessages() {
  try {
    const res  = await fetch(`${API}?action=fetch&since=${lastId}`);
    const json = await res.json();
    if (!json.success || !json.messages.length) return;

    const area   = document.getElementById('messagesArea');
    const empty  = document.getElementById('emptyState');
    const atBottom = area.scrollHeight - area.scrollTop <= area.clientHeight + 60;

    if (empty) empty.remove();

    json.messages.forEach(m => {
      lastId = Math.max(lastId, m.id);
      const isMe         = m.user_id == ME_ID;
      const isContinuation = (m.student_id === lastSender) &&
                             lastTime && ((new Date(m.created_at) - new Date(lastTime)) < 60000);
      lastSender = m.student_id;
      lastTime   = m.created_at;

      const row = document.createElement('div');
      row.className = `msg-row${isMe?' me':''}${isContinuation?' continuation':''}`;
      row.dataset.id = m.id;

      const initials = m.full_name.split(' ').map(w=>w[0]).slice(0,2).join('').toUpperCase();

      row.innerHTML = `
        <div class="msg-avatar">${initials}</div>
        <div class="msg-content">
          ${!isContinuation ? `<div class="msg-meta"><span class="msg-sender">${m.full_name}</span><span>${m.student_id}</span></div>` : ''}
          <div class="msg-bubble">${m.message}</div>
          <div class="msg-time">${m.time_label}</div>
        </div>`;

      area.appendChild(row);
    });

    if (atBottom) area.scrollTop = area.scrollHeight;
  } catch { /* silent — keep polling */ }
}

// ── Fetch online count ──
async function fetchCount() {
  try {
    const res  = await fetch(`${API}?action=count`);
    const json = await res.json();
    if (json.success) {
      document.getElementById('onlineCount').textContent =
        json.count + (json.count === 1 ? ' student active' : ' students active');
    }
  } catch {}
}

// ── Toast ──
function showToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 3500);
}

// ── Rules modal ──
function openRules()  { document.getElementById('modalBg').classList.add('open'); }
function closeRules(e){ if (!e || e.target === document.getElementById('modalBg')) document.getElementById('modalBg').classList.remove('open'); }

// ── Start polling ──
fetchMessages();
fetchCount();
setInterval(fetchMessages, 2500);   // poll every 2.5s
setInterval(fetchCount, 15000);     // update count every 15s

// Show rules on first visit
if (!sessionStorage.getItem('chat_rules_seen')) {
  setTimeout(() => { openRules(); sessionStorage.setItem('chat_rules_seen','1'); }, 800);
}

document.getElementById('msgInput').focus();
</script>
</body>
</html>