<?php
require_once __DIR__ . '/auth/db.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php'); exit;
}

$admin_id   = (int)$_SESSION['user_id'];
$admin_name = htmlspecialchars($_SESSION['full_name'] ?? 'Admin');
$first      = explode(' ', trim($admin_name))[0];
$words      = array_filter(explode(' ', trim($admin_name)));
$initials   = strtoupper(substr($words[0]??'A',0,1).substr(end($words)??'',0,1));

$msg_success = '';
$msg_error   = '';



if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

/* ── ADD STUDENT ── */
if ($_POST['action'] === 'add_student') {
    $fn=$_POST['full_name']??''; $em=$_POST['email']??'';
    $sid=$_POST['student_id']??''; $pw=$_POST['password']??'';
    if (!$fn||!$em||!$sid||!$pw) { $msg_error='All fields are required.'; }
    else {
        $c=$pdo->prepare("SELECT id FROM users WHERE email=? OR student_id=?");
        $c->execute([$em,$sid]);
        if ($c->fetch()) { $msg_error='Email or student ID already exists.'; }
        else {
            $pdo->prepare("INSERT INTO users (student_id,full_name,email,password,role,is_active,must_change_password) VALUES(?,?,?,?,'student',1,1)")
                ->execute([$sid,trim($fn),trim($em),password_hash($pw,PASSWORD_DEFAULT)]);
            $msg_success="✓ Student added!";
        }
    }
}

    /* ── ADD NOTICE ── */
    if ($_POST['action'] === 'add_notice') {
        $title    = trim($_POST['title'] ?? '');
        $content  = trim($_POST['content'] ?? '');
        $tag      = in_array($_POST['tag']??'',['event','notice','urgent']) ? $_POST['tag'] : 'notice';
        $ev_date  = $_POST['event_date'] ?: null;
        $location = trim($_POST['location'] ?? '');
        if (!$title) { $msg_error = 'Title is required.'; }
        else {
            $pdo->prepare("INSERT INTO notices (title,tag,content,event_date,location,is_active,created_by) VALUES(?,?,?,?,?,1,?)")
                ->execute([$title,$tag,$content,$ev_date,$location?:null,$admin_id]);
            $msg_success = '📌 Notice posted!';
        }
    }

    /* ── TOGGLE / DELETE NOTICE ── */
    if ($_POST['action'] === 'toggle_notice') {
        $pdo->prepare("UPDATE notices SET is_active=1-is_active WHERE id=?")->execute([(int)$_POST['notice_id']]);
        $msg_success = 'Notice updated.';
    }
    if ($_POST['action'] === 'delete_notice') {
        $pdo->prepare("DELETE FROM notices WHERE id=?")->execute([(int)$_POST['notice_id']]);
        $msg_success = 'Notice deleted.';
    }

    /* ── ADD EVENT ── */
if ($_POST['action'] === 'add_event') {
    $title    = trim($_POST['ev_title'] ?? '');
    $ev_date  = $_POST['ev_date'] ?? null;
    $ev_time  = trim($_POST['ev_time'] ?? '');
    $ev_loc   = trim($_POST['ev_location'] ?? '');
    $ev_desc  = trim($_POST['ev_desc'] ?? '');
    if (!$title || !$ev_date) { $msg_error = 'Title and date are required.'; }
    else {
        $pdo->prepare("INSERT INTO events (title,description,event_date,event_time,location,created_by) VALUES(?,?,?,?,?,?)")
            ->execute([$title, $ev_desc?:null, $ev_date, $ev_time?:null, $ev_loc?:null, $admin_id]);

        // ── Notify all active students ──
        $date_fmt = date('d M Y', strtotime($ev_date));
        $notif_msg = "A new campus event has been added: <strong>" . htmlspecialchars($title) . "</strong>"
                   . ($date_fmt ? " on <strong>{$date_fmt}</strong>" : "")
                   . ($ev_loc   ? " at <strong>" . htmlspecialchars($ev_loc) . "</strong>" : "") . ".";
        $all_students = $pdo->query("SELECT id FROM users WHERE role='student' AND is_active=1")->fetchAll(PDO::FETCH_COLUMN);
        $nstmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, 'new_event', '🗓️ New Campus Event', ?, 'student_dashboard.php')");
        foreach ($all_students as $sid) {
            $nstmt->execute([$sid, $notif_msg]);
        }

        $msg_success = '🗓️ Event added and students notified!';
    }
}
    if ($_POST['action'] === 'delete_event') {
        $pdo->prepare("DELETE FROM events WHERE id=?")->execute([(int)$_POST['event_id']]);
        $msg_success = 'Event removed.';
    }

    /* ── ADD STUDENT ── */
if ($_POST['action'] === 'add_student') {
    $fn=$_POST['full_name']??''; $em=$_POST['email']??'';
    $sid=$_POST['student_id']??''; $pw=$_POST['password']??'';
    if (!$fn||!$em||!$sid||!$pw) { $msg_error='All fields are required.'; }
    else {
        $c=$pdo->prepare("SELECT id FROM users WHERE email=? OR student_id=?");
        $c->execute([$em,$sid]);
        if ($c->fetch()) { $msg_error='Email or student ID already exists.'; }
        else {
            $pdo->prepare("INSERT INTO users (student_id,full_name,email,password,role,is_active,must_change_password) VALUES(?,?,?,?,'student',1,1)")
                ->execute([$sid,trim($fn),trim($em),password_hash($pw,PASSWORD_DEFAULT)]);
            $msg_success="✓ Student added!";
        }
    }
}

    /* ── TOGGLE STUDENT ── */
    if ($_POST['action'] === 'toggle_student') {
        $pdo->prepare("UPDATE users SET is_active=? WHERE id=? AND role='student'")
            ->execute([(int)$_POST['new_active'],(int)$_POST['user_id']]);
        $msg_success = (int)$_POST['new_active'] ? 'Account enabled.' : 'Account disabled.';
    }

    /* ── DELETE CHAT MESSAGE ── */
    if ($_POST['action'] === 'delete_chat') {
        $pdo->prepare("DELETE FROM chat_messages WHERE id=?")->execute([(int)$_POST['msg_id']]);
        $msg_success = 'Message deleted.';
    }

    /* ── ADD MODULE ── */
    if ($_POST['action'] === 'add_module') {
        $code=strtoupper(trim($_POST['module_code']??''));
        $name=trim($_POST['module_name']??'');
        if (!$code||!$name) { $msg_error='Code and name required.'; }
        else {
            $c=$pdo->prepare("SELECT id FROM modules WHERE module_code=?"); $c->execute([$code]);
            if ($c->fetch()) { $msg_error="Code {$code} already exists."; }
            else {
                $pdo->prepare("INSERT INTO modules (module_code,module_name,description) VALUES(?,?,?)")
                    ->execute([$code,$name,trim($_POST['module_desc']??'')?:null]);
                $msg_success="📚 Module {$code} added!";
            }
        }
    }

    /* ── UPDATE BURROW BUDDY REQUEST ── */
    if ($_POST['action'] === 'update_bb') {
        $st=in_array($_POST['bb_status']??'',['approved','rejected','returned'])?$_POST['bb_status']:'rejected';
        $pdo->prepare("UPDATE bb_requests SET status=?,responded_at=NOW() WHERE id=?")->execute([$st,(int)$_POST['req_id']]);
        $msg_success="Request marked as {$st}.";
    }
}

/* ── DATA ── */
$total_students  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn();
$active_students = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND is_active=1")->fetchColumn();
$disabled_count  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND is_active=0")->fetchColumn();
$new_today       = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND DATE(created_at)=CURDATE()")->fetchColumn();
$active_notices  = (int)$pdo->query("SELECT COUNT(*) FROM notices WHERE is_active=1")->fetchColumn();
$upcoming_events = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE event_date>=CURDATE()")->fetchColumn();
$active_sessions = (int)$pdo->query("SELECT COUNT(*) FROM tutor_offers WHERE status='active'")->fetchColumn();
$pending_reqs    = (int)$pdo->query("SELECT COUNT(*) FROM module_requests WHERE status='pending'")->fetchColumn();
$chat_today      = (int)$pdo->query("SELECT COUNT(*) FROM chat_messages WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$chat_total      = (int)$pdo->query("SELECT COUNT(*) FROM chat_messages")->fetchColumn();
$bb_pending      = (int)$pdo->query("SELECT COUNT(*) FROM bb_requests WHERE status='pending'")->fetchColumn();
$bb_available    = (int)$pdo->query("SELECT COUNT(*) FROM bb_listings WHERE status='available'")->fetchColumn();

$notices   = $pdo->query("SELECT n.*,u.full_name AS posted_by FROM notices n LEFT JOIN users u ON u.id=n.created_by ORDER BY n.created_at DESC")->fetchAll();
$events    = $pdo->query("SELECT * FROM events ORDER BY event_date ASC")->fetchAll();
$students  = $pdo->query("SELECT id,student_id,full_name,email,is_active,created_at,last_login FROM users WHERE role='student' ORDER BY created_at DESC")->fetchAll();
$chat_msgs = array_reverse($pdo->query("SELECT * FROM chat_messages ORDER BY created_at DESC LIMIT 100")->fetchAll());
$modules   = $pdo->query("SELECT * FROM modules ORDER BY module_name")->fetchAll();
$activity  = $pdo->query("SELECT sr.registered_at,sr.status,u.full_name AS student,m.module_name,tu.full_name AS tutor
    FROM session_registrations sr JOIN users u ON u.id=sr.student_id
    JOIN tutor_offers t ON t.id=sr.tutor_offer_id JOIN modules m ON m.id=t.module_id
    JOIN users tu ON tu.id=t.tutor_id ORDER BY sr.registered_at DESC LIMIT 8")->fetchAll();
$all_offers= $pdo->query("SELECT t.*,u.full_name AS tname,u.student_id AS tsid,m.module_code,m.module_name,
    COUNT(CASE WHEN r.status='attending' THEN 1 END) AS att
    FROM tutor_offers t JOIN users u ON u.id=t.tutor_id JOIN modules m ON m.id=t.module_id
    LEFT JOIN session_registrations r ON r.tutor_offer_id=t.id
    WHERE t.status='active' GROUP BY t.id ORDER BY t.created_at DESC")->fetchAll();
$all_pending_req=$pdo->query("SELECT mr.*,u.full_name,u.student_id AS sid,m.module_code,m.module_name
    FROM module_requests mr JOIN users u ON u.id=mr.student_id JOIN modules m ON m.id=mr.module_id
    WHERE mr.status='pending' ORDER BY mr.created_at DESC")->fetchAll();
$bb_requests=$pdo->query("SELECT br.*,bl.title AS item_title,bl.category,u.full_name AS bname,u.student_id AS bsid,lu.full_name AS lname
    FROM bb_requests br JOIN bb_listings bl ON bl.id=br.listing_id
    JOIN users u ON u.id=br.borrower_id JOIN users lu ON lu.id=bl.lender_id
    ORDER BY br.created_at DESC LIMIT 50")->fetchAll();
$bb_listings=$pdo->query("SELECT bl.*,u.full_name AS lender,
    (SELECT COUNT(*) FROM bb_requests WHERE listing_id=bl.id AND status='pending') AS preqs
    FROM bb_listings bl JOIN users u ON u.id=bl.lender_id ORDER BY bl.created_at DESC")->fetchAll();

/* ── RATINGS DATA ── */
$ratings_all = $pdo->query("
    SELECT r.*, r.module,
           ru.full_name AS rater_name, ru.student_id AS rater_sid,
           rd.full_name AS rated_name, rd.student_id AS rated_sid
    FROM ratings r
    JOIN users ru ON ru.id = r.rater_id
    JOIN users rd ON rd.id = r.rated_id
    ORDER BY r.created_at DESC LIMIT 200
")->fetchAll();

$ratings_reclaim = $pdo->query("
    SELECT rr.*, rc.item_id,
           ru.full_name AS rater_name, ru.student_id AS rater_sid,
           rd.full_name AS rated_name, rd.student_id AS rated_sid,
           ri.title AS item_title, ri.category AS item_cat
    FROM reclaim_ratings rr
    JOIN users ru ON ru.id = rr.rater_id
    JOIN users rd ON rd.id = rr.rated_id
    LEFT JOIN reclaim_claims rc ON rc.id = rr.claim_id
    LEFT JOIN reclaim_items ri ON ri.id = rc.item_id
    ORDER BY rr.created_at DESC LIMIT 200
")->fetchAll();

$rating_totals = $pdo->query("
    SELECT module, COUNT(*) AS cnt, AVG(stars) AS avg
    FROM ratings GROUP BY module
")->fetchAll();

/* ── RECLAIM DATA ── */
$reclaim_items = $pdo->query("
    SELECT ri.*, u.full_name AS finder_name, u.student_id AS finder_sid,
           COUNT(DISTINCT rq.id) AS question_count,
           COUNT(DISTINCT rc.id) AS claim_count,
           SUM(CASE WHEN rc.status='pending' THEN 1 ELSE 0 END) AS pending_claims,
           SUM(CASE WHEN rc.status='confirmed' THEN 1 ELSE 0 END) AS confirmed_claims
    FROM reclaim_items ri
    JOIN users u ON u.id = ri.finder_id
    LEFT JOIN reclaim_questions rq ON rq.item_id = ri.id
    LEFT JOIN reclaim_claims rc ON rc.item_id = ri.id
    GROUP BY ri.id ORDER BY ri.created_at DESC
")->fetchAll();

$reclaim_claims = $pdo->query("
    SELECT rc.*, ri.title AS item_title, ri.category, ri.location_found,
           u.full_name AS claimer_name, u.student_id AS claimer_sid,
           fu.full_name AS finder_name, fu.student_id AS finder_sid,
           ri.finder_id
    FROM reclaim_claims rc
    JOIN reclaim_items ri ON ri.id = rc.item_id
    JOIN users u ON u.id = rc.claimer_id
    JOIN users fu ON fu.id = ri.finder_id
    ORDER BY rc.created_at DESC LIMIT 80
")->fetchAll();

$reclaim_total_unclaimed  = (int)$pdo->query("SELECT COUNT(*) FROM reclaim_items WHERE status='unclaimed'")->fetchColumn();
$reclaim_total_claimed    = (int)$pdo->query("SELECT COUNT(*) FROM reclaim_items WHERE status='claimed'")->fetchColumn();
$reclaim_total_completed  = (int)$pdo->query("SELECT COUNT(*) FROM reclaim_items WHERE status='completed'")->fetchColumn();
$reclaim_pending_claims   = (int)$pdo->query("SELECT COUNT(*) FROM reclaim_claims WHERE status='pending'")->fetchColumn();
$reclaim_top_finders      = $pdo->query("
    SELECT u.full_name, u.student_id, COUNT(ri.id) AS found_count,
           COALESCE(AVG(rr.stars),0) AS avg_rating, COUNT(DISTINCT rr.id) AS rating_count
    FROM reclaim_items ri
    JOIN users u ON u.id = ri.finder_id
    LEFT JOIN reclaim_ratings rr ON rr.rated_id = ri.finder_id
    GROUP BY ri.finder_id ORDER BY found_count DESC LIMIT 5
")->fetchAll();

$chart_data=[];
for($i=6;$i>=0;$i--) $chart_data[date('Y-m-d',strtotime("-{$i} days"))]=0;
foreach($pdo->query("SELECT DATE(registered_at) d,COUNT(*) c FROM session_registrations WHERE registered_at>=CURDATE()-INTERVAL 6 DAY GROUP BY DATE(registered_at)") as $r) $chart_data[$r['d']]=(int)$r['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Admin Panel — UniConnect</title>
<link rel="icon" type="image/png" href="icbt.png">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=Nunito:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --coral:#EE4540;--crimson:#C72C41;--wine:#801336;--plum:#510A32;--midnight:#2D142C;
  --bg:#f5eeee;--card:#fff;--text:#2D142C;--muted:#9a8080;--border:#eedede;
  --sw:242px;--green:#2e9e68;--amber:#d97706;--blue:#3b82f6;--indigo:#6366f1;
}
html,body{height:100%;font-family:'Nunito',sans-serif;background:var(--bg);color:var(--text);overflow:hidden}
.layout{display:grid;grid-template-columns:var(--sw) 1fr;height:100vh}

/* ─── SIDEBAR ─── */
.sidebar{background:linear-gradient(175deg,var(--midnight) 0%,var(--plum) 55%,var(--wine) 100%);
  display:flex;flex-direction:column;height:100vh;overflow:hidden;position:relative}
.sidebar::after{content:'';position:absolute;top:0;right:0;width:1.5px;height:100%;
  background:linear-gradient(to bottom,transparent,rgba(238,69,64,.5) 50%,transparent)}
.sbb{padding:22px 18px 14px;display:flex;align-items:center;gap:10px;
  border-bottom:1px solid rgba(255,255,255,.06);flex-shrink:0}
.sblogo{width:34px;height:34px;border-radius:50%;border:1.5px solid rgba(255,255,255,.2);
  background:rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;font-size:15px}
.sbname{font-family:'Cormorant Garamond',serif;font-size:15px;font-weight:600;color:#fff}
.sbsub{font-size:8.5px;color:rgba(255,255,255,.3);letter-spacing:.12em;text-transform:uppercase}
.apill{background:linear-gradient(135deg,var(--coral),var(--crimson));color:#fff;
  font-size:7px;font-weight:800;padding:2px 8px;border-radius:50px;letter-spacing:.1em;text-transform:uppercase;margin-top:3px;display:inline-block}
.navs{flex:1;overflow-y:auto;padding:10px 0}.navs::-webkit-scrollbar{display:none}
.nl{font-size:8.5px;letter-spacing:.18em;text-transform:uppercase;color:rgba(255,255,255,.22);font-weight:700;padding:9px 17px 3px}
.ni{display:flex;align-items:center;gap:10px;padding:9px 17px;color:rgba(255,255,255,.52);
  font-size:12px;font-weight:500;cursor:pointer;border-left:2.5px solid transparent;
  transition:all .2s;user-select:none;text-decoration:none}
.ni:hover{color:#fff;background:rgba(255,255,255,.05);border-left-color:rgba(238,69,64,.32)}
.ni.active{color:#fff;background:rgba(238,69,64,.14);border-left-color:var(--coral)}
.ni.active .nico{color:var(--coral)}
.nico{font-size:14px;width:16px;text-align:center;flex-shrink:0}
.np{margin-left:auto;color:#fff;font-size:7.5px;font-weight:800;padding:2px 7px;border-radius:50px;background:var(--coral)}
.np.g{background:var(--green)}.np.a{background:var(--amber)}
.sbfoot{border-top:1px solid rgba(255,255,255,.06);padding:11px 13px 16px;flex-shrink:0}
.uch{display:flex;align-items:center;gap:9px;padding:7px 9px;border-radius:9px}
.ava{width:30px;height:30px;border-radius:50%;flex-shrink:0;
  background:linear-gradient(135deg,var(--indigo),#8b5cf6);
  display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff}
.ucn{font-size:11px;font-weight:600;color:#fff}
.ucr{font-size:9px;color:rgba(255,255,255,.32)}

/* ─── MAIN ─── */
.main{display:flex;flex-direction:column;height:100vh;overflow:hidden}
.topbar{background:rgba(245,238,238,.95);backdrop-filter:blur(10px);
  border-bottom:1px solid var(--border);padding:0 26px;height:57px;
  display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.pgt{font-family:'Cormorant Garamond',serif;font-size:19px;font-weight:600;color:var(--midnight)}
.tr{display:flex;align-items:center;gap:9px}
.tbadge{display:flex;align-items:center;gap:5px;padding:5px 12px;border-radius:50px;
  background:linear-gradient(135deg,rgba(99,102,241,.1),rgba(139,92,246,.1));
  border:1px solid rgba(99,102,241,.2);font-size:11px;font-weight:700;color:var(--indigo)}
.back-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 13px;
  border:1px solid var(--border);border-radius:50px;font-size:11px;font-weight:600;
  color:var(--muted);text-decoration:none;transition:all .2s;background:var(--card)}
.back-btn:hover{border-color:var(--crimson);color:var(--crimson)}
.chip{display:flex;align-items:center;gap:7px;padding:4px 11px 4px 5px;
  border:1px solid var(--border);border-radius:50px;background:var(--card)}
.avsm{width:24px;height:24px;border-radius:50%;background:linear-gradient(135deg,var(--indigo),#8b5cf6);
  display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:#fff}

.scroll{flex:1;overflow-y:auto;padding:22px 26px 50px}
.scroll::-webkit-scrollbar{width:4px}
.scroll::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px}

/* ─── ALERTS ─── */
.alert{padding:11px 15px;border-radius:10px;margin-bottom:16px;font-size:12px;
  display:flex;align-items:center;gap:8px;font-weight:500;animation:fu .3s ease}
.as{background:rgba(46,158,104,.08);border:1px solid rgba(46,158,104,.22);color:var(--green)}
.ae{background:rgba(238,69,64,.08);border:1px solid rgba(238,69,64,.25);color:var(--coral)}

/* ─── PANELS ─── */
.tab-panel{display:none;animation:fu .32s ease both}
.tab-panel.active{display:block}

/* ─── STAT GRID ─── */
.sg4{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.sc{background:var(--card);border:1px solid var(--border);border-radius:14px;
  padding:15px 17px;position:relative;overflow:hidden;
  transition:transform .25s,box-shadow .25s;animation:fu .4s ease both}
.sc:hover{transform:translateY(-3px);box-shadow:0 10px 24px rgba(45,20,44,.09)}
.scd{position:absolute;width:75px;height:75px;border-radius:50%;top:-16px;right:-14px;opacity:.09}
.sc .ico{font-size:19px;margin-bottom:7px;display:block}
.scv{font-family:'Cormorant Garamond',serif;font-size:30px;font-weight:600;color:var(--midnight);line-height:1}
.scl{font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-top:3px;font-weight:600}
.scs{font-size:9.5px;font-weight:700;margin-top:6px;display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:50px}
.sg{color:var(--green);background:rgba(46,158,104,.09)}
.sa{color:var(--amber);background:rgba(217,119,6,.09)}
.sr2{color:var(--coral);background:rgba(238,69,64,.09)}
.sc:nth-child(1) .scd{background:var(--crimson)}
.sc:nth-child(2) .scd{background:var(--green)}
.sc:nth-child(3) .scd{background:var(--amber)}
.sc:nth-child(4) .scd{background:var(--blue)}

/* ─── LAYOUT GRIDS ─── */
.g2{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start}
.g2l{display:grid;grid-template-columns:2fr 1fr;gap:16px;align-items:start}
.g3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;align-items:start}
.mb{margin-bottom:20px}

/* ─── SECTION TITLE ─── */
.sec{font-family:'Cormorant Garamond',serif;font-size:16.5px;font-weight:600;color:var(--midnight);
  margin-bottom:13px;display:flex;align-items:center;gap:8px}
.sec::before{content:'';width:3px;height:16px;border-radius:2px;
  background:linear-gradient(to bottom,var(--coral),var(--crimson));flex-shrink:0}

/* ─── CARD SHELL ─── */
.card{background:var(--card);border:1px solid var(--border);border-radius:15px;overflow:hidden}
.ch{background:linear-gradient(125deg,var(--midnight),var(--plum) 55%,var(--wine) 100%);
  padding:17px 19px;position:relative;overflow:hidden}
.chdeco{position:absolute;width:110px;height:110px;border-radius:50%;background:rgba(238,69,64,.1);top:-28px;right:-13px;pointer-events:none}
.ct{font-family:'Cormorant Garamond',serif;font-size:17px;font-weight:600;color:#fff;margin-bottom:3px;position:relative;z-index:1}
.cs{font-size:10px;color:rgba(255,255,255,.38);font-weight:300;position:relative;z-index:1}
.cb{padding:19px}

/* ─── FORM ─── */
.fg{margin-bottom:12px}
.fl{display:block;font-size:9px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;margin-bottom:5px}
.fc{width:100%;padding:9px 12px;background:var(--bg);border:1px solid var(--border);
  border-radius:9px;color:var(--text);font-family:'Nunito',sans-serif;font-size:12px;
  outline:none;transition:border-color .2s,box-shadow .2s;appearance:none}
.fc:focus{border-color:var(--crimson);box-shadow:0 0 0 3px rgba(199,44,65,.07)}
textarea.fc{resize:vertical;min-height:70px;line-height:1.55}
.fcr{display:grid;grid-template-columns:1fr 1fr;gap:9px}
.btn{width:100%;padding:11px;border:none;border-radius:9px;
  background:linear-gradient(135deg,var(--crimson),var(--wine));
  color:#fff;font-family:'Nunito',sans-serif;font-size:12.5px;font-weight:700;
  cursor:pointer;transition:all .25s;box-shadow:0 4px 12px rgba(199,44,65,.2);margin-top:4px}
.btn:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(199,44,65,.28)}
.bsm{padding:5px 12px;border:none;border-radius:7px;font-family:'Nunito',sans-serif;
  font-size:10px;font-weight:700;cursor:pointer;transition:all .18s}
.bd{background:rgba(238,69,64,.08);color:var(--coral);border:1px solid rgba(238,69,64,.18)}
.bd:hover{background:rgba(238,69,64,.15)}
.bg2{background:rgba(46,158,104,.08);color:var(--green);border:1px solid rgba(46,158,104,.18)}
.bg2:hover{background:rgba(46,158,104,.15)}
.bm{background:var(--bg);color:var(--muted);border:1px solid var(--border)}
.bm:hover{border-color:var(--crimson);color:var(--crimson)}
.tagrow{display:flex;gap:7px}
.tropt input{display:none}
.tropt label{padding:7px 13px;border:1.5px solid var(--border);border-radius:50px;
  font-size:11px;font-weight:700;color:var(--muted);cursor:pointer;transition:all .2s;display:block}
.tropt input:checked+label{border-color:var(--crimson);background:rgba(199,44,65,.07);color:var(--crimson)}

/* ─── TABLE ─── */
.tcard{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden}
.ttb{padding:11px 15px;display:flex;align-items:center;gap:9px;border-bottom:1px solid var(--border)}
.sw{flex:1;position:relative}
.sw input{width:100%;padding:8px 13px 8px 33px;background:var(--bg);border:1px solid var(--border);
  border-radius:50px;font-family:'Nunito',sans-serif;font-size:11.5px;color:var(--text);outline:none;transition:border-color .2s}
.sw input:focus{border-color:var(--crimson)}
.sw input::placeholder{color:var(--muted)}
.sico{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:12px;pointer-events:none}
.tblw{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:11.5px}
thead tr{background:var(--bg);border-bottom:1px solid var(--border)}
th{padding:10px 14px;text-align:left;font-size:8.5px;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);font-weight:700}
td{padding:10px 14px;border-bottom:1px solid rgba(238,222,222,.32);vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:rgba(199,44,65,.01)}
.tn{font-weight:700;color:var(--midnight);font-size:12px}
.tsub{font-size:9.5px;color:var(--muted);margin-top:1px}
.badge{display:inline-block;padding:2px 9px;border-radius:50px;font-size:9.5px;font-weight:700}
.bact{background:rgba(46,158,104,.1);color:var(--green)}
.bdis{background:rgba(154,128,128,.1);color:var(--muted)}
.bpend{background:rgba(238,69,64,.09);color:var(--coral)}
.bappr{background:rgba(46,158,104,.1);color:var(--green)}
.brej{background:rgba(154,128,128,.1);color:var(--muted)}
.bret{background:rgba(59,130,246,.1);color:var(--blue)}
.bev{background:rgba(99,102,241,.1);color:var(--indigo)}
.bno{background:rgba(45,20,44,.08);color:var(--plum)}
.burg{background:rgba(238,69,64,.1);color:var(--coral)}

/* ─── NOTICE ITEMS ─── */
.nlist{display:flex;flex-direction:column;gap:9px}
.nitem{background:var(--card);border:1px solid var(--border);border-radius:12px;
  padding:13px 15px;display:flex;gap:11px;align-items:flex-start;transition:box-shadow .2s;animation:fu .3s ease both}
.nitem:hover{box-shadow:0 5px 14px rgba(45,20,44,.08)}
.nitem.off{opacity:.5}
.nbar{width:3px;border-radius:3px;flex-shrink:0;align-self:stretch;min-height:32px}
.nb-event{background:var(--indigo)}.nb-notice{background:var(--crimson)}.nb-urgent{background:var(--coral)}
.nc{flex:1;min-width:0}
.nt{display:flex;align-items:center;gap:7px;margin-bottom:3px;flex-wrap:wrap}
.ntitle{font-weight:700;font-size:12.5px;color:var(--midnight)}
.nbody{font-size:11px;color:var(--muted);line-height:1.5;margin-bottom:4px}
.nmeta{font-size:9px;color:var(--muted)}
.nact{display:flex;flex-direction:column;gap:4px;flex-shrink:0}

/* ─── CHART ─── */
.chartw{height:120px;display:flex;align-items:flex-end;gap:5px;padding:0 2px;margin-top:10px}
.bcol{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px}
.bbar{width:100%;border-radius:4px 4px 0 0;background:linear-gradient(to top,var(--crimson),var(--coral));min-height:3px;transition:height .5s}
.bbar.z{background:var(--border)}
.blbl{font-size:8px;color:var(--muted);font-weight:600}
.bval{font-size:8px;color:var(--midnight);font-weight:700}

/* ─── CHAT ─── */
.chatbox{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;display:flex;flex-direction:column;height:450px}
.chath{padding:12px 15px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.chatmsgs{flex:1;overflow-y:auto;padding:13px;display:flex;flex-direction:column;gap:7px}
.chatmsgs::-webkit-scrollbar{width:3px}
.chatmsgs::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px}
.mrow{display:flex;gap:8px;align-items:flex-start}
.mava{width:26px;height:26px;border-radius:50%;flex-shrink:0;
  background:linear-gradient(135deg,var(--coral),var(--crimson));
  display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:#fff}
.mbub{max-width:72%;padding:8px 11px;border-radius:11px;background:var(--bg);border:1px solid var(--border)}
.mname{font-size:9px;font-weight:700;color:var(--crimson);margin-bottom:2px}
.mtxt{font-size:11.5px;color:var(--text);line-height:1.5}
.mtime{font-size:8.5px;color:var(--muted);margin-top:2px}
.mdel{background:none;border:none;font-size:10px;color:var(--muted);cursor:pointer;
  opacity:0;transition:opacity .18s;padding:0 3px;align-self:center}
.mrow:hover .mdel{opacity:1}

/* ─── EMPTY ─── */
.empty{text-align:center;padding:34px 16px;background:var(--card);border:1px solid var(--border);border-radius:13px;margin-bottom:14px}
.ei{width:60px;height:60px;border-radius:50%;background:rgba(238,69,64,.05);border:1px solid var(--border);
  display:flex;align-items:center;justify-content:center;font-size:25px;margin:0 auto 9px}
.et{font-family:'Cormorant Garamond',serif;font-size:16px;font-weight:600;color:var(--midnight);margin-bottom:3px}
.es{font-size:11px;color:var(--muted)}

@keyframes fu{from{opacity:0;transform:translateY(9px)}to{opacity:1;transform:translateY(0)}}

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
<div class="layout">

<!-- ═══ SIDEBAR ═══ -->
<aside class="sidebar">
  <div class="sbb">
    <div class="sblogo">🎓</div>
    <div>
      <div class="sbname">UniConnect</div>
      <div class="sbsub">ICBT Campus</div>
      <div class="apill">Admin Panel</div>
    </div>
  </div>
  <div class="navs">
    <div class="nl">Overview</div>
    <a class="ni active" onclick="sw('overview',this)"><span class="nico">📊</span>Dashboard</a>
    <div class="nl" style="margin-top:4px">Content</div>
    <a class="ni" onclick="sw('notices',this)"><span class="nico">📌</span>Noticeboard
      <?php if($active_notices): ?><span class="np"><?=$active_notices?></span><?php endif?>
    </a>
    <a class="ni" onclick="sw('events',this)"><span class="nico">🗓️</span>Events
      <?php if($upcoming_events): ?><span class="np g"><?=$upcoming_events?></span><?php endif?>
    </a>
    <div class="nl" style="margin-top:4px">Management</div>
    <a class="ni" onclick="sw('students',this)"><span class="nico">👥</span>Students
      <?php if($new_today): ?><span class="np g">+<?=$new_today?></span><?php endif?>
    </a>
    <a class="ni" onclick="sw('addstudent',this)"><span class="nico">➕</span>Add Student</a>
    <a class="ni" onclick="sw('chatroom',this)"><span class="nico">💬</span>Chatroom
      <?php if($chat_today): ?><span class="np a"><?=$chat_today?></span><?php endif?>
    </a>
    <div class="nl" style="margin-top:4px">Services</div>
    <a class="ni" onclick="sw('brainbridge',this)"><span class="nico">🧠</span>Brain Bridge
      <?php if($pending_reqs): ?><span class="np"><?=$pending_reqs?></span><?php endif?>
    </a>

    <a class="ni" onclick="sw('reclaim',this)"><span class="nico">♻️</span>Reclaim
  <?php
    $reclaim_pending = (int)$pdo->query("SELECT COUNT(*) FROM reclaim_claims WHERE status='pending'")->fetchColumn();
    if($reclaim_pending): ?><span class="np a"><?=$reclaim_pending?></span><?php endif?>
</a>

    <a class="ni" onclick="sw('burrow',this)"><span class="nico">📦</span>Burrow Buddy
      <?php if($bb_pending): ?><span class="np a"><?=$bb_pending?></span><?php endif?>
    </a>

    <a class="ni" onclick="sw('ratings',this)"><span class="nico">⭐</span>Ratings</a>

    <a class="ni" onclick="sw('modules',this)"><span class="nico">📚</span>Modules</a>
    <div class="nl" style="margin-top:4px">Account</div>
    <a class="ni" href="auth/logout.php"><span class="nico">🚪</span>Sign Out</a>
  </div>
  <div class="sbfoot">
    <div class="uch">
      <div class="ava"><?=$initials?></div>
      <div><div class="ucn"><?=$admin_name?></div><div class="ucr">Administrator</div></div>
    </div>
  </div>
</aside>

<!-- ═══ MAIN ═══ -->
<div class="main">
  <div class="topbar">
    <div class="pgt" id="pgt">Admin Dashboard</div>
    <div class="tr">
      <div class="tbadge">🛡️ Admin Access</div>
      <!--<a href="student_dashboard.php" class="back-btn">← Student View</a>-->
      <div class="chip">
        <div class="avsm"><?=$initials?></div>
        <span style="font-size:11.5px;font-weight:600;color:var(--text)"><?=$first?></span>
      </div>
    </div>
  </div>

  <div class="scroll">

    <?php if($msg_success): ?><div class="alert as">✓ <?=htmlspecialchars($msg_success)?></div><?php endif; ?>
    <?php if($msg_error):   ?><div class="alert ae">✕ <?=htmlspecialchars($msg_error)?></div><?php endif; ?>

    <!-- ══════════ OVERVIEW ══════════ -->
    <div class="tab-panel active" id="panel-overview">
      <div class="sg4">
        <div class="sc" style="animation-delay:.04s">
          <div class="scd"></div><span class="ico">👥</span>
          <div class="scv"><?=$total_students?></div><div class="scl">Total Students</div>
          <?php if($new_today): ?><span class="scs sg">↑ +<?=$new_today?> today</span><?php endif?>
        </div>
        <div class="sc" style="animation-delay:.08s">
          <div class="scd"></div><span class="ico">🧠</span>
          <div class="scv"><?=$active_sessions?></div><div class="scl">Active BB Sessions</div>
          <?php if($pending_reqs): ?><span class="scs sa">⏳ <?=$pending_reqs?> requests</span><?php endif?>
        </div>
        <div class="sc" style="animation-delay:.12s">
          <div class="scd"></div><span class="ico">📌</span>
          <div class="scv"><?=$active_notices?></div><div class="scl">Active Notices</div>
        </div>
        <div class="sc" style="animation-delay:.16s">
          <div class="scd"></div><span class="ico">📦</span>
          <div class="scv"><?=$bb_available?></div><div class="scl">Items Available</div>
          <?php if($bb_pending): ?><span class="scs sr2">📩 <?=$bb_pending?> pending</span><?php endif?>
        </div>
      </div>

      <div class="g2l mb">
        <div style="background:var(--card);border:1px solid var(--border);border-radius:14px;padding:17px 19px">
          <div style="display:flex;align-items:center;justify-content:space-between">
            <div class="sec" style="margin-bottom:0">Brain Bridge — 7-Day Registrations</div>
            <span style="font-size:10px;color:var(--muted)"><?=array_sum($chart_data)?> total</span>
          </div>
          <?php $mx=max(max(array_values($chart_data)),1); ?>
          <div class="chartw">
            <?php foreach($chart_data as $day=>$val): ?>
            <div class="bcol">
              <span class="bval"><?=$val?:''?></span>
              <div class="bbar <?=$val==0?'z':''?>" style="height:<?=$val==0?5:round($val/$mx*105)?>px"></div>
              <span class="blbl"><?=date('D',strtotime($day))?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div style="background:var(--card);border:1px solid var(--border);border-radius:14px;padding:15px 17px">
          <div class="sec" style="margin-bottom:11px">Recent Activity</div>
          <?php if(empty($activity)): ?>
            <div style="text-align:center;padding:20px;color:var(--muted);font-size:11.5px">No activity yet.</div>
          <?php else: foreach($activity as $a): ?>
            <div style="display:flex;gap:8px;padding:6px 0;border-bottom:1px solid rgba(238,222,222,.28)">
              <div style="width:6px;height:6px;border-radius:50%;flex-shrink:0;margin-top:5px;
                background:<?=$a['status']==='attending'?'var(--green)':'var(--amber)'?>"></div>
              <div>
                <div style="font-size:11px;line-height:1.4">
                  <strong><?=htmlspecialchars($a['student'])?></strong>
                  <?=$a['status']==='attending'?'joined':'waitlisted for'?>
                  <strong><?=htmlspecialchars($a['module_name'])?></strong>
                </div>
                <div style="font-size:9px;color:var(--muted)"><?=date('d M, g:i a',strtotime($a['registered_at']))?></div>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <div class="g3">
        <?php
          $qblocks = [
            ['Student Status',      [['Active','var(--green)',$active_students],['Disabled','var(--muted)',$disabled_count],['New Today','var(--amber)',$new_today]]],
            ['Content & Events',    [['Active Notices','var(--crimson)',$active_notices],['Upcoming Events','var(--indigo)',$upcoming_events],['Modules','',count($modules)]]],
            ['Burrow Buddy',        [['Available Items','var(--green)',$bb_available],['Pending Requests','var(--amber)',$bb_pending],['Total Listings','',count($bb_listings)]]],
          ];
          foreach($qblocks as [$title,$rows]):
        ?>
          <div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:15px">
            <div style="font-size:8.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;margin-bottom:9px"><?=$title?></div>
            <?php foreach($rows as [$l,$c,$v]): ?>
            <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:5px">
              <span <?php if($c) echo "style='color:$c'"; ?>><?=$l?></span><strong><?=$v?></strong>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ══════════ NOTICEBOARD ══════════ -->
    <div class="tab-panel" id="panel-notices">
      <div class="g2">
        <div class="card">
          <div class="ch"><div class="chdeco"></div>
            <div class="ct">Post a Notice</div>
            <div class="cs">Students see this on their dashboard noticeboard.</div>
          </div>
          <div class="cb">
            <form method="POST">
              <input type="hidden" name="action" value="add_notice"/>
              <div class="fg"><label class="fl">Title</label>
                <input type="text" name="title" class="fc" placeholder="Notice title…" required/>
              </div>
              <div class="fg"><label class="fl">Content <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:9px">(optional)</span></label>
                <textarea name="content" class="fc" placeholder="Full notice details…"></textarea>
              </div>
              <div class="fg"><label class="fl">Tag</label>
                <div class="tagrow">
                  <div class="tropt"><input type="radio" name="tag" id="t1" value="notice" checked/><label for="t1">📋 Notice</label></div>
                  <div class="tropt"><input type="radio" name="tag" id="t2" value="event"/><label for="t2">🎉 Event</label></div>
                  <div class="tropt"><input type="radio" name="tag" id="t3" value="urgent"/><label for="t3">🚨 Urgent</label></div>
                </div>
              </div>
              <div class="fcr">
                <div class="fg" style="margin-bottom:0"><label class="fl">Date <span style="font-weight:400;font-size:9px;text-transform:none;letter-spacing:0">(optional)</span></label>
                  <input type="date" name="event_date" class="fc"/>
                </div>
                <div class="fg" style="margin-bottom:0"><label class="fl">Location <span style="font-weight:400;font-size:9px;text-transform:none;letter-spacing:0">(optional)</span></label>
                  <input type="text" name="location" class="fc" placeholder="e.g. Main Hall"/>
                </div>
              </div>
              <button type="submit" class="btn">📌 Post to Noticeboard →</button>
            </form>
          </div>
        </div>

        <div>
          <div class="sec">All Notices (<?=count($notices)?>)</div>
          <?php if(empty($notices)): ?>
            <div class="empty"><div class="ei">📭</div><div class="et">No notices yet</div></div>
          <?php else: ?>
            <div class="nlist">
              <?php foreach($notices as $i=>$n): ?>
              <div class="nitem <?=$n['is_active']?'':'off'?>" style="animation-delay:<?=$i*.04?>s">
                <div class="nbar nb-<?=$n['tag']?>"></div>
                <div class="nc">
                  <div class="nt">
                    <span class="ntitle"><?=htmlspecialchars($n['title'])?></span>
                    <span class="badge b<?=substr($n['tag'],0,2)?>"><?=ucfirst($n['tag'])?></span>
                    <?php if(!$n['is_active']): ?><span class="badge bdis">Hidden</span><?php endif; ?>
                  </div>
                  <?php if($n['content']): ?><div class="nbody"><?=htmlspecialchars(mb_strimwidth($n['content'],0,90,'…'))?></div><?php endif; ?>
                  <div class="nmeta">
                    <?=$n['event_date']?'📅 '.date('d M Y',strtotime($n['event_date'])).' · ':''?>
                    <?=$n['location']?'📍 '.htmlspecialchars($n['location']).' · ':''?>
                    <?=date('d M Y',strtotime($n['created_at']))?>
                    <?php if($n['posted_by']): ?> · <?=htmlspecialchars($n['posted_by'])?><?php endif; ?>
                  </div>
                </div>
                <div class="nact">
                  <form method="POST">
                    <input type="hidden" name="action" value="toggle_notice"/>
                    <input type="hidden" name="notice_id" value="<?=$n['id']?>"/>
                    <button type="submit" class="bsm bm"><?=$n['is_active']?'Hide':'Show'?></button>
                  </form>
                  <form method="POST" onsubmit="return confirm('Delete this notice?')">
                    <input type="hidden" name="action" value="delete_notice"/>
                    <input type="hidden" name="notice_id" value="<?=$n['id']?>"/>
                    <button type="submit" class="bsm bd">Delete</button>
                  </form>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ══════════ EVENTS ══════════ -->
    <div class="tab-panel" id="panel-events">
      <div class="g2">
        <div class="card">
          <div class="ch"><div class="chdeco"></div>
            <div class="ct">Add Campus Event</div>
            <div class="cs">Shows on the student dashboard upcoming events list.</div>
          </div>
          <div class="cb">
            <form method="POST">
              <input type="hidden" name="action" value="add_event"/>
              <div class="fg"><label class="fl">Event Title</label>
                <input type="text" name="ev_title" class="fc" placeholder="e.g. Tech Symposium 2025" required/>
              </div>
              <div class="fg"><label class="fl">Description <span style="font-weight:400;font-size:9px;text-transform:none;letter-spacing:0">(optional)</span></label>
                <textarea name="ev_desc" class="fc" placeholder="Brief description…" style="min-height:52px"></textarea>
              </div>
              <div class="fcr">
                <div class="fg" style="margin-bottom:0"><label class="fl">Date</label>
                  <input type="date" name="ev_date" class="fc" required/>
                </div>
                <div class="fg" style="margin-bottom:0"><label class="fl">Time <span style="font-weight:400;font-size:9px;text-transform:none;letter-spacing:0">(optional)</span></label>
                  <input type="text" name="ev_time" class="fc" placeholder="e.g. 9:00 AM"/>
                </div>
              </div>
              <div class="fg" style="margin-top:11px"><label class="fl">Location <span style="font-weight:400;font-size:9px;text-transform:none;letter-spacing:0">(optional)</span></label>
                <input type="text" name="ev_location" class="fc" placeholder="e.g. Auditorium"/>
              </div>
              <button type="submit" class="btn">🗓️ Add Event →</button>
            </form>
          </div>
        </div>
        <div>
          <div class="sec">All Events (<?=count($events)?>)</div>
          <?php if(empty($events)): ?>
            <div class="empty"><div class="ei">🗓️</div><div class="et">No events yet</div></div>
          <?php else: ?>
            <div class="nlist">
              <?php foreach($events as $i=>$ev):
                $past = strtotime($ev['event_date']) < strtotime('today');
              ?>
              <div class="nitem <?=$past?'off':''?>" style="animation-delay:<?=$i*.04?>s">
                <div class="nbar nb-event"></div>
                <div class="nc">
                  <div class="nt">
                    <span class="ntitle"><?=htmlspecialchars($ev['title'])?></span>
                    <?php if($past): ?><span class="badge bdis">Past</span>
                    <?php else: ?><span class="badge bact">Upcoming</span><?php endif; ?>
                  </div>
                  <?php if($ev['description']): ?><div class="nbody"><?=htmlspecialchars($ev['description'])?></div><?php endif; ?>
                  <div class="nmeta">
                    📅 <?=date('d M Y',strtotime($ev['event_date']))?>
                    <?=$ev['event_time']?' · '.$ev['event_time']:''?>
                    <?=$ev['location']?' · 📍 '.htmlspecialchars($ev['location']):''?>
                  </div>
                </div>
                <form method="POST" onsubmit="return confirm('Delete event?')">
                  <input type="hidden" name="action" value="delete_event"/>
                  <input type="hidden" name="event_id" value="<?=$ev['id']?>"/>
                  <button type="submit" class="bsm bd">Delete</button>
                </form>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ══════════ STUDENTS ══════════ -->
    <div class="tab-panel" id="panel-students">
      <div class="sec">Student Roster</div>
      <div class="tcard">
        <div class="ttb">
          <div class="sw"><span class="sico">🔍</span>
            <input type="text" id="ss" placeholder="Search name, email, student ID…" oninput="fs()"/>
          </div>
          <span style="font-size:11px;color:var(--muted);white-space:nowrap"><?=$active_students?> active · <?=$disabled_count?> disabled</span>
        </div>
        <div class="tblw">
          <table>
            <thead><tr><th>#</th><th>Student</th><th>Email</th><th>Student ID</th><th>Status</th><th>Joined</th><th>Last Login</th><th>Action</th></tr></thead>
            <tbody>
              <?php foreach($students as $i=>$s):
                $sw2=array_filter(explode(' ',trim($s['full_name'])));
                $si2=strtoupper(substr($sw2[0]??'S',0,1).substr(end($sw2)??'',0,1));
              ?>
              <tr class="srow"
                  data-name="<?=strtolower(htmlspecialchars($s['full_name']))?>"
                  data-email="<?=strtolower(htmlspecialchars($s['email']))?>"
                  data-sid="<?=strtolower(htmlspecialchars($s['student_id']))?>">
                <td style="color:var(--muted);font-weight:600"><?=$i+1?></td>
                <td>
                  <div style="display:flex;align-items:center;gap:8px">
                    <div style="width:25px;height:25px;border-radius:50%;flex-shrink:0;
                      background:linear-gradient(135deg,var(--coral),var(--crimson));
                      display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:#fff"><?=$si2?></div>
                    <div class="tn"><?=htmlspecialchars($s['full_name'])?></div>
                  </div>
                </td>
                <td style="color:var(--muted);font-size:10.5px"><?=htmlspecialchars($s['email'])?></td>
                <td><code style="font-size:10.5px;background:var(--bg);padding:2px 8px;border-radius:5px;border:1px solid var(--border)"><?=htmlspecialchars($s['student_id'])?></code></td>
                <td><span class="badge <?=$s['is_active']?'bact':'bdis'?>"><?=$s['is_active']?'Active':'Disabled'?></span></td>
                <td style="font-size:10px;color:var(--muted)"><?=date('d M Y',strtotime($s['created_at']))?></td>
                <td style="font-size:10px;color:var(--muted)"><?=$s['last_login']?date('d M, g:i a',strtotime($s['last_login'])):'—'?></td>
                <td>
                  <form method="POST" onsubmit="return confirm('<?=$s['is_active']?'Disable':'Enable'?> this account?')">
                    <input type="hidden" name="action" value="toggle_student"/>
                    <input type="hidden" name="user_id" value="<?=$s['id']?>"/>
                    <input type="hidden" name="new_active" value="<?=$s['is_active']?0:1?>"/>
                    <button type="submit" class="bsm <?=$s['is_active']?'bd':'bg2'?>"><?=$s['is_active']?'Disable':'Enable'?></button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div style="padding:9px 15px;border-top:1px solid var(--border)">
          <span style="font-size:10.5px;color:var(--muted)" id="sc2">Showing <?=count($students)?> students</span>
        </div>
      </div>
    </div>

    <!-- ══════════ ADD STUDENT ══════════ -->
    <div class="tab-panel" id="panel-addstudent">
      <div class="g2" style="max-width:760px">
        <div class="card">
          <div class="ch"><div class="chdeco"></div>
            <div class="ct">Add New Student</div>
            <div class="cs">Creates a login account immediately.</div>
          </div>
          <div class="cb">
            <form method="POST">
              <input type="hidden" name="action" value="add_student"/>
              <div class="fg"><label class="fl">Full Name</label>
                <input type="text" name="full_name" class="fc" placeholder="e.g. Amara Jayasinghe" required/>
              </div>
              <div class="fg"><label class="fl">Email Address</label>
                <input type="email" name="email" class="fc" placeholder="student@email.com" required/>
              </div>
              <div class="fg"><label class="fl">Student ID</label>
                <input type="text" name="student_id" class="fc" placeholder="e.g. ICB2024001" required/>
              </div>
              <div class="fg"><label class="fl">Initial Password</label>
                <input type="password" name="password" class="fc" placeholder="Temporary password" required/>
              </div>
              <button type="submit" class="btn">➕ Create Account →</button>
            </form>
          </div>
        </div>
        <div style="background:var(--card);border:1px solid var(--border);border-radius:14px;padding:19px">
          <div class="sec">Notes</div>
          <?php foreach(['Student can log in immediately with the credentials you set.','Student IDs and emails must be unique — duplicates are rejected.','Disable accounts from the Students tab; data is preserved.'] as $i=>$n): ?>
          <div style="display:flex;gap:9px;margin-bottom:11px">
            <div style="width:20px;height:20px;border-radius:50%;flex-shrink:0;margin-top:1px;
              background:linear-gradient(135deg,var(--coral),var(--crimson));
              display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:#fff"><?=$i+1?></div>
            <div style="font-size:11.5px;color:var(--text);line-height:1.55"><?=$n?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- ══════════ CHATROOM ══════════ -->
    <div class="tab-panel" id="panel-chatroom">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:13px">
        <div class="sec" style="margin-bottom:0">Chat Monitor
          <span style="font-family:'Nunito';font-size:11px;color:var(--muted)">(<?=$chat_total?> total · <?=$chat_today?> today)</span>
        </div>
        <span style="font-size:10.5px;color:var(--muted)">Hover a message to reveal delete button</span>
      </div>
      <div class="chatbox">
        <div class="chath">
          <span style="font-family:'Cormorant Garamond',serif;font-size:15px;font-weight:600;color:var(--midnight)">💬 Global Chat</span>
          <span style="font-size:10px;color:var(--muted)"><?=count($chat_msgs)?> messages · auto-expiry enabled</span>
        </div>
        <div class="chatmsgs" id="chatMsgs">
          <?php if(empty($chat_msgs)): ?>
            <div style="text-align:center;padding:30px;color:var(--muted);font-size:12px">No messages yet.</div>
          <?php else: foreach($chat_msgs as $cm):
            $cw=array_filter(explode(' ',trim($cm['full_name'])));
            $ci=strtoupper(substr($cw[0]??'U',0,1).substr(end($cw)??'',0,1));
          ?>
            <div class="mrow">
              <div class="mava"><?=$ci?></div>
              <div class="mbub">
                <div class="mname"><?=htmlspecialchars($cm['full_name'])?> · <?=htmlspecialchars($cm['student_id'])?></div>
                <div class="mtxt"><?=nl2br(htmlspecialchars($cm['message']))?></div>
                <div class="mtime"><?=date('d M, g:i a',strtotime($cm['created_at']))?> · expires <?=date('g:i a',strtotime($cm['expires_at']))?></div>
              </div>
              <form method="POST">
                <input type="hidden" name="action" value="delete_chat"/>
                <input type="hidden" name="msg_id" value="<?=$cm['id']?>"/>
                <button type="submit" class="mdel" onclick="return confirm('Delete this message?')">🗑️</button>
              </form>
            </div>
          <?php endforeach; endif; ?>
        </div>
        <div style="padding:11px 13px;border-top:1px solid var(--border)">
          <div style="padding:9px 13px;background:rgba(238,69,64,.05);border:1px solid rgba(238,69,64,.15);border-radius:9px;font-size:11.5px;color:var(--crimson)">
            💡 Messages auto-purge via a scheduled database event every 10 minutes. Use 🗑️ to manually remove any message.
          </div>
        </div>
      </div>
    </div>

    <!-- ══════════ BRAIN BRIDGE ══════════ -->
    <div class="tab-panel" id="panel-brainbridge">
      <div class="sec">Active Tutor Sessions (<?=count($all_offers)?>)</div>
      <?php if(empty($all_offers)): ?>
        <div class="empty"><div class="ei">🧠</div><div class="et">No active sessions</div></div>
      <?php else: ?>
        <div class="tcard mb">
          <div class="tblw">
            <table>
              <thead><tr><th>#</th><th>Tutor</th><th>Module</th><th>Mode</th><th>Date / Time</th><th>Attendees</th><th>Max</th><th>Fill</th></tr></thead>
              <tbody>
                <?php foreach($all_offers as $i=>$o):
                  $pct=$o['max_students']>0?min(100,round($o['att']/$o['max_students']*100)):0;
                ?>
                <tr>
                  <td style="color:var(--muted);font-weight:600"><?=$i+1?></td>
                  <td><div class="tn"><?=htmlspecialchars($o['tname'])?></div><div class="tsub"><?=htmlspecialchars($o['tsid'])?></div></td>
                  <td><div class="tn"><?=htmlspecialchars($o['module_name'])?></div><div class="tsub"><?=htmlspecialchars($o['module_code'])?></div></td>
                  <td><span class="badge bno"><?=ucfirst($o['preferred_mode'])?></span></td>
                  <td style="font-size:10px;color:var(--muted)"><?=$o['session_date']?date('d M Y',strtotime($o['session_date'])):'—'?><?=$o['session_time']?'<br>'.$o['session_time']:''?></td>
                  <td style="font-weight:700;color:var(--crimson);font-size:15px"><?=$o['att']?></td>
                  <td style="color:var(--muted)"><?=$o['max_students']?></td>
                  <td>
                    <div style="width:52px;height:5px;background:var(--border);border-radius:50px;overflow:hidden">
                      <div style="height:100%;width:<?=$pct?>%;border-radius:50px;background:<?=$pct>=100?'var(--muted)':($pct>=75?'var(--amber)':'var(--green)')?>"></div>
                    </div>
                    <div style="font-size:8.5px;color:var(--muted);margin-top:2px"><?=$pct?>%</div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>

      <div class="sec">Pending Tutor Requests (<?=count($all_pending_req)?>)</div>
      <?php if(empty($all_pending_req)): ?>
        <div class="empty"><div class="ei">📋</div><div class="et">No pending requests</div></div>
      <?php else: ?>
        <div class="tcard">
          <div class="tblw">
            <table>
              <thead><tr><th>#</th><th>Student</th><th>Module</th><th>Mode</th><th>Message</th><th>Submitted</th></tr></thead>
              <tbody>
                <?php foreach($all_pending_req as $i=>$r): ?>
                <tr>
                  <td style="color:var(--muted);font-weight:600"><?=$i+1?></td>
                  <td><div class="tn"><?=htmlspecialchars($r['full_name'])?></div><div class="tsub"><?=htmlspecialchars($r['sid'])?></div></td>
                  <td><div class="tn"><?=htmlspecialchars($r['module_name'])?></div><div class="tsub"><?=htmlspecialchars($r['module_code'])?></div></td>
                  <td><span class="badge bno"><?=ucfirst($r['preferred_mode'])?></span></td>
                  <td style="max-width:170px;font-size:11px;color:var(--muted)"><?=htmlspecialchars($r['message']?:'—')?></td>
                  <td style="font-size:10px;color:var(--muted)"><?=date('d M Y',strtotime($r['created_at']))?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- ══════════ BURROW BUDDY ══════════ -->
    <div class="tab-panel" id="panel-burrow">
      <div class="sec">Borrow Requests (<?=count($bb_requests)?>)</div>
      <?php if(empty($bb_requests)): ?>
        <div class="empty"><div class="ei">📦</div><div class="et">No requests yet</div></div>
      <?php else: ?>
        <div class="tcard mb">
          <div class="tblw">
            <table>
              <thead><tr><th>#</th><th>Item</th><th>Borrower</th><th>Lender</th><th>Dates</th><th>Status</th><th>Update</th></tr></thead>
              <tbody>
                <?php foreach($bb_requests as $i=>$br): ?>
                <tr>
                  <td style="color:var(--muted);font-weight:600"><?=$i+1?></td>
                  <td><div class="tn"><?=htmlspecialchars($br['item_title'])?></div><div class="tsub"><?=ucfirst($br['category'])?></div></td>
                  <td><div class="tn"><?=htmlspecialchars($br['bname'])?></div><div class="tsub"><?=htmlspecialchars($br['bsid'])?></div></td>
                  <td style="font-size:11px"><?=htmlspecialchars($br['lname'])?></td>
                  <td style="font-size:10px;color:var(--muted)">
                    <?=($br['borrow_from']&&$br['borrow_until'])?date('d M',strtotime($br['borrow_from'])).' – '.date('d M',strtotime($br['borrow_until'])):'—'?>
                  </td>
                  <td><span class="badge b<?=substr($br['status'],0,4)?>"><?=ucfirst($br['status'])?></span></td>
                  <td>
                    <?php if($br['status']==='pending'): ?>
                      <form method="POST" style="display:flex;gap:5px">
                        <input type="hidden" name="action" value="update_bb"/>
                        <input type="hidden" name="req_id" value="<?=$br['id']?>"/>
                        <button name="bb_status" value="approved" type="submit" class="bsm bg2">Approve</button>
                        <button name="bb_status" value="rejected" type="submit" class="bsm bd">Reject</button>
                      </form>
                    <?php elseif($br['status']==='approved'): ?>
                      <form method="POST">
                        <input type="hidden" name="action" value="update_bb"/>
                        <input type="hidden" name="req_id" value="<?=$br['id']?>"/>
                        <button name="bb_status" value="returned" type="submit" class="bsm bm">Mark Returned</button>
                      </form>
                    <?php else: ?>
                      <span style="font-size:10px;color:var(--muted)"><?=ucfirst($br['status'])?></span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>

      <div class="sec">All Listings (<?=count($bb_listings)?>)</div>
      <div class="tcard">
        <div class="tblw">
          <table>
            <thead><tr><th>#</th><th>Item</th><th>Category</th><th>Lender</th><th>Condition</th><th>Max Days</th><th>Status</th><th>Pending</th></tr></thead>
            <tbody>
              <?php foreach($bb_listings as $i=>$l): ?>
              <tr>
                <td style="color:var(--muted);font-weight:600"><?=$i+1?></td>
                <td><div class="tn"><?=htmlspecialchars($l['title'])?></div></td>
                <td><span class="badge bno"><?=ucfirst($l['category'])?></span></td>
                <td style="font-size:11px"><?=htmlspecialchars($l['lender'])?></td>
                <td style="font-size:11px;color:var(--muted)"><?=ucfirst($l['condition'])?></td>
                <td style="text-align:center;font-weight:700;color:var(--crimson)"><?=$l['borrow_days']?></td>
                <td><span class="badge <?=$l['status']==='available'?'bact':($l['status']==='borrowed'?'bpend':'bdis')?>"><?=ucfirst($l['status'])?></span></td>
                <td style="text-align:center"><?=$l['preqs']>0?"<span class='badge bpend'>{$l['preqs']}</span>":'—'?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══════════ MODULES ══════════ -->
<div class="tab-panel" id="panel-modules">

  <div class="sec">All Modules (<?=count($modules)?>)</div>

  <div class="tcard">
    <div class="ttb">
      <div class="sw">
        <span class="sico">🔍</span>
        <input type="text" id="modSearch" placeholder="Search by module code or name…" oninput="filterModules()"/>
      </div>
      <span style="font-size:11px;color:var(--muted);white-space:nowrap" id="modCount">
        <?=count($modules)?> module<?=count($modules)!=1?'s':''?>
      </span>
    </div>
    <div class="tblw">
      <table>
        <thead>
          <tr>
            <th>Code</th>
            <th>Module Name</th>
            <th>Description</th>
            <th>Active Tutors</th>
            <th>Pending Requests</th>
          </tr>
        </thead>
        <tbody id="modTableBody">
          <?php foreach($modules as $m):
            $tcs=$pdo->prepare("SELECT COUNT(*) FROM tutor_offers WHERE module_id=? AND status='active'");
            $tcs->execute([$m['id']]); $tc=(int)$tcs->fetchColumn();
            $mrs=$pdo->prepare("SELECT COUNT(*) FROM module_requests WHERE module_id=? AND status='pending'");
            $mrs->execute([$m['id']]); $mr=(int)$mrs->fetchColumn();
          ?>
          <tr class="mod-row"
              data-code="<?=strtolower(htmlspecialchars($m['module_code']))?>"
              data-name="<?=strtolower(htmlspecialchars($m['module_name']))?>">
            <td>
              <code style="font-size:10.5px;background:var(--bg);padding:2px 8px;border-radius:5px;
                           border:1px solid var(--border);color:var(--crimson);font-weight:700">
                <?=htmlspecialchars($m['module_code'])?>
              </code>
            </td>
            <td><div class="tn"><?=htmlspecialchars($m['module_name'])?></div></td>
            <td style="font-size:11px;color:var(--muted);max-width:220px">
              <?=htmlspecialchars($m['description'] ? mb_strimwidth($m['description'],0,70,'…') : '—')?>
            </td>
            <td style="text-align:center;font-weight:700;color:var(--green)"><?=$tc?></td>
            <td style="text-align:center">
              <?=$mr > 0 ? "<span class='badge bpend'>{$mr}</span>" : '—'?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="padding:9px 15px;border-top:1px solid var(--border)">
      <span style="font-size:10.5px;color:var(--muted)" id="modFooter">
        Showing <?=count($modules)?> module<?=count($modules)!=1?'s':''?>
      </span>
    </div>
  </div>

</div>



    <!-- ══════════ RECLAIM ══════════ -->
<div class="tab-panel" id="panel-reclaim">

  <?php
  $cat_icons = ['bag'=>'🎒','bottle'=>'🍶','electronics'=>'💻','clothing'=>'👕',
                'keys'=>'🔑','stationery'=>'✏️','id_card'=>'🪪','wallet'=>'👛',
                'jewellery'=>'💍','other'=>'📦'];
  ?>

  <!-- ── STAT ROW ── -->
  <div class="sg4" style="margin-bottom:20px">
    <div class="sc"><div class="scd"></div><span class="ico">📦</span>
      <div class="scv"><?=$reclaim_total_unclaimed?></div><div class="scl">Unclaimed Items</div>
    </div>
    <div class="sc"><div class="scd" style="background:var(--amber)"></div><span class="ico">🙋</span>
      <div class="scv"><?=$reclaim_pending_claims?></div><div class="scl">Pending Claims</div>
      <?php if($reclaim_pending_claims): ?><span class="scs sa">⏳ Needs review</span><?php endif?>
    </div>
    <div class="sc"><div class="scd" style="background:var(--green)"></div><span class="ico">✅</span>
      <div class="scv"><?=$reclaim_total_completed?></div><div class="scl">Successfully Returned</div>
    </div>
    <div class="sc"><div class="scd" style="background:var(--blue)"></div><span class="ico">🔄</span>
      <div class="scv"><?=$reclaim_total_claimed?></div><div class="scl">Awaiting Pickup</div>
    </div>
  </div>

  <div class="g2l mb">

    <!-- ── LEFT: ALL ITEMS with questions & claims ── -->
    <div>
      <div class="sec">All Posted Items (<?=count($reclaim_items)?>)</div>
      <?php if(empty($reclaim_items)): ?>
        <div class="empty"><div class="ei">♻️</div><div class="et">No items posted yet</div></div>
      <?php else: ?>
        <?php foreach($reclaim_items as $idx=>$it):
          $icon = $cat_icons[$it['category']] ?? '📦';

          // Fetch questions
          $qs = $pdo->prepare("SELECT question FROM reclaim_questions WHERE item_id=? ORDER BY sort_order");
          $qs->execute([$it['id']]);
          $questions = $qs->fetchAll(PDO::FETCH_COLUMN);

          // Fetch claims with answers
          $cls = $pdo->prepare("
              SELECT rc.*, u.full_name AS cname, u.student_id AS csid
              FROM reclaim_claims rc
              JOIN users u ON u.id=rc.claimer_id
              WHERE rc.item_id=?
              ORDER BY rc.created_at DESC
          ");
          $cls->execute([$it['id']]);
          $item_claims = $cls->fetchAll();

          $status_colors = ['unclaimed'=>'var(--green)','claimed'=>'var(--amber)','completed'=>'var(--blue)'];
          $status_bg     = ['unclaimed'=>'rgba(46,158,104,.1)','claimed'=>'rgba(217,119,6,.1)','completed'=>'rgba(59,130,246,.1)'];
        ?>
        <div style="background:var(--card);border:1px solid var(--border);border-radius:14px;
                    margin-bottom:14px;overflow:hidden;animation:fu .3s ease both;animation-delay:<?=$idx*.04?>s">

          <!-- Item header -->
          <div style="padding:13px 17px;background:var(--bg);border-bottom:1px solid var(--border);
                      display:flex;align-items:center;gap:12px">
            <span style="font-size:24px"><?=$icon?></span>
            <div style="flex:1;min-width:0">
              <div style="font-weight:700;font-size:13.5px;color:var(--midnight)"><?=htmlspecialchars($it['title'])?></div>
              <div style="font-size:10px;color:var(--muted);margin-top:2px">
                Found by <strong><?=htmlspecialchars($it['finder_name'])?></strong>
                (<?=htmlspecialchars($it['finder_sid'])?>)
                · 📍 <?=htmlspecialchars($it['location_found'])?>
                · <?=date('d M Y',strtotime($it['found_date']))?>
              </div>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-shrink:0">
              <span style="padding:3px 11px;border-radius:50px;font-size:10px;font-weight:700;
                           background:<?=$status_bg[$it['status']]??'rgba(154,128,128,.1)'?>;
                           color:<?=$status_colors[$it['status']]??'var(--muted)'?>">
                <?=ucfirst($it['status'])?>
              </span>
              <span style="font-size:10px;color:var(--muted)"><?=$it['question_count']?> Q · <?=$it['claim_count']?> claim<?=$it['claim_count']!=1?'s':''?></span>
            </div>
          </div>

          <div style="padding:14px 17px">

            <!-- Verification Questions -->
            <?php if(!empty($questions)): ?>
            <div style="margin-bottom:13px">
              <div style="font-size:9px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;margin-bottom:8px">
                🔒 Verification Questions
              </div>
              <div style="display:flex;flex-direction:column;gap:5px">
                <?php foreach($questions as $qi=>$q): ?>
                <div style="display:flex;gap:8px;align-items:flex-start;padding:7px 10px;
                            background:var(--bg);border-radius:8px;border:1px solid var(--border)">
                  <span style="width:18px;height:18px;border-radius:50%;flex-shrink:0;
                               background:linear-gradient(135deg,var(--crimson),var(--wine));
                               display:flex;align-items:center;justify-content:center;
                               font-size:8.5px;font-weight:800;color:#fff;margin-top:1px"><?=$qi+1?></span>
                  <span style="font-size:12px;color:var(--text)"><?=htmlspecialchars($q)?></span>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>

            <!-- Claims & Answers -->
            <?php if(!empty($item_claims)): ?>
            <div>
              <div style="font-size:9px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;margin-bottom:9px">
                🙋 Claims (<?=count($item_claims)?>)
              </div>
              <?php foreach($item_claims as $cl):
                $answers = json_decode($cl['answers'] ?? '[]', true);
                $cl_colors = ['pending'=>'var(--amber)','confirmed'=>'var(--green)','rejected'=>'var(--muted)'];
                $cl_bg     = ['pending'=>'rgba(217,119,6,.08)','confirmed'=>'rgba(46,158,104,.08)','rejected'=>'rgba(154,128,128,.06)'];
              ?>
              <div style="border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:9px;
                          border-left:3px solid <?=$cl_colors[$cl['status']]??'var(--border)'?>">

                <!-- Claimer row -->
                <div style="padding:9px 13px;background:<?=$cl_bg[$cl['status']]??'var(--bg)'?>;
                            display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--border)">
                  <div style="width:26px;height:26px;border-radius:50%;flex-shrink:0;
                              background:linear-gradient(135deg,var(--coral),var(--crimson));
                              display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:#fff">
                    <?=strtoupper(substr($cl['cname'],0,1).substr(strrchr($cl['cname'],' ')??'',1,1))?>
                  </div>
                  <div style="flex:1">
                    <div style="font-size:12px;font-weight:700;color:var(--midnight)"><?=htmlspecialchars($cl['cname'])?></div>
                    <div style="font-size:9.5px;color:var(--muted)"><?=htmlspecialchars($cl['csid'])?> · <?=date('d M Y, g:i a',strtotime($cl['created_at']))?></div>
                  </div>
                  <span style="padding:3px 11px;border-radius:50px;font-size:10px;font-weight:700;
                               background:<?=$cl_bg[$cl['status']]??''?>;color:<?=$cl_colors[$cl['status']]??'var(--muted)'?>;
                               border:1px solid <?=$cl_colors[$cl['status']]??'var(--border)'?>">
                    <?=ucfirst($cl['status'])?>
                  </span>
                </div>

                <!-- Q&A answers -->
                <?php if(!empty($answers) && !empty($questions)): ?>
                <div style="padding:10px 13px;display:flex;flex-direction:column;gap:7px">
                  <?php foreach($questions as $qi=>$q): ?>
                  <div>
                    <div style="font-size:9.5px;font-weight:700;color:var(--muted);margin-bottom:3px">
                      Q<?=$qi+1?>: <?=htmlspecialchars($q)?>
                    </div>
                    <div style="font-size:12px;color:var(--midnight);padding:6px 10px;
                                background:var(--bg);border-radius:7px;border:1px solid var(--border)">
                      <?=htmlspecialchars($answers[$qi] ?? '—')?>
                    </div>
                  </div>
                  <?php endforeach; ?>

                  <!-- Pickup info if exists -->
                  <?php if($cl['pickup_place'] && $cl['pickup_time']): ?>
                  <div style="margin-top:5px;padding:8px 11px;background:rgba(46,158,104,.06);
                              border:1px solid rgba(46,158,104,.18);border-radius:8px;font-size:11.5px;color:var(--green)">
                    📍 Pickup: <strong><?=htmlspecialchars($cl['pickup_place'])?></strong>
                    · 🕐 <?=date('d M Y, g:i a',strtotime($cl['pickup_time']))?>
                    <?php if($cl['pickup_confirmed']): ?>
                      <span style="margin-left:8px;font-weight:700">✓ Collected</span>
                    <?php endif; ?>
                  </div>
                  <?php endif; ?>
                </div>
                <?php endif; ?>

              </div>
              <?php endforeach; ?>
            </div>
            <?php else: ?>
              <div style="font-size:11.5px;color:var(--muted);text-align:center;padding:10px 0">No claims yet.</div>
            <?php endif; ?>

          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- ── RIGHT: SIDEBAR STATS ── -->
    <div style="display:flex;flex-direction:column;gap:16px;position:sticky;top:0">

      <!-- Top Finders -->
      <div style="background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden">
        <div style="padding:14px 17px;border-bottom:1px solid var(--border)">
          <div class="sec" style="margin-bottom:0">Top Finders</div>
        </div>
        <div style="padding:12px 17px">
          <?php if(empty($reclaim_top_finders)): ?>
            <div style="font-size:11.5px;color:var(--muted);text-align:center;padding:12px 0">No data yet.</div>
          <?php else: foreach($reclaim_top_finders as $i=>$f): ?>
          <div style="display:flex;align-items:center;gap:10px;padding:8px 0;
                      border-bottom:1px solid rgba(238,222,222,.3)">
            <div style="width:24px;height:24px;border-radius:50%;flex-shrink:0;
                        background:linear-gradient(135deg,var(--crimson),var(--wine));
                        display:flex;align-items:center;justify-content:center;
                        font-size:9.5px;font-weight:800;color:#fff"><?=$i+1?></div>
            <div style="flex:1;min-width:0">
              <div style="font-size:12px;font-weight:700;color:var(--midnight);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                <?=htmlspecialchars($f['full_name'])?>
              </div>
              <div style="font-size:9.5px;color:var(--muted)"><?=htmlspecialchars($f['student_id'])?></div>
            </div>
            <div style="text-align:right;flex-shrink:0">
              <div style="font-size:13px;font-weight:700;color:var(--crimson)"><?=$f['found_count']?> found</div>
              <?php if($f['rating_count']>0): ?>
              <div style="font-size:10px;color:var(--amber)">
                <?=str_repeat('★',round($f['avg_rating']))?><?=str_repeat('☆',5-round($f['avg_rating']))?>
                <span style="color:var(--muted)">(<?=$f['rating_count']?>)</span>
              </div>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <!-- Recent Claim Activity -->
      <div style="background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden">
        <div style="padding:14px 17px;border-bottom:1px solid var(--border)">
          <div class="sec" style="margin-bottom:0">Recent Claims</div>
        </div>
        <div style="padding:10px 17px">
          <?php
          $recent_claims = array_slice($reclaim_claims, 0, 8);
          if(empty($recent_claims)): ?>
            <div style="font-size:11.5px;color:var(--muted);text-align:center;padding:14px 0">No claims yet.</div>
          <?php else: foreach($recent_claims as $rc):
            $cl_col = ['pending'=>'var(--amber)','confirmed'=>'var(--green)','rejected'=>'var(--muted)'];
          ?>
          <div style="display:flex;gap:9px;padding:8px 0;border-bottom:1px solid rgba(238,222,222,.3)">
            <div style="width:7px;height:7px;border-radius:50%;flex-shrink:0;margin-top:5px;
                        background:<?=$cl_col[$rc['status']]??'var(--border)'?>"></div>
            <div style="flex:1;min-width:0">
              <div style="font-size:11.5px;font-weight:600;color:var(--midnight);line-height:1.35;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                <?=htmlspecialchars($rc['claimer_name'])?> → <em><?=htmlspecialchars($rc['item_title'])?></em>
              </div>
              <div style="font-size:9.5px;color:var(--muted);margin-top:2px">
                <?=ucfirst($rc['status'])?> · <?=date('d M, g:i a',strtotime($rc['created_at']))?>
              </div>
            </div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <!-- Quick Summary -->
      <div style="background:linear-gradient(135deg,var(--midnight),var(--plum) 55%,var(--wine));
                  border-radius:14px;padding:18px 17px">
        <div style="font-size:9px;letter-spacing:.16em;text-transform:uppercase;
                    color:rgba(255,255,255,.35);font-weight:700;margin-bottom:13px">Reclaim At a Glance</div>
        <?php
        $total_items = count($reclaim_items);
        $return_rate = $total_items > 0 ? round($reclaim_total_completed / $total_items * 100) : 0;
        foreach([
          ['Total items posted', $total_items, '#fff'],
          ['Return rate', $return_rate.'%', 'var(--coral)'],
          ['Pending review', $reclaim_pending_claims, 'var(--amber-light,#fef3c7)'],
          ['Ratings given', (int)$pdo->query("SELECT COUNT(*) FROM reclaim_ratings")->fetchColumn(), 'rgba(255,255,255,.6)'],
        ] as [$lbl,$val,$col]): ?>
        <div style="display:flex;justify-content:space-between;font-size:12px;
                    padding:6px 0;border-bottom:1px solid rgba(255,255,255,.07)">
          <span style="color:rgba(255,255,255,.5)"><?=$lbl?></span>
          <strong style="color:<?=$col?>"><?=$val?></strong>
        </div>
        <?php endforeach; ?>
      </div>

    </div>
  </div>

</div><!-- /panel-reclaim -->

<!-- ══════════ RATINGS ══════════ -->
<div class="tab-panel" id="panel-ratings">
  <?php
    $module_meta = [
      'burrow_buddy'  => ['label'=>'Burrow Buddy', 'icon'=>'📚', 'color'=>'#0e7490', 'bg'=>'rgba(14,116,144,.1)'],
      'peer_tutoring' => ['label'=>'Brain Bridge',  'icon'=>'🧠', 'color'=>'#7c3aed', 'bg'=>'rgba(124,58,237,.1)'],
      'reclaim'       => ['label'=>'Reclaim',        'icon'=>'♻️', 'color'=>'#b45309', 'bg'=>'rgba(180,83,9,.1)'],
    ];
    $module_totals_map = [];
    foreach($rating_totals as $rt) $module_totals_map[$rt['module']] = $rt;

    $unified = [];
    foreach($ratings_all as $r) {
      $mod  = $r['module'] ?? 'unknown';
      $meta = $module_meta[$mod] ?? ['label'=>ucfirst($mod),'icon'=>'⭐','color'=>'var(--muted)','bg'=>'rgba(154,128,128,.1)'];
      $context = '—';
      if (!empty($r['ref_id'])) {
        if ($mod === 'burrow_buddy') {
          $cx = $pdo->prepare("SELECT bl.title FROM bb_requests br JOIN bb_listings bl ON bl.id=br.listing_id WHERE br.id=?");
          $cx->execute([$r['ref_id']]); $cx = $cx->fetch();
          if ($cx) $context = htmlspecialchars($cx['title']);
        } elseif ($mod === 'peer_tutoring') {
          $cx = $pdo->prepare("SELECT m.module_name FROM session_registrations sr JOIN tutor_offers t ON t.id=sr.tutor_offer_id JOIN modules m ON m.id=t.module_id WHERE sr.id=?");
          $cx->execute([$r['ref_id']]); $cx = $cx->fetch();
          if ($cx) $context = htmlspecialchars($cx['module_name']);
        }
      }
      $unified[] = ['rater_name'=>$r['rater_name'],'rater_sid'=>$r['rater_sid'],
        'rated_name'=>$r['rated_name'],'rated_sid'=>$r['rated_sid'],
        'module'=>$mod,'meta'=>$meta,'stars'=>(int)$r['stars'],
        'comment'=>$r['comment']??'','context'=>$context,'created_at'=>$r['created_at']];
    }
    foreach($ratings_reclaim as $r) {
      $unified[] = ['rater_name'=>$r['rater_name'],'rater_sid'=>$r['rater_sid'],
        'rated_name'=>$r['rated_name'],'rated_sid'=>$r['rated_sid'],
        'module'=>'reclaim','meta'=>$module_meta['reclaim'],'stars'=>(int)$r['stars'],
        'comment'=>$r['comment']??'',
        'context'=>($r['item_title'] ? htmlspecialchars($r['item_title']).' ('.ucfirst($r['item_cat']??'item').')' : 'Reclaim item'),
        'created_at'=>$r['created_at']];
    }
    usort($unified, fn($a,$b) => strtotime($b['created_at']) - strtotime($a['created_at']));
    $overall_avg = count($unified) ? array_sum(array_column($unified,'stars')) / count($unified) : 0;

    $rc_cnt  = (int)$pdo->query("SELECT COUNT(*) FROM reclaim_ratings")->fetchColumn();
    $rc_avg2 = $rc_cnt > 0 ? (float)$pdo->query("SELECT AVG(stars) FROM reclaim_ratings")->fetchColumn() : 0;
  ?>

  <!-- Stat row -->
  <div class="sg4" style="margin-bottom:20px">
    <div class="sc"><div class="scd"></div><span class="ico">⭐</span>
      <div class="scv"><?= count($unified) ?></div>
      <div class="scl">Total Ratings</div>
    </div>
    <div class="sc"><div class="scd" style="background:#0e7490"></div><span class="ico">📚</span>
      <div class="scv"><?= number_format((float)($module_totals_map['burrow_buddy']['avg'] ?? 0), 1) ?></div>
      <div class="scl">Burrow Buddy Avg</div>
      <span class="scs" style="background:rgba(14,116,144,.1);color:#0e7490"><?= $module_totals_map['burrow_buddy']['cnt'] ?? 0 ?> reviews</span>
    </div>
    <div class="sc"><div class="scd" style="background:#7c3aed"></div><span class="ico">🧠</span>
      <div class="scv"><?= number_format((float)($module_totals_map['peer_tutoring']['avg'] ?? 0), 1) ?></div>
      <div class="scl">Brain Bridge Avg</div>
      <span class="scs" style="background:rgba(124,58,237,.1);color:#7c3aed"><?= $module_totals_map['peer_tutoring']['cnt'] ?? 0 ?> reviews</span>
    </div>
    <div class="sc"><div class="scd" style="background:#b45309"></div><span class="ico">♻️</span>
      <div class="scv"><?= number_format($rc_avg2, 1) ?></div>
      <div class="scl">Reclaim Avg</div>
      <span class="scs" style="background:rgba(180,83,9,.1);color:#b45309"><?= $rc_cnt ?> reviews</span>
    </div>
  </div>

  <!-- Filter + Table -->
  <div class="tcard">
    <div class="ttb" style="gap:12px;flex-wrap:wrap">
      <div class="sw" style="min-width:200px">
        <span class="sico">🔍</span>
        <input type="text" id="ratingSearch" placeholder="Search name, student ID, context…" oninput="filterRatings()"/>
      </div>
      <div style="display:flex;gap:7px;flex-wrap:wrap">
        <button class="bsm bm rfilter active" data-mod="all" onclick="setRatingFilter('all',this)">All</button>
        <button class="bsm bm rfilter" data-mod="burrow_buddy" onclick="setRatingFilter('burrow_buddy',this)" style="color:#0e7490;border-color:rgba(14,116,144,.3)">📚 Burrow Buddy</button>
        <button class="bsm bm rfilter" data-mod="peer_tutoring" onclick="setRatingFilter('peer_tutoring',this)" style="color:#7c3aed;border-color:rgba(124,58,237,.3)">🧠 Brain Bridge</button>
        <button class="bsm bm rfilter" data-mod="reclaim" onclick="setRatingFilter('reclaim',this)" style="color:#b45309;border-color:rgba(180,83,9,.3)">♻️ Reclaim</button>
      </div>
      <span style="font-size:11px;color:var(--muted);margin-left:auto" id="ratingCount"><?= count($unified) ?> ratings</span>
    </div>
    <div class="tblw">
      <table>
        <thead>
          <tr>
            <th>#</th><th>Rated By</th><th>Rated</th><th>Module</th>
            <th>Case / Context</th><th>Stars</th><th>Comment</th><th>Date</th>
          </tr>
        </thead>
        <tbody id="ratingsBody">
          <?php foreach($unified as $i=>$u): ?>
          <tr class="rrow"
              data-mod="<?= htmlspecialchars($u['module']) ?>"
              data-search="<?= strtolower($u['rater_name'].' '.$u['rater_sid'].' '.$u['rated_name'].' '.$u['rated_sid'].' '.$u['context']) ?>">
            <td style="color:var(--muted);font-weight:600"><?= $i+1 ?></td>
            <td><div class="tn"><?= htmlspecialchars($u['rater_name']) ?></div><div class="tsub"><?= htmlspecialchars($u['rater_sid']) ?></div></td>
            <td><div class="tn"><?= htmlspecialchars($u['rated_name']) ?></div><div class="tsub"><?= htmlspecialchars($u['rated_sid']) ?></div></td>
            <td><span class="badge" style="background:<?= $u['meta']['bg'] ?>;color:<?= $u['meta']['color'] ?>"><?= $u['meta']['icon'] ?> <?= $u['meta']['label'] ?></span></td>
            <td style="font-size:11px;color:var(--muted);max-width:160px"><?= $u['context'] ?></td>
            <td>
              <div style="display:flex;align-items:center;gap:5px">
                <span style="font-family:'Cormorant Garamond',serif;font-size:17px;font-weight:600;color:var(--midnight)"><?= $u['stars'] ?></span>
                <span style="font-size:11px;color:#f59e0b"><?= str_repeat('★',$u['stars']).str_repeat('☆',5-$u['stars']) ?></span>
              </div>
            </td>
            <td style="max-width:180px;font-size:11px;color:var(--muted);font-style:<?= $u['comment']?'italic':'normal' ?>">
              <?= $u['comment'] ? '"'.htmlspecialchars(mb_strimwidth($u['comment'],0,80,'…')).'"' : '—' ?>
            </td>
            <td style="font-size:10px;color:var(--muted);white-space:nowrap"><?= date('d M Y, g:i a', strtotime($u['created_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($unified)): ?>
          <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--muted);font-size:12px">No ratings recorded yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div style="padding:9px 15px;border-top:1px solid var(--border)">
      <span style="font-size:10.5px;color:var(--muted)" id="ratingFooter">
        Showing <?= count($unified) ?> ratings · Overall avg: <?= number_format($overall_avg,1) ?> ★
      </span>
    </div>
  </div>
</div><!-- /panel-ratings -->

  </div><!-- /scroll -->
</div><!-- /main -->
</div><!-- /layout -->

<script>
const titles={
  overview:'Admin Dashboard',notices:'Noticeboard Manager',events:'Events Manager',
  students:'Student Management',addstudent:'Add New Student',chatroom:'Chatroom Monitor',
  brainbridge:'Brain Bridge Overview',burrow:'Burrow Buddy Overview',modules:'Module Management',
  reclaim:'Reclaim — Lost & Found',
  ratings: 'Ratings Overview',
};
function sw(id,el){
  document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.ni').forEach(n=>n.classList.remove('active'));
  document.getElementById('panel-'+id).classList.add('active');
  if(el) el.classList.add('active');
  document.getElementById('pgt').textContent=titles[id]||'Admin Dashboard';
  if(id==='chatroom') setTimeout(()=>{const c=document.getElementById('chatMsgs');if(c)c.scrollTop=c.scrollHeight;},80);
}
function fs(){
  const q=document.getElementById('ss').value.toLowerCase();
  let v=0;
  document.querySelectorAll('.srow').forEach(r=>{
    const ok=r.dataset.name.includes(q)||r.dataset.email.includes(q)||r.dataset.sid.includes(q);
    r.style.display=ok?'':'none';
    if(ok)v++;
  });
  document.getElementById('sc2').textContent=`Showing ${v} students`;
}
window.addEventListener('load',()=>{const c=document.getElementById('chatMsgs');if(c)c.scrollTop=c.scrollHeight;});
setTimeout(()=>{document.querySelectorAll('.alert').forEach(a=>{a.style.transition='opacity .4s';a.style.opacity='0';setTimeout(()=>a.remove(),400);});},4500);

function openSignoutToast()  { document.getElementById('signoutToast').classList.add('open'); }
function closeSignoutToast() { document.getElementById('signoutToast').classList.remove('open'); }
// Close on backdrop click
document.getElementById('signoutToast').addEventListener('click', function(e) {
  if (e.target === this) closeSignoutToast();
});

function filterModules() {
  const q = document.getElementById('modSearch').value.toLowerCase();
  let v = 0;
  document.querySelectorAll('.mod-row').forEach(r => {
    const ok = r.dataset.code.includes(q) || r.dataset.name.includes(q);
    r.style.display = ok ? '' : 'none';
    if (ok) v++;
  });
  const label = v + ' module' + (v !== 1 ? 's' : '');
  document.getElementById('modCount').textContent = label;
  document.getElementById('modFooter').textContent = 'Showing ' + label;
}

let activeRatingMod = 'all';
function setRatingFilter(mod, el) {
  activeRatingMod = mod;
  document.querySelectorAll('.rfilter').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  filterRatings();
}
function filterRatings() {
  const q = (document.getElementById('ratingSearch')?.value || '').toLowerCase();
  let vis = 0;
  document.querySelectorAll('.rrow').forEach(r => {
    const ok = (activeRatingMod === 'all' || r.dataset.mod === activeRatingMod)
            && (!q || r.dataset.search.includes(q));
    r.style.display = ok ? '' : 'none';
    if (ok) vis++;
  });
  const el = document.getElementById('ratingCount');
  if (el) el.textContent = vis + ' rating' + (vis !== 1 ? 's' : '');
}
</script>
</body>
</html>