<?php
session_start();
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: index.php'); exit;
}

require_once __DIR__ . '/auth/db.php'; // $pdo

$name       = htmlspecialchars($_SESSION['full_name'] ?? 'Student');
$student_id = htmlspecialchars($_SESSION['student_id'] ?? '');
$email      = htmlspecialchars($_SESSION['email'] ?? '');
$first      = explode(' ', trim($name))[0];
$words      = array_filter(explode(' ', trim($name)));
$initials   = strtoupper(substr($words[0]??'S',0,1) . substr(end($words)??'',0,1));

// ── Fetch must_change_password flag ──
$row = $pdo->prepare("SELECT must_change_password FROM users WHERE id=?");
$row->execute([(int)$_SESSION['user_id']]);
$must_change_pw = (bool)($row->fetchColumn());

// ── Handle password change ──
$pw_error   = '';
$pw_success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $new_pw  = $_POST['new_password']  ?? '';
    $conf_pw = $_POST['confirm_password'] ?? '';

    if (strlen($new_pw) < 8) {
        $pw_error = 'Password must be at least 8 characters.';
    } elseif ($new_pw !== $conf_pw) {
        $pw_error = 'Passwords do not match.';
    } else {
        $pdo->prepare("UPDATE users SET password=?, must_change_password=0 WHERE id=?")
            ->execute([password_hash($new_pw, PASSWORD_DEFAULT), (int)$_SESSION['user_id']]);
        $pw_success = 'Password updated successfully!';
    }
}

// ── Fetch must_change_password flag AFTER any update ──
$row = $pdo->prepare("SELECT must_change_password FROM users WHERE id=?");
$row->execute([(int)$_SESSION['user_id']]);
$must_change_pw = (bool)($row->fetchColumn());

// ── Fetch notifications ──
$notifs = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 20");
$notifs->execute([(int)$_SESSION['user_id']]);
$notifs = $notifs->fetchAll();
$unread_count = count(array_filter($notifs, fn($n) => !$n['is_read']));

// ── Mark all read on POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_notifs_read') {
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([(int)$_SESSION['user_id']]);
    header('Location: student_dashboard.php'); exit;
}

// ── Fetch notices from DB ──
$notices = $pdo->query("
    SELECT id, title, tag, poster_url, content, event_date, location, created_at
    FROM notices
    WHERE is_active = 1
    ORDER BY created_at DESC
")->fetchAll();

// ── Fetch events for calendar ──
$events_raw = $pdo->query("
    SELECT title, event_date, event_time, location
    FROM events
    ORDER BY event_date ASC
")->fetchAll();

$events_js = json_encode(array_map(fn($e) => [
    'date' => $e['event_date'],
    'name' => $e['title'],
    'time' => trim(($e['event_time'] ?? '') . ($e['location'] ? ' · ' . $e['location'] : '')),
], $events_raw));

// Poster visuals per tag
$poster_themes = [
    'event'  => ['bg' => 'linear-gradient(145deg,#EE4540 0%,#801336 60%,#2D142C 100%)', 'icon' => '🎓', 'accent' => '#EE4540'],
    'notice' => ['bg' => 'linear-gradient(145deg,#510A32 0%,#2D142C 70%,#1a0a1c 100%)', 'icon' => '📋', 'accent' => '#C72C41'],
    'urgent' => ['bg' => 'linear-gradient(145deg,#C72C41 0%,#EE4540 50%,#801336 100%)', 'icon' => '🚨', 'accent' => '#ff6b6b'],
];

// Icon per title keyword
function noticeIcon(string $title): string {
    $t = strtolower($title);
    if (str_contains($t,'convoc') || str_contains($t,'graduat')) return '🎓';
    if (str_contains($t,'exam') || str_contains($t,'timetable')) return '📝';
    if (str_contains($t,'sport') || str_contains($t,'game')) return '🏆';
    if (str_contains($t,'tech') || str_contains($t,'symposium')) return '💡';
    if (str_contains($t,'registr')) return '✍️';
    if (str_contains($t,'gather') || str_contains($t,'social')) return '🎉';
    return '📌';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>UniConnect — Student Dashboard</title>
<link rel="icon" type="image/png" href="icbt.png">

<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=Nunito:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
  --right-w:  290px;
}

html, body { height:100%; font-family:'Nunito',sans-serif; background:var(--bg); color:var(--text); overflow:hidden; }

.layout { display:grid; grid-template-columns:var(--sidebar-w) 1fr var(--right-w); grid-template-rows:100vh; height:100vh; }

/* ── SIDEBAR ── */
.sidebar { background:linear-gradient(175deg,var(--midnight) 0%,var(--plum) 55%,var(--wine) 100%); display:flex; flex-direction:column; height:100vh; overflow:hidden; position:relative; }
.sidebar::after { content:''; position:absolute; top:0; right:0; width:1.5px; height:100%; background:linear-gradient(to bottom,transparent 0%,rgba(138,43,226,0.5) 25%,rgba(238,69,64,0.5) 60%,transparent 100%); }
.sidebar-brand { padding:26px 20px 18px; display:flex; align-items:center; gap:11px; border-bottom:1px solid rgba(255,255,255,0.06); flex-shrink:0; }
.brand-img { width:36px; height:36px; border-radius:50%; border:1.5px solid rgba(255,255,255,0.25); overflow:hidden; flex-shrink:0; background:rgba(255,255,255,0.1); }
.brand-img img { width:100%; height:100%; object-fit:cover; }
.brand-name { font-family:'Cormorant Garamond',serif; font-size:16px; font-weight:600; color:#fff; letter-spacing:.02em; line-height:1.1; }
.brand-sub { font-size:9px; color:rgba(255,255,255,0.35); letter-spacing:.1em; text-transform:uppercase; }
.nav-scroll { flex:1; overflow-y:auto; padding:14px 0; }
.nav-scroll::-webkit-scrollbar { display:none; }
.nav-section-label { font-size:9px; letter-spacing:.2em; text-transform:uppercase; color:rgba(255,255,255,0.25); font-weight:600; padding:10px 20px 6px; }
.nav-item { display:flex; align-items:center; gap:11px; padding:10px 20px; color:rgba(255,255,255,0.58); font-size:12.5px; font-weight:500; text-decoration:none; cursor:pointer; border-left:2.5px solid transparent; transition:all .25s ease; }
.nav-item:hover { color:#fff; background:rgba(255,255,255,0.06); border-left-color:rgba(238,69,64,0.4); }
.nav-item.active { color:#fff; background:rgba(238,69,64,0.14); border-left-color:var(--coral); }
.nav-item.active .ni { color:var(--coral); }
.ni { font-size:15px; width:18px; text-align:center; flex-shrink:0; transition:color .25s; }
.nav-pill { margin-left:auto; background:var(--coral); color:#fff; font-size:8.5px; font-weight:700; padding:2px 7px; border-radius:50px; letter-spacing:.04em; }
.sidebar-foot { border-top:1px solid rgba(255,255,255,0.06); padding:14px 16px 20px; flex-shrink:0; }
.user-chip { display:flex; align-items:center; gap:10px; padding:9px 11px; border-radius:10px; cursor:pointer; text-decoration:none; transition:background .25s; }
.user-chip:hover { background:rgba(255,255,255,0.08); }
.ava { width:32px; height:32px; border-radius:50%; flex-shrink:0; background:linear-gradient(135deg,var(--coral),var(--crimson)); display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; color:#fff; box-shadow:0 2px 8px rgba(238,69,64,0.4); }
.user-chip-name { font-size:11.5px; font-weight:600; color:#fff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.user-chip-id   { font-size:9.5px; color:rgba(255,255,255,0.35); }
.chip-arrow { margin-left:auto; color:rgba(255,255,255,0.3); font-size:13px; }
.user-chip:hover .chip-arrow { color:var(--coral); }

/* ── CENTER ── */
.center { display:flex; flex-direction:column; height:100vh; overflow:hidden; }
.topbar { background:rgba(245,238,238,0.9); backdrop-filter:blur(10px); border-bottom:1px solid var(--border); padding:0 28px; height:60px; display:flex; align-items:center; justify-content:space-between; flex-shrink:0; position:relative; z-index:100; overflow:visible; }.page-title { font-family:'Cormorant Garamond',serif; font-size:20px; font-weight:600; color:var(--midnight); letter-spacing:.01em; }
.topbar-right { display:flex; align-items:center; gap:10px; }
.icon-btn { width:34px; height:34px; border-radius:50%; border:1px solid var(--border); background:var(--card); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:15px; position:relative; transition:box-shadow .2s; }
.icon-btn:hover { box-shadow:0 2px 10px rgba(45,20,44,0.1); }
.notif-dot { position:absolute; top:5px; right:5px; width:7px; height:7px; border-radius:50%; background:var(--coral); border:1.5px solid var(--bg); }
.profile-chip { display:flex; align-items:center; gap:8px; padding:5px 12px 5px 6px; border:1px solid var(--border); border-radius:50px; background:var(--card); cursor:pointer; transition:box-shadow .2s; }
.profile-chip:hover { box-shadow:0 2px 10px rgba(45,20,44,0.1); }
.ava-sm { width:26px; height:26px; border-radius:50%; background:linear-gradient(135deg,var(--coral),var(--crimson)); display:flex; align-items:center; justify-content:center; font-size:10px; font-weight:700; color:#fff; }
.chip-name { font-size:12px; font-weight:600; color:var(--text); }
.center-scroll { flex:1; overflow-y:auto; padding:24px 28px 32px; }
.center-scroll::-webkit-scrollbar { width:4px; }
.center-scroll::-webkit-scrollbar-thumb { background:var(--border); border-radius:4px; }

/* ── WELCOME BANNER ── */
.welcome-banner { background:linear-gradient(125deg,var(--midnight) 0%,var(--plum) 45%,var(--wine) 80%,var(--crimson) 100%); border-radius:18px; padding:26px 28px; display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; position:relative; overflow:hidden; animation:fadeUp .5s ease both; }
.wb-circle-1 { position:absolute; width:200px; height:200px; border-radius:50%; background:rgba(238,69,64,0.1); top:-60px; right:-40px; }
.wb-circle-2 { position:absolute; width:130px; height:130px; border-radius:50%; background:rgba(255,255,255,0.04); bottom:-40px; right:100px; }
.wb-text { position:relative; z-index:1; }
.wb-hello { font-size:11px; color:rgba(255,255,255,0.5); letter-spacing:.14em; text-transform:uppercase; font-weight:300; margin-bottom:5px; }
.wb-name { font-family:'Cormorant Garamond',serif; font-size:26px; font-weight:600; color:#fff; line-height:1.1; margin-bottom:6px; }
.wb-name span { color:var(--coral); font-style:italic; }
.wb-sub { font-size:12px; color:rgba(255,255,255,0.45); font-weight:300; }
.wb-badge { position:relative; z-index:1; background:rgba(255,255,255,0.1); border:1px solid rgba(255,255,255,0.15); border-radius:12px; padding:12px 18px; text-align:center; }
.wb-badge-label { font-size:9px; letter-spacing:.12em; text-transform:uppercase; color:rgba(255,255,255,0.4); margin-bottom:4px; }
.wb-badge-val { font-size:17px; font-weight:700; color:#fff; letter-spacing:.04em; }

/* ── SECTION TITLE ── */
.section-title { font-family:'Cormorant Garamond',serif; font-size:16px; font-weight:600; color:var(--midnight); margin-bottom:14px; letter-spacing:.01em; display:flex; align-items:center; gap:8px; }
.section-title::before { content:''; width:3px; height:16px; border-radius:2px; background:linear-gradient(to bottom,var(--coral),var(--crimson)); }

/* ── QUICK GRID ── */
.quick-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:28px; }
.q-tile { display:flex; align-items:center; gap:13px; padding:15px 16px; border-radius:13px; background:var(--card); border:1px solid var(--border); cursor:pointer; text-decoration:none; transition:transform .25s,box-shadow .25s,border-color .25s; animation:fadeUp .5s ease both; }
.q-tile:hover { transform:translateY(-3px); box-shadow:0 8px 22px rgba(45,20,44,0.1); border-color:rgba(199,44,65,0.25); }
.q-tile:nth-child(1){animation-delay:.1s}.q-tile:nth-child(2){animation-delay:.15s}.q-tile:nth-child(3){animation-delay:.2s}.q-tile:nth-child(4){animation-delay:.25s}
.q-icon { width:40px; height:40px; border-radius:10px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:18px; background:linear-gradient(135deg,rgba(238,69,64,0.12),rgba(128,19,54,0.08)); }
.q-label { font-size:12.5px; font-weight:600; color:var(--text); }
.q-desc  { font-size:10.5px; color:var(--muted); font-weight:300; margin-top:1px; }
.q-arrow { margin-left:auto; color:var(--border); font-size:14px; transition:color .2s,transform .2s; }
.q-tile:hover .q-arrow { color:var(--crimson); transform:translateX(3px); }

/* ════════════════════════════════
   NOTICE BOARD
════════════════════════════════ */
.noticeboard-wrap {
  background: #f0e6d3;
  background-image:
    repeating-linear-gradient(0deg, transparent, transparent 28px, rgba(160,120,80,0.07) 28px, rgba(160,120,80,0.07) 29px),
    repeating-linear-gradient(90deg, transparent, transparent 28px, rgba(160,120,80,0.07) 28px, rgba(160,120,80,0.07) 29px);
  border: 2px solid #c9a87a;
  border-radius: 16px;
  padding: 20px 20px 22px;
  position: relative;
  box-shadow: inset 0 2px 12px rgba(120,80,40,0.1), 0 4px 20px rgba(45,20,44,0.08);
}
/* cork texture overlay */
.noticeboard-wrap::before {
  content:'';
  position:absolute; inset:0;
  border-radius:14px;
  background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='80' height='80'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='80' height='80' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
  pointer-events:none;
}
.noticeboard-label {
  font-size: 9px; letter-spacing: .22em; text-transform: uppercase;
  color: #8a6040; font-weight:700; margin-bottom: 16px;
  display: flex; align-items: center; gap: 8px;
}
.noticeboard-label::after { content:''; flex:1; height:1px; background:rgba(140,96,64,0.2); }

.poster-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
  gap: 16px;
  position: relative; z-index: 1;
}

/* ── POSTER CARD ── */
.poster-card {
  border-radius: 8px;
  overflow: hidden;
  cursor: pointer;
  position: relative;
  box-shadow: 3px 4px 14px rgba(45,20,44,0.22), 0 1px 3px rgba(0,0,0,0.15);
  transition: transform .3s cubic-bezier(.34,1.56,.64,1), box-shadow .3s ease;
  animation: pinDrop .5s cubic-bezier(.34,1.56,.64,1) both;
  transform-origin: top center;
}
/* slight random tilt per card */
.poster-card:nth-child(odd)  { transform: rotate(-1.2deg); }
.poster-card:nth-child(even) { transform: rotate(0.8deg); }
.poster-card:hover { transform: rotate(0deg) translateY(-6px) scale(1.03) !important; box-shadow:5px 14px 32px rgba(45,20,44,0.32); z-index:10; }

@keyframes pinDrop {
  from { opacity:0; transform: rotate(-1deg) translateY(-20px); }
  to   { opacity:1; }
}
.poster-card:nth-child(1){animation-delay:.05s}.poster-card:nth-child(2){animation-delay:.1s}
.poster-card:nth-child(3){animation-delay:.15s}.poster-card:nth-child(4){animation-delay:.2s}
.poster-card:nth-child(5){animation-delay:.25s}.poster-card:nth-child(6){animation-delay:.3s}

/* pushpin */
.poster-card::before {
  content: '';
  position:absolute; top:-5px; left:50%; transform:translateX(-50%);
  width:12px; height:12px;
  border-radius:50%;
  background: radial-gradient(circle at 35% 35%, #ff6b6b, #c0392b);
  box-shadow:0 2px 6px rgba(0,0,0,0.4);
  z-index:20;
}

/* poster visual area */
.poster-visual {
  height: 160px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  position: relative;
  overflow: hidden;
  padding: 16px 12px 12px;
}
.poster-visual img {
  position:absolute; inset:0; width:100%; height:100%; object-fit:cover;
}
/* decorative geometric shapes inside poster */
.poster-visual::after {
  content:'';
  position:absolute; inset:0;
  background:
    radial-gradient(circle at 80% 20%, rgba(255,255,255,0.12) 0%, transparent 45%),
    radial-gradient(circle at 20% 80%, rgba(0,0,0,0.15) 0%, transparent 45%);
  pointer-events:none;
}
.poster-deco-circle {
  position:absolute;
  border-radius:50%;
  opacity:.15;
  pointer-events:none;
}
.poster-icon {
  font-size: 36px;
  position:relative; z-index:1;
  filter: drop-shadow(0 2px 8px rgba(0,0,0,0.3));
  margin-bottom: 6px;
}
.poster-tag-chip {
  position:relative; z-index:1;
  font-size: 7.5px; letter-spacing:.12em; text-transform:uppercase; font-weight:700;
  padding: 3px 10px; border-radius:50px;
  background:rgba(255,255,255,0.2); color:rgba(255,255,255,0.95);
  border: 1px solid rgba(255,255,255,0.3);
  backdrop-filter: blur(4px);
}
.poster-date-ribbon {
  position:absolute; bottom:0; left:0; right:0;
  background:rgba(0,0,0,0.35);
  backdrop-filter:blur(6px);
  padding:5px 10px;
  display:flex; align-items:center; gap:6px;
  z-index:2;
}
.poster-date-ribbon span {
  font-size:9px; color:rgba(255,255,255,0.85); font-weight:500;
}
.poster-date-ribbon .dot { width:4px; height:4px; border-radius:50%; background:rgba(255,255,255,0.5); flex-shrink:0; }

/* poster info strip */
.poster-info {
  background: #fff;
  padding: 10px 11px 11px;
}
.poster-title {
  font-size: 11px; font-weight:700; color:var(--midnight);
  line-height:1.35; margin-bottom:3px;
  display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
}
.poster-location {
  font-size: 9.5px; color:var(--muted); font-weight:300;
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}

/* ── NOTICE MODAL ── */
.modal-bg {
  position:fixed; inset:0; background:rgba(45,20,44,0.55);
  backdrop-filter:blur(6px); z-index:300;
  display:flex; align-items:center; justify-content:center;
  opacity:0; pointer-events:none;
  transition:opacity .3s ease;
}
.modal-bg.open { opacity:1; pointer-events:auto; }
.modal-box {
  background:var(--card);
  border-radius:20px;
  width:420px; max-width:92vw;
  overflow:hidden;
  transform:translateY(30px) scale(.96);
  transition:transform .35s cubic-bezier(.34,1.56,.64,1);
  box-shadow:0 24px 60px rgba(45,20,44,0.35);
}
.modal-bg.open .modal-box { transform:translateY(0) scale(1); }
.modal-poster {
  height: 200px;
  display:flex; align-items:center; justify-content:center;
  font-size:64px;
  position:relative; overflow:hidden;
}
.modal-poster img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
.modal-poster::after { content:''; position:absolute; inset:0; background:linear-gradient(to bottom,transparent 40%,rgba(0,0,0,0.3)); }
.modal-poster-icon { position:relative; z-index:1; filter:drop-shadow(0 4px 12px rgba(0,0,0,0.4)); }
.modal-close {
  position:absolute; top:14px; right:14px; z-index:10;
  width:30px; height:30px; border-radius:50%;
  background:rgba(0,0,0,0.3); border:none; color:#fff;
  font-size:14px; cursor:pointer;
  display:flex; align-items:center; justify-content:center;
  transition:background .2s;
}
.modal-close:hover { background:rgba(0,0,0,0.5); }
.modal-body { padding:22px 24px 26px; }
.modal-tag {
  display:inline-block; font-size:8.5px; letter-spacing:.12em; text-transform:uppercase; font-weight:700;
  padding:3px 10px; border-radius:50px; margin-bottom:10px;
}
.modal-title { font-family:'Cormorant Garamond',serif; font-size:22px; font-weight:600; color:var(--midnight); line-height:1.2; margin-bottom:14px; }
.modal-meta { display:flex; flex-direction:column; gap:7px; margin-bottom:14px; }
.modal-meta-row { display:flex; align-items:center; gap:8px; font-size:12px; color:var(--muted); }
.modal-meta-row strong { color:var(--text); font-weight:600; }
.modal-content-text { font-size:12.5px; color:#555; line-height:1.7; border-top:1px solid var(--border); padding-top:14px; }

/* ── RIGHT COLUMN ── */
.right-col { border-left:1px solid var(--border); background:var(--card); height:100vh; overflow-y:auto; display:flex; flex-direction:column; }
.right-col::-webkit-scrollbar { width:4px; }
.right-col::-webkit-scrollbar-thumb { background:var(--border); border-radius:4px; }
.widget { padding:20px 20px 0; }
.widget+.widget { border-top:1px solid var(--border); margin-top:4px; padding-top:20px; }
.widget:last-child { padding-bottom:24px; }
.widget-title { font-family:'Cormorant Garamond',serif; font-size:15px; font-weight:600; color:var(--midnight); margin-bottom:14px; letter-spacing:.01em; display:flex; align-items:center; justify-content:space-between; }
.widget-title-text { display:flex; align-items:center; gap:7px; }
.widget-icon { width:24px; height:24px; border-radius:6px; background:linear-gradient(135deg,rgba(238,69,64,0.12),rgba(128,19,54,0.08)); display:flex; align-items:center; justify-content:center; font-size:12px; }
.widget-link { font-size:10px; color:var(--crimson); cursor:pointer; font-weight:600; }
.widget-link:hover { text-decoration:underline; }

/* Calendar */
.cal-nav { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
.cal-month { font-size:13px; font-weight:600; color:var(--text); }
.cal-btn { width:24px; height:24px; border-radius:6px; border:1px solid var(--border); background:var(--bg); cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:12px; color:var(--muted); transition:background .2s,color .2s; }
.cal-btn:hover { background:var(--midnight); color:#fff; border-color:var(--midnight); }
.cal-header { display:grid; grid-template-columns:repeat(7,1fr); margin-bottom:4px; }
.cal-lbl { text-align:center; font-size:8.5px; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); font-weight:600; padding:3px 0; }
.cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:2px; }
.cal-cell { aspect-ratio:1; border-radius:7px; display:flex; align-items:center; justify-content:center; font-size:10.5px; color:var(--text); cursor:pointer; transition:background .2s; position:relative; }
.cal-cell:hover { background:rgba(199,44,65,0.08); }
.cal-cell.empty { cursor:default; pointer-events:none; }
.cal-cell.today { background:linear-gradient(135deg,var(--coral),var(--crimson)); color:#fff; font-weight:700; box-shadow:0 2px 8px rgba(199,44,65,0.35); }
.cal-cell.has-event::after { content:''; position:absolute; bottom:2px; left:50%; transform:translateX(-50%); width:3px; height:3px; border-radius:50%; background:var(--crimson); }
.cal-cell.today.has-event::after { background:rgba(255,255,255,0.85); }
.events-section { margin-top:16px; }
.events-label { font-size:9px; letter-spacing:.15em; text-transform:uppercase; color:var(--muted); font-weight:600; margin-bottom:10px; }
.ev-item { display:flex; gap:10px; align-items:flex-start; padding:9px 10px; border-radius:9px; background:var(--bg); margin-bottom:8px; border-left:2.5px solid var(--coral); transition:transform .2s; cursor:pointer; }
.ev-item:hover { transform:translateX(3px); }
.ev-date { flex-shrink:0; text-align:center; background:linear-gradient(135deg,var(--coral),var(--crimson)); border-radius:7px; padding:4px 6px; min-width:32px; }
.ev-d { font-size:13px; font-weight:700; color:#fff; line-height:1; }
.ev-m { font-size:7.5px; text-transform:uppercase; color:rgba(255,255,255,0.8); letter-spacing:.05em; }
.ev-info { flex:1; min-width:0; }
.ev-name { font-size:11.5px; font-weight:600; color:var(--text); line-height:1.3; margin-bottom:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ev-time { font-size:9.5px; color:var(--muted); font-weight:300; }
.rn-item { display:flex; gap:10px; align-items:flex-start; padding:10px 0; border-bottom:1px solid var(--border); }
.rn-item:last-child { border-bottom:none; }
.rn-dot { width:8px; height:8px; border-radius:50%; background:var(--coral); flex-shrink:0; margin-top:4px; }
.rn-dot.plum { background:var(--plum); } .rn-dot.wine { background:var(--wine); }
.rn-title { font-size:12px; font-weight:600; color:var(--text); margin-bottom:2px; line-height:1.3; }
.rn-date  { font-size:10px; color:var(--muted); font-weight:300; }

/* Profile overlay */
.overlay-bg { position:fixed; inset:0; background:rgba(45,20,44,0.35); backdrop-filter:blur(4px); z-index:199; opacity:0; pointer-events:none; transition:opacity .35s ease; }
.overlay-bg.open { opacity:1; pointer-events:auto; }
.profile-panel { position:fixed; top:0; right:0; width:300px; height:100vh; background:var(--card); z-index:200; border-left:1px solid var(--border); box-shadow:-8px 0 40px rgba(45,20,44,0.15); transform:translateX(100%); transition:transform .4s cubic-bezier(.77,0,.18,1); display:flex; flex-direction:column; overflow-y:auto; }
.profile-panel.open { transform:translateX(0); }
.pp-banner { height:80px; background:linear-gradient(130deg,var(--midnight),var(--plum),var(--wine)); position:relative; flex-shrink:0; }
.pp-close { position:absolute; top:12px; right:12px; width:28px; height:28px; border-radius:50%; background:rgba(255,255,255,0.15); border:none; color:#fff; font-size:14px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background .2s; }
.pp-close:hover { background:rgba(255,255,255,0.25); }
.pp-avatar { position:absolute; bottom:-28px; left:50%; transform:translateX(-50%); width:56px; height:56px; border-radius:50%; background:linear-gradient(135deg,var(--coral),var(--crimson)); border:3px solid #fff; display:flex; align-items:center; justify-content:center; font-size:18px; font-weight:700; color:#fff; box-shadow:0 4px 16px rgba(199,44,65,0.4); }
.pp-body { padding:40px 22px 28px; flex:1; }
.pp-name { font-family:'Cormorant Garamond',serif; font-size:18px; font-weight:600; color:var(--midnight); text-align:center; margin-bottom:3px; }
.pp-id { font-size:11px; color:var(--muted); text-align:center; margin-bottom:22px; }
.pp-section { font-size:9px; letter-spacing:.16em; text-transform:uppercase; color:var(--muted); font-weight:600; margin-bottom:10px; margin-top:18px; }
.pp-field { margin-bottom:10px; }
.pp-field label { display:block; font-size:9.5px; color:var(--muted); text-transform:uppercase; letter-spacing:.08em; margin-bottom:4px; }
.pp-field .val { font-size:12.5px; font-weight:500; color:var(--text); background:var(--bg); padding:8px 12px; border-radius:8px; border:1px solid var(--border); }
.pp-logout { display:flex; align-items:center; gap:9px; width:100%; padding:11px 16px; border-radius:10px; margin-top:22px; background:rgba(238,69,64,0.07); border:1px solid rgba(238,69,64,0.18); color:var(--crimson); font-family:'Nunito',sans-serif; font-size:12.5px; font-weight:600; cursor:pointer; text-decoration:none; transition:background .25s,transform .2s; }
.pp-logout:hover { background:rgba(238,69,64,0.13); transform:translateX(3px); }

@keyframes fadeUp { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }
/* ── NOTIFICATION BELL ── */
.notif-bell-wrap { position:relative; }
.notif-panel {
  position:absolute; top:calc(100% + 10px); right:0; width:320px;
  background:var(--card); border:1px solid var(--border); border-radius:16px;
  box-shadow:0 12px 36px rgba(45,20,44,.16); z-index:9999;
  display:none; max-height:420px; overflow-y:auto;
  animation:fadeUp .2s ease;
}
.notif-panel.open { display:block; }
.notif-panel::-webkit-scrollbar { width:3px; }
.notif-panel::-webkit-scrollbar-thumb { background:var(--border); border-radius:3px; }
.notif-panel-head {
  padding:13px 16px; border-bottom:1px solid var(--border);
  display:flex; align-items:center; justify-content:space-between;
  font-size:12px; font-weight:700; color:var(--midnight);
  position:sticky; top:0; background:var(--card); z-index:1;
}
.notif-mark-all {
  background:none; border:none; color:var(--crimson);
  font-size:10.5px; font-weight:700; cursor:pointer;
  font-family:'Nunito',sans-serif; padding:0;
}
.notif-mark-all:hover { text-decoration:underline; }
.notif-empty { padding:32px 20px; text-align:center; font-size:12px; color:var(--muted); }
.notif-item {
  padding:13px 16px; border-bottom:1px solid rgba(238,222,222,.4);
  transition:background .2s; cursor:default;
}
.notif-item:last-child { border-bottom:none; }
.notif-item.unread { background:rgba(238,69,64,.04); }
.notif-item-title {
  font-size:12px; font-weight:700; color:var(--midnight); margin-bottom:4px;
}
.notif-item.unread .notif-item-title::before {
  content:'● '; color:var(--coral); font-size:8px; vertical-align:middle;
}
.notif-item-msg  { font-size:11.5px; color:var(--text); line-height:1.55; margin-bottom:5px; }
.notif-item-time { font-size:10px; color:var(--muted); }
.notif-badge {
  position:absolute; top:-5px; right:-5px;
  background:var(--coral); color:#fff;
  font-size:9px; font-weight:800; min-width:17px; height:17px;
  border-radius:50px; display:flex; align-items:center; justify-content:center;
  padding:0 4px; border:1.5px solid var(--bg); font-family:'Nunito',sans-serif;
}

/* ── Sign-out confirmation toast ── */
.signout-toast-overlay {
  position: fixed; inset: 0;
  background: rgba(45,20,44,0.5);
  backdrop-filter: blur(5px);
  z-index: 500;
  display: flex; align-items: center; justify-content: center;
  opacity: 0; pointer-events: none;
  transition: opacity .3s ease;
}
.signout-toast-overlay.open { opacity: 1; pointer-events: auto; }
.signout-toast-box {
  background: var(--card);
  border-radius: 18px;
  padding: 28px 28px 22px;
  width: 300px;
  box-shadow: 0 20px 50px rgba(45,20,44,0.35);
  text-align: center;
  transform: scale(.92) translateY(12px);
  transition: transform .35s cubic-bezier(.34,1.56,.64,1);
}
.signout-toast-overlay.open .signout-toast-box { transform: scale(1) translateY(0); }
.signout-toast-icon {
  width: 52px; height: 52px; border-radius: 50%;
  background: linear-gradient(135deg,rgba(238,69,64,0.12),rgba(128,19,54,0.08));
  display: flex; align-items: center; justify-content: center;
  font-size: 22px; margin: 0 auto 14px;
}
.signout-toast-title { font-size: 15px; font-weight: 700; color: var(--midnight); margin-bottom: 7px; }
.signout-toast-msg { font-size: 12px; color: var(--muted); line-height: 1.65; margin-bottom: 22px; }
.signout-toast-actions { display: flex; gap: 10px; }
.signout-btn-cancel {
  flex: 1; padding: 10px; border-radius: 10px;
  border: 1px solid var(--border); background: var(--bg);
  color: var(--text); font-size: 12.5px; font-weight: 600;
  cursor: pointer; font-family: 'Nunito', sans-serif;
  transition: background .2s;
}
.signout-btn-cancel:hover { background: var(--border); }
.signout-btn-confirm {
  flex: 1; padding: 10px; border-radius: 10px; border: none;
  background: linear-gradient(135deg,var(--coral),var(--crimson));
  color: #fff; font-size: 12.5px; font-weight: 700;
  cursor: pointer; font-family: 'Nunito', sans-serif;
  box-shadow: 0 4px 12px rgba(199,44,65,0.35);
  transition: transform .2s, box-shadow .2s;
}
.signout-btn-confirm:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(199,44,65,0.4); }
</style>

</head>
<body>

<!-- Sign-out confirmation toast -->
<div class="signout-toast-overlay" id="signoutToast">
  <div class="signout-toast-box">
    <div class="signout-toast-icon">🚪</div>
    <div class="signout-toast-title">Sign out of UniConnect?</div>
    <div class="signout-toast-msg">You'll need to sign back in to access your dashboard and campus services.</div>
    <div class="signout-toast-actions">
      <button class="signout-btn-cancel" onclick="closeSignoutToast()">Stay in</button>
      <button class="signout-btn-confirm" onclick="window.location.href='auth/logout.php'">Sign out</button>
    </div>
  </div>
</div>

<!-- ══ FORCED PASSWORD CHANGE MODAL ══ -->
<div class="modal-bg <?= $must_change_pw ? 'open' : '' ?>" id="pwChangeModal"
     style="z-index:400">
  <div class="modal-box" style="max-width:380px">

    <!-- Modal header banner -->
    <div class="modal-poster" style="background:linear-gradient(145deg,var(--midnight),var(--plum) 55%,var(--wine) 100%);height:130px">
      <span class="modal-poster-icon" style="font-size:48px">🔐</span>
    </div>

    <div class="modal-body">
      <div style="display:inline-block;font-size:8.5px;letter-spacing:.12em;text-transform:uppercase;
                  font-weight:700;padding:3px 10px;border-radius:50px;margin-bottom:10px;
                  background:rgba(238,69,64,.1);color:var(--crimson)">Action Required</div>

      <div style="font-family:'Cormorant Garamond',serif;font-size:20px;font-weight:600;
                  color:var(--midnight);margin-bottom:6px;line-height:1.2">
        Set your password
      </div>
      <p style="font-size:12px;color:var(--muted);line-height:1.6;margin-bottom:18px">
        Your account was created with a temporary password. Please choose a new password to continue. You can only do this once.
      </p>

      <?php if ($pw_error): ?>
        <div style="padding:10px 13px;background:rgba(238,69,64,.08);border:1px solid rgba(238,69,64,.25);
                    border-radius:9px;color:var(--coral);font-size:12px;font-weight:500;margin-bottom:14px">
          ✕ <?= htmlspecialchars($pw_error) ?>
        </div>
      <?php endif; ?>

      <?php if ($pw_success): ?>
        <div style="padding:10px 13px;background:rgba(46,158,104,.08);border:1px solid rgba(46,158,104,.22);
                    border-radius:9px;color:#2e9e68;font-size:12px;font-weight:500;margin-bottom:14px">
          ✓ <?= htmlspecialchars($pw_success) ?>
        </div>
      <?php endif; ?>

      <form method="POST">
        <input type="hidden" name="action" value="change_password"/>

        <div style="margin-bottom:12px">
          <label style="display:block;font-size:9px;font-weight:700;color:var(--muted);
                        text-transform:uppercase;letter-spacing:.1em;margin-bottom:5px">New Password</label>
          <input type="password" name="new_password" required minlength="8"
                 placeholder="Min. 8 characters"
                 style="width:100%;padding:10px 12px;background:var(--bg);border:1px solid var(--border);
                        border-radius:9px;color:var(--text);font-family:'Nunito',sans-serif;font-size:13px;
                        outline:none;transition:border-color .2s"
                 onfocus="this.style.borderColor='var(--crimson)'"
                 onblur="this.style.borderColor='var(--border)'"/>
        </div>

        <div style="margin-bottom:18px">
          <label style="display:block;font-size:9px;font-weight:700;color:var(--muted);
                        text-transform:uppercase;letter-spacing:.1em;margin-bottom:5px">Confirm Password</label>
          <input type="password" name="confirm_password" required minlength="8"
                 placeholder="Re-enter password"
                 style="width:100%;padding:10px 12px;background:var(--bg);border:1px solid var(--border);
                        border-radius:9px;color:var(--text);font-family:'Nunito',sans-serif;font-size:13px;
                        outline:none;transition:border-color .2s"
                 onfocus="this.style.borderColor='var(--crimson)'"
                 onblur="this.style.borderColor='var(--border)'"/>
        </div>

        <!-- strength indicator -->
        <div id="pwStrengthWrap" style="margin-bottom:16px;display:none">
          <div style="display:flex;justify-content:space-between;font-size:9.5px;
                      color:var(--muted);margin-bottom:4px">
            <span>Password strength</span><span id="pwStrengthLabel"></span>
          </div>
          <div style="height:4px;background:var(--border);border-radius:50px;overflow:hidden">
            <div id="pwStrengthBar" style="height:100%;border-radius:50px;width:0;transition:width .3s,background .3s"></div>
          </div>
        </div>

        <button type="submit"
                style="width:100%;padding:12px;border:none;border-radius:10px;
                       background:linear-gradient(135deg,var(--crimson),var(--wine));
                       color:#fff;font-family:'Nunito',sans-serif;font-size:13px;font-weight:700;
                       cursor:pointer;box-shadow:0 4px 14px rgba(199,44,65,.25);transition:all .25s"
                onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 8px 22px rgba(199,44,65,.3)'"
                onmouseout="this.style.transform='none';this.style.boxShadow='0 4px 14px rgba(199,44,65,.25)'">
          🔐 Set New Password →
        </button>
      </form>
    </div>
  </div>
</div>

<!-- Overlay (profile) -->
<div class="overlay-bg" id="overlayBg" onclick="closeProfile()"></div>

<!-- Profile Panel -->
<div class="profile-panel" id="profilePanel">
  <div class="pp-banner">
    <button class="pp-close" onclick="closeProfile()">✕</button>
    <div class="pp-avatar"><?= $initials ?></div>
  </div>
 <div class="pp-body">
  <div class="pp-name"><?= $name ?></div>
  <div class="pp-id"><?= $student_id ?></div>
  <div class="pp-field"><label>Full Name</label><div class="val"><?= $name ?></div></div>
  <div class="pp-field"><label>Student ID</label><div class="val"><?= $student_id ?></div></div>
  <div class="pp-field"><label>Email</label><div class="val"><?= $email ?></div></div>
  <div class="pp-field"><label>Role</label><div class="val">Student</div></div>

  <div class="pp-section">Security</div>

  

  <?php if ($must_change_pw): ?>
    <!-- ── Forced: student hasn't set password yet ── -->
    <?php if (!empty($pw_error)): ?>
      <div style="padding:9px 12px;background:rgba(238,69,64,.08);border:1px solid rgba(238,69,64,.25);
                  border-radius:9px;color:var(--coral);font-size:11.5px;font-weight:500;margin-bottom:12px">
        ✕ <?= htmlspecialchars($pw_error) ?>
      </div>
    <?php endif; ?>
    <form method="POST">
      <input type="hidden" name="action" value="change_password"/>
      <div class="pp-field">
        <label>New Password</label>
        <input type="password" name="new_password" required minlength="8"
               placeholder="Min. 8 characters" id="profilePwInput"
               style="width:100%;padding:9px 12px;background:var(--bg);border:1px solid var(--border);
                      border-radius:9px;color:var(--text);font-family:'Nunito',sans-serif;font-size:12.5px;
                      outline:none;transition:border-color .2s"
               onfocus="this.style.borderColor='var(--crimson)'"
               onblur="this.style.borderColor='var(--border)'"/>
      </div>
      <div class="pp-field">
        <label>Confirm Password</label>
        <input type="password" name="confirm_password" required minlength="8"
               placeholder="Re-enter password"
               style="width:100%;padding:9px 12px;background:var(--bg);border:1px solid var(--border);
                      border-radius:9px;color:var(--text);font-family:'Nunito',sans-serif;font-size:12.5px;
                      outline:none;transition:border-color .2s"
               onfocus="this.style.borderColor='var(--crimson)'"
               onblur="this.style.borderColor='var(--border)'"/>
      </div>
      <div id="profilePwStrengthWrap" style="margin-bottom:12px;display:none">
        <div style="display:flex;justify-content:space-between;font-size:9px;color:var(--muted);margin-bottom:4px">
          <span>Strength</span><span id="profilePwStrengthLabel"></span>
        </div>
        <div style="height:4px;background:var(--border);border-radius:50px;overflow:hidden">
          <div id="profilePwStrengthBar" style="height:100%;border-radius:50px;width:0;transition:width .3s,background .3s"></div>
        </div>
      </div>
      <button type="submit"
              style="width:100%;padding:11px 16px;border-radius:10px;border:none;
                     background:linear-gradient(135deg,var(--crimson),var(--wine));
                     color:#fff;font-family:'Nunito',sans-serif;font-size:12.5px;font-weight:600;
                     cursor:pointer;box-shadow:0 4px 12px rgba(199,44,65,.25);
                     transition:all .2s;margin-bottom:10px">
        🔐 Set My Password
      </button>
    </form>

  <?php else: ?>
    <!-- ── Password already set — locked permanently ── -->
    <div style="display:flex;align-items:center;gap:11px;padding:13px 14px;
                background:rgba(46,158,104,.06);border:1px solid rgba(46,158,104,.2);
                border-radius:11px;margin-bottom:14px">
      <div style="width:34px;height:34px;border-radius:50%;flex-shrink:0;
                  background:rgba(46,158,104,.12);display:flex;align-items:center;
                  justify-content:center;font-size:16px">✅</div>
      <div>
        <div style="font-size:12px;font-weight:700;color:#2e9e68;margin-bottom:2px">Password set</div>
        <div style="font-size:10.5px;color:var(--muted);line-height:1.5">
          Your password has been updated. Contact admin if you need a reset.
        </div>
      </div>
    </div>
  <?php endif; ?>

  <a href="#" onclick="openSignoutToast(); return false;" class="pp-logout">🚪 &nbsp;Sign Out of UniConnect</a>
</div>

</div>

<!-- Notice Modal -->
<div class="modal-bg" id="noticeModal" onclick="closeModal(event)">
  <div class="modal-box">
    <div class="modal-poster" id="modalPoster">
      <button class="modal-close" onclick="closeModalDirect()">✕</button>
      <span class="modal-poster-icon" id="modalIcon"></span>
    </div>
    <div class="modal-body">
      <span class="modal-tag" id="modalTag"></span>
      <div class="modal-title" id="modalTitle"></div>
      <div class="modal-meta" id="modalMeta"></div>
      <div class="modal-content-text" id="modalContent" style="display:none"></div>
    </div>
  </div>
</div>

<!-- ══ 3-COLUMN LAYOUT ══ -->
<div class="layout">

  <!-- ── SIDEBAR ── -->
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
  <a class="nav-item active" href="student_dashboard.php" onclick="setActive(this,'Dashboard')">
    <span class="ni">🏠</span> Dashboard
  </a>

  <div class="nav-section-label" style="margin-top:8px">Services</div>
  <a class="nav-item" href="student_link.php" onclick="setActive(this,'Student Link')">
    <span class="ni">🔗</span> Student Link
  </a>
  <a class="nav-item" href="burrow_buddy.php" onclick="setActive(this,'Burrow Buddy')">
    <span class="ni">📚</span> Burrow Buddy<span class="nav-pill">New</span>
  </a>
  <a class="nav-item" href="reclaim.php" onclick="setActive(this,'Reclaim')">
    <span class="ni">♻️</span> Reclaim
  </a>
  <a class="nav-item" href="brain_bridge.php" onclick="setActive(this,'Brain Bridge')">
    <span class="ni">🧠</span> Brain Bridge
  </a>

  <div class="nav-section-label" style="margin-top:8px">Account</div>
  <a class="nav-item" href="student_profile.php">
    <span class="ni">👤</span> My Profile
  </a>
  <a class="nav-item" href="#" onclick="openSignoutToast(); return false;">
    <span class="ni">🚪</span> Sign Out
  </a>
</div>

 <!-- Profile panel sign-out -->
    <div class="sidebar-foot">
      <a class="user-chip" href="#" onclick="openProfile(); return false;">
        <div class="ava"><?= $initials ?></div>
        <div><div class="user-chip-name"><?= $name ?></div><div class="user-chip-id"><?= $student_id ?></div></div>
      </a>
    </div>
  </aside>

  <!-- ── CENTER ── -->
  <main class="center">
    <div class="topbar">
      <div class="page-title" id="pageTitle">Dashboard</div>
      <div class="topbar-right">
        
      <div class="notif-bell-wrap" id="notifWrap">
  <div class="icon-btn" onclick="toggleNotifPanel()" style="cursor:pointer">
    🔔
    <?php if (!empty($unread_count) && $unread_count > 0): ?>
      <span class="notif-badge"><?= $unread_count ?></span>
    <?php else: ?>
      <div class="notif-dot" style="display:none"></div>
    <?php endif; ?>
  </div>
  <div class="notif-panel" id="notifPanel">
    <div class="notif-panel-head">
      <span>Notifications</span>
      <?php if (!empty($unread_count) && $unread_count > 0): ?>
        <form method="POST" style="margin:0">
          <input type="hidden" name="action" value="mark_notifs_read"/>
          <button type="submit" class="notif-mark-all">Mark all read</button>
        </form>
      <?php endif; ?>
    </div>
    <?php if (empty($notifs)): ?>
      <div class="notif-empty">🔔 No notifications yet</div>
    <?php else: ?>
      <?php foreach ($notifs as $notif): ?>
        <div class="notif-item <?= $notif['is_read'] ? '' : 'unread' ?>">
          <div class="notif-item-title"><?= htmlspecialchars($notif['title']) ?></div>
          <div class="notif-item-msg"><?= $notif['message'] ?></div>
          <div class="notif-item-time"><?= date('d M Y, g:i a', strtotime($notif['created_at'])) ?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
        <div class="profile-chip" onclick="openProfile()">
          <div class="ava-sm"><?= $initials ?></div>
          <span class="chip-name"><?= $first ?></span>
        </div>
      </div>
    </div>

    <div class="center-scroll">

      <!-- Welcome Banner -->
      <div class="welcome-banner">
        <div class="wb-circle-1"></div><div class="wb-circle-2"></div>
        <div class="wb-text">
          <div class="wb-hello">Good day</div>
          <div class="wb-name">Welcome back, <span><?= $first ?></span> 👋</div>
          <div class="wb-sub">Here's everything happening at ICBT today.</div>
        </div>
        <div class="wb-badge">
          <div class="wb-badge-label">Student ID</div>
          <div class="wb-badge-val"><?= $student_id ?></div>
        </div>
      </div>

      <!-- Quick Access -->
      <div class="section-title">Quick Access</div>
      <div class="quick-grid">
        <a class="q-tile" href="student_link.php"><div class="q-icon">🔗</div><div><div class="q-label">Student Link</div><div class="q-desc">Resources & portals</div></div><span class="q-arrow">›</span></a>
        <a class="q-tile" href="burrow_buddy.php"><div class="q-icon">📚</div><div><div class="q-label">Burrow Buddy</div><div class="q-desc">Library & borrowing</div></div><span class="q-arrow">›</span></a>
        <a class="q-tile" href="reclaim.php"><div class="q-icon">♻️</div><div><div class="q-label">Reclaim</div><div class="q-desc">Lost & found</div></div><span class="q-arrow">›</span></a>
        <a class="q-tile" href="brain_bridge.php"><div class="q-icon">🧠</div><div><div class="q-label">Brain Bridge</div><div class="q-desc">Study & connect</div></div><span class="q-arrow">›</span></a>
      </div>

      <!-- ══ NOTICE BOARD ══ -->
      <div class="section-title" style="margin-top:26px">Notice Board</div>
      <div class="noticeboard-wrap">
        <div class="noticeboard-label">📌 ICBT Campus Notices & Events</div>
        <div class="poster-grid">

          <?php
          $tag_styles = [
            'event'  => ['bg'=>'linear-gradient(145deg,#EE4540 0%,#801336 60%,#2D142C 100%)', 'chip_bg'=>'rgba(238,69,64,0.15)', 'chip_color'=>'#C72C41', 'label'=>'Event'],
            'notice' => ['bg'=>'linear-gradient(145deg,#510A32 0%,#2D142C 70%,#1a0a1c 100%)', 'chip_bg'=>'rgba(81,10,50,0.09)',  'chip_color'=>'#510A32', 'label'=>'Notice'],
            'urgent' => ['bg'=>'linear-gradient(145deg,#C72C41 0%,#EE4540 50%,#801336 100%)', 'chip_bg'=>'rgba(238,69,64,0.18)', 'chip_color'=>'#C72C41', 'label'=>'Urgent'],
          ];

          foreach ($notices as $n):
            $tag   = $n['tag'] ?? 'notice';
            $ts    = $tag_styles[$tag] ?? $tag_styles['notice'];
            $icon  = noticeIcon($n['title']);
            $dateF = $n['event_date'] ? date('d M Y', strtotime($n['event_date'])) : '';
            $loc   = htmlspecialchars($n['location'] ?? '');
            $hasPoster = !empty($n['poster_url']);
            // encode for JS modal
            $jsData = htmlspecialchars(json_encode([
              'title'   => $n['title'],
              'tag'     => $tag,
              'tagLabel'=> $ts['label'],
              'icon'    => $icon,
              'bg'      => $ts['bg'],
              'date'    => $dateF,
              'loc'     => $n['location'] ?? '',
              'content' => $n['content'] ?? '',
              'poster'  => $n['poster_url'] ?? '',
              'chipBg'  => $ts['chip_bg'],
              'chipCol' => $ts['chip_color'],
            ]), ENT_QUOTES);
          ?>
          <div class="poster-card" onclick='openModal(<?= $jsData ?>)'>

            <div class="poster-visual" style="background:<?= $ts['bg'] ?>">
              <?php if ($hasPoster): ?>
                <img src="<?= htmlspecialchars($n['poster_url']) ?>" alt="<?= htmlspecialchars($n['title']) ?>">
              <?php else: ?>
                <!-- decorative circles -->
                <div class="poster-deco-circle" style="width:80px;height:80px;background:rgba(255,255,255,0.08);top:-20px;right:-20px;"></div>
                <div class="poster-deco-circle" style="width:50px;height:50px;background:rgba(255,255,255,0.06);bottom:-10px;left:-10px;"></div>
                <div class="poster-icon"><?= $icon ?></div>
                <span class="poster-tag-chip"><?= $ts['label'] ?></span>
              <?php endif; ?>

              <?php if ($dateF || $loc): ?>
              <div class="poster-date-ribbon">
                <?php if ($dateF): ?><span>📅 <?= $dateF ?></span><?php endif; ?>
                <?php if ($dateF && $loc): ?><div class="dot"></div><?php endif; ?>
                <?php if ($loc): ?><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">📍 <?= $loc ?></span><?php endif; ?>
              </div>
              <?php endif; ?>
            </div>

            <div class="poster-info">
              <div class="poster-title"><?= htmlspecialchars($n['title']) ?></div>
              <?php if ($loc): ?><div class="poster-location">📍 <?= $loc ?></div><?php endif; ?>
            </div>

          </div>
          <?php endforeach; ?>

          <?php if (empty($notices)): ?>
            <div style="grid-column:1/-1;text-align:center;padding:40px;color:#8a6040;font-size:13px;">No notices posted yet.</div>
          <?php endif; ?>

        </div>
      </div>

    </div><!-- /center-scroll -->
  </main>

  <!-- ── RIGHT WIDGETS ── -->
  <aside class="right-col">
    <div class="widget">
      <div class="widget-title">
        <div class="widget-title-text"><div class="widget-icon">📅</div> Calendar</div>
      </div>
      <div class="cal-nav">
        <button class="cal-btn" onclick="changeMonth(-1)">‹</button>
        <div class="cal-month" id="calMonth"></div>
        <button class="cal-btn" onclick="changeMonth(1)">›</button>
      </div>
      <div class="cal-header">
        <div class="cal-lbl">Su</div><div class="cal-lbl">Mo</div><div class="cal-lbl">Tu</div>
        <div class="cal-lbl">We</div><div class="cal-lbl">Th</div><div class="cal-lbl">Fr</div><div class="cal-lbl">Sa</div>
      </div>
      <div class="cal-grid" id="calGrid"></div>
      <div class="events-section">
        <div class="events-label">Upcoming Events</div>
        <div id="evList"></div>
      </div>
    </div>

    <div class="widget">
      <div class="widget-title">
        <div class="widget-title-text"><div class="widget-icon">📌</div> Recent Notices</div>
        <span class="widget-link">See all</span>
      </div>
      <div>
        <?php
        $dot_classes = ['', 'plum', 'wine', ''];
        foreach (array_slice($notices, 0, 4) as $i => $n):
          $dc = $dot_classes[$i % 4];
          $dateStr = $n['event_date'] ? date('M d, Y', strtotime($n['event_date'])) : date('M d, Y', strtotime($n['created_at']));
        ?>
        <div class="rn-item">
          <div class="rn-dot <?= $dc ?>"></div>
          <div>
            <div class="rn-title"><?= htmlspecialchars($n['title']) ?></div>
            <div class="rn-date"><?= $dateStr ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </aside>

</div><!-- /layout -->

<script>
// ── Profile ──
function openProfile()  { document.getElementById('profilePanel').classList.add('open'); document.getElementById('overlayBg').classList.add('open'); }
function closeProfile() { document.getElementById('profilePanel').classList.remove('open'); document.getElementById('overlayBg').classList.remove('open'); }
function setActive(el, title) {
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  if (el) el.classList.add('active');
  document.getElementById('pageTitle').textContent = title;
}

// ── Notice Modal ──
function openModal(d) {
  const modal = document.getElementById('noticeModal');
  const poster = document.getElementById('modalPoster');

  // set poster background or image
  if (d.poster) {
    poster.style.background = '#111';
    document.getElementById('modalIcon').textContent = '';
    // image already in PHP as img tag, re-inject
    let img = poster.querySelector('img');
    if (!img) { img = document.createElement('img'); img.style.cssText='position:absolute;inset:0;width:100%;height:100%;object-fit:cover;'; poster.prepend(img); }
    img.src = d.poster;
  } else {
    poster.style.background = d.bg;
    document.getElementById('modalIcon').textContent = d.icon;
    const img = poster.querySelector('img'); if (img) img.remove();
  }

  // tag chip
  const tagEl = document.getElementById('modalTag');
  tagEl.textContent = d.tagLabel;
  tagEl.style.background = d.chipBg;
  tagEl.style.color = d.chipCol;

  document.getElementById('modalTitle').textContent = d.title;

  let meta = '';
  if (d.date) meta += `<div class="modal-meta-row">📅 <strong>${d.date}</strong></div>`;
  if (d.loc)  meta += `<div class="modal-meta-row">📍 <strong>${d.loc}</strong></div>`;
  document.getElementById('modalMeta').innerHTML = meta;

  const contentEl = document.getElementById('modalContent');
  if (d.content) { contentEl.textContent = d.content; contentEl.style.display = 'block'; }
  else { contentEl.style.display = 'none'; }

  modal.classList.add('open');
}
function closeModal(e) { if (e.target === document.getElementById('noticeModal')) closeModalDirect(); }
function closeModalDirect() { document.getElementById('noticeModal').classList.remove('open'); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModalDirect(); });

// ── Calendar ──
const today = new Date();
let cy = today.getFullYear(), cm = today.getMonth();
const MONTHS   = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const MONTHS_S = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const events   = <?= $events_js ?>.map(e => ({ ...e, date: new Date(e.date) }));

function renderCal() {
  document.getElementById('calMonth').textContent = MONTHS[cm] + ' ' + cy;
  const first  = new Date(cy, cm, 1).getDay();
  const days   = new Date(cy, cm + 1, 0).getDate();
  const evDays = events.filter(e => e.date.getFullYear()===cy && e.date.getMonth()===cm).map(e => e.date.getDate());
  let html = '';
  for (let i = 0; i < first; i++) html += '<div class="cal-cell empty"></div>';
  for (let d = 1; d <= days; d++) {
    const isToday = d===today.getDate() && cm===today.getMonth() && cy===today.getFullYear();
    const hasEv   = evDays.includes(d);
    html += `<div class="cal-cell${isToday?' today':''}${hasEv?' has-event':''}">${d}</div>`;
  }
  document.getElementById('calGrid').innerHTML = html;
  const upcoming = events.filter(e => e.date >= today).sort((a,b)=>a.date-b.date).slice(0,3);
  document.getElementById('evList').innerHTML = upcoming.length
    ? upcoming.map(e => `<div class="ev-item"><div class="ev-date"><div class="ev-d">${e.date.getDate()}</div><div class="ev-m">${MONTHS_S[e.date.getMonth()]}</div></div><div class="ev-info"><div class="ev-name">${e.name}</div><div class="ev-time">${e.time}</div></div></div>`).join('')
    : '<div style="font-size:11px;color:var(--muted);text-align:center;padding:12px 0">No upcoming events</div>';
}
function changeMonth(d) { cm+=d; if(cm<0){cm=11;cy--;} if(cm>11){cm=0;cy++;} renderCal(); }
renderCal();

// ── Notification panel ──
function toggleNotifPanel() {
  document.getElementById('notifPanel').classList.toggle('open');
}
// Close when clicking outside
document.addEventListener('click', function(e) {
  const wrap = document.getElementById('notifWrap');
  if (wrap && !wrap.contains(e.target)) {
    document.getElementById('notifPanel').classList.remove('open');
  }
});

function openSignoutToast()  { document.getElementById('signoutToast').classList.add('open'); }
function closeSignoutToast() { document.getElementById('signoutToast').classList.remove('open'); }
// Close on backdrop click
document.getElementById('signoutToast').addEventListener('click', function(e) {
  if (e.target === this) closeSignoutToast();
});

// ── Password strength meter ──
(function () {
  const inp = document.querySelector('input[name="new_password"]');
  if (!inp) return;
  inp.addEventListener('input', function () {
    const v = this.value;
    const wrap  = document.getElementById('pwStrengthWrap');
    const bar   = document.getElementById('pwStrengthBar');
    const label = document.getElementById('pwStrengthLabel');
    if (!v) { wrap.style.display = 'none'; return; }
    wrap.style.display = 'block';

    let score = 0;
    if (v.length >= 8)  score++;
    if (v.length >= 12) score++;
    if (/[A-Z]/.test(v)) score++;
    if (/[0-9]/.test(v)) score++;
    if (/[^A-Za-z0-9]/.test(v)) score++;

    const levels = [
      { pct: '20%', bg: '#ef4444', text: 'Very weak' },
      { pct: '40%', bg: '#f97316', text: 'Weak'      },
      { pct: '60%', bg: '#eab308', text: 'Fair'      },
      { pct: '80%', bg: '#22c55e', text: 'Strong'    },
      { pct: '100%',bg: '#16a34a', text: 'Very strong'},
    ];
    const l = levels[Math.min(score - 1, 4)] || levels[0];
    bar.style.width      = l.pct;
    bar.style.background = l.bg;
    label.textContent    = l.text;
    label.style.color    = l.bg;
  });
})();

// ── Prevent closing the forced password modal by clicking backdrop ──
const pwModal = document.getElementById('pwChangeModal');
if (pwModal) {
  pwModal.addEventListener('click', function (e) {
    // only block if still "forced" (no success state)
    <?php if ($must_change_pw): ?>
    e.stopPropagation();
    <?php endif; ?>
  });
}

// ── Auto-open profile panel if password change was submitted from there ──
<?php if (($pw_success || $pw_error) && !$must_change_pw): ?>
  window.addEventListener('load', () => openProfile());
<?php endif; ?>

// ── Strength meter for profile panel ──
(function () {
  const inp = document.getElementById('profilePwInput');
  if (!inp) return;
  inp.addEventListener('input', function () {
    const v = this.value;
    const wrap  = document.getElementById('profilePwStrengthWrap');
    const bar   = document.getElementById('profilePwStrengthBar');
    const label = document.getElementById('profilePwStrengthLabel');
    if (!v) { wrap.style.display = 'none'; return; }
    wrap.style.display = 'block';
    let score = 0;
    if (v.length >= 8)  score++;
    if (v.length >= 12) score++;
    if (/[A-Z]/.test(v)) score++;
    if (/[0-9]/.test(v)) score++;
    if (/[^A-Za-z0-9]/.test(v)) score++;
    const levels = [
      { pct:'20%', bg:'#ef4444', text:'Very weak'  },
      { pct:'40%', bg:'#f97316', text:'Weak'        },
      { pct:'60%', bg:'#eab308', text:'Fair'        },
      { pct:'80%', bg:'#22c55e', text:'Strong'      },
      { pct:'100%',bg:'#16a34a', text:'Very strong' },
    ];
    const l = levels[Math.min(score - 1, 4)] || levels[0];
    bar.style.width      = l.pct;
    bar.style.background = l.bg;
    label.textContent    = l.text;
    label.style.color    = l.bg;
  });
})();

// ── Strength meter for profile panel ──
(function () {
  const inp = document.getElementById('profilePwInput');
  if (!inp) return; // not shown when already set
  inp.addEventListener('input', function () {
    const v = this.value;
    const wrap  = document.getElementById('profilePwStrengthWrap');
    const bar   = document.getElementById('profilePwStrengthBar');
    const label = document.getElementById('profilePwStrengthLabel');
    if (!v) { wrap.style.display = 'none'; return; }
    wrap.style.display = 'block';
    let score = 0;
    if (v.length >= 8)  score++;
    if (v.length >= 12) score++;
    if (/[A-Z]/.test(v)) score++;
    if (/[0-9]/.test(v)) score++;
    if (/[^A-Za-z0-9]/.test(v)) score++;
    const levels = [
      { pct:'20%', bg:'#ef4444', text:'Very weak'  },
      { pct:'40%', bg:'#f97316', text:'Weak'        },
      { pct:'60%', bg:'#eab308', text:'Fair'        },
      { pct:'80%', bg:'#22c55e', text:'Strong'      },
      { pct:'100%',bg:'#16a34a', text:'Very strong' },
    ];
    const l = levels[Math.min(score - 1, 4)] || levels[0];
    bar.style.width = l.pct; bar.style.background = l.bg;
    label.textContent = l.text; label.style.color = l.bg;
  });
})();

// ── Auto-open profile panel after successful password set ──
<?php if ($pw_success): ?>
  window.addEventListener('load', () => openProfile());
<?php endif; ?>
</script>
</body>
</html>