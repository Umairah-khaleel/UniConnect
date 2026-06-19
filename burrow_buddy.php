<?php
// burrow_buddy.php
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

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'mark_notifs_read') {
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$user_id]);
    $msg_success = 'All notifications marked as read.';
}

    // List a new device
    if ($action === 'list_device') {
        $title       = trim($_POST['title'] ?? '');
        $category    = $_POST['category'] ?? 'other';
        $description = trim($_POST['description'] ?? '');
        $condition   = $_POST['condition'] ?? 'good';
        $borrow_days = max(1, (int)($_POST['borrow_days'] ?? 1));

        if (!$title) {
            $msg_error = 'Please enter a device title.';
        } else {
            $ins = $pdo->prepare("INSERT INTO bb_listings (lender_id,title,category,description,`condition`,borrow_days) VALUES (?,?,?,?,?,?)");
            $ins->execute([$user_id, $title, $category, $description, $condition, $borrow_days])
                ? $msg_success = 'Your device has been listed!'
                : $msg_error   = 'Could not save listing. Please try again.';
        }
    }

    // Request to borrow
    if ($action === 'request_borrow') {
    $listing_id   = (int)($_POST['listing_id'] ?? 0);
    $message      = trim($_POST['message'] ?? '');
    $borrow_from  = $_POST['borrow_from']  ?? null;
    $borrow_until = $_POST['borrow_until'] ?? null;

    $own = $pdo->prepare("SELECT id, lender_id, title FROM bb_listings WHERE id=?");
    $own->execute([$listing_id]);
    $listing = $own->fetch();

    if (!$listing || $listing['lender_id'] == $user_id) {
        $msg_error = 'You cannot request your own listing.';
    } else {
        $dup = $pdo->prepare("SELECT id FROM bb_requests WHERE listing_id=? AND borrower_id=? AND status='pending'");
        $dup->execute([$listing_id, $user_id]);
        if ($dup->fetch()) {
            $msg_error = 'You already have a pending request for this item.';
        } else {
            $ins = $pdo->prepare("INSERT INTO bb_requests (listing_id,borrower_id,message,borrow_from,borrow_until) VALUES (?,?,?,?,?)");
            if ($ins->execute([$listing_id, $user_id, $message, $borrow_from ?: null, $borrow_until ?: null])) {
                // Notify lender
                $from_fmt  = $borrow_from  ? date('d M Y', strtotime($borrow_from))  : '—';
                $until_fmt = $borrow_until ? date('d M Y', strtotime($borrow_until)) : '—';
                $notif_msg = "<strong>{$name}</strong> has requested to borrow your device <strong>"
                           . htmlspecialchars($listing['title'])
                           . "</strong> from <strong>{$from_fmt}</strong> to <strong>{$until_fmt}</strong>."
                           . ($message ? " They said: \"" . htmlspecialchars(substr($message,0,80)) . "\"" : '');
                $pdo->prepare("INSERT INTO notifications (user_id,type,title,message,link) VALUES (?,?,?,?,?)")
                    ->execute([$listing['lender_id'], 'borrow_request', '📥 New Borrow Request', $notif_msg, 'burrow_buddy.php']);
                $msg_success = 'Borrow request sent! The lender has been notified.';
            } else {
                $msg_error = 'Could not submit request. Please try again.';
            }
        }
    }
}

    // Approve / reject request (lender action)
    if ($action === 'respond_request') {
    $req_id     = (int)($_POST['req_id'] ?? 0);
    $new_status = in_array($_POST['new_status'] ?? '', ['approved','rejected']) ? $_POST['new_status'] : null;

    // Extra fields only sent on approve
    $handover_place  = trim($_POST['handover_place']  ?? '');
    $handover_time   = trim($_POST['handover_time']   ?? '');
    $return_place    = trim($_POST['return_place']    ?? '');
    $return_deadline = trim($_POST['return_deadline'] ?? '');

    if ($req_id && $new_status) {
        $chk = $pdo->prepare("
            SELECT br.id, br.listing_id, br.borrower_id, br.borrow_from, br.borrow_until,
                   bl.title AS device_title, bl.lender_id
            FROM bb_requests br
            JOIN bb_listings bl ON bl.id = br.listing_id
            WHERE br.id=? AND bl.lender_id=?
        ");
        $chk->execute([$req_id, $user_id]);
        $req = $chk->fetch();

        if ($req) {
            if ($new_status === 'approved') {
                if (!$handover_place || !$handover_time || !$return_place || !$return_deadline) {
                    $msg_error = 'Please fill in all pickup and return details before approving.';
                } else {
                    // Save approval + logistics
                    $pdo->prepare("UPDATE bb_requests
                                   SET status='approved', responded_at=NOW(),
                                       handover_place=?, handover_time=?,
                                       return_place=?, return_deadline=?
                                   WHERE id=?")
                        ->execute([$handover_place, $handover_time,
                                   $return_place, $return_deadline, $req_id]);
                    $pdo->prepare("UPDATE bb_listings SET status='borrowed' WHERE id=?")
                        ->execute([$req['listing_id']]);

                    // Notify borrower
                    $ho_time_fmt  = date('d M Y \a\t g:i A', strtotime($handover_time));
                    $ret_fmt      = date('d M Y \a\t g:i A', strtotime($return_deadline));
                    $notif_msg    = "Your request for <strong>" . htmlspecialchars($req['device_title']) . "</strong> has been <strong>approved!</strong>"
                                  . "<br><br>📍 <strong>Pick up from:</strong> " . htmlspecialchars($handover_place)
                                  . "<br>🕐 <strong>Pickup time:</strong> "      . $ho_time_fmt
                                  . "<br><br>♻️ <strong>Return to:</strong> "    . htmlspecialchars($return_place)
                                  . "<br>📅 <strong>Return by:</strong> "        . $ret_fmt;
                    $pdo->prepare("INSERT INTO notifications (user_id,type,title,message,link) VALUES (?,?,?,?,?)")
                        ->execute([$req['borrower_id'], 'borrow_approved',
                                   '✅ Borrow Request Approved', $notif_msg, 'burrow_buddy.php']);
                    $msg_success = 'Request approved! The borrower has been notified with pickup and return details.';
                }
            } else {
                // Rejected
                $pdo->prepare("UPDATE bb_requests SET status='rejected', responded_at=NOW() WHERE id=?")
                    ->execute([$req_id]);
                // Notify borrower of rejection
                $pdo->prepare("INSERT INTO notifications (user_id,type,title,message,link) VALUES (?,?,?,?,?)")
                    ->execute([$req['borrower_id'], 'borrow_rejected',
                               '❌ Borrow Request Rejected',
                               "Unfortunately your request for <strong>" . htmlspecialchars($req['device_title']) . "</strong> was not approved. Try another listing!",
                               'burrow_buddy.php']);
                $msg_success = 'Request rejected.';
            }
        }
    }
}

    // Mark as returned
    if ($action === 'mark_returned') {
        $req_id = (int)($_POST['req_id'] ?? 0);
        $chk = $pdo->prepare("SELECT br.listing_id FROM bb_requests br JOIN bb_listings bl ON bl.id=br.listing_id WHERE br.id=? AND bl.lender_id=?");
        $chk->execute([$req_id, $user_id]);
        if ($row = $chk->fetch()) {
            $pdo->prepare("UPDATE bb_requests SET status='returned' WHERE id=?")->execute([$req_id]);
            $pdo->prepare("UPDATE bb_listings SET status='available' WHERE id=?")->execute([$row['listing_id']]);
            $msg_success = 'Item marked as returned. It is now available again.';
        }
    }

    // Submit rating
    if ($action === 'submit_rating') {
        $rated_id = (int)($_POST['rated_id'] ?? 0);
        $stars    = max(1, min(5, (int)($_POST['stars'] ?? 5)));
        $comment  = trim($_POST['comment'] ?? '');
        $ref_id   = (int)($_POST['ref_id'] ?? 0) ?: null;
        if ($rated_id && $rated_id !== $user_id) {
            $ins = $pdo->prepare("INSERT INTO ratings (rater_id,rated_id,module,ref_id,stars,comment) VALUES (?,?,'burrow_buddy',?,?,?)
                                  ON DUPLICATE KEY UPDATE stars=VALUES(stars), comment=VALUES(comment)");
            $ins->execute([$user_id, $rated_id, $ref_id, $stars, $comment])
                ? $msg_success = 'Rating submitted!'
                : $msg_error   = 'Could not save rating.';
        }
    }


// Remove listing
if ($action === 'remove_listing') {
    $listing_id = (int)($_POST['listing_id'] ?? 0);
    // Only allow removal if it's yours and not currently borrowed
    $chk = $pdo->prepare("SELECT id, status FROM bb_listings WHERE id=? AND lender_id=?");
    $chk->execute([$listing_id, $user_id]);
    $row = $chk->fetch();
    if (!$row) {
        $msg_error = 'Listing not found.';
    } elseif ($row['status'] === 'borrowed') {
        $msg_error = 'You cannot remove a listing that is currently borrowed out.';
    } else {
        $pdo->prepare("DELETE FROM bb_listings WHERE id=?")->execute([$listing_id]);
        $msg_success = 'Listing removed successfully.';
    }
}
}

// ── Fetch available listings ──────────────────────────────────────────────────
$listings = $pdo->query("
    SELECT l.*, u.full_name, u.student_id AS s_id, u.id AS lender_uid,
           COALESCE(AVG(r.stars),0) AS avg_rating,
           COUNT(r.id) AS rating_count
    FROM bb_listings l
    JOIN users u ON u.id = l.lender_id
    LEFT JOIN ratings r ON r.rated_id = l.lender_id AND r.module='burrow_buddy'
    WHERE l.status = 'available'
    GROUP BY l.id
    ORDER BY l.created_at DESC
")->fetchAll();

// ── My listings ───────────────────────────────────────────────────────────────
$my_listings = $pdo->prepare("
    SELECT l.*, COUNT(br.id) AS request_count
    FROM bb_listings l
    LEFT JOIN bb_requests br ON br.listing_id=l.id AND br.status='pending'
    WHERE l.lender_id=?
    GROUP BY l.id ORDER BY l.created_at DESC
");
$my_listings->execute([$user_id]);
$my_listings = $my_listings->fetchAll();

// ── Incoming requests (for my listings) ──────────────────────────────────────
$incoming = $pdo->prepare("
    SELECT br.*, l.title AS device_title, l.category,
           u.full_name AS borrower_name, u.student_id AS borrower_sid
    FROM bb_requests br
    JOIN bb_listings l ON l.id = br.listing_id
    JOIN users u ON u.id = br.borrower_id
    WHERE l.lender_id = ? AND br.status IN ('pending','approved')
    ORDER BY br.created_at DESC
");
$incoming->execute([$user_id]);
$incoming = $incoming->fetchAll();

// ── My borrow requests ────────────────────────────────────────────────────────
$my_borrows = $pdo->prepare("
    SELECT br.*, l.title AS device_title, l.category, l.lender_id,
           u.full_name AS lender_name, u.student_id AS lender_sid
    FROM bb_requests br
    JOIN bb_listings l ON l.id = br.listing_id
    JOIN users u ON u.id = l.lender_id
    WHERE br.borrower_id = ?
    ORDER BY br.created_at DESC
");
$my_borrows->execute([$user_id]);
$my_borrows = $my_borrows->fetchAll();

// ── Stats ─────────────────────────────────────────────────────────────────────
$total_listed   = count($my_listings);
$total_borrowed = count(array_filter($my_borrows, fn($r) => $r['status'] === 'approved'));
$pending_in     = count(array_filter($incoming, fn($r) => $r['status'] === 'pending'));

$cat_icons = [
    'laptop'     => '💻',
    'keyboard'   => '⌨️',
    'mouse'      => '🖱️',
    'pendrive'   => '💾',
    'headphones' => '🎧',
    'charger'    => '🔌',
    'other'      => '📦',
];
$cond_labels = ['excellent'=>'Excellent','good'=>'Good','fair'=>'Fair'];
$cond_colors = ['excellent'=>'#22c55e','good'=>'#f59e0b','fair'=>'#9a8080'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Burrow Buddy — UniConnect</title>
<link rel="icon" type="image/png" href="icbt.png">

<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=Nunito:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

:root {
  /* shared dashboard vars */
  --coral:    #EE4540;
  --crimson:  #C72C41;
  --wine:     #801336;
  --plum:     #510A32;
  --midnight: #2D142C;
  --bg:       #f5eeee;
  --card:     #ffffff;
  --text:     #2D142C;
  --muted:    #9a8080;
  --border:   #eedede;
  /* Burrow Buddy accent — warm amber/teal to distinguish from Student Link */
  --bb:       #0e7490;   /* teal */
  --bb2:      #0891b2;
  --bb-light: #ecfeff;
  --bb-border:#a5f3fc;
  --sidebar-w:230px;
}

html,body{height:100%;font-family:'Nunito',sans-serif;background:var(--bg);color:var(--text);overflow:hidden;}
.layout{display:grid;grid-template-columns:var(--sidebar-w) 1fr;height:100vh;}

/* ── SIDEBAR (identical to dashboard) ── */
.sidebar{background:linear-gradient(175deg,var(--midnight) 0%,var(--plum) 55%,var(--wine) 100%);display:flex;flex-direction:column;height:100vh;overflow:hidden;position:relative;}
.sidebar::after{content:'';position:absolute;top:0;right:0;width:1.5px;height:100%;background:linear-gradient(to bottom,transparent,rgba(138,43,226,.5) 25%,rgba(238,69,64,.5) 60%,transparent);}
.sidebar-brand{padding:26px 20px 18px;display:flex;align-items:center;gap:11px;border-bottom:1px solid rgba(255,255,255,.06);flex-shrink:0;}
.brand-img{width:36px;height:36px;border-radius:50%;border:1.5px solid rgba(255,255,255,.25);overflow:hidden;background:rgba(255,255,255,.1);}
.brand-img img{width:100%;height:100%;object-fit:cover;}
.brand-name{font-family:'Cormorant Garamond',serif;font-size:16px;font-weight:600;color:#fff;letter-spacing:.02em;line-height:1.1;}
.brand-sub{font-size:9px;color:rgba(255,255,255,.35);letter-spacing:.1em;text-transform:uppercase;}
.nav-scroll{flex:1;overflow-y:auto;padding:14px 0;}
.nav-scroll::-webkit-scrollbar{display:none;}
.nav-section-label{font-size:9px;letter-spacing:.2em;text-transform:uppercase;color:rgba(255,255,255,.25);font-weight:600;padding:10px 20px 6px;}
.nav-item{display:flex;align-items:center;gap:11px;padding:10px 20px;color:rgba(255,255,255,.58);font-size:12.5px;font-weight:500;text-decoration:none;border-left:2.5px solid transparent;transition:all .25s;}
.nav-item:hover{color:#fff;background:rgba(255,255,255,.06);border-left-color:rgba(238,69,64,.4);}
.nav-item.active{color:#fff;background:rgba(14,116,144,.2);border-left-color:#22d3ee;}
.nav-item.active .ni{color:#22d3ee;}
.ni{font-size:15px;width:18px;text-align:center;flex-shrink:0;}
.nav-pill{margin-left:auto;background:var(--coral);color:#fff;font-size:8.5px;font-weight:700;padding:2px 7px;border-radius:50px;}
.sidebar-foot{border-top:1px solid rgba(255,255,255,.06);padding:14px 16px 20px;flex-shrink:0;}
.user-chip{display:flex;align-items:center;gap:10px;padding:9px 11px;border-radius:10px;text-decoration:none;transition:background .25s;cursor:pointer;}
.user-chip:hover{background:rgba(255,255,255,.08);}
.ava{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--coral),var(--crimson));display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;box-shadow:0 2px 8px rgba(238,69,64,.4);}
.user-chip-name{font-size:11.5px;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.user-chip-id{font-size:9.5px;color:rgba(255,255,255,.35);}
.chip-arrow{margin-left:auto;color:rgba(255,255,255,.3);font-size:13px;}

/* ── MAIN ── */
.main{display:flex;flex-direction:column;height:100vh;overflow:hidden;}
.topbar{background:rgba(245,238,238,.92);backdrop-filter:blur(10px);border-bottom:1px solid var(--border);padding:0 32px;height:60px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;position:relative;z-index:100;overflow:visible;}.page-title{font-family:'Cormorant Garamond',serif;font-size:20px;font-weight:600;color:var(--midnight);display:flex;align-items:center;gap:10px;}
.page-title-badge{background:linear-gradient(135deg,var(--bb),var(--bb2));color:#fff;font-family:'Nunito',sans-serif;font-size:9px;font-weight:700;padding:3px 10px;border-radius:50px;letter-spacing:.08em;text-transform:uppercase;}
.topbar-right{display:flex;align-items:center;gap:10px;}
.back-btn{display:inline-flex;align-items:center;gap:7px;padding:8px 18px;border:1px solid var(--border);border-radius:50px;font-size:12px;font-weight:600;color:var(--muted);text-decoration:none;transition:all .2s;background:var(--card);}
.back-btn:hover{border-color:var(--bb);color:var(--bb);}
.profile-link{display:flex;align-items:center;gap:8px;padding:5px 12px 5px 6px;border:1px solid var(--border);border-radius:50px;background:var(--card);cursor:pointer;text-decoration:none;transition:all .2s;}
.profile-link:hover{border-color:var(--bb);box-shadow:0 2px 10px rgba(14,116,144,.1);}
.ava-sm{width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,var(--coral),var(--crimson));display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;}
.chip-name{font-size:12px;font-weight:600;color:var(--text);}

.scroll-area{flex:1;overflow-y:auto;padding:28px 32px 48px;}
.scroll-area::-webkit-scrollbar{width:4px;}
.scroll-area::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px;}

/* ── ALERTS ── */
.alert{padding:14px 20px;border-radius:12px;margin-bottom:22px;font-size:12.5px;display:flex;align-items:center;gap:10px;font-weight:500;animation:fadeUp .3s ease;}
.alert-success{background:rgba(14,116,144,.07);border:1px solid rgba(14,116,144,.2);color:var(--bb);}
.alert-error{background:rgba(238,69,64,.08);border:1px solid rgba(238,69,64,.25);color:var(--coral);}

/* ── HERO ── */
.hero{
  border-radius:20px;overflow:hidden;
  display:grid;grid-template-columns:1fr 280px;
  min-height:200px;margin-bottom:30px;
  position:relative;animation:fadeUp .5s ease both;
  background:linear-gradient(130deg,#0c4a6e 0%,#075985 35%,#0e7490 70%,#0891b2 100%);
}
.hero-deco-1{position:absolute;width:240px;height:240px;border-radius:50%;background:rgba(255,255,255,.05);top:-70px;left:30%;pointer-events:none;}
.hero-deco-2{position:absolute;width:120px;height:120px;border-radius:50%;background:rgba(255,255,255,.04);bottom:-30px;left:12%;pointer-events:none;}
.hero-text{padding:34px 32px;position:relative;z-index:1;display:flex;flex-direction:column;justify-content:center;}
.hero-eyebrow{font-size:9px;letter-spacing:.22em;text-transform:uppercase;color:rgba(255,255,255,.45);font-weight:600;margin-bottom:10px;}
.hero-title{font-family:'Cormorant Garamond',serif;font-size:32px;font-weight:600;color:#fff;line-height:1.1;margin-bottom:10px;}
.hero-title em{color:#67e8f9;font-style:italic;}
.hero-sub{font-size:12px;color:rgba(255,255,255,.5);font-weight:300;line-height:1.65;max-width:380px;}
.hero-stats{display:flex;gap:0;margin-top:20px;}
.hero-stat{text-align:center;padding:0 18px;}
.hero-stat:first-child{padding-left:0;}
.hero-stat+.hero-stat{border-left:1px solid rgba(255,255,255,.12);}
.hs-val{font-family:'Cormorant Garamond',serif;font-size:26px;font-weight:600;color:#fff;line-height:1;}
.hs-lbl{font-size:9px;color:rgba(255,255,255,.38);text-transform:uppercase;letter-spacing:.1em;margin-top:3px;}
.hero-illus{display:flex;align-items:flex-end;justify-content:center;position:relative;overflow:hidden;}
.hero-illus img{width:100%;height:100%;object-fit:contain;object-position:bottom;}
.illus-hint{border:1.5px dashed rgba(255,255,255,.18);border-radius:12px;padding:14px 18px;text-align:center;color:rgba(255,255,255,.25);font-size:11px;}
.illus-hint span{display:block;font-size:22px;margin-bottom:5px;}

/* ── TABS ── */
.tabs-row{display:flex;gap:6px;margin-bottom:26px;flex-wrap:wrap;}
.tab-btn{display:flex;align-items:center;gap:8px;padding:10px 20px;border:1.5px solid var(--border);border-radius:50px;background:var(--card);color:var(--muted);font-family:'Nunito',sans-serif;font-size:12.5px;font-weight:600;cursor:pointer;transition:all .25s;white-space:nowrap;}
.tab-btn:hover{border-color:rgba(14,116,144,.3);color:var(--bb);}
.tab-btn.active{background:linear-gradient(135deg,var(--bb),#0c4a6e);border-color:transparent;color:#fff;box-shadow:0 4px 16px rgba(14,116,144,.28);}
.tab-count{background:rgba(255,255,255,.22);padding:1px 8px;border-radius:50px;font-size:10px;}
.tab-btn:not(.active) .tab-count{background:rgba(14,116,144,.1);color:var(--bb);}
.tab-panel{display:none;animation:fadeUp .35s ease both;}
.tab-panel.active{display:block;}

/* ── SECTION TITLE ── */
.section-title{font-family:'Cormorant Garamond',serif;font-size:18px;font-weight:600;color:var(--midnight);margin-bottom:18px;display:flex;align-items:center;gap:10px;}
.section-title::before{content:'';width:3px;height:18px;border-radius:2px;background:linear-gradient(to bottom,var(--bb),#0c4a6e);flex-shrink:0;}

/* ── FILTER BAR ── */
.filter-bar{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;}
.search-wrap{flex:1;min-width:180px;position:relative;}
.search-wrap input{width:100%;padding:10px 16px 10px 38px;background:var(--card);border:1px solid var(--border);border-radius:50px;color:var(--text);font-family:'Nunito',sans-serif;font-size:12px;outline:none;transition:border-color .2s;}
.search-wrap input:focus{border-color:var(--bb);}
.search-wrap input::placeholder{color:var(--muted);}
.si{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px;pointer-events:none;}
.fsel{padding:10px 34px 10px 16px;background:var(--card);border:1px solid var(--border);border-radius:50px;color:var(--text);font-family:'Nunito',sans-serif;font-size:12px;outline:none;cursor:pointer;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239a8080' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 13px center;}
.fsel:focus{border-color:var(--bb);outline:none;}

/* ── DEVICE GRID ── */
.device-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;}

.device-card{
  background:var(--card);border:1px solid var(--border);border-radius:16px;
  overflow:hidden;transition:transform .3s,box-shadow .3s,border-color .3s;
  animation:fadeUp .4s ease both;
}
.device-card:hover{transform:translateY(-4px);box-shadow:0 14px 34px rgba(14,116,144,.12);border-color:rgba(14,116,144,.25);}

.device-card-top{
  height:110px;
  background:linear-gradient(135deg,#0c4a6e 0%,#0e7490 55%,#0891b2 100%);
  display:flex;align-items:center;justify-content:center;
  position:relative;overflow:hidden;
}
.device-card-top::before{content:'';position:absolute;width:130px;height:130px;border-radius:50%;background:rgba(255,255,255,.06);top:-35px;right:-25px;}
.device-card-top::after{content:'';position:absolute;width:60px;height:60px;border-radius:50%;background:rgba(255,255,255,.04);bottom:-12px;left:16px;}
.device-icon{font-size:38px;position:relative;z-index:1;filter:drop-shadow(0 3px 8px rgba(0,0,0,.25));}
.device-card-img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.18;}

.device-body{padding:14px 16px 16px;}
.device-title{font-family:'Cormorant Garamond',serif;font-size:15px;font-weight:600;color:var(--midnight);margin-bottom:4px;line-height:1.2;}
.device-lender{display:flex;align-items:center;gap:7px;margin-bottom:10px;text-decoration:none;}
.lender-ava{width:22px;height:22px;border-radius:50%;background:linear-gradient(135deg,var(--coral),var(--crimson));display:flex;align-items:center;justify-content:center;font-size:8.5px;font-weight:700;color:#fff;flex-shrink:0;}
.lender-name{font-size:11px;color:var(--muted);font-weight:500;transition:color .2s;}
.device-lender:hover .lender-name{color:var(--bb);}
.device-tags{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:10px;}
.dtag{padding:3px 10px;border-radius:50px;font-size:10px;font-weight:600;}
.dtag-cat{background:rgba(14,116,144,.08);color:var(--bb);}
.dtag-cond{padding:3px 10px;border-radius:50px;font-size:10px;font-weight:600;}
.dtag-days{background:rgba(45,20,44,.06);color:var(--midnight);}
.device-rating{display:flex;align-items:center;gap:5px;margin-bottom:12px;}
.stars-mini{color:#f59e0b;font-size:11px;letter-spacing:1px;}
.rating-count{font-size:10px;color:var(--muted);}
.device-desc{font-size:11px;color:var(--muted);line-height:1.55;margin-bottom:12px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.btn-borrow{width:100%;padding:10px;border:none;border-radius:10px;background:linear-gradient(135deg,var(--bb),#0c4a6e);color:#fff;font-family:'Nunito',sans-serif;font-size:12px;font-weight:700;cursor:pointer;transition:all .25s;box-shadow:0 3px 12px rgba(14,116,144,.2);}
.btn-borrow:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(14,116,144,.3);}
.btn-borrowed{background:var(--bg);color:var(--muted);border:1px solid var(--border);cursor:not-allowed;box-shadow:none;transform:none !important;}

/* ── BORROW MODAL ── */
.modal-bg{position:fixed;inset:0;background:rgba(45,20,44,.5);backdrop-filter:blur(6px);z-index:300;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .3s;}
.modal-bg.open{opacity:1;pointer-events:auto;}
.modal-box{background:var(--card);border-radius:20px;width:460px;max-width:92vw;overflow:hidden;transform:translateY(30px) scale(.96);transition:transform .35s cubic-bezier(.34,1.56,.64,1);box-shadow:0 24px 60px rgba(45,20,44,.3);}
.modal-bg.open .modal-box{transform:none;}
.modal-head{background:linear-gradient(130deg,#0c4a6e,#0e7490 60%,#0891b2 100%);padding:22px 24px;display:flex;align-items:center;justify-content:space-between;}
.modal-head-title{font-family:'Cormorant Garamond',serif;font-size:20px;font-weight:600;color:#fff;}
.modal-close{width:30px;height:30px;border-radius:50%;background:rgba(255,255,255,.15);border:none;color:#fff;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .2s;}
.modal-close:hover{background:rgba(255,255,255,.25);}
.modal-body{padding:22px 24px 26px;}

/* ── FORM ELEMENTS (shared) ── */
.fg{margin-bottom:14px;}
.fl{display:block;font-size:9.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;}
.fc{width:100%;padding:11px 14px;background:var(--bg);border:1px solid var(--border);border-radius:10px;color:var(--text);font-family:'Nunito',sans-serif;font-size:12.5px;outline:none;transition:border-color .2s;appearance:none;}
.fc:focus{border-color:var(--bb);box-shadow:0 0 0 3px rgba(14,116,144,.07);}
.fc option{background:#fff;}
textarea.fc{resize:vertical;min-height:70px;line-height:1.6;}
.date-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.btn-sub-teal{width:100%;padding:13px;border:none;border-radius:12px;background:linear-gradient(135deg,var(--bb),#0c4a6e);color:#fff;font-family:'Nunito',sans-serif;font-size:13px;font-weight:700;cursor:pointer;transition:all .25s;box-shadow:0 4px 14px rgba(14,116,144,.22);}
.btn-sub-teal:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(14,116,144,.32);}

/* ── LIST DEVICE FORM ── */
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:22px;align-items:start;}
.fcard{background:var(--card);border:1px solid var(--border);border-radius:18px;overflow:hidden;}
.fcard-head{background:linear-gradient(130deg,#0c4a6e 0%,#0e7490 55%,#0891b2 100%);display:grid;grid-template-columns:1fr 150px;min-height:130px;position:relative;overflow:hidden;}
.fcard-head-deco{position:absolute;width:160px;height:160px;border-radius:50%;background:rgba(255,255,255,.06);top:-50px;left:38%;pointer-events:none;}
.fcard-head-text{padding:22px;position:relative;z-index:1;display:flex;flex-direction:column;justify-content:center;}
.fcard-title{font-family:'Cormorant Garamond',serif;font-size:20px;font-weight:600;color:#fff;margin-bottom:5px;}
.fcard-sub{font-size:11px;color:rgba(255,255,255,.42);font-weight:300;line-height:1.5;}
.fcard-illus{display:flex;align-items:flex-end;justify-content:center;position:relative;overflow:hidden;}
.fcard-illus img{width:100%;height:100%;object-fit:contain;object-position:bottom;}
.fcard-illus-hint{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);border:1.5px dashed rgba(255,255,255,.16);border-radius:10px;padding:10px 12px;text-align:center;color:rgba(255,255,255,.22);font-size:10px;white-space:nowrap;}
.fcard-illus-hint span{display:block;font-size:18px;margin-bottom:3px;}
.fcard-body{padding:22px;}
.cat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:4px;}
.cat-opt input{display:none;}
.cat-opt label{display:flex;flex-direction:column;align-items:center;gap:4px;padding:10px 6px;border:1.5px solid var(--border);border-radius:10px;font-size:10px;font-weight:600;color:var(--muted);cursor:pointer;transition:all .2s;text-align:center;}
.cat-opt label span{font-size:20px;}
.cat-opt input:checked+label{border-color:var(--bb);background:rgba(14,116,144,.06);color:var(--bb);}
.cat-opt label:hover{border-color:rgba(14,116,144,.3);color:var(--bb);}
.cond-row{display:flex;gap:8px;}
.cond-opt{flex:1;}
.cond-opt input{display:none;}
.cond-opt label{display:flex;align-items:center;justify-content:center;gap:4px;padding:9px 4px;border:1.5px solid var(--border);border-radius:10px;font-size:11px;font-weight:600;color:var(--muted);cursor:pointer;transition:all .2s;}
.cond-opt input:checked+label{border-color:var(--bb);background:rgba(14,116,144,.06);color:var(--bb);}
.cond-opt label:hover{border-color:rgba(14,116,144,.25);color:var(--bb);}

/* ── INFO CARD ── */
.icard{background:var(--card);border:1px solid var(--border);border-radius:18px;overflow:hidden;}
.icard-head{background:linear-gradient(130deg,#0c4a6e,#0e7490 60%,#0891b2);display:grid;grid-template-columns:1fr 130px;min-height:110px;position:relative;overflow:hidden;}
.icard-head-deco{position:absolute;width:130px;height:130px;border-radius:50%;background:rgba(255,255,255,.06);top:-35px;left:42%;pointer-events:none;}
.icard-head-text{padding:20px;position:relative;z-index:1;display:flex;flex-direction:column;justify-content:center;}
.icard-title{font-family:'Cormorant Garamond',serif;font-size:17px;font-weight:600;color:#fff;margin-bottom:4px;}
.icard-sub{font-size:10px;color:rgba(255,255,255,.38);font-weight:300;}
.icard-illus{display:flex;align-items:flex-end;justify-content:center;position:relative;overflow:hidden;}
.icard-body{padding:20px;}
.istep{display:flex;gap:12px;align-items:flex-start;margin-bottom:13px;}
.istep:last-child{margin-bottom:0;}
.snum{width:24px;height:24px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,var(--bb),#0c4a6e);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;box-shadow:0 2px 8px rgba(14,116,144,.28);margin-top:1px;}
.stxt{font-size:12px;color:var(--text);line-height:1.55;}
.stxt strong{color:var(--midnight);}
.tip-box{margin-top:14px;padding:12px 14px;background:rgba(14,116,144,.05);border:1px solid rgba(14,116,144,.14);border-radius:10px;font-size:11.5px;color:var(--bb);line-height:1.5;}

/* ── MY LISTINGS & REQUESTS ── */
.item-list{display:flex;flex-direction:column;gap:12px;}
.item-row{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:16px 18px;display:flex;align-items:center;gap:14px;transition:border-color .2s;}
.item-row:hover{border-color:rgba(14,116,144,.2);}
.item-icon{width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#0c4a6e,#0891b2);display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}
.item-info{flex:1;min-width:0;}
.item-title{font-weight:600;font-size:13px;color:var(--midnight);margin-bottom:3px;}
.item-meta{font-size:10.5px;color:var(--muted);}
.item-status{display:flex;flex-direction:column;align-items:flex-end;gap:6px;}
.badge{display:inline-block;padding:3px 12px;border-radius:50px;font-size:10px;font-weight:700;}
.badge-available{background:rgba(14,116,144,.08);color:var(--bb);}
.badge-borrowed{background:rgba(245,158,11,.1);color:#b45309;}
.badge-unavailable{background:rgba(154,128,128,.1);color:var(--muted);}
.badge-pending{background:rgba(245,158,11,.1);color:#b45309;}
.badge-approved{background:rgba(14,116,144,.08);color:var(--bb);}
.badge-rejected{background:rgba(238,69,64,.08);color:var(--coral);}
.badge-returned{background:rgba(81,10,50,.06);color:var(--plum);}
.badge-reqs{background:rgba(238,69,64,.1);color:var(--coral);}

.action-btns{display:flex;gap:6px;}
.btn-sm{padding:6px 14px;border-radius:50px;font-family:'Nunito',sans-serif;font-size:11px;font-weight:700;cursor:pointer;border:none;transition:all .2s;}
.btn-approve{background:rgba(14,116,144,.1);color:var(--bb);}
.btn-approve:hover{background:var(--bb);color:#fff;}
.btn-reject{background:rgba(238,69,64,.08);color:var(--coral);}
.btn-reject:hover{background:var(--coral);color:#fff;}
.btn-return{background:rgba(81,10,50,.07);color:var(--plum);}
.btn-return:hover{background:var(--plum);color:#fff;}
.btn-rate{background:rgba(245,158,11,.1);color:#b45309;}
.btn-rate:hover{background:#f59e0b;color:#fff;}
.btn-profile{background:rgba(14,116,144,.08);color:var(--bb);}
.btn-profile:hover{background:var(--bb);color:#fff;}

/* ── EMPTY ── */
.empty{text-align:center;padding:48px 20px;background:var(--card);border:1px solid var(--border);border-radius:18px;}
.empty-ico{width:80px;height:80px;border-radius:50%;background:rgba(14,116,144,.06);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:32px;margin:0 auto 14px;}
.empty-title{font-family:'Cormorant Garamond',serif;font-size:18px;font-weight:600;color:var(--midnight);margin-bottom:5px;}
.empty-sub{font-size:12px;color:var(--muted);font-weight:300;}

/* ── RATING MODAL ── */
.star-row{display:flex;gap:8px;justify-content:center;margin:16px 0;}
.star-btn{font-size:28px;cursor:pointer;transition:transform .15s;color:#d1d5db;line-height:1;}
.star-btn:hover,.star-btn.lit{color:#f59e0b;transform:scale(1.15);}

@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
/* ── NOTIFICATION BELL ── */
.notif-bell-wrap { position:relative; }
.notif-bell-btn {
  background:var(--card); border:1px solid var(--border); border-radius:50px;
  padding:7px 13px; font-size:16px; cursor:pointer; position:relative;
  transition:border-color .2s; display:flex; align-items:center;
}
.notif-bell-btn:hover { border-color:var(--bb); }
.notif-badge {
  position:absolute; top:-5px; right:-5px; background:var(--coral); color:#fff;
  font-size:9px; font-weight:800; min-width:17px; height:17px; border-radius:50px;
  display:flex; align-items:center; justify-content:center; padding:0 4px;
  border:1.5px solid var(--bg); font-family:'Nunito',sans-serif;
}
.notif-panel {
  position:absolute; top:calc(100% + 10px); right:0; width:340px;
  background:var(--card); border:1px solid var(--border); border-radius:16px;
  box-shadow:0 12px 36px rgba(14,116,144,.16); z-index:9999;
  display:none; max-height:440px; overflow-y:auto; animation:fadeUp .2s ease;
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
  background:none; border:none; color:var(--bb); font-size:10.5px;
  font-weight:700; cursor:pointer; font-family:'Nunito',sans-serif; padding:0;
}
.notif-mark-all:hover { text-decoration:underline; }
.notif-empty { padding:32px 20px; text-align:center; font-size:12px; color:var(--muted); }
.notif-item {
  padding:13px 16px; border-bottom:1px solid rgba(238,222,222,.4); transition:background .2s;
}
.notif-item:last-child { border-bottom:none; }
.notif-item.unread { background:rgba(14,116,144,.04); }
.notif-item-title { font-size:12px; font-weight:700; color:var(--midnight); margin-bottom:4px; }
.notif-item.unread .notif-item-title::before { content:'● '; color:var(--bb); font-size:8px; vertical-align:middle; }
.notif-item-msg  { font-size:11.5px; color:var(--text); line-height:1.6; margin-bottom:5px; }
.notif-item-time { font-size:10px; color:var(--muted); }
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
      <a class="nav-item active" href="burrow_buddy.php"><span class="ni">📚</span> Burrow Buddy</a>
      <a class="nav-item" href="reclaim.php"><span class="ni">♻️</span> Reclaim</a>
      <a class="nav-item" href="brain_bridge.php"><span class="ni">🧠</span> Brain Bridge</a>
      <div class="nav-section-label" style="margin-top:8px">Account</div>
      <a class="nav-item" href="student_profile.php?id=<?= $user_id ?>"><span class="ni">👤</span> My Profile</a>
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
      <div class="page-title">
        Burrow Buddy
        <span class="page-title-badge">Device Lending</span>
      </div>
      <div class="topbar-right">
  <a href="student_dashboard.php" class="back-btn">← Dashboard</a>

  <div class="notif-bell-wrap" id="notifWrap">
    <button class="notif-bell-btn" onclick="toggleNotifPanel()">
      🔔
      <?php if (!empty($unread_count) && $unread_count > 0): ?>
        <span class="notif-badge"><?= $unread_count ?></span>
      <?php endif; ?>
    </button>
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

  <a href="student_profile.php?id=<?= $user_id ?>" class="profile-link">
    <div class="ava-sm"><?= $initials ?></div>
    <span class="chip-name"><?= $first ?></span>
  </a>
</div>
    </div>

    <div class="scroll-area">

      <!-- Alerts -->
      <?php if ($msg_success): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg_success) ?></div><?php endif; ?>
      <?php if ($msg_error):   ?><div class="alert alert-error">✕ <?= htmlspecialchars($msg_error) ?></div><?php endif; ?>

      <!-- ══ HERO ══ -->
      <div class="hero">
        <div class="hero-deco-1"></div><div class="hero-deco-2"></div>
        <div class="hero-text">
          <div class="hero-eyebrow">Burrow Buddy · Device Exchange</div>
          <div class="hero-title">Lend, borrow,<br/><em>connect.</em></div>
          <div class="hero-sub">Share your devices and study gear with fellow ICBT students when you're not using them — and borrow what you need when you need it.</div>
          <div class="hero-stats">
            <div class="hero-stat"><div class="hs-val"><?= count($listings) ?></div><div class="hs-lbl">Available</div></div>
            <div class="hero-stat"><div class="hs-val"><?= $total_listed ?></div><div class="hs-lbl">My Listings</div></div>
            <div class="hero-stat"><div class="hs-val"><?= $pending_in ?></div><div class="hs-lbl">Pending</div></div>
          </div>
        </div>
        <!-- 🖼️ ILLUSTRATION SLOT 1 — Hero right (~280×200px transparent PNG) -->
        <div class="hero-illus">
          <div style="display:flex;align-items:center;justify-content:center;width:100%;height:100%;">
            <div class="illus-hint"><span><img src="burrow.png" alt=""></span><br/><span style="font-size:9px;opacity:.6"></span></div>
          </div>
          <!-- <img src="images/bb-hero.png" alt=""> -->
        </div>
      </div>

      <!-- ══ TABS ══ -->
      <div class="tabs-row">
        <button class="tab-btn active" onclick="switchTab('browse',this)">🔍 Browse Devices <span class="tab-count"><?= count($listings) ?></span></button>
        <button class="tab-btn" onclick="switchTab('list',this)">➕ List a Device</button>
        <button class="tab-btn" onclick="switchTab('mylistings',this)">📦 My Listings <span class="tab-count"><?= count($my_listings) ?></span></button>
        <button class="tab-btn" onclick="switchTab('incoming',this)">
          📥 Incoming Requests
          <?php if ($pending_in > 0): ?><span class="tab-count"><?= $pending_in ?></span><?php endif; ?>
        </button>
        <button class="tab-btn" onclick="switchTab('myborrows',this)">🤝 My Borrows <span class="tab-count"><?= count($my_borrows) ?></span></button>
      </div>

      <!-- ══ BROWSE DEVICES ══ -->
      <div class="tab-panel active" id="panel-browse">
        <div class="filter-bar">
          <div class="search-wrap">
            <span class="si">🔍</span>
            <input type="text" id="deviceSearch" placeholder="Search devices…" oninput="filterDevices()"/>
          </div>
          <select class="fsel" id="catFilter" onchange="filterDevices()">
            <option value="">All categories</option>
            <option value="laptop">💻 Laptop</option>
            <option value="keyboard">⌨️ Keyboard</option>
            <option value="mouse">🖱️ Mouse</option>
            <option value="pendrive">💾 Pen Drive</option>
            <option value="headphones">🎧 Headphones</option>
            <option value="charger">🔌 Charger</option>
            <option value="other">📦 Other</option>
          </select>
        </div>

        <?php if (empty($listings)): ?>
          <div class="empty"><div class="empty-ico">📦</div><div class="empty-title">No devices listed yet</div><div class="empty-sub">Be the first to share a device!</div></div>
        <?php else: ?>
          <div class="device-grid" id="deviceGrid">
            <?php foreach ($listings as $i => $l):
              $icon = $cat_icons[$l['category']] ?? '📦';
              $cond_color = $cond_colors[$l['condition']] ?? '#9a8080';
              $stars_filled = round($l['avg_rating']);
              $lender_init = strtoupper(substr($l['full_name'],0,1));
              $is_mine = ($l['lender_uid'] == $user_id);
            ?>
            <div class="device-card" style="animation-delay:<?= $i*.06 ?>s"
                 data-title="<?= htmlspecialchars(strtolower($l['title'])) ?>"
                 data-cat="<?= $l['category'] ?>">
              <div class="device-card-top">
                <div class="device-icon"><?= $icon ?></div>
              </div>
              <div class="device-body">
                <div class="device-title"><?= htmlspecialchars($l['title']) ?></div>
                <a href="student_profile.php?id=<?= $l['lender_uid'] ?>" class="device-lender">
                  <div class="lender-ava"><?= $lender_init ?></div>
                  <span class="lender-name"><?= htmlspecialchars($l['full_name']) ?></span>
                </a>
                <div class="device-tags">
                  <span class="dtag dtag-cat"><?= ucfirst($l['category']) ?></span>
                  <span class="dtag dtag-cond" style="background:<?= $cond_color ?>18;color:<?= $cond_color ?>"><?= $cond_labels[$l['condition']] ?></span>
                  <span class="dtag dtag-days">⏱ Up to <?= $l['borrow_days'] ?> day<?= $l['borrow_days']>1?'s':'' ?></span>
                </div>
                <?php if ($l['avg_rating'] > 0): ?>
                <div class="device-rating">
                  <span class="stars-mini"><?= str_repeat('★',$stars_filled).str_repeat('☆',5-$stars_filled) ?></span>
                  <span class="rating-count"><?= number_format($l['avg_rating'],1) ?> (<?= $l['rating_count'] ?>)</span>
                </div>
                <?php endif; ?>
                <?php if ($l['description']): ?>
                  <div class="device-desc"><?= htmlspecialchars($l['description']) ?></div>
                <?php endif; ?>
                <?php if ($is_mine): ?>
                  <button class="btn-borrow btn-borrowed" disabled>Your Listing</button>
                <?php else: ?>
                  <button class="btn-borrow" onclick="openBorrowModal(<?= $l['id'] ?>, '<?= addslashes(htmlspecialchars($l['title'])) ?>', <?= $l['borrow_days'] ?>)">Request to Borrow</button>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="empty" id="noDevicesMsg" style="display:none"><div class="empty-ico">🔍</div><div class="empty-title">No matches</div><div class="empty-sub">Try a different search or category.</div></div>
        <?php endif; ?>
      </div>

      <!-- ══ LIST A DEVICE ══ -->
      <div class="tab-panel" id="panel-list">
        <div class="two-col">
          <div class="fcard">
            <div class="fcard-head">
              <div class="fcard-head-deco"></div>
              <div class="fcard-head-text">
                <div class="fcard-title">List Your Device</div>
                <div class="fcard-sub">Share what you have and help a fellow student.</div>
              </div>
              <!-- 🖼️ ILLUSTRATION SLOT 2 -->
              <div class="fcard-illus">
                <div class="fcard-illus-hint"><span>🖼️</span>Illustration</div>
                <!-- <img src="images/bb-list.png" alt=""> -->
              </div>
            </div>
            <div class="fcard-body">
              <form method="POST">
                <input type="hidden" name="action" value="list_device"/>
                <div class="fg">
                  <label class="fl">Device Name / Title</label>
                  <input type="text" name="title" class="fc" placeholder="e.g. Logitech MX Master 3 Mouse" required/>
                </div>
                <div class="fg">
                  <label class="fl">Category</label>
                  <div class="cat-grid">
                    <?php
                    $cats = ['laptop'=>'💻','keyboard'=>'⌨️','mouse'=>'🖱️','pendrive'=>'💾','headphones'=>'🎧','charger'=>'🔌','other'=>'📦'];
                    foreach ($cats as $val => $ic):
                    ?>
                    <div class="cat-opt">
                      <input type="radio" name="category" id="cat_<?= $val ?>" value="<?= $val ?>" <?= $val==='other'?'checked':'' ?>>
                      <label for="cat_<?= $val ?>"><span><?= $ic ?></span><?= ucfirst($val) ?></label>
                    </div>
                    <?php endforeach; ?>
                  </div>
                </div>
                <div class="fg">
                  <label class="fl">Condition</label>
                  <div class="cond-row">
                    <div class="cond-opt"><input type="radio" name="condition" id="cond_ex" value="excellent"><label for="cond_ex">✨ Excellent</label></div>
                    <div class="cond-opt"><input type="radio" name="condition" id="cond_gd" value="good" checked><label for="cond_gd">👍 Good</label></div>
                    <div class="cond-opt"><input type="radio" name="condition" id="cond_fr" value="fair"><label for="cond_fr">⚠️ Fair</label></div>
                  </div>
                </div>
                <div class="fg">
                  <label class="fl">Max Borrow Duration (days)</label>
                  <input type="number" name="borrow_days" class="fc" min="1" max="30" value="3" style="max-width:120px"/>
                </div>
                <div class="fg">
                  <label class="fl">Description <span style="color:var(--muted);font-size:9px;text-transform:none;letter-spacing:0">(optional)</span></label>
                  <textarea name="description" class="fc" placeholder="Describe your device, accessories included, any notes…"></textarea>
                </div>
                <button type="submit" class="btn-sub-teal">List Device →</button>
              </form>
            </div>
          </div>

          <div class="icard">
            <div class="icard-head">
              <div class="icard-head-deco"></div>
              <div class="icard-head-text"><div class="icard-title">How It Works</div><div class="icard-sub">Simple & safe device sharing</div></div>
              <!-- 🖼️ ILLUSTRATION SLOT 3 -->
              <div class="icard-illus">
                <div class="fcard-illus-hint" style="font-size:9.5px;padding:8px 10px;"><span style="font-size:16px">🖼️</span>Illustration</div>
                <!-- <img src="images/bb-how.png" alt=""> -->
              </div>
            </div>
            <div class="icard-body">
              <div class="istep"><div class="snum">1</div><div class="stxt"><strong>List your device</strong> — describe it and set the max borrow duration.</div></div>
              <div class="istep"><div class="snum">2</div><div class="stxt"><strong>Review requests</strong> — students request to borrow and you approve or reject.</div></div>
              <div class="istep"><div class="snum">3</div><div class="stxt"><strong>Hand it over</strong> — meet on campus and lend your device.</div></div>
              <div class="istep"><div class="snum">4</div><div class="stxt"><strong>Mark returned</strong> — once you get it back, mark it returned and it's available again.</div></div>
              <div class="tip-box">⭐ After returning, borrowers can <strong>rate you as a lender</strong> — build your Burrow Buddy reputation!</div>
            </div>
          </div>
        </div>
      </div>

      <!-- ══ MY LISTINGS ══ -->
      <div class="tab-panel" id="panel-mylistings">
        <div class="section-title">My Device Listings</div>
        <?php if (empty($my_listings)): ?>
          <div class="empty"><div class="empty-ico">📦</div><div class="empty-title">No listings yet</div><div class="empty-sub">Head to "List a Device" to share your gear.</div></div>
        <?php else: ?>
          <div class="item-list">
            <?php foreach ($my_listings as $l):
              $icon = $cat_icons[$l['category']] ?? '📦';
            ?>
            <div class="item-row">
              <div class="item-icon"><?= $icon ?></div>
              <div class="item-info">
                <div class="item-title"><?= htmlspecialchars($l['title']) ?></div>
                <div class="item-meta">📅 Listed <?= date('d M Y',strtotime($l['created_at'])) ?> &nbsp;·&nbsp; ⏱ Up to <?= $l['borrow_days'] ?> day<?= $l['borrow_days']>1?'s':'' ?></div>
              </div>
              <div class="item-status">
    <span class="badge badge-<?= $l['status'] ?>"><?= ucfirst($l['status']) ?></span>
    <?php if ($l['request_count'] > 0): ?>
        <span class="badge badge-reqs">⚡ <?= $l['request_count'] ?> pending</span>
    <?php endif; ?>
    <?php if ($l['status'] !== 'borrowed'): ?>
        <form method="POST" style="display:inline;margin-top:4px">
            <input type="hidden" name="action" value="remove_listing">
            <input type="hidden" name="listing_id" value="<?= $l['id'] ?>">
            <button type="submit" class="btn-sm btn-reject"
                    onclick="return confirm('Remove this listing? This cannot be undone.')">
                🗑 Remove
            </button>
        </form>
    <?php else: ?>
        <span style="font-size:10px;color:var(--muted);font-style:italic">Currently borrowed</span>
    <?php endif; ?>
</div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- ══ INCOMING REQUESTS ══ -->
      <div class="tab-panel" id="panel-incoming">
        <div class="section-title">Incoming Borrow Requests</div>
        <?php if (empty($incoming)): ?>
          <div class="empty"><div class="empty-ico">📥</div><div class="empty-title">No incoming requests</div><div class="empty-sub">When students request your devices, they'll appear here.</div></div>
        <?php else: ?>
          <div class="item-list">
            <?php foreach ($incoming as $r):
              $icon = $cat_icons[$r['category']] ?? '📦';
              $b_init = strtoupper(substr($r['borrower_name'],0,1));
            ?>
            <div class="item-row">
              <div class="item-icon"><?= $icon ?></div>
              <div class="item-info">
                <div class="item-title"><?= htmlspecialchars($r['device_title']) ?></div>
                <div class="item-meta" style="display:flex;align-items:center;gap:8px;margin-top:4px;">
                  <span style="display:flex;align-items:center;gap:5px;">
                    <span style="width:20px;height:20px;border-radius:50%;background:linear-gradient(135deg,var(--coral),var(--crimson));display:inline-flex;align-items:center;justify-content:center;font-size:8px;font-weight:700;color:#fff;"><?= $b_init ?></span>
                    <span><?= htmlspecialchars($r['borrower_name']) ?></span>
                  </span>
                  <?php if ($r['borrow_from'] && $r['borrow_until']): ?>
                    <span>· 📅 <?= date('d M',strtotime($r['borrow_from'])) ?> → <?= date('d M',strtotime($r['borrow_until'])) ?></span>
                  <?php endif; ?>
                  <?php if ($r['message']): ?>
                    <span>· 💬 "<?= htmlspecialchars(substr($r['message'],0,60)).(strlen($r['message'])>60?'…':'') ?>"</span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="item-status">
                <span class="badge badge-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span>
               <?php if ($r['status'] === 'pending'): ?>
  <div class="action-btns">
    <button class="btn-sm btn-approve"
            onclick="openApproveModal(<?= $r['id'] ?>, '<?= addslashes(htmlspecialchars($r['device_title'])) ?>')">
      ✓ Approve
    </button>
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="respond_request">
      <input type="hidden" name="req_id" value="<?= $r['id'] ?>">
      <input type="hidden" name="new_status" value="rejected">
      <button type="submit" class="btn-sm btn-reject">✕ Reject</button>
    </form>
  </div>
                <?php elseif ($r['status'] === 'approved'): ?>
                  <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="mark_returned">
                    <input type="hidden" name="req_id" value="<?= $r['id'] ?>">
                    <button type="submit" class="btn-sm btn-return">📦 Mark Returned</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- ══ MY BORROWS ══ -->
      <div class="tab-panel" id="panel-myborrows">
        <div class="section-title">My Borrow Requests</div>
        <?php if (empty($my_borrows)): ?>
          <div class="empty"><div class="empty-ico">🤝</div><div class="empty-title">No borrow requests yet</div><div class="empty-sub">Browse available devices and send a request!</div></div>
        <?php else: ?>
          <div class="item-list">
            <?php foreach ($my_borrows as $r):
              $icon = $cat_icons[$r['category']] ?? '📦';
              $l_init = strtoupper(substr($r['lender_name'],0,1));
            ?>
            <div class="item-row">
              <div class="item-icon"><?= $icon ?></div>
              <div class="item-info">
                <div class="item-title"><?= htmlspecialchars($r['device_title']) ?></div>
                <div class="item-meta">
                  Lender: <?= htmlspecialchars($r['lender_name']) ?>
                  <?php if ($r['borrow_from']): ?> · 📅 <?= date('d M',strtotime($r['borrow_from'])) ?> → <?= date('d M',strtotime($r['borrow_until'])) ?><?php endif; ?>
                </div>
              </div>
              <div class="item-status">
                <span class="badge badge-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span>
                <div class="action-btns">
                  <a href="student_profile.php?id=<?= $r['lender_id'] ?>" class="btn-sm btn-profile">👤 Profile</a>
                  <?php if ($r['status'] === 'returned'): ?>
                    <button class="btn-sm btn-rate" onclick="openRateModal(<?= $r['lender_id'] ?>, '<?= addslashes(htmlspecialchars($r['lender_name'])) ?>', <?= $r['id'] ?>)">⭐ Rate</button>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

    </div><!-- /scroll-area -->
  </div><!-- /main -->
</div><!-- /layout -->

<!-- ── BORROW MODAL ── -->
<div class="modal-bg" id="borrowModal" onclick="closeBorrowModal(event)">
  <div class="modal-box">
    <div class="modal-head">
      <div class="modal-head-title" id="borrowModalTitle">Request to Borrow</div>
      <button class="modal-close" onclick="closeBorrowModalDirect()">✕</button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="action" value="request_borrow"/>
        <input type="hidden" name="listing_id" id="borrowListingId"/>
        <div class="fg">
          <label class="fl">Borrow Period</label>
          <div class="date-row">
            <div><label class="fl" style="margin-bottom:4px">From</label><input type="date" name="borrow_from" class="fc" required/></div>
            <div><label class="fl" style="margin-bottom:4px">Until</label><input type="date" name="borrow_until" class="fc" id="borrowUntil" required/></div>
          </div>
          <div id="daysHint" style="font-size:10.5px;color:var(--muted);margin-top:5px;"></div>
        </div>
        <div class="fg">
          <label class="fl">Message to Lender <span style="color:var(--muted);font-size:9px;text-transform:none;letter-spacing:0">(optional)</span></label>
          <textarea name="message" class="fc" placeholder="Tell the lender why you need it and how you'll take care of it…"></textarea>
        </div>
        <button type="submit" class="btn-sub-teal">Send Borrow Request →</button>
      </form>
    </div>
  </div>
</div>

<!-- ── RATE MODAL ── -->
<div class="modal-bg" id="rateModal" onclick="closeRateModal(event)">
  <div class="modal-box">
    <div class="modal-head">
      <div class="modal-head-title" id="rateModalTitle">Rate Lender</div>
      <button class="modal-close" onclick="closeRateModalDirect()">✕</button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="action" value="submit_rating"/>
        <input type="hidden" name="rated_id" id="rateUserId"/>
        <input type="hidden" name="ref_id" id="rateRefId"/>
        <input type="hidden" name="stars" id="rateStars" value="5"/>
        <div class="fg" style="text-align:center;">
          <label class="fl" style="text-align:center">How was the experience?</label>
          <div class="star-row" id="starRow">
            <span class="star-btn lit" data-v="1" onclick="setStar(1)">★</span>
            <span class="star-btn lit" data-v="2" onclick="setStar(2)">★</span>
            <span class="star-btn lit" data-v="3" onclick="setStar(3)">★</span>
            <span class="star-btn lit" data-v="4" onclick="setStar(4)">★</span>
            <span class="star-btn lit" data-v="5" onclick="setStar(5)">★</span>
          </div>
        </div>
        <div class="fg">
          <label class="fl">Comment <span style="color:var(--muted);font-size:9px;text-transform:none;letter-spacing:0">(optional)</span></label>
          <textarea name="comment" class="fc" placeholder="Share your experience with this lender…"></textarea>
        </div>
        <button type="submit" class="btn-sub-teal">Submit Rating →</button>
      </form>
    </div>
  </div>
</div>

<!-- ── APPROVE MODAL ── -->
<div class="modal-bg" id="approveModal" onclick="closeApproveModal(event)">
  <div class="modal-box" style="max-width:500px">
    <div class="modal-head">
      <div class="modal-head-title" id="approveModalTitle">Approve Request</div>
      <button class="modal-close" onclick="closeApproveModalDirect()">✕</button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="action" value="respond_request"/>
        <input type="hidden" name="new_status" value="approved"/>
        <input type="hidden" name="req_id" id="approveReqId"/>

        <div style="font-size:11px;color:var(--muted);margin-bottom:18px;padding:10px 14px;
                    background:rgba(14,116,144,.05);border:1px solid rgba(14,116,144,.15);
                    border-radius:10px;line-height:1.6;">
          📋 Fill in where and when the borrower should pick up the device, and where and when they must return it.
        </div>

        <div style="font-size:10px;font-weight:700;color:var(--bb);text-transform:uppercase;
                    letter-spacing:.08em;margin-bottom:10px;">📦 Pickup Details</div>
        <div class="fg">
          <label class="fl">Pickup Location</label>
          <input type="text" name="handover_place" class="fc" required
                 placeholder="e.g. Library Entrance, Block A Reception…"/>
        </div>
        <div class="fg">
          <label class="fl">Pickup Date &amp; Time</label>
          <input type="datetime-local" name="handover_time" class="fc" required
                 min="<?= date('Y-m-d\TH:i') ?>"/>
        </div>

        <div style="font-size:10px;font-weight:700;color:var(--coral);text-transform:uppercase;
                    letter-spacing:.08em;margin:18px 0 10px;">♻️ Return Details</div>
        <div class="fg">
          <label class="fl">Return Location</label>
          <input type="text" name="return_place" class="fc" required
                 placeholder="e.g. Same as pickup, Cafeteria…"/>
        </div>
        <div class="fg">
          <label class="fl">Return Deadline</label>
          <input type="datetime-local" name="return_deadline" class="fc" required
                 min="<?= date('Y-m-d\TH:i') ?>"/>
        </div>

        <button type="submit" class="btn-sub-teal">✓ Confirm Approval &amp; Notify Borrower →</button>
      </form>
    </div>
  </div>
</div>

<script>
// ── Tabs ──
function switchTab(id, btn) {
  document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.getElementById('panel-'+id).classList.add('active');
  btn.classList.add('active');
}

// ── Filter devices ──
function filterDevices() {
  const q = document.getElementById('deviceSearch').value.toLowerCase();
  const cat = document.getElementById('catFilter').value;
  let v = 0;
  document.querySelectorAll('.device-card').forEach(c => {
    const ok = c.dataset.title.includes(q) && (!cat || c.dataset.cat===cat);
    c.style.display = ok ? '' : 'none';
    if (ok) v++;
  });
  document.getElementById('noDevicesMsg').style.display = v===0?'block':'none';
}

// ── Borrow modal ──
let maxDays = 1;
function openBorrowModal(id, title, days) {
  maxDays = days;
  document.getElementById('borrowListingId').value = id;
  document.getElementById('borrowModalTitle').textContent = 'Request: ' + title;
  document.getElementById('daysHint').textContent = 'Max ' + days + ' day' + (days>1?'s':'') + ' allowed.';
  document.getElementById('borrowModal').classList.add('open');
}
function closeBorrowModal(e) { if(e.target===document.getElementById('borrowModal')) closeBorrowModalDirect(); }
function closeBorrowModalDirect() { document.getElementById('borrowModal').classList.remove('open'); }

// ── Rate modal ──
function openRateModal(uid, name, refId) {
  document.getElementById('rateUserId').value = uid;
  document.getElementById('rateRefId').value = refId;
  document.getElementById('rateModalTitle').textContent = 'Rate ' + name;
  setStar(5);
  document.getElementById('rateModal').classList.add('open');
}
function closeRateModal(e) { if(e.target===document.getElementById('rateModal')) closeRateModalDirect(); }
function closeRateModalDirect() { document.getElementById('rateModal').classList.remove('open'); }

function setStar(v) {
  document.getElementById('rateStars').value = v;
  document.querySelectorAll('.star-btn').forEach(s => {
    s.classList.toggle('lit', parseInt(s.dataset.v) <= v);
  });
}

document.addEventListener('keydown', e => {
  if(e.key==='Escape') {
    closeBorrowModalDirect();
    closeRateModalDirect();
    closeApproveModalDirect();
  }
});
// Auto-dismiss alerts
setTimeout(()=>{
  document.querySelectorAll('.alert').forEach(a=>{
    a.style.transition='opacity .4s'; a.style.opacity='0';
    setTimeout(()=>a.remove(),400);
  });
},4000);

// ── Notification panel ──
function toggleNotifPanel() {
  document.getElementById('notifPanel').classList.toggle('open');
}
document.addEventListener('click', function(e) {
  const wrap = document.getElementById('notifWrap');
  if (wrap && !wrap.contains(e.target)) {
    document.getElementById('notifPanel').classList.remove('open');
  }
});

// ── Approve modal ──
function openApproveModal(reqId, title) {
  document.getElementById('approveReqId').value = reqId;
  document.getElementById('approveModalTitle').textContent = 'Approve: ' + title;
  document.getElementById('approveModal').classList.add('open');
}
function closeApproveModal(e) {
  if (e.target === document.getElementById('approveModal')) closeApproveModalDirect();
}
function closeApproveModalDirect() {
  document.getElementById('approveModal').classList.remove('open');
}
</script>
</body>
</html>