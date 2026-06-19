<?php
require_once __DIR__ . '/auth/db.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: index.php'); exit;
}

$user_id    = (int)$_SESSION['user_id'];
$name       = htmlspecialchars($_SESSION['full_name'] ?? 'Student');
$student_id = htmlspecialchars($_SESSION['student_id'] ?? '');
$first      = explode(' ', trim($name))[0];
$words      = array_filter(explode(' ', trim($name)));
$initials   = strtoupper(substr($words[0]??'S',0,1).substr(end($words)??'',0,1));

$msg_success = '';
$msg_error   = '';



// Restructure into a simple lookup: tutor_id => [avg, count]
$tutor_ratings = [];
$rr = $pdo->query("
    SELECT rated_id, ROUND(AVG(stars),1) AS avg, COUNT(*) AS cnt
    FROM ratings WHERE module='peer_tutoring' GROUP BY rated_id
")->fetchAll();
foreach ($rr as $r) {
    $tutor_ratings[$r['rated_id']] = ['avg' => $r['avg'], 'cnt' => $r['cnt']];
}

// ── Fetch notifications ──
$notifs_q = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 20");
$notifs_q->execute([$user_id]);
$notifs_all = $notifs_q->fetchAll();
$unread_count = count(array_filter($notifs_all, fn($n) => !$n['is_read']));

// ── Mark all read on POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_notifs_read') {
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$user_id]);
    header('Location: brain_bridge.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    /* ── JOIN SESSION ── */
    if ($_POST['action'] === 'rsvp') {
        $offer_id = (int)($_POST['offer_id'] ?? 0);
        $own = $pdo->prepare("SELECT tutor_id FROM tutor_offers WHERE id=?");
        $own->execute([$offer_id]);
        $row = $own->fetch();
        if (!$row) {
            $msg_error = 'Session not found.';
        } elseif ($row['tutor_id'] == $user_id) {
            $msg_error = 'You cannot join your own session.';
        } else {
            $chk = $pdo->prepare("SELECT id,status FROM session_registrations WHERE tutor_offer_id=? AND student_id=?");
            $chk->execute([$offer_id, $user_id]);
            $ex = $chk->fetch();
            if ($ex && $ex['status'] === 'attending') {
                $msg_error = 'You have already joined this session.';
            } elseif ($ex && $ex['status'] === 'cancelled') {
                $pdo->prepare("UPDATE session_registrations SET status='attending',registered_at=NOW() WHERE tutor_offer_id=? AND student_id=?")
                    ->execute([$offer_id, $user_id]);
                $msg_success = 'You have re-joined the session! The tutor can now see you.';
            } else {
                $cap = $pdo->prepare("SELECT COALESCE(max_students,10) FROM tutor_offers WHERE id=?");
                $cap->execute([$offer_id]);
                $max = (int)$cap->fetchColumn();
                $cnt = $pdo->prepare("SELECT COUNT(*) FROM session_registrations WHERE tutor_offer_id=? AND status='attending'");
                $cnt->execute([$offer_id]);
                $cur    = (int)$cnt->fetchColumn();
                $status = ($cur >= $max) ? 'waitlisted' : 'attending';
                $note   = trim($_POST['rsvp_message'] ?? '');
                $pdo->prepare("INSERT INTO session_registrations (tutor_offer_id,student_id,status,message) VALUES(?,?,?,?)")
    ->execute([$offer_id, $user_id, $status, $note]);
$msg_success = $status === 'attending'
    ? '✓ You\'ve joined the session! The tutor has been notified.'
    : '⏳ Session is full — you\'ve been added to the waitlist.';

// ── Notify the tutor ──
$offer_info = $pdo->prepare("
    SELECT t.tutor_id, m.module_code, m.module_name
    FROM tutor_offers t JOIN modules m ON m.id = t.module_id
    WHERE t.id = ?
");
$offer_info->execute([$offer_id]);
$offer_info = $offer_info->fetch();

if ($offer_info) {
    $action_label = $status === 'attending' ? 'joined' : 'joined the waitlist for';
    $notif_msg = "<strong>" . htmlspecialchars($_SESSION['full_name']) . "</strong> has {$action_label} your "
               . "<strong>" . htmlspecialchars($offer_info['module_code'] . ' — ' . $offer_info['module_name']) . "</strong> tutoring session.";
    $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, 'session_join', 'New Student Joined 🎓', ?, 'brain_bridge.php')")
        ->execute([$offer_info['tutor_id'], $notif_msg]);
}
            }
        }
    }

    /* ── CANCEL RSVP ── */
    if ($_POST['action'] === 'cancel_rsvp') {
        $offer_id = (int)($_POST['offer_id'] ?? 0);
        $pdo->prepare("UPDATE session_registrations SET status='cancelled' WHERE tutor_offer_id=? AND student_id=?")
            ->execute([$offer_id, $user_id]);
        $msg_success = 'Registration cancelled.';
    }

    /* ── REQUEST TUTOR ── */
    if ($_POST['action'] === 'submit_request') {
        $module_id = (int)($_POST['module_id'] ?? 0);
        $pref_mode = $_POST['preferred_mode'] ?? 'both';
        $message   = trim($_POST['message'] ?? '');
        if ($module_id <= 0) {
            $msg_error = 'Please select a module.';
        } else {
            $chk = $pdo->prepare("SELECT id FROM module_requests WHERE student_id=? AND module_id=? AND status='pending'");
            $chk->execute([$user_id, $module_id]);
            if ($chk->fetch()) {
                $msg_error = 'You already have a pending request for this module.';
            } else {
                $pdo->prepare("INSERT INTO module_requests (student_id,module_id,preferred_mode,message) VALUES(?,?,?,?)")
                    ->execute([$user_id, $module_id, $pref_mode, $message]);
                $msg_success = 'Your request has been submitted!';
            }
        }
    }

    /* ── OFFER TO TUTOR ── */
    if ($_POST['action'] === 'offer_tutor') {
        $module_id    = (int)($_POST['offer_module_id'] ?? 0);
        $avail        = trim($_POST['availability'] ?? '');
        $pref_mode    = $_POST['offer_mode'] ?? 'both';
        $note         = trim($_POST['offer_note'] ?? '');
        $max_students = max(1, min(50, (int)($_POST['max_students'] ?? 10)));
        $sess_date    = $_POST['session_date'] ?? null;
       $sess_time       = trim($_POST['session_time'] ?? '');
$campus_location = trim($_POST['campus_location'] ?? '');
// Sanitise & validate the Zoom / meeting link
$meeting_link    = trim($_POST['meeting_link'] ?? '');
        if ($meeting_link && !filter_var($meeting_link, FILTER_VALIDATE_URL)) {
            $msg_error = 'Please enter a valid meeting link (must start with https://).';
        } elseif ($module_id <= 0) {
            $msg_error = 'Please select a module to offer.';
        } else {
           $pdo->prepare("INSERT INTO tutor_offers
                   (tutor_id,module_id,availability,preferred_mode,note,max_students,session_date,session_time,meeting_link,campus_location)
               VALUES(?,?,?,?,?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE
                 availability=VALUES(availability),preferred_mode=VALUES(preferred_mode),
                 note=VALUES(note),max_students=VALUES(max_students),
                 session_date=VALUES(session_date),session_time=VALUES(session_time),
                 meeting_link=VALUES(meeting_link),
                 campus_location=VALUES(campus_location),
                 status='active'")
    ->execute([$user_id, $module_id, $avail, $pref_mode, $note, $max_students,
               $sess_date ?: null, $sess_time, $meeting_link ?: null,
               $campus_location ?: null]);
           $msg_success = 'You are now listed as a tutor!';
$new_offer_id = (int)$pdo->lastInsertId();
// ── Notify students who requested this module ──
$new_offer_id = (int)$pdo->lastInsertId();

// Get module details
$mod_info = $pdo->prepare("SELECT module_code, module_name FROM modules WHERE id = ?");
$mod_info->execute([$module_id]);
$mod_info = $mod_info->fetch();

if ($mod_info && $new_offer_id) {
    // Find all students with a pending request for this module (excluding the tutor themselves)
    $interested = $pdo->prepare("
        SELECT DISTINCT student_id FROM module_requests
        WHERE module_id = ? AND status = 'pending' AND student_id != ?
    ");
    $interested->execute([$module_id, $user_id]);
    $interested = $interested->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($interested)) {
        $tutor_name = htmlspecialchars($_SESSION['full_name']);
        $mod_label  = htmlspecialchars($mod_info['module_code'] . ' — ' . $mod_info['module_name']);
        $date_str   = $sess_date ? ' on <strong>' . date('d M Y', strtotime($sess_date)) . '</strong>' : '';
        $notif_msg  = "<strong>{$tutor_name}</strong> just posted a new tutoring session for "
                    . "<strong>{$mod_label}</strong>{$date_str}. Go to Brain Bridge to join!";

        $ins = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, 'new_session', 'New Tutoring Session 🧠', ?, 'brain_bridge.php')");
        foreach ($interested as $sid) {
            $ins->execute([$sid, $notif_msg]);
        }
    }
}
        }
    }



    /* ── RATE TUTOR (after session) ── */
    if ($_POST['action'] === 'rate_tutor') {
    $offer_id = (int)($_POST['offer_id'] ?? 0);
    $stars    = max(1, min(5, (int)($_POST['stars'] ?? 5)));
    $comment  = trim($_POST['comment'] ?? '');

    // Verify the rater attended this session
    $chk = $pdo->prepare("
        SELECT sr.id, t.tutor_id FROM session_registrations sr
        JOIN tutor_offers t ON t.id = sr.tutor_offer_id
        WHERE sr.tutor_offer_id=? AND sr.student_id=? AND sr.status='attending'
    ");
    $chk->execute([$offer_id, $user_id]);
    $reg = $chk->fetch();

    if (!$reg) {
        $msg_error = 'You can only rate sessions you attended.';
    } else {
        $exists = $pdo->prepare("SELECT id FROM ratings WHERE rater_id=? AND ref_id=? AND module='peer_tutoring'");
        $exists->execute([$user_id, $offer_id]);
        if ($exists->fetch()) {
            $msg_error = 'You have already rated this tutor for this session.';
        } else {
            $pdo->prepare("INSERT INTO ratings (rater_id,rated_id,module,ref_id,stars,comment) VALUES(?,?,'peer_tutoring',?,?,?)")
                ->execute([$user_id, $reg['tutor_id'], $offer_id, $stars, $comment ?: null]);
            $msg_success = '⭐ Thank you for rating your tutor!';
         }
    }
} 
} 

/* ── FETCH DATA ── */
$all_modules = $pdo->query("SELECT id,module_code,module_name FROM modules ORDER BY module_name")->fetchAll();

$ts = $pdo->prepare("
    SELECT t.id AS offer_id, t.tutor_id,
           u.full_name, u.student_id AS tutor_sid,
           m.module_code, m.module_name,
           t.availability, t.preferred_mode, t.note, t.created_at,
           COALESCE(t.max_students,10) AS max_students,
           t.session_date, t.session_time,
           t.meeting_link,
           t.campus_location,
           COUNT(CASE WHEN r.status='attending' THEN 1 END)  AS attendee_count,
           MAX(CASE WHEN r.student_id=:me  AND r.status='attending'  THEN 1 ELSE 0 END) AS i_am_attending,
           MAX(CASE WHEN r.student_id=:me2 AND r.status='waitlisted' THEN 1 ELSE 0 END) AS i_am_waitlisted
    FROM tutor_offers t
    JOIN users   u ON u.id=t.tutor_id
    JOIN modules m ON m.id=t.module_id
    LEFT JOIN session_registrations r ON r.tutor_offer_id=t.id
    WHERE t.status='active'
  AND (t.session_date IS NULL OR t.session_date >= CURDATE())
    GROUP BY t.id ORDER BY t.created_at DESC
");
$ts->execute([':me' => $user_id, ':me2' => $user_id]);
$tutors = $ts->fetchAll();

$stmt = $pdo->prepare("SELECT r.id,m.module_code,m.module_name,r.preferred_mode,r.status,r.created_at
    FROM module_requests r JOIN modules m ON m.id=r.module_id
    WHERE r.student_id=? ORDER BY r.created_at DESC");
$stmt->execute([$user_id]);
$my_requests = $stmt->fetchAll();

$rs = $pdo->prepare("SELECT sr.status,sr.registered_at,t.id AS offer_id,
       u.full_name AS tutor_name,m.module_code,m.module_name,
       t.session_date,t.session_time,t.preferred_mode,t.meeting_link
    FROM session_registrations sr
    JOIN tutor_offers t ON t.id=sr.tutor_offer_id
    JOIN users u ON u.id=t.tutor_id
    JOIN modules m ON m.id=t.module_id
    WHERE sr.student_id=? ORDER BY sr.registered_at DESC");
$rs->execute([$user_id]);
$my_sessions = $rs->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Brain Bridge — UniConnect</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=Nunito:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --coral: #EE4540;
  --crimson: #C72C41;
  --wine: #801336;
  --plum: #510A32;
  --midnight: #2D142C;
  --bg: #f5eeee;
  --card: #ffffff;
  --text: #2D142C;
  --muted: #9a8080;
  --border: #eedede;
  --sidebar-w:230px;
  --green: #2e9e68;
  --amber: #d97706;--zoom:#2D8CFF;
}
html,body{height:100%;font-family:'Nunito',sans-serif;background:var(--bg);color:var(--text);overflow:hidden;}
.layout{display:grid;grid-template-columns:var(--sidebar-w) 1fr;grid-template-rows:100vh;height:100vh;}

/* SIDEBAR */
.sidebar{background:linear-gradient(175deg,var(--midnight) 0%,var(--plum) 55%,var(--wine) 100%);display:flex;flex-direction:column;height:100vh;overflow:hidden;position:relative;}
.sidebar::after{content:'';position:absolute;top:0;right:0;width:1.5px;height:100%;background:linear-gradient(to bottom,transparent 0%,rgba(138,43,226,0.5) 25%,rgba(238,69,64,0.5) 60%,transparent 100%);}
.sidebar-brand{padding:26px 20px 18px;display:flex;align-items:center;gap:11px;border-bottom:1px solid rgba(255,255,255,0.06);flex-shrink:0;}
.brand-img{width:36px;height:36px;border-radius:50%;border:1.5px solid rgba(255,255,255,0.25);overflow:hidden;flex-shrink:0;background:rgba(255,255,255,0.1);}
.brand-img img{width:100%;height:100%;object-fit:cover;}
.brand-name{font-family:'Cormorant Garamond',serif;font-size:16px;font-weight:600;color:#fff;letter-spacing:.02em;line-height:1.1;}
.brand-sub{font-size:9px;color:rgba(255,255,255,0.35);letter-spacing:.1em;text-transform:uppercase;}
.nav-scroll{flex:1;overflow-y:auto;padding:14px 0;}.nav-scroll::-webkit-scrollbar{display:none;}
.nav-section-label{font-size:9px;letter-spacing:.2em;text-transform:uppercase;color:rgba(255,255,255,0.25);font-weight:600;padding:10px 20px 6px;}
.nav-item{display:flex;align-items:center;gap:11px;padding:10px 20px;color:rgba(255,255,255,0.58);font-size:12.5px;font-weight:500;text-decoration:none;cursor:pointer;border-left:2.5px solid transparent;transition:all .25s ease;}
.nav-item:hover{color:#fff;background:rgba(255,255,255,0.06);border-left-color:rgba(238,69,64,0.4);}
.nav-item.active{color:#fff;background:rgba(238,69,64,0.14);border-left-color:var(--coral);}
.nav-item.active .ni{color:var(--coral);}
.ni{font-size:15px;width:18px;text-align:center;flex-shrink:0;}
.nav-pill{margin-left:auto;background:var(--coral);color:#fff;font-size:8.5px;font-weight:700;padding:2px 7px;border-radius:50px;}
.sidebar-foot{border-top:1px solid rgba(255,255,255,0.06);padding:14px 16px 20px;flex-shrink:0;}
.user-chip{display:flex;align-items:center;gap:10px;padding:9px 11px;border-radius:10px;cursor:pointer;text-decoration:none;transition:background .25s;}
.user-chip:hover{background:rgba(255,255,255,0.08);}
.ava{width:32px;height:32px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,var(--coral),var(--crimson));display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;box-shadow:0 2px 8px rgba(238,69,64,0.4);}
.user-chip-name{font-size:11.5px;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.user-chip-id{font-size:9.5px;color:rgba(255,255,255,0.35);}
.chip-arrow{margin-left:auto;color:rgba(255,255,255,0.3);font-size:13px;}

/* MAIN */
.main{display:flex;flex-direction:column;height:100vh;overflow:hidden;}
.topbar{background:rgba(245,238,238,.92);backdrop-filter:blur(10px);border-bottom:1px solid var(--border);padding:0 32px;height:60px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;position:relative;z-index:100;overflow:visible;}.page-title{font-family:'Cormorant Garamond',serif;font-size:20px;font-weight:600;color:var(--midnight);}
.topbar-right{display:flex;align-items:center;gap:10px;}
.back-btn{display:inline-flex;align-items:center;gap:7px;padding:8px 18px;border:1px solid var(--border);border-radius:50px;font-size:12px;font-weight:600;color:var(--muted);text-decoration:none;transition:all .2s;background:var(--card);}
.back-btn:hover{border-color:var(--crimson);color:var(--crimson);}
.profile-chip{display:flex;align-items:center;gap:8px;padding:5px 12px 5px 6px;border:1px solid var(--border);border-radius:50px;background:var(--card);cursor:pointer;}
.ava-sm{width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,var(--coral),var(--crimson));display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;}
.chip-name{font-size:12px;font-weight:600;color:var(--text);}
.scroll-area{flex:1;overflow-y:auto;padding:28px 32px 48px;}
.scroll-area::-webkit-scrollbar{width:4px;}
.scroll-area::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px;}

/* HERO */
.hero{background:linear-gradient(125deg,var(--midnight) 0%,var(--plum) 45%,var(--wine) 80%,var(--crimson) 100%);border-radius:20px;display:grid;grid-template-columns:1fr 300px;overflow:hidden;margin-bottom:32px;min-height:210px;position:relative;animation:fadeUp .5s ease both;}
.hero-deco-1{position:absolute;width:260px;height:260px;border-radius:50%;background:rgba(238,69,64,0.1);top:-80px;left:35%;pointer-events:none;}
.hero-deco-2{position:absolute;width:140px;height:140px;border-radius:50%;background:rgba(255,255,255,0.04);bottom:-40px;left:8%;pointer-events:none;}
.hero-text{padding:36px 32px;position:relative;z-index:1;display:flex;flex-direction:column;justify-content:center;}
.hero-eyebrow{font-size:9px;letter-spacing:.22em;text-transform:uppercase;color:rgba(255,255,255,0.4);font-weight:600;margin-bottom:10px;}
.hero-title{font-family:'Cormorant Garamond',serif;font-size:34px;font-weight:600;color:#fff;line-height:1.1;margin-bottom:10px;}
.hero-title em{color:var(--coral);font-style:italic;}
.hero-sub{font-size:12px;color:rgba(255,255,255,0.45);font-weight:300;line-height:1.65;max-width:380px;}
.hero-stats{display:flex;gap:0;margin-top:22px;}
.hero-stat{text-align:center;padding:0 20px;}.hero-stat:first-child{padding-left:0;}
.hero-stat+.hero-stat{border-left:1px solid rgba(255,255,255,0.1);}
.hero-stat-val{font-family:'Cormorant Garamond',serif;font-size:26px;font-weight:600;color:#fff;line-height:1;}
.hero-stat-label{font-size:9px;color:rgba(255,255,255,0.38);text-transform:uppercase;letter-spacing:.1em;margin-top:3px;}
.hero-illus{position:relative;display:flex;align-items:flex-end;justify-content:center;overflow:hidden;}
.hero-illus img{width:100%;height:100%;object-fit:contain;object-position:bottom center;}
.illus-placeholder{width:100%;height:100%;display:flex;align-items:center;justify-content:center;}
.illus-hint{border:1.5px dashed rgba(255,255,255,0.18);border-radius:12px;padding:14px 18px;text-align:center;color:rgba(255,255,255,0.28);font-size:11px;font-weight:500;}
.illus-hint span{display:block;font-size:24px;margin-bottom:5px;}

.two-col {
  display:grid;
  grid-template-columns:1fr 1fr;
}

/* ALERTS */
.alert{padding:14px 20px;border-radius:12px;margin-bottom:22px;font-size:12.5px;display:flex;align-items:center;gap:10px;font-weight:500;animation:fadeUp .3s ease;}
.alert-success{background:rgba(46,158,104,0.08);border:1px solid rgba(46,158,104,0.22);color:var(--green);}
.alert-error{background:rgba(238,69,64,0.08);border:1px solid rgba(238,69,64,0.25);color:var(--coral);}

.notif-panel {
  position:absolute; top:calc(100% + 10px); right:0; width:340px;
  background:var(--card); border:1px solid var(--border); border-radius:16px;
  box-shadow:0 12px 36px rgba(14,116,144,.16); z-index:9999;
}
/* TABS */
.tabs-row{display:flex;gap:6px;margin-bottom:28px;flex-wrap:wrap;}
.tab-btn{display:flex;align-items:center;gap:8px;padding:11px 22px;border:1.5px solid var(--border);border-radius:50px;background:var(--card);color:var(--muted);font-family:'Nunito',sans-serif;font-size:12.5px;font-weight:600;cursor:pointer;transition:all .25s;white-space:nowrap;}
.tab-btn:hover{border-color:rgba(199,44,65,0.3);color:var(--crimson);}
.tab-btn.active{background:linear-gradient(135deg,var(--crimson),var(--wine));border-color:transparent;color:#fff;box-shadow:0 4px 16px rgba(199,44,65,0.28);}
.tab-count{background:rgba(255,255,255,0.22);padding:1px 8px;border-radius:50px;font-size:10px;}
.tab-btn:not(.active) .tab-count{background:rgba(199,44,65,0.1);color:var(--crimson);}
.tab-panel{display:none;animation:fadeUp .35s ease both;}
.tab-panel.active{display:block;}

/* SECTION TITLE */
.section-title{font-family:'Cormorant Garamond',serif;font-size:18px;font-weight:600;color:var(--midnight);margin-bottom:18px;display:flex;align-items:center;gap:10px;}
.section-title::before{content:'';width:3px;height:18px;border-radius:2px;background:linear-gradient(to bottom,var(--coral),var(--crimson));flex-shrink:0;}

/* FILTER BAR */
.filter-bar{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;}
.search-wrap{flex:1;min-width:180px;position:relative;}
.search-wrap input{width:100%;padding:10px 16px 10px 38px;background:var(--card);border:1px solid var(--border);border-radius:50px;color:var(--text);font-family:'Nunito',sans-serif;font-size:12px;outline:none;transition:border-color .2s;}
.search-wrap input:focus{border-color:var(--crimson);}
.search-wrap input::placeholder{color:var(--muted);}
.si{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px;pointer-events:none;}
.fsel{padding:10px 34px 10px 16px;background:var(--card);border:1px solid var(--border);border-radius:50px;color:var(--text);font-family:'Nunito',sans-serif;font-size:12px;outline:none;cursor:pointer;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239a8080' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 13px center;}

/* ══════════════════════
   TUTOR CARD + RSVP
══════════════════════ */
.tutor-grid{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(280px,1fr));
  gap:18px;
  align-items:stretch;
}
.tutor-card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;transition:transform .3s,box-shadow .3s,border-color .3s;animation:fadeUp .4s ease both;display:flex;flex-direction:column;}
.tutor-card:hover{transform:translateY(-4px);box-shadow:0 14px 34px rgba(45,20,44,0.11);border-color:rgba(199,44,65,0.22);}
.tutor-card.is-attending{border-color:rgba(46,158,104,0.45);box-shadow:0 4px 18px rgba(46,158,104,0.1);}
.tutor-card.is-waitlisted{border-color:rgba(217,119,6,0.4);}

.tutor-card-top{height:120px;background:linear-gradient(135deg,var(--midnight) 0%,var(--plum) 55%,var(--wine) 100%);display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden;}
.tutor-card-top::before{content:'';position:absolute;width:140px;height:140px;border-radius:50%;background:rgba(238,69,64,0.1);top:-40px;right:-30px;}
.tutor-card-top::after{content:'';position:absolute;width:70px;height:70px;border-radius:50%;background:rgba(255,255,255,0.04);bottom:-15px;left:16px;}
.tutor-card-bg-img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.15;}
.tutor-avatar{width:58px;height:58px;border-radius:50%;background:linear-gradient(135deg,var(--coral),var(--crimson));display:flex;align-items:center;justify-content:center;font-family:'Cormorant Garamond',serif;font-size:22px;font-weight:600;color:#fff;border:3px solid rgba(255,255,255,0.18);position:relative;z-index:1;box-shadow:0 4px 16px rgba(0,0,0,0.28);}
.tutor-card.is-attending .tutor-avatar::after,
.tutor-card.is-waitlisted .tutor-avatar::after{
  content:'';position:absolute;bottom:-3px;right:-3px;
  width:18px;height:18px;border-radius:50%;
  border:2px solid var(--card);
  display:flex;align-items:center;justify-content:center;
  font-size:9px;font-family:'Nunito',sans-serif;font-weight:700;
}
.tutor-card.is-attending .tutor-avatar::after{content:'✓';background:var(--green);color:#fff;}
.tutor-card.is-waitlisted .tutor-avatar::after{content:'⏳';background:var(--amber);color:#fff;}

.tutor-body{padding:14px 16px 16px;flex:1;display:flex;flex-direction:column;gap:8px;}
.tutor-name{font-family:'Cormorant Garamond',serif;font-size:15px;font-weight:600;color:var(--midnight);}
.tutor-sid{font-size:10px;color:var(--muted);}
.tutor-module{display:inline-flex;align-items:center;gap:5px;background:rgba(199,44,65,0.07);color:var(--crimson);padding:4px 11px;border-radius:50px;font-size:11px;font-weight:600;}
.tutor-tags{display:flex;flex-wrap:wrap;gap:6px;}
.ttag{padding:3px 10px;border-radius:50px;font-size:10px;font-weight:600;}
.ttag-mode{background:rgba(81,10,50,0.07);color:var(--plum);}
.ttag-avail{background:rgba(238,69,64,0.07);color:var(--coral);}
.ttag-date{background:rgba(45,20,44,0.07);color:var(--midnight);}
.tutor-note{font-size:11px;color:var(--muted);line-height:1.55;font-style:italic;border-top:1px solid var(--border);padding-top:8px;}

/* Seats progress */
.seats-bar{margin-top:2px;}
.seats-meta{display:flex;justify-content:space-between;font-size:10px;color:var(--muted);margin-bottom:5px;}
.seats-cnt{font-weight:600;color:var(--text);}
.seats-cnt.full{color:var(--amber);}
.seats-track{height:5px;background:var(--border);border-radius:50px;overflow:hidden;}
.seats-fill{height:100%;border-radius:50px;background:linear-gradient(to right,var(--coral),var(--crimson));transition:width .5s ease;}
.seats-fill.near{background:linear-gradient(to right,var(--amber),#c97706);}
.seats-fill.seats-full{background:var(--muted);}

/* ── RSVP BOTTOM ── */
.rsvp-section{border-top:1px solid var(--border);padding-top:12px;margin-top:auto;}

/* Attending badge */
.status-box{display:flex;align-items:center;gap:10px;padding:10px 13px;border-radius:10px;}
.status-box.attending{background:rgba(46,158,104,0.07);border:1px solid rgba(46,158,104,0.2);}
.status-box.waitlisted{background:rgba(217,119,6,0.07);border:1px solid rgba(217,119,6,0.22);}
.status-icon{font-size:20px;flex-shrink:0;}
.status-label{font-size:11.5px;font-weight:700;line-height:1.25;}
.status-box.attending .status-label{color:var(--green);}
.status-box.waitlisted .status-label{color:var(--amber);}
.status-sub{font-size:9.5px;font-weight:400;opacity:.75;margin-top:1px;}
.status-box.attending .status-sub{color:var(--green);}
.status-box.waitlisted .status-sub{color:var(--amber);}
.cancel-wrap{margin-top:7px;text-align:right;}
.cancel-btn{background:none;border:none;font-size:10.5px;color:var(--muted);cursor:pointer;font-family:'Nunito',sans-serif;text-decoration:underline;padding:0;}
.cancel-btn:hover{color:var(--coral);}

/* ── ZOOM LINK BOX (shown to registered students only when date is set) ── */
.zoom-link-box{
  display:flex;align-items:center;gap:10px;
  padding:10px 13px;border-radius:10px;margin-top:8px;
  background:rgba(45,140,255,0.07);
  border:1.5px solid rgba(45,140,255,0.25);
  text-decoration:none;
  transition:background .2s,border-color .2s,transform .2s;
  cursor:pointer;
}
.zoom-link-box:hover{
  background:rgba(45,140,255,0.13);
  border-color:rgba(45,140,255,0.45);
  transform:translateY(-1px);
}
.zoom-icon{
  width:32px;height:32px;border-radius:8px;flex-shrink:0;
  background:var(--zoom);display:flex;align-items:center;
  justify-content:center;font-size:16px;box-shadow:0 2px 8px rgba(45,140,255,0.3);
}
.zoom-label{font-size:11px;font-weight:700;color:var(--zoom);line-height:1.25;}
.zoom-sub{font-size:9.5px;color:rgba(45,140,255,0.7);margin-top:1px;}
.zoom-arrow{margin-left:auto;color:var(--zoom);font-size:14px;opacity:.6;}

/* Join button */
.join-btn{width:100%;padding:11px 14px;border:none;border-radius:11px;color:#fff;font-family:'Nunito',sans-serif;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;transition:all .25s;}
.join-btn.join-mode{background:linear-gradient(135deg,var(--crimson),var(--wine));box-shadow:0 4px 14px rgba(199,44,65,0.22);}
.join-btn.join-mode:hover{transform:translateY(-2px);box-shadow:0 7px 20px rgba(199,44,65,0.34);}
.join-btn.waitlist-mode{background:linear-gradient(135deg,#b06000,var(--amber));box-shadow:0 4px 14px rgba(217,119,6,0.2);}
.join-btn.waitlist-mode:hover{transform:translateY(-2px);box-shadow:0 7px 20px rgba(217,119,6,0.32);}
.join-btn.own-mode{background:var(--border);color:var(--muted);cursor:not-allowed;}
.join-btn.own-mode:hover{transform:none;box-shadow:none;}

/* Expand confirm box */
.msg-expand{overflow:hidden;max-height:0;opacity:0;transition:max-height .38s ease,opacity .3s ease;}
.msg-expand.open{max-height:220px;opacity:1;}
.msg-ta{width:100%;margin-top:10px;margin-bottom:8px;padding:9px 13px;border:1.5px solid var(--border);border-radius:10px;font-family:'Nunito',sans-serif;font-size:12px;color:var(--text);background:var(--bg);outline:none;resize:none;min-height:55px;line-height:1.5;transition:border-color .2s;}
.msg-ta:focus{border-color:var(--crimson);}
.msg-ta::placeholder{color:#c0a8a8;font-weight:300;}
.confirm-btn{width:100%;padding:9px;border:none;border-radius:10px;color:#fff;font-family:'Nunito',sans-serif;font-size:12px;font-weight:700;cursor:pointer;transition:all .25s;}
.confirm-btn.join-confirm{background:linear-gradient(135deg,var(--crimson),var(--wine));box-shadow:0 3px 10px rgba(199,44,65,0.2);}
.confirm-btn.wait-confirm{background:linear-gradient(135deg,#b06000,var(--amber));}
.confirm-btn:hover{transform:translateY(-1px);}

/* FORM CARDS */
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:22px;align-items:start;}
.fcard{background:var(--card);border:1px solid var(--border);border-radius:18px;overflow:hidden;}
.fcard-head{background:linear-gradient(125deg,var(--midnight) 0%,var(--plum) 55%,var(--wine) 100%);display:grid;grid-template-columns:1fr 150px;min-height:130px;position:relative;overflow:hidden;}
.fcard-head-deco{position:absolute;width:160px;height:160px;border-radius:50%;background:rgba(238,69,64,0.1);top:-50px;left:38%;pointer-events:none;}
.fcard-head-text{padding:22px 22px;position:relative;z-index:1;display:flex;flex-direction:column;justify-content:center;}
.fcard-title{font-family:'Cormorant Garamond',serif;font-size:20px;font-weight:600;color:#fff;margin-bottom:5px;line-height:1.1;}
.fcard-sub{font-size:11px;color:rgba(255,255,255,0.42);font-weight:300;line-height:1.5;}
.fcard-illus{display:flex;align-items:flex-end;justify-content:center;position:relative;overflow:hidden;}
.fcard-illus img{width:100%;height:100%;object-fit:contain;object-position:bottom center;}
.fcard-illus-hint{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);border:1.5px dashed rgba(255,255,255,0.16);border-radius:10px;padding:10px 12px;text-align:center;color:rgba(255,255,255,0.22);font-size:10px;white-space:nowrap;}
.fcard-illus-hint span{display:block;font-size:18px;margin-bottom:3px;}
.fcard-body{padding:22px;}
.fg{margin-bottom:15px;}
.fl{display:block;font-size:9.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;}
.fc{width:100%;padding:11px 14px;background:var(--bg);border:1px solid var(--border);border-radius:10px;color:var(--text);font-family:'Nunito',sans-serif;font-size:12.5px;outline:none;transition:border-color .2s,box-shadow .2s;appearance:none;}
.fc:focus{border-color:var(--crimson);box-shadow:0 0 0 3px rgba(199,44,65,0.07);}
.fc option{background:#fff;}
textarea.fc{resize:vertical;min-height:78px;line-height:1.6;}
/* Zoom field highlight */
.fc.zoom-field:focus{border-color:var(--zoom);box-shadow:0 0 0 3px rgba(45,140,255,0.1);}
.zoom-field-wrap{position:relative;}
.zoom-field-wrap .zoom-badge{
  position:absolute;right:12px;top:50%;transform:translateY(-50%);
  background:var(--zoom);color:#fff;font-size:9px;font-weight:700;
  padding:2px 8px;border-radius:50px;pointer-events:none;
}
.mode-row{display:flex;gap:8px;}
.mopt{flex:1;}.mopt input{display:none;}
.mopt label{display:flex;align-items:center;justify-content:center;gap:4px;padding:9px 4px;border:1.5px solid var(--border);border-radius:10px;font-size:11px;font-weight:600;color:var(--muted);cursor:pointer;transition:all .2s;text-align:center;}
.mopt input:checked+label{border-color:var(--crimson);background:rgba(199,44,65,0.06);color:var(--crimson);}
.mopt label:hover{border-color:rgba(199,44,65,0.25);color:var(--crimson);}
.btn-sub{width:100%;padding:13px;border:none;border-radius:12px;background:linear-gradient(135deg,var(--crimson),var(--wine));color:#fff;font-family:'Nunito',sans-serif;font-size:13px;font-weight:700;cursor:pointer;transition:all .25s;box-shadow:0 4px 16px rgba(199,44,65,0.22);}
.btn-sub:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(199,44,65,0.32);}
/* Field helper text */
.fhelp{font-size:10px;color:var(--muted);margin-top:5px;line-height:1.4;}
.fhelp.zoom-help{color:rgba(45,140,255,0.7);}
/* Divider */
.form-divider{border:none;border-top:1px dashed var(--border);margin:18px 0;}

/* INFO CARDS */
.icard{background:var(--card);border:1px solid var(--border);border-radius:18px;overflow:hidden;}
.icard-head{background:linear-gradient(125deg,var(--midnight),var(--plum) 60%,var(--wine));display:grid;grid-template-columns:1fr 130px;min-height:110px;position:relative;overflow:hidden;}
.icard-head-deco{position:absolute;width:130px;height:130px;border-radius:50%;background:rgba(238,69,64,0.1);top:-35px;left:42%;pointer-events:none;}
.icard-head-text{padding:20px 20px;position:relative;z-index:1;display:flex;flex-direction:column;justify-content:center;}
.icard-title{font-family:'Cormorant Garamond',serif;font-size:17px;font-weight:600;color:#fff;margin-bottom:4px;}
.icard-sub{font-size:10px;color:rgba(255,255,255,0.38);font-weight:300;}
.icard-illus{display:flex;align-items:flex-end;justify-content:center;position:relative;overflow:hidden;}
.icard-illus img{width:100%;height:100%;object-fit:contain;object-position:bottom;}
.icard-body{padding:20px;}
.istep{display:flex;gap:12px;align-items:flex-start;margin-bottom:13px;}.istep:last-child{margin-bottom:0;}
.snum{width:24px;height:24px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,var(--coral),var(--crimson));display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;box-shadow:0 2px 8px rgba(199,44,65,0.28);margin-top:1px;}
.stxt{font-size:12px;color:var(--text);line-height:1.55;}.stxt strong{color:var(--midnight);}
.stat-row{display:flex;gap:8px;margin-top:16px;padding-top:16px;border-top:1px solid var(--border);}
.sbox{flex:1;text-align:center;background:var(--bg);border-radius:10px;padding:11px 6px;border:1px solid var(--border);}
.sbox-val{font-family:'Cormorant Garamond',serif;font-size:22px;font-weight:600;color:var(--crimson);}
.sbox-lbl{font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-top:2px;}
.tip-box{margin-top:14px;padding:12px 14px;background:rgba(199,44,65,0.05);border:1px solid rgba(199,44,65,0.14);border-radius:10px;font-size:11.5px;color:var(--crimson);line-height:1.5;}

/* TABLES */
.req-card{background:var(--card);border:1px solid var(--border);border-radius:18px;overflow:hidden;margin-bottom:24px;}
.req-table-wrap{overflow-x:auto;}
.req-card {
  width: 100%;
  overflow: hidden;
}

.req-table-wrap {
  width: 100%;
  overflow-x: auto;
}

.req-table-wrap table {
  min-width: 900px;
}

.req-table-wrap th,
.req-table-wrap td {
  white-space: nowrap;
  vertical-align: middle;
}

table{width:100%;border-collapse:collapse;font-size:12px;}
thead tr{background:var(--bg);border-bottom:1px solid var(--border);}
th{padding:12px 18px;text-align:left;font-size:9.5px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);font-weight:700;}
td{padding:13px 18px;border-bottom:1px solid rgba(238,222,222,0.4);}
tr:last-child td{border-bottom:none;}
tr:hover td{background:rgba(199,44,65,0.015);}
.mn{font-weight:600;color:var(--midnight);}
.mc{font-size:10px;color:var(--muted);margin-top:2px;}
.badge{display:inline-block;padding:3px 12px;border-radius:50px;font-size:10px;font-weight:700;}
.badge-attending{background:rgba(46,158,104,0.1);color:var(--green);}
.badge-waitlisted{background:rgba(217,119,6,0.1);color:var(--amber);}
.badge-cancelled{background:rgba(154,128,128,0.1);color:var(--muted);}
.badge-pending{background:rgba(238,69,64,0.08);color:var(--coral);}
.badge-matched{background:rgba(81,10,50,0.08);color:var(--plum);}
.badge-closed{background:rgba(154,128,128,0.1);color:var(--muted);}
.badge-mode{background:rgba(128,19,54,0.07);color:var(--wine);}

/* Zoom link in table */
.zoom-pill{
  display:inline-flex;align-items:center;gap:5px;
  padding:4px 12px;border-radius:50px;
  background:rgba(45,140,255,0.1);color:var(--zoom);
  font-size:10px;font-weight:700;text-decoration:none;
  border:1px solid rgba(45,140,255,0.2);
  transition:background .2s,border-color .2s;
}
.zoom-pill:hover{background:rgba(45,140,255,0.18);border-color:rgba(45,140,255,0.4);}
.zoom-pending{font-size:10px;color:var(--muted);font-style:italic;}

/* EMPTY */
.empty{text-align:center;padding:52px 20px;background:var(--card);border:1px solid var(--border);border-radius:18px;margin-bottom:20px;}
.empty-ico{width:90px;height:90px;border-radius:50%;background:linear-gradient(135deg,rgba(238,69,64,0.07),rgba(81,10,50,0.04));border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:34px;margin:0 auto 14px;}
.empty-title{font-family:'Cormorant Garamond',serif;font-size:18px;font-weight:600;color:var(--midnight);margin-bottom:5px;}
.empty-sub{font-size:12px;color:var(--muted);font-weight:300;}

@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}

/* Campus location field — shown only for in-person/both modes */
.location-field-wrap {
  display:none;
  animation:fadeUp .25s ease both;
}
.location-field-wrap.visible { display:block; }
.location-preset-btns { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
.loc-preset {
  padding:5px 12px; border:1px solid var(--border); border-radius:50px;
  background:var(--bg); font-family:'Nunito',sans-serif; font-size:10.5px;
  font-weight:600; color:var(--muted); cursor:pointer; transition:all .2s;
}
.loc-preset:hover { border-color:var(--crimson); color:var(--crimson); background:rgba(199,44,65,0.05); }
.loc-preset.selected { border-color:var(--crimson); color:var(--crimson); background:rgba(199,44,65,0.07); }

</style>
</head>
<body>
<div class="layout">

<!-- ── SIDEBAR ── -->
<aside class="sidebar">
  <div class="sidebar-brand">
    <div class="brand-img"><img src="icbt_logo.png" alt="ICBT"></div>
    <div><div class="brand-name">UniConnect</div><div class="brand-sub">ICBT Campus</div></div>
  </div>
  <div class="nav-scroll">
    <div class="nav-section-label">Main</div>
    <a class="nav-item" href="student_dashboard.php"><span class="ni">🏠</span> Dashboard</a>
    <div class="nav-section-label" style="margin-top:8px">Services</div>
    <a class="nav-item" href="student_link.php"><span class="ni">🔗</span> Student Link</a>
    <a class="nav-item" href="burrow_buddy.php"><span class="ni">📚</span> Burrow Buddy<span class="nav-pill">New</span></a>
    <a class="nav-item" href="reclaim.php"><span class="ni">♻️</span> Reclaim</a>
    <a class="nav-item active" href="brain_bridge.php"><span class="ni">🧠</span> Brain Bridge</a>
    <div class="nav-section-label" style="margin-top:8px">Account</div>
    <a class="nav-item" href="student_profile.php"><span class="ni">👤</span> My Profile</a>
    <a class="nav-item" href="auth/logout.php"><span class="ni">🚪</span> Sign Out</a>
  </div>
  <div class="sidebar-foot">
    <div class="user-chip">
      <div class="ava"><?= $initials ?></div>
      <div><div class="user-chip-name"><?= $name ?></div><div class="user-chip-id"><?= $student_id ?></div></div>
      <span class="chip-arrow">›</span>
    </div>
  </div>
</aside>

<!-- ── MAIN ── -->
<div class="main">
  <div class="topbar">
    <div class="page-title">Brain Bridge</div>
    <div class="topbar-right">
  <a href="student_dashboard.php" class="back-btn">← Back to Dashboard</a>

  <!-- Notification Bell -->
  <div style="position:relative" id="notifWrap">
    <div onclick="toggleNotifPanel()" style="width:34px;height:34px;border-radius:50%;border:1px solid var(--border);background:var(--card);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:15px;position:relative;transition:box-shadow .2s;">
      🔔
      <?php if ($unread_count > 0): ?>
        <span style="position:absolute;top:-5px;right:-5px;background:var(--coral);color:#fff;font-size:9px;font-weight:800;min-width:17px;height:17px;border-radius:50px;display:flex;align-items:center;justify-content:center;padding:0 4px;border:1.5px solid var(--bg);font-family:'Nunito',sans-serif;"><?= $unread_count ?></span>
      <?php endif; ?>
    </div>
    <div id="notifPanel" style="display:none;position:absolute;top:calc(100% + 10px);right:0;width:320px;background:var(--card);border:1px solid var(--border);border-radius:16px;box-shadow:0 12px 36px rgba(45,20,44,.16);z-index:9999;max-height:420px;overflow-y:auto;">
      <div style="padding:13px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;font-size:12px;font-weight:700;color:var(--midnight);position:sticky;top:0;background:var(--card);">
        <span>Notifications</span>
        <?php if ($unread_count > 0): ?>
          <form method="POST" style="margin:0">
            <input type="hidden" name="action" value="mark_notifs_read"/>
            <button type="submit" style="background:none;border:none;color:var(--crimson);font-size:10.5px;font-weight:700;cursor:pointer;font-family:'Nunito',sans-serif;">Mark all read</button>
          </form>
        <?php endif; ?>
      </div>
      <?php if (empty($notifs_all)): ?>
        <div style="padding:32px 20px;text-align:center;font-size:12px;color:var(--muted);">🔔 No notifications yet</div>
      <?php else: ?>
        <?php foreach ($notifs_all as $notif): ?>
          <div style="padding:13px 16px;border-bottom:1px solid rgba(238,222,222,.4);<?= !$notif['is_read'] ? 'background:rgba(238,69,64,.04);' : '' ?>">
            <div style="font-size:12px;font-weight:700;color:var(--midnight);margin-bottom:4px;">
              <?= !$notif['is_read'] ? '<span style="color:var(--coral);font-size:8px;">● </span>' : '' ?>
              <?= htmlspecialchars($notif['title']) ?>
            </div>
            <div style="font-size:11.5px;color:var(--text);line-height:1.55;margin-bottom:5px;"><?= $notif['message'] ?></div>
            <div style="font-size:10px;color:var(--muted);"><?= date('d M Y, g:i a', strtotime($notif['created_at'])) ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="profile-chip">
    <div class="ava-sm"><?= $initials ?></div>
    <span class="chip-name"><?= $first ?></span>
  </div>
</div>  
  </div>
    

  <div class="scroll-area">

    <?php if ($msg_success): ?>
      <div class="alert alert-success">✓ <?= htmlspecialchars($msg_success) ?></div>
    <?php endif; ?>
    <?php if ($msg_error): ?>
      <div class="alert alert-error">✕ <?= htmlspecialchars($msg_error) ?></div>
    <?php endif; ?>

    <!-- HERO -->
    <div class="hero">
      <div class="hero-deco-1"></div><div class="hero-deco-2"></div>
      <div class="hero-text">
        <div class="hero-eyebrow">Brain Bridge · Peer Tutoring</div>
        <div class="hero-title">Learn together,<br/><em>grow together.</em></div>
        <div class="hero-sub">Connect with fellow students who excel in the modules you're working on — or share your own knowledge with those who need it most.</div>
        <div class="hero-stats">
          <div class="hero-stat"><div class="hero-stat-val"><?= count($tutors) ?></div><div class="hero-stat-label">Active Sessions</div></div>
          <div class="hero-stat"><div class="hero-stat-val"><?= array_sum(array_column($tutors,'attendee_count')) ?></div><div class="hero-stat-label">Students Joined</div></div>
          <div class="hero-stat"><div class="hero-stat-val"><?= count($all_modules) ?></div><div class="hero-stat-label">Modules</div></div>
        </div>
      </div>
      <div class="hero-illus">
        <div class="illus-placeholder">
          <div class="illus-hint"><span><img src="brain.png" alt=""></span><br/><span style="font-size:9px;opacity:.7"></span></div>
        </div>
      </div>
    </div>

    <!-- TABS -->
    <div class="tabs-row">
      <button class="tab-btn active" onclick="switchTab('tutors',this)">👥 Browse Sessions <span class="tab-count"><?= count($tutors) ?></span></button>
      <button class="tab-btn" onclick="switchTab('request',this)">📩 Request a Tutor</button>
      <button class="tab-btn" onclick="switchTab('offer',this)">🎓 Offer to Tutor</button>
      <button class="tab-btn" onclick="switchTab('mine',this)">📋 My Activity
        <?php if (!empty($my_sessions)||!empty($my_requests)): ?>
          <span class="tab-count"><?= count($my_sessions)+count($my_requests) ?></span>
        <?php endif; ?>
      </button>
    </div>

    <!-- ══ BROWSE SESSIONS ══ -->
    <div class="tab-panel active" id="panel-tutors">
      <div class="filter-bar">
        <div class="search-wrap">
          <span class="si">🔍</span>
          <input type="text" id="tutorSearch" placeholder="Search by name or module…" oninput="filterTutors()"/>
        </div>
        <select class="fsel" id="modeFilter" onchange="filterTutors()">
          <option value="">All modes</option>
          <option value="in-person">📍 In-Person</option>
          <option value="online">🌐 Online</option>
          <option value="both">🔄 Both</option>
        </select>
      </div>

      <?php if (empty($tutors)): ?>
        <div class="empty"><div class="empty-ico">🎓</div><div class="empty-title">No sessions yet</div><div class="empty-sub">Be the first to offer your knowledge!</div></div>
      <?php else: ?>
        <div class="tutor-grid" id="tutorGrid">
          <?php foreach ($tutors as $i => $t):
            $init       = strtoupper(substr($t['full_name'],0,1));
            $max        = (int)($t['max_students'] ?: 10);
            $attending  = (int)$t['attendee_count'];
            $pct        = $max>0 ? min(100,round($attending/$max*100)) : 0;
            $is_full    = $attending >= $max;
            $i_attend   = (bool)$t['i_am_attending'];
            $i_wait     = (bool)$t['i_am_waitlisted'];
            $is_own     = $t['tutor_id'] == $user_id;
            $bar_class  = $pct>=100 ? 'seats-full' : ($pct>=75 ? 'near' : '');
            $card_class = $i_attend ? 'is-attending' : ($i_wait ? 'is-waitlisted' : '');
            $mode_label = match($t['preferred_mode']){'online'=>'🌐 Online','in-person'=>'📍 In-Person',default=>'🔄 Both'};
            // Show Zoom link only to registered (attending/waitlisted) students when a date is set
            $show_zoom  = ($i_attend || $i_wait) && !empty($t['meeting_link']) && !empty($t['session_date']);
          ?>
          <div class="tutor-card <?= $card_class ?>" style="animation-delay:<?= $i*0.06 ?>s"
               data-name="<?= htmlspecialchars(strtolower($t['full_name'])) ?>"
               data-module="<?= htmlspecialchars(strtolower($t['module_name'])) ?>"
               data-mode="<?= $t['preferred_mode'] ?>">

            <div class="tutor-card-top">
              <div class="tutor-avatar"><?= $init ?></div>
            </div>

            <div class="tutor-body">
              <div>
                <div class="tutor-name"><?= htmlspecialchars($t['full_name']) ?></div>
<div class="tutor-sid"><?= htmlspecialchars($t['tutor_sid']) ?></div>
<?php 
  $tr = $tutor_ratings[$t['tutor_id']] ?? null;
  if ($tr): 
    $full_stars  = floor($tr['avg']);
    $half_star   = ($tr['avg'] - $full_stars) >= 0.5;
    $empty_stars = 5 - $full_stars - ($half_star ? 1 : 0);
?>
<div style="display:flex;align-items:center;gap:4px;margin-top:2px">
  <span style="color:#f59e0b;font-size:11px;letter-spacing:1px">
    <?= str_repeat('★', $full_stars) ?>
    <?= $half_star ? '½' : '' ?>
    <?= str_repeat('☆', $empty_stars) ?>
  </span>
  <span style="font-size:9.5px;color:var(--muted);font-weight:600">
    <?= $tr['avg'] ?> <span style="font-weight:400">(<?= $tr['cnt'] ?> <?= $tr['cnt'] == 1 ? 'review' : 'reviews' ?>)</span>
  </span>
</div>
<?php else: ?>
<div style="font-size:9.5px;color:var(--muted);margin-top:2px;font-weight:400;font-style:italic">No ratings yet</div>
<?php endif; ?>
</div>
              <div class="tutor-module">📘 <?= htmlspecialchars($t['module_code']) ?> — <?= htmlspecialchars($t['module_name']) ?></div>
              <div class="tutor-tags">
                <span class="ttag ttag-mode"><?= $mode_label ?></span>
                <?php if ($t['availability']): ?><span class="ttag ttag-avail">🕐 <?= htmlspecialchars($t['availability']) ?></span><?php endif; ?>
                <?php if ($t['session_date']): ?><span class="ttag ttag-date">📅 <?= date('d M',strtotime($t['session_date'])) ?><?= $t['session_time'] ? ' · '.$t['session_time'] : '' ?></span><?php endif; ?>
                  <?php if (!empty($t['campus_location'])): ?>
  <span class="ttag" style="background:rgba(46,158,104,0.07);color:var(--green);">
    📍 <?= htmlspecialchars($t['campus_location']) ?>
  </span>
<?php endif; ?>
              </div>
              <?php if ($t['note']): ?>
                <div class="tutor-note">"<?= htmlspecialchars($t['note']) ?>"</div>
              <?php endif; ?>

              <!-- Seats bar -->
              <div class="seats-bar">
                <div class="seats-meta">
                  <span>Seats available</span>
                  <span class="seats-cnt <?= $is_full?'full':'' ?>"><?= $attending ?>/<?= $max ?><?= $is_full?' · Full':'' ?></span>
                </div>
                <div class="seats-track"><div class="seats-fill <?= $bar_class ?>" style="width:<?= $pct ?>%"></div></div>
              </div>

              <!-- ══ RSVP ══ -->
              <div class="rsvp-section">

                <?php if ($is_own): ?>
                  <button class="join-btn own-mode" disabled>📌 Your Session</button>

                <?php elseif ($i_attend): ?>
                  <div class="status-box attending">
                    <div class="status-icon">✅</div>
                    <div>
                      <div class="status-label">You're attending!</div>
                      <div class="status-sub">The tutor can see you on their list</div>
                    </div>
                  </div>

                  <?php if ($show_zoom): ?>
                    <!-- Zoom link visible only to attending students with a scheduled date -->
                    <a href="<?= htmlspecialchars($t['meeting_link']) ?>" target="_blank" rel="noopener" class="zoom-link-box">
                      <div class="zoom-icon">📹</div>
                      <div>
                        <div class="zoom-label">Join Online Session</div>
                        <div class="zoom-sub">📅 <?= date('d M Y', strtotime($t['session_date'])) ?><?= $t['session_time'] ? ' · '.$t['session_time'] : '' ?></div>
                      </div>
                      <span class="zoom-arrow">→</span>
                    </a>
                  <?php elseif ($i_attend && !empty($t['session_date']) && empty($t['meeting_link'])): ?>
                    <!-- Date is set but no link yet -->
                    <div style="margin-top:8px;font-size:10px;color:var(--muted);text-align:center;padding:7px;background:var(--bg);border-radius:8px;border:1px dashed var(--border);">
                      🔗 Meeting link will appear here once the tutor adds it
                    </div>
                  <?php endif; ?>

                  <div class="cancel-wrap">
                    <form method="POST" style="display:inline">
                      <input type="hidden" name="action" value="cancel_rsvp">
                      <input type="hidden" name="offer_id" value="<?= $t['offer_id'] ?>">
                      <button type="submit" class="cancel-btn" onclick="return confirm('Cancel your spot?')">Cancel registration</button>
                    </form>
                  </div>

                <?php elseif ($i_wait): ?>
                  <div class="status-box waitlisted">
                    <div class="status-icon">⏳</div>
                    <div>
                      <div class="status-label">On the waitlist</div>
                      <div class="status-sub">You'll move up if a spot opens</div>
                    </div>
                  </div>

                  <?php if ($show_zoom): ?>
                    <a href="<?= htmlspecialchars($t['meeting_link']) ?>" target="_blank" rel="noopener" class="zoom-link-box">
                      <div class="zoom-icon">📹</div>
                      <div>
                        <div class="zoom-label">Join Online Session</div>
                        <div class="zoom-sub">📅 <?= date('d M Y', strtotime($t['session_date'])) ?><?= $t['session_time'] ? ' · '.$t['session_time'] : '' ?></div>
                      </div>
                      <span class="zoom-arrow">→</span>
                    </a>
                  <?php endif; ?>

                  <div class="cancel-wrap">
                    <form method="POST" style="display:inline">
                      <input type="hidden" name="action" value="cancel_rsvp">
                      <input type="hidden" name="offer_id" value="<?= $t['offer_id'] ?>">
                      <button type="submit" class="cancel-btn">Leave waitlist</button>
                    </form>
                  </div>

                <?php else: ?>
                  <!-- Join button — click to expand confirm box -->
                  <button class="join-btn <?= $is_full?'waitlist-mode':'join-mode' ?>"
                          onclick="toggleExpand(<?= $t['offer_id'] ?>)">
                    <?= $is_full ? '⏳ Join Waitlist' : '✋ Join Session' ?>
                  </button>

                  <div class="msg-expand" id="expand-<?= $t['offer_id'] ?>">
                    <form method="POST">
                      <input type="hidden" name="action" value="rsvp">
                      <input type="hidden" name="offer_id" value="<?= $t['offer_id'] ?>">
                      <textarea name="rsvp_message" class="msg-ta" rows="2"
                        placeholder="Optional: let the tutor know what you need help with…"></textarea>
                      <button type="submit" class="confirm-btn <?= $is_full?'wait-confirm':'join-confirm' ?>">
                        <?= $is_full ? 'Confirm — Add Me to Waitlist →' : "Confirm — I'll Be There →" ?>
                      </button>
                    </form>
                  </div>

                <?php endif; ?>
              </div><!-- /rsvp-section -->
            </div><!-- /tutor-body -->
          </div>
          <?php endforeach; ?>
        </div>
        <div class="empty" id="noTutorsMsg" style="display:none">
          <div class="empty-ico">🔍</div><div class="empty-title">No matches</div>
          <div class="empty-sub">Try different search terms.</div>
        </div>
      <?php endif; ?>
    </div>

    <!-- ══ REQUEST A TUTOR ══ -->
    <div class="tab-panel" id="panel-request">
      <div class="two-col">
        <div class="fcard">
          <div class="fcard-head">
            <div class="fcard-head-deco"></div>
            <div class="fcard-head-text"><div class="fcard-title">Request a Tutor</div><div class="fcard-sub">Tell us which module you need help with and we'll find you a match.</div></div>
            <div class="fcard-illus"><div class="fcard-illus-hint"><span>🖼️</span>Illustration</div></div>
          </div>
          <div class="fcard-body">
            <form method="POST">
              <input type="hidden" name="action" value="submit_request"/>
              <div class="fg"><label class="fl">Select Module</label>
                <select name="module_id" class="fc" required><option value="">— Choose a module —</option>
                  <?php foreach ($all_modules as $m): ?><option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['module_code'].' – '.$m['module_name']) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="fg"><label class="fl">Preferred Mode</label>
                <div class="mode-row">
                  <div class="mopt"><input type="radio" name="preferred_mode" id="r1" value="in-person"/><label for="r1">📍 In-Person</label></div>
                  <div class="mopt"><input type="radio" name="preferred_mode" id="r2" value="online"/><label for="r2">🌐 Online</label></div>
                  <div class="mopt"><input type="radio" name="preferred_mode" id="r3" value="both" checked/><label for="r3">🔄 Either</label></div>
                </div>
              </div>
              <div class="fg"><label class="fl">Message <span style="color:var(--muted);font-size:9px;text-transform:none;letter-spacing:0">(optional)</span></label>
                <textarea name="message" class="fc" placeholder="What topics are you struggling with?"></textarea>
              </div>
              <button type="submit" class="btn-sub">Submit Request →</button>
            </form>
          </div>
        </div>
        <div class="icard">
          <div class="icard-head"><div class="icard-head-deco"></div>
            <div class="icard-head-text"><div class="icard-title">How It Works</div><div class="icard-sub">Three steps to get matched</div></div>
            <div class="icard-illus"></div>
          </div>
          <div class="icard-body">
            <div class="istep"><div class="snum">1</div><div class="stxt">Select the <strong>module</strong> you need help with and submit your request.</div></div>
            <div class="istep"><div class="snum">2</div><div class="stxt">Browse <strong>Browse Sessions</strong> to find a student offering that module.</div></div>
            <div class="istep"><div class="snum">3</div><div class="stxt">Click <strong>Join Session</strong> — the tutor is instantly notified you're attending.</div></div>
            <div class="stat-row">
              <div class="sbox"><div class="sbox-val"><?= count($my_requests) ?></div><div class="sbox-lbl">My Requests</div></div>
              <div class="sbox"><div class="sbox-val"><?= count(array_filter($my_sessions,fn($s)=>$s['status']==='attending')) ?></div><div class="sbox-lbl">Joined</div></div>
              <div class="sbox"><div class="sbox-val"><?= count($all_modules) ?></div><div class="sbox-lbl">Modules</div></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ OFFER TO TUTOR ══ -->
    <div class="tab-panel" id="panel-offer">
      <div class="two-col">
        <div class="fcard">
          <div class="fcard-head">
            <div class="fcard-head-deco"></div>
            <div class="fcard-head-text"><div class="fcard-title">Offer to Tutor</div><div class="fcard-sub">Share your expertise and help fellow students succeed.</div></div>
            <div class="fcard-illus"><div class="fcard-illus-hint"><span>🖼️</span>Illustration</div></div>
          </div>
          <div class="fcard-body">
            <form method="POST">
              <input type="hidden" name="action" value="offer_tutor"/>
              <div class="fg"><label class="fl">Module You Can Teach</label>
                <select name="offer_module_id" class="fc" required><option value="">— Choose a module —</option>
                  <?php foreach ($all_modules as $m): ?><option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['module_code'].' – '.$m['module_name']) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="fg"><label class="fl">Session Date <span style="color:var(--muted);font-size:9px;text-transform:none;letter-spacing:0"></span></label>
                <input type="date" name="session_date" class="fc" min="<?= date('Y-m-d') ?>">
              </div>
              <div class="fg"><label class="fl">Session Time <span style="color:var(--muted);font-size:9px;text-transform:none;letter-spacing:0"></span></label>
                <input type="text" name="session_time" class="fc" placeholder="e.g. 3:00 PM – 5:00 PM">
              </div>
              <div class="fg"><label class="fl">Your Availability</label>
                <input type="text" name="availability" class="fc" placeholder="e.g. Mon & Wed 3–5 PM"/>
              </div>
              <div class="fg"><label class="fl">Max Students</label>
                <input type="number" name="max_students" class="fc" value="10" min="1" max="50">
              </div>
              <div class="fg"><label class="fl">Teaching Mode</label>
  <div class="mode-row">
    <div class="mopt"><input type="radio" name="offer_mode" id="o1" value="in-person" onchange="toggleLocationField(this.value)"/><label for="o1">📍 In-Person</label></div>
    <div class="mopt"><input type="radio" name="offer_mode" id="o2" value="online"    onchange="toggleLocationField(this.value)"/><label for="o2">🌐 Online</label></div>
    <div class="mopt"><input type="radio" name="offer_mode" id="o3" value="both"      onchange="toggleLocationField(this.value)" checked/><label for="o3">🔄 Both</label></div>
  </div>
</div>
                  </div>

<!-- Campus location — visible when in-person or both -->
<div class="fg location-field-wrap" id="campusLocationWrap">
  <label class="fl">📍 Campus Location
    <span style="color:var(--muted);font-size:9px;text-transform:none;letter-spacing:0;font-weight:400"> (where on campus will the session be held?)</span>
  </label>
  <input type="text" name="campus_location" id="campusLocationInput" class="fc"
         placeholder="e.g. Library Study Room 3, Block A Seminar Hall…"/>
  <div class="location-preset-btns">
    <button type="button" class="loc-preset" onclick="setLocation('Library — Study Room')">📚 Library</button>
    <button type="button" class="loc-preset" onclick="setLocation('Cafeteria — Seating Area')">☕ Cafeteria</button>
    <button type="button" class="loc-preset" onclick="setLocation('Block A — Seminar Hall')">🏫 Block A Hall</button>
    <button type="button" class="loc-preset" onclick="setLocation('Block B — Seminar Hall')">🏫 Block B Hall</button>
    <button type="button" class="loc-preset" onclick="setLocation('IT Lab — Ground Floor')">💻 IT Lab</button>
    <button type="button" class="loc-preset" onclick="setLocation('Student Lounge')">🛋️ Student Lounge</button>
    <button type="button" class="loc-preset" onclick="setLocation('Lecture Hall 1')">🎓 Lecture Hall 1</button>
    <button type="button" class="loc-preset" onclick="setLocation('Lecture Hall 2')">🎓 Lecture Hall 2</button>
  </div>
</div>

              <hr class="form-divider"/>




              <!-- ── MEETING LINK ── -->
              <div class="fg">
                <label class="fl" style="color:var(--zoom);opacity:.85;">
                  🔗 Online Meeting Link
                  <span style="color:var(--muted);font-size:9px;text-transform:none;letter-spacing:0;font-weight:400"> (optional — only shown to registered students once a date is set)</span>
                </label>
                <div class="zoom-field-wrap">
                  <input type="url" name="meeting_link" class="fc zoom-field"
                         placeholder="https://zoom.us/j/your-meeting-id  or  Google Meet / Teams link"
                         id="meetingLinkInput"
                         oninput="detectLinkBadge(this)"/>
                  <span class="zoom-badge" id="linkBadge" style="display:none">ZOOM</span>
                </div>
                <div class="fhelp zoom-help">🔒 This link is hidden from students until they register <em>and</em> a session date is scheduled.</div>
              </div>

              <hr class="form-divider"/>

              <div class="fg"><label class="fl">Short Note <span style="color:var(--muted);font-size:9px;text-transform:none;letter-spacing:0">(optional)</span></label>
                <textarea name="offer_note" class="fc" placeholder="Describe your teaching style or experience…"></textarea>
              </div>
              <button type="submit" class="btn-sub">List Me as a Tutor →</button>
            </form>
          </div>
        </div>
        <div class="icard">
          <div class="icard-head"><div class="icard-head-deco"></div>
            <div class="icard-head-text"><div class="icard-title">Why Tutor?</div><div class="icard-sub">Teaching is the best way to learn</div></div>
            <div class="icard-illus"></div>
          </div>
          <div class="icard-body">
            <div class="istep"><div class="snum">✦</div><div class="stxt"><strong>Deepen your knowledge</strong> — explaining concepts solidifies your own understanding.</div></div>
            <div class="istep"><div class="snum">✦</div><div class="stxt"><strong>Build communication skills</strong> — an essential attribute for any future career.</div></div>
            <div class="istep"><div class="snum">✦</div><div class="stxt"><strong>See who joins</strong> — you'll see every student who registers for your session.</div></div>
            <div class="istep"><div class="snum">✦</div><div class="stxt"><strong>Help your community</strong> — make ICBT a better place for everyone.</div></div>
            <div class="tip-box">💡 You can offer <strong>multiple modules</strong> — just submit the form once per module.</div>
            <div class="tip-box" style="margin-top:10px;background:rgba(45,140,255,0.05);border-color:rgba(45,140,255,0.18);color:var(--zoom);">
              🔗 <strong>Meeting link privacy:</strong> your Zoom / Meet link stays hidden until a student registers <em>and</em> you've set a session date — keeping it safe from uninvited guests.
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ MY ACTIVITY ══ -->
    <div class="tab-panel" id="panel-mine">

      <div class="section-title">Sessions I'm Attending</div>
      <?php if (empty($my_sessions)): ?>
        <div class="empty"><div class="empty-ico">🗓</div><div class="empty-title">No sessions joined yet</div><div class="empty-sub">Browse sessions and click "Join Session" to register.</div></div>
      <?php else: ?>
        <div class="req-card">
          <div class="req-table-wrap">
            <table>
              <thead>
                <tr>
                  <th>#</th><th>Tutor</th><th>Module</th><th>Date / Time</th>
                  <th>Mode</th><th>Status</th><th>Meeting Link</th><th>Action</th><th>Rate</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($my_sessions as $i => $s):
  $has_link  = !empty($s['meeting_link']);
  $has_date  = !empty($s['session_date']);
  $is_active = $s['status'] !== 'cancelled';
  $show_link = $has_link && $has_date && $is_active;

  $rated = $pdo->prepare("SELECT id FROM ratings WHERE rater_id=? AND ref_id=? AND module='peer_tutoring'");
  $rated->execute([$user_id, $s['offer_id']]);
  $already_rated = (bool)$rated->fetch();
?>
<tr>
  <td style="color:var(--muted);font-weight:600"><?= $i+1 ?></td>
  <td><div class="mn"><?= htmlspecialchars($s['tutor_name']) ?></div></td>
  <td>
    <div class="mn"><?= htmlspecialchars($s['module_name']) ?></div>
    <div class="mc"><?= htmlspecialchars($s['module_code']) ?></div>
  </td>
  <td style="color:var(--muted);font-size:11px">
    <?= $s['session_date'] ? date('d M Y', strtotime($s['session_date'])) : '—' ?>
    <?= $s['session_time'] ? '<br><span style="font-size:10px">'.$s['session_time'].'</span>' : '' ?>
  </td>
  <td><span class="badge badge-mode"><?= ucfirst($s['preferred_mode']) ?></span></td>
  <td><span class="badge badge-<?= $s['status'] ?>"><?= ucfirst($s['status']) ?></span></td>
  <td>
    <?php if ($show_link): ?>
      <a href="<?= htmlspecialchars($s['meeting_link']) ?>" target="_blank" rel="noopener" class="zoom-pill">📹 Join</a>
    <?php elseif ($is_active && $has_date && !$has_link): ?>
      <span class="zoom-pending">Link pending…</span>
    <?php elseif ($is_active && !$has_date): ?>
      <span class="zoom-pending">Date not set</span>
    <?php else: ?>
      <span style="color:var(--muted);font-size:10px">—</span>
    <?php endif; ?>
  </td>
  <td>
    <?php if ($is_active): ?>
      <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="cancel_rsvp">
        <input type="hidden" name="offer_id" value="<?= $s['offer_id'] ?>">
        <button type="submit" class="cancel-btn" onclick="return confirm('Cancel?')">Cancel</button>
      </form>
    <?php else: ?>
      <span style="color:var(--muted);font-size:10px">Cancelled</span>
    <?php endif; ?>
  </td>
  <td>
    <?php if ($s['status'] === 'attending' && !$already_rated): ?>
      <button onclick="openTutorRateModal(<?= $s['offer_id'] ?>, '<?= addslashes(htmlspecialchars($s['tutor_name'])) ?>')"
              style="background:rgba(217,119,6,0.12);color:#b45309; border:none;padding:5px 12px;border-radius:50px;font-family:'Nunito',sans-serif;font-size:11px;font-weight:700;cursor:pointer;">
        ⭐ Rate
      </button>
    <?php elseif ($s['status'] === 'attending' && $already_rated): ?>
      <span style="font-size:10px;color:var(--muted)">Rated ✓</span>
    <?php else: ?>
      <span style="font-size:10px;color:var(--muted)">—</span>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>

      <div class="section-title">My Module Requests</div>
      <?php if (empty($my_requests)): ?>
        <div class="empty"><div class="empty-ico">📋</div><div class="empty-title">No requests yet</div><div class="empty-sub">Switch to "Request a Tutor" tab to get started.</div></div>
      <?php else: ?>
        <div class="req-card">
          <div class="req-table-wrap">
            <table>
              <thead><tr><th>#</th><th>Module</th><th>Mode</th><th>Status</th><th>Submitted</th></tr></thead>
              <tbody>
                <?php foreach ($my_requests as $i => $r): ?>
                <tr>
                  <td style="color:var(--muted);font-weight:600"><?= $i+1 ?></td>
                  <td><div class="mn"><?= htmlspecialchars($r['module_name']) ?></div><div class="mc"><?= htmlspecialchars($r['module_code']) ?></div></td>
                  <td><span class="badge badge-mode"><?= ucfirst($r['preferred_mode']) ?></span></td>
                  <td><span class="badge badge-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
                  <td style="color:var(--muted)"><?= date('d M Y',strtotime($r['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    </div>

  

  <!-- TUTOR RATING MODAL -->
<div id="tutorRateModal" style="display:none;position:fixed;inset:0;background:rgba(45,20,44,0.55);
  backdrop-filter:blur(4px);z-index:1000;align-items:center;justify-content:center;padding:20px">
  <div style="background:#fff;border-radius:20px;width:100%;max-width:420px;overflow:hidden;
    box-shadow:0 24px 60px rgba(0,0,0,.25)">
    <div style="background:linear-gradient(125deg,var(--midnight),var(--plum) 55%,var(--wine));
      padding:24px 26px;position:relative">
      <div style="font-family:'Cormorant Garamond',serif;font-size:20px;font-weight:600;
        color:#fff;margin-bottom:4px" id="tutorRateTitle">Rate Tutor</div>
      <div style="font-size:11px;color:rgba(255,255,255,.45)">Your feedback helps other students</div>
      <button onclick="closeTutorRateModal()" style="position:absolute;top:16px;right:16px;
        width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,.12);
        border:none;color:#fff;font-size:14px;cursor:pointer">✕</button>
    </div>
    <div style="padding:24px 26px">
      <form method="POST">
        <input type="hidden" name="action" value="rate_tutor"/>
        <input type="hidden" name="offer_id" id="tutorRateOfferId" value=""/>
        <input type="hidden" name="stars" id="tutorRateStars" value="5"/>
        <div style="margin-bottom:18px">
          <div style="font-size:10px;font-weight:700;color:var(--muted);
            text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px">
            Your Rating
          </div>
          <div id="tutorStarRow" style="display:flex;gap:8px">
            <?php for ($s=1; $s<=5; $s++): ?>
            <span data-v="<?= $s ?>" onclick="setTutorStar(<?= $s ?>)"
              style="font-size:32px;cursor:pointer;color:#f59e0b;transition:transform .15s"
              onmouseover="this.style.transform='scale(1.2)'"
              onmouseout="this.style.transform='scale(1)'">★</span>
            <?php endfor; ?>
          </div>
        </div>
        <div style="margin-bottom:18px">
          <label style="display:block;font-size:9.5px;font-weight:700;color:var(--muted);
            text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px">
            Comment <span style="font-weight:400;text-transform:none;font-size:9px">(optional)</span>
          </label>
          <textarea name="comment" style="width:100%;padding:10px 13px;background:var(--bg);
            border:1.5px solid var(--border);border-radius:10px;font-family:'Nunito',sans-serif;
            font-size:12px;color:var(--text);outline:none;resize:none;min-height:70px;line-height:1.6"
            placeholder="What did you find most helpful about this session?"></textarea>
        </div>
        <button type="submit" style="width:100%;padding:13px;border:none;border-radius:12px;
          background:linear-gradient(135deg,#f59e0b,#b45309);color:#fff;
          font-family:'Nunito',sans-serif;font-size:13px;font-weight:700;cursor:pointer;
          box-shadow:0 4px 16px rgba(217,119,6,.3)">
          ⭐ Submit Rating →
        </button>
      </form>
    </div>
  </div>
</div>
            </div>

</div><!-- /main -->'
</div><!-- /layout -->
</div><!-- /scroll-area -->

<script>
function switchTab(id, btn) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('panel-' + id).classList.add('active');
  btn.classList.add('active');
}

function toggleExpand(id) {
  const box = document.getElementById('expand-' + id);
  const isOpen = box.classList.contains('open');
  document.querySelectorAll('.msg-expand.open').forEach(b => b.classList.remove('open'));
  if (!isOpen) {
    box.classList.add('open');
    box.querySelector('textarea')?.focus();
  }
}

function filterTutors() {
  const q    = document.getElementById('tutorSearch').value.toLowerCase();
  const mode = document.getElementById('modeFilter').value;
  let visible = 0;
  document.querySelectorAll('.tutor-card').forEach(c => {
    const ok = (c.dataset.name.includes(q) || c.dataset.module.includes(q))
             && (!mode || c.dataset.mode === mode || c.dataset.mode === 'both');
    c.style.display = ok ? '' : 'none';
    if (ok) visible++;
  });
  document.getElementById('noTutorsMsg').style.display = visible === 0 ? 'block' : 'none';
}

// Detect platform and show badge on the meeting link field
function detectLinkBadge(input) {
  const val = input.value.toLowerCase();
  const badge = document.getElementById('linkBadge');
  if (!val) 
    { badge.style.display = 'none'; return;
      input.style.paddingRight = '';
      return
     }
  if (val.includes('zoom.us'))              { badge.textContent = 'ZOOM';  badge.style.background = '#2D8CFF'; badge.style.display = ''; }
  else if (val.includes('meet.google'))     { badge.textContent = 'MEET';  badge.style.background = '#00897B'; badge.style.display = ''; }
  else if (val.includes('teams.microsoft')) { badge.textContent = 'TEAMS'; badge.style.background = '#6264A7'; badge.style.display = ''; }
  else if (val.includes('webex'))           { badge.textContent = 'WEBEX'; badge.style.background = '#00B140'; badge.style.display = ''; }
  else                                      { badge.textContent = 'LINK';  badge.style.background = '#555';    badge.style.display = ''; }
  // Adjust padding so text doesn't sit behind badge
  input.style.paddingRight = '70px';
}

setTimeout(() => {
  document.querySelectorAll('.alert').forEach(a => {
    a.style.transition = 'opacity .4s'; a.style.opacity = '0';
    setTimeout(() => a.remove(), 400);
  });
}, 4000);

function toggleLocationField(mode) {
  const wrap = document.getElementById('campusLocationWrap');
  if (mode === 'online') {
    wrap.classList.remove('visible');
  } else {
    wrap.classList.add('visible');
  }
}

function setLocation(place) {
  const input = document.getElementById('campusLocationInput');
  input.value = place;
  // highlight selected preset
  document.querySelectorAll('.loc-preset').forEach(b => {
    b.classList.toggle('selected', b.textContent.trim().includes(place.split('—')[0].trim()));
  });
}

function openTutorRateModal(offerId, tutorName) {
  document.getElementById('tutorRateOfferId').value = offerId;
  document.getElementById('tutorRateTitle').textContent = 'Rate ' + tutorName;
  // Reset to 0 — force a deliberate choice
  document.getElementById('tutorRateStars').value = 0;
  document.querySelectorAll('#tutorStarRow span').forEach(s => {
    s.style.color = '#d1d5db';
  });
  const m = document.getElementById('tutorRateModal');
  m.style.display = 'flex';
}
function closeTutorRateModal() {
  document.getElementById('tutorRateModal').style.display = 'none';
}
function setTutorStar(v) {
  document.getElementById('tutorRateStars').value = v;
  document.querySelectorAll('#tutorStarRow span').forEach(s => {
    s.style.color = parseInt(s.dataset.v) <= v ? '#f59e0b' : '#d1d5db';
  });
}
document.getElementById('tutorRateModal').addEventListener('click', function(e) {
  if (e.target === this) closeTutorRateModal();
});

function toggleNotifPanel() {
  const panel = document.getElementById('notifPanel');
  panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
}
document.addEventListener('click', function(e) {
  const wrap = document.getElementById('notifWrap');
  if (wrap && !wrap.contains(e.target)) {
    document.getElementById('notifPanel').style.display = 'none';
  }
});
</script>
</body>
</html>