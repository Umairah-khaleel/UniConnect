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

/* ═══════════════════════════════════════════
   CATEGORY → SMART QUESTION PRESETS
═══════════════════════════════════════════ */
$preset_questions = [
    'bottle'      => ['What color is the bottle?','Is it a straw bottle or a cap bottle?','What brand or design is on it?','Does it have any scratches or stickers?','Approximately what size is it (small/medium/large)?'],
    'bag'         => ['What color and type is the bag (backpack, tote, sling)?','What brand is on the bag?','Does it have any distinctive keychains or patches?','How many compartments does it have?','What was inside it when you lost it?'],
    'electronics' => ['What type of device is it?','What brand and model?','What color is it?','Does it have a case or cover? Describe it.','Is there any unique identifier (sticker, engraving)?'],
    'clothing'    => ['What type of clothing is it?','What color and size?','What brand is it?','Any distinctive prints, logos or damage?'],
    'keys'        => ['How many keys are on the keyring?','Is there a keychain? Describe it.','What do the keys unlock (home, car, locker)?','What color is the keyring?'],
    'stationery'  => ['What type of stationery is it?','What brand or color?','Is there a name written on it?','Any distinctive features?'],
    'id_card'     => ['What type of ID card is it (student, national, bank)?','What name appears on the card?','What institution/bank issued it?'],
    'wallet'      => ['What color and material is the wallet?','What brand is it?','What was inside it when lost?','Does it have any distinctive marks?'],
    'jewellery'   => ['What type of jewellery is it?','What metal/material (gold, silver, plastic)?','Does it have any engravings or gemstones?','Approximate size?'],
    'other'       => ['Describe the object in detail.','What color is it?','What size is it approximately?','Any unique markings or damage?','Where exactly did you lose it?'],
];

/* ═══════════════════════════════════════════
   POST ACTIONS
═══════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

/* ── MARK NOTIFICATIONS READ ── */
if ($_POST['action'] === 'mark_notifs_read') {
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$user_id]);
    $msg_success = 'All notifications marked as read.';
}

    /* ── POST A FOUND ITEM ── */
    if ($_POST['action'] === 'post_item') {
        $category     = $_POST['category'] ?? 'other';
        $title        = trim($_POST['title'] ?? '');
        $description  = trim($_POST['description'] ?? '');
        $location     = trim($_POST['location_found'] ?? '');
        $found_date   = $_POST['found_date'] ?? date('Y-m-d');
        $found_time   = trim($_POST['found_time'] ?? '');
        $image_url    = trim($_POST['image_url'] ?? '');
        $questions    = array_filter(array_map('trim', $_POST['questions'] ?? []));

        if (!$title || !$description || !$location) {
            $msg_error = 'Please fill in all required fields.';
        } elseif (count($questions) < 2) {
            $msg_error = 'Please add at least 2 verification questions so the owner can prove it\'s theirs.';
        } else {
            $pdo->prepare("INSERT INTO reclaim_items (finder_id,category,title,description,location_found,found_date,found_time,image_url)
                           VALUES(?,?,?,?,?,?,?,?)")
                ->execute([$user_id, $category, $title, $description, $location, $found_date, $found_time?:null, $image_url?:null]);
            $item_id = (int)$pdo->lastInsertId();
            $qstmt = $pdo->prepare("INSERT INTO reclaim_questions (item_id,question,sort_order) VALUES(?,?,?)");
            foreach (array_values($questions) as $i => $q) {
                $qstmt->execute([$item_id, $q, $i]);
            }
            $msg_success = '✓ Item posted! Students can now claim it.';
        }
    }

    /* ── SUBMIT A CLAIM ── */
    if ($_POST['action'] === 'submit_claim') {
        $item_id = (int)$_POST['item_id'];
        $answers = array_map('trim', $_POST['answers'] ?? []);

        // Verify item exists, is unclaimed, and doesn't belong to this user
        $item = $pdo->prepare("SELECT * FROM reclaim_items WHERE id=? AND status='unclaimed'");
        $item->execute([$item_id]);
        $item = $item->fetch();

        if (!$item) {
            $msg_error = 'This item is no longer available to claim.';
        } elseif ($item['finder_id'] == $user_id) {
            $msg_error = 'You cannot claim your own found item.';
        } else {
            // Check if already claimed by this user
            $exists = $pdo->prepare("SELECT id FROM reclaim_claims WHERE item_id=? AND claimer_id=?");
            $exists->execute([$item_id, $user_id]);
            if ($exists->fetch()) {
                $msg_error = 'You have already submitted a claim for this item.';
            } else {
                $pdo->prepare("INSERT INTO reclaim_claims (item_id,claimer_id,answers) VALUES(?,?,?)")
                    ->execute([$item_id, $user_id, json_encode($answers)]);
                // Mark item as claimed
                $pdo->prepare("UPDATE reclaim_items SET status='claimed' WHERE id=?")->execute([$item_id]);
                $msg_success = '✓ Claim submitted! The finder will review your answers shortly.';
            }
        }
    }

    /* ── CONFIRM OR REJECT CLAIM (finder action) ── */
    if ($_POST['action'] === 'respond_claim') {
        $claim_id  = (int)$_POST['claim_id'];
        $response  = $_POST['response'] === 'confirm' ? 'confirmed' : 'rejected';

        // Verify this user is the finder
        $c = $pdo->prepare("SELECT rc.*, ri.finder_id, ri.id AS item_id
            FROM reclaim_claims rc JOIN reclaim_items ri ON ri.id=rc.item_id WHERE rc.id=?");
        $c->execute([$claim_id]);
        $claim = $c->fetch();

        if (!$claim || $claim['finder_id'] != $user_id) {
            $msg_error = 'Unauthorized action.';
        } else {
            $pdo->prepare("UPDATE reclaim_claims SET status=?,responded_at=NOW() WHERE id=?")->execute([$response, $claim_id]);
            if ($response === 'rejected') {
                // Reopen for new claims
                $pdo->prepare("UPDATE reclaim_items SET status='unclaimed' WHERE id=?")->execute([$claim['item_id']]);
            }
            $msg_success = $response === 'confirmed' ? '✓ Claim confirmed! Schedule a pickup now.' : 'Claim rejected. The item is available for others to claim.';
        }
    }

    /* ── SET PICKUP DETAILS ── */
if ($_POST['action'] === 'set_pickup') {
    $claim_id     = (int)$_POST['claim_id'];
    $pickup_place = trim($_POST['pickup_place'] ?? '');
    $pickup_time  = $_POST['pickup_time'] ?? '';

    $c = $pdo->prepare("
        SELECT rc.*, ri.finder_id, ri.title AS item_title
        FROM reclaim_claims rc
        JOIN reclaim_items ri ON ri.id = rc.item_id
        WHERE rc.id = ? AND rc.status = 'confirmed'
    ");
    $c->execute([$claim_id]);
    $claim = $c->fetch();

    if (!$claim || $claim['finder_id'] != $user_id) {
        $msg_error = 'Unauthorized or claim not confirmed.';
    } elseif (!$pickup_place || !$pickup_time) {
        $msg_error = 'Please fill in both pickup location and time.';
    } else {
        $pdo->prepare("UPDATE reclaim_claims SET pickup_place=?, pickup_time=? WHERE id=?")
            ->execute([$pickup_place, $pickup_time, $claim_id]);

        // ── Send in-app notification to claimer ──
        $fmt_time  = date('d M Y \a\t g:i A', strtotime($pickup_time));
        $notif_msg = "Your claim for <strong>" . htmlspecialchars($claim['item_title']) . "</strong> has been confirmed! "
                   . "Please collect it from <strong>" . htmlspecialchars($pickup_place) . "</strong> on <strong>" . $fmt_time . "</strong>.";
        $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, link)
            VALUES (?, 'pickup_scheduled', 'Pickup Scheduled 📅', ?, 'reclaim.php')
        ")->execute([$claim['claimer_id'], $notif_msg]);

        $msg_success = '✓ Pickup details set! The claimer has been notified.';
    }
}

    /* ── MARK PICKUP COMPLETE ── */
    if ($_POST['action'] === 'complete_pickup') {
        $claim_id = (int)$_POST['claim_id'];
        $c = $pdo->prepare("SELECT rc.*, ri.finder_id, ri.id AS item_id FROM reclaim_claims rc JOIN reclaim_items ri ON ri.id=rc.item_id WHERE rc.id=?");
        $c->execute([$claim_id]);
        $claim = $c->fetch();
        if ($claim && ($claim['finder_id'] == $user_id || $claim['claimer_id'] == $user_id)) {
            $pdo->prepare("UPDATE reclaim_claims SET pickup_confirmed=1 WHERE id=?")->execute([$claim_id]);
            $pdo->prepare("UPDATE reclaim_items SET status='completed' WHERE id=?")->execute([$claim['item_id']]);
            $msg_success = '🎉 Item successfully returned! Please rate your experience.';
        }
    }

    /* ── SUBMIT RATING ── */
if ($_POST['action'] === 'rate_founder') {
    $claim_id = (int)$_POST['claim_id'];
    $stars    = max(1, min(5, (int)$_POST['stars']));
    $comment  = trim($_POST['comment'] ?? '');

    $c = $pdo->prepare("SELECT rc.*, ri.finder_id FROM reclaim_claims rc JOIN reclaim_items ri ON ri.id=rc.item_id WHERE rc.id=? AND rc.claimer_id=? AND rc.pickup_confirmed=1");
    $c->execute([$claim_id, $user_id]);
    $claim = $c->fetch();

    if (!$claim) {
        $msg_error = 'You can only rate after the item has been picked up.';
    } else {
        // Check using unified ratings table
        $exists = $pdo->prepare("SELECT id FROM ratings WHERE rater_id=? AND ref_id=? AND module='reclaim'");
        $exists->execute([$user_id, $claim_id]);
        if ($exists->fetch()) {
            $msg_error = 'You have already rated this finder.';
        } else {
            // Write to unified ratings table
            $pdo->prepare("INSERT INTO ratings (rater_id,rated_id,module,ref_id,stars,comment) VALUES(?,?,'reclaim',?,?,?)")
                ->execute([$user_id, $claim['finder_id'], $claim_id, $stars, $comment ?: null]);
            // Also keep reclaim_ratings in sync for backward compatibility
            $pdo->prepare("INSERT IGNORE INTO reclaim_ratings (claim_id,rater_id,rated_id,stars,comment) VALUES(?,?,?,?,?)")
                ->execute([$claim_id, $user_id, $claim['finder_id'], $stars, $comment ?: null]);
            $msg_success = '⭐ Thank you for your rating!';
        }
    }
}

    /* ── DELETE MY ITEM (if no active claims) ── */
    if ($_POST['action'] === 'delete_item') {
        $item_id = (int)$_POST['item_id'];
        $item = $pdo->prepare("SELECT * FROM reclaim_items WHERE id=? AND finder_id=?");
        $item->execute([$item_id, $user_id]);
        $item = $item->fetch();
        if ($item && $item['status'] === 'unclaimed') {
            $pdo->prepare("DELETE FROM reclaim_items WHERE id=?")->execute([$item_id]);
            $msg_success = 'Item removed.';
        } else {
            $msg_error = 'Cannot delete — item has active claims or is already completed.';
        }
    }
}

/* ═══════════════════════════════════════════
   FETCH DATA
═══════════════════════════════════════════ */

// All unclaimed items (excluding own posts)
$browse_items = $pdo->prepare("
    SELECT ri.*, u.full_name AS finder_name, u.student_id AS finder_sid,
           COUNT(rq.id) AS question_count,
           COALESCE(AVG(rr.stars),0) AS finder_rating,
           COUNT(DISTINCT rr.id) AS finder_rating_count
    FROM reclaim_items ri
    JOIN users u ON u.id = ri.finder_id
    LEFT JOIN reclaim_questions rq ON rq.item_id = ri.id
    LEFT JOIN reclaim_claims rc2 ON rc2.item_id = ri.id AND rc2.status='confirmed'
    LEFT JOIN reclaim_ratings rr ON rr.rated_id = ri.finder_id
    WHERE ri.status = 'unclaimed' AND ri.finder_id != :me
    GROUP BY ri.id ORDER BY ri.created_at DESC
");
$browse_items->execute([':me' => $user_id]);
$browse_items = $browse_items->fetchAll();

// My posted items (as finder)
$my_items = $pdo->prepare("
    SELECT ri.*,
           COUNT(DISTINCT rq.id) AS question_count,
           COUNT(DISTINCT rc.id) AS claim_count,
           SUM(CASE WHEN rc.status='pending' THEN 1 ELSE 0 END) AS pending_claims
    FROM reclaim_items ri
    LEFT JOIN reclaim_questions rq ON rq.item_id = ri.id
    LEFT JOIN reclaim_claims rc ON rc.item_id = ri.id
    WHERE ri.finder_id = ?
    GROUP BY ri.id ORDER BY ri.created_at DESC
");
$my_items->execute([$user_id]);
$my_items = $my_items->fetchAll();

// My claims (as claimer)
$my_claims = $pdo->prepare("
    SELECT rc.*, ri.title, ri.category, ri.location_found, ri.image_url, ri.status AS item_status,
           ri.finder_id, u.full_name AS finder_name, u.student_id AS finder_sid,
           rr.stars AS my_rating
    FROM reclaim_claims rc
    JOIN reclaim_items ri ON ri.id = rc.item_id
    JOIN users u ON u.id = ri.finder_id
    LEFT JOIN reclaim_ratings rr ON rr.claim_id = rc.id
    WHERE rc.claimer_id = ?
    ORDER BY rc.created_at DESC
");
$my_claims->execute([$user_id]);
$my_claims = $my_claims->fetchAll();

// Pending claims ON my items (finder view)
$pending_on_my_items = $pdo->prepare("
    SELECT rc.*, ri.title, ri.category, ri.id AS item_id,
           u.full_name AS claimer_name, u.student_id AS claimer_sid,
           rr.stars AS given_rating
    FROM reclaim_claims rc
    JOIN reclaim_items ri ON ri.id = rc.item_id
    JOIN users u ON u.id = rc.claimer_id
    LEFT JOIN reclaim_ratings rr ON rr.claim_id = rc.id
    WHERE ri.finder_id = ? AND rc.status IN ('pending','confirmed')
    ORDER BY rc.created_at DESC
");
$pending_on_my_items->execute([$user_id]);
$pending_on_my_items = $pending_on_my_items->fetchAll();

// Stats
$total_found    = (int)$pdo->query("SELECT COUNT(*) FROM reclaim_items WHERE status='unclaimed'")->fetchColumn();
$total_returned = (int)$pdo->query("SELECT COUNT(*) FROM reclaim_items WHERE status='completed'")->fetchColumn();
$my_found_count = count($my_items);
$my_rating_row  = $pdo->prepare("SELECT COALESCE(AVG(rr.stars),0) AS avg, COUNT(*) AS cnt FROM reclaim_ratings rr WHERE rr.rated_id=?");
$my_rating_row->execute([$user_id]);
$my_rating_row  = $my_rating_row->fetch();

// Fetch unread notifications for this user
$notifs = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 20");
$notifs->execute([$user_id]);
$notifs = $notifs->fetchAll();
$unread_count = count(array_filter($notifs, fn($n) => !$n['is_read']));

$category_icons = [
    'bag'=>'🎒','bottle'=>'🍶','electronics'=>'💻','clothing'=>'👕',
    'keys'=>'🔑','stationery'=>'✏️','id_card'=>'🪪','wallet'=>'👛',
    'jewellery'=>'💍','other'=>'📦'
];

function stars_html($rating, $max=5) {
    $html = '<span style="color:var(--amber);font-size:13px;letter-spacing:1px">';
    for ($i=1; $i<=$max; $i++) {
        $html .= $i <= round($rating) ? '★' : '☆';
    }
    $html .= '</span>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Reclaim — UniConnect</title>
<link rel="icon" type="image/png" href="icbt.png">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=Nunito:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --coral:#EE4540;--crimson:#C72C41;--wine:#801336;--plum:#510A32;--midnight:#2D142C;
  --bg:#f5eeee;--card:#ffffff;--text:#2D142C;--muted:#9a8080;--border:#eedede;
  --sidebar-w:230px;
  --amber:#d97706;--amber-light:#fef3c7;--amber-mid:#f59e0b;
  --green:#2e9e68;--green-light:rgba(46,158,104,0.08);
  --terracotta:#c2693a;--sand:#f0e6d3;
}
html,body{height:100%;font-family:'Nunito',sans-serif;background:var(--bg);color:var(--text);overflow:hidden}
.layout{display:grid;grid-template-columns:var(--sidebar-w) 1fr;height:100vh}

/* ── SIDEBAR (matches existing UniConnect style) ── */
.sidebar{background:linear-gradient(175deg,var(--midnight) 0%,var(--plum) 55%,var(--wine) 100%);
  display:flex;flex-direction:column;height:100vh;overflow:hidden;position:relative}
.sidebar::after{content:'';position:absolute;top:0;right:0;width:1.5px;height:100%;
  background:linear-gradient(to bottom,transparent,rgba(238,69,64,.5) 50%,transparent)}
.sb-brand{padding:26px 20px 18px;display:flex;align-items:center;gap:11px;
  border-bottom:1px solid rgba(255,255,255,.06);flex-shrink:0}
.brand-img{width:36px;height:36px;border-radius:50%;border:1.5px solid rgba(255,255,255,.25);
  overflow:hidden;flex-shrink:0;background:rgba(255,255,255,.1)}
.brand-img img{width:100%;height:100%;object-fit:cover}
.brand-name{font-family:'Cormorant Garamond',serif;font-size:16px;font-weight:600;color:#fff;letter-spacing:.02em;line-height:1.1}
.brand-sub{font-size:9px;color:rgba(255,255,255,.35);letter-spacing:.1em;text-transform:uppercase}
.nav-scroll{flex:1;overflow-y:auto;padding:14px 0}.nav-scroll::-webkit-scrollbar{display:none}
.nav-section-label{font-size:9px;letter-spacing:.2em;text-transform:uppercase;color:rgba(255,255,255,.25);font-weight:600;padding:10px 20px 6px}
.nav-item{display:flex;align-items:center;gap:11px;padding:10px 20px;color:rgba(255,255,255,.58);
  font-size:12.5px;font-weight:500;text-decoration:none;cursor:pointer;border-left:2.5px solid transparent;transition:all .25s ease}
.nav-item:hover{color:#fff;background:rgba(255,255,255,.06);border-left-color:rgba(238,69,64,.4)}
.nav-item.active{color:#fff;background:rgba(238,69,64,.14);border-left-color:var(--coral)}
.nav-item.active .ni{color:var(--coral)}
.ni{font-size:15px;width:18px;text-align:center;flex-shrink:0}
.nav-pill{margin-left:auto;background:var(--coral);color:#fff;font-size:8.5px;font-weight:700;padding:2px 7px;border-radius:50px}
.nav-pill.amber{background:var(--amber-mid)}
.sidebar-foot{border-top:1px solid rgba(255,255,255,.06);padding:14px 16px 20px;flex-shrink:0}
.user-chip{display:flex;align-items:center;gap:10px;padding:9px 11px;border-radius:10px;cursor:pointer;text-decoration:none;transition:background .25s}
.user-chip:hover{background:rgba(255,255,255,.08)}
.ava{width:32px;height:32px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,var(--coral),var(--crimson));
  display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;box-shadow:0 2px 8px rgba(238,69,64,.4)}
.user-chip-name{font-size:11.5px;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.user-chip-id{font-size:9.5px;color:rgba(255,255,255,.35)}

/* ── MAIN ── */
.main{display:flex;flex-direction:column;height:100vh;overflow:hidden}
.topbar{background:rgba(245,238,238,.92);backdrop-filter:blur(10px);border-bottom:1px solid var(--border);padding:0 32px;height:60px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;position:relative;z-index:100;overflow:visible;}  border-bottom:1px solid var(--border);padding:0 32px;height:60px;
  display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.page-title{font-family:'Cormorant Garamond',serif;font-size:20px;font-weight:600;color:var(--midnight)}
.topbar-right{display:flex;align-items:center;gap:10px}
.back-btn{display:inline-flex;align-items:center;gap:7px;padding:8px 18px;border:1px solid var(--border);
  border-radius:50px;font-size:12px;font-weight:600;color:var(--muted);text-decoration:none;transition:all .2s;background:var(--card)}
.back-btn:hover{border-color:var(--crimson);color:var(--crimson)}
.profile-chip{display:flex;align-items:center;gap:8px;padding:5px 12px 5px 6px;border:1px solid var(--border);border-radius:50px;background:var(--card);cursor:pointer}
.ava-sm{width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,var(--coral),var(--crimson));display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff}
.chip-name{font-size:12px;font-weight:600;color:var(--text)}
.scroll-area{flex:1;overflow-y:auto;padding:28px 32px 60px}
.scroll-area::-webkit-scrollbar{width:4px}
.scroll-area::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px}

/* ── ALERTS ── */
.alert{padding:14px 20px;border-radius:12px;margin-bottom:22px;font-size:12.5px;
  display:flex;align-items:center;gap:10px;font-weight:500;animation:fadeUp .3s ease}
.alert-success{background:rgba(46,158,104,.08);border:1px solid rgba(46,158,104,.22);color:var(--green)}
.alert-error{background:rgba(238,69,64,.08);border:1px solid rgba(238,69,64,.25);color:var(--coral)}

/* ── HERO ── */
.hero{background:linear-gradient(125deg,#1a0a0a 0%,#3d1a0a 40%,var(--terracotta) 80%,var(--amber-mid) 100%);
  border-radius:20px;overflow:hidden;margin-bottom:32px;min-height:200px;position:relative;
  display:grid;grid-template-columns:1fr 280px;animation:fadeUp .5s ease both}
.hero-deco-1{position:absolute;width:300px;height:300px;border-radius:50%;
  background:rgba(242,120,56,.08);top:-100px;left:40%;pointer-events:none}
.hero-deco-2{position:absolute;width:120px;height:120px;border-radius:50%;
  background:rgba(255,255,255,.03);bottom:-30px;left:5%;pointer-events:none}
.hero-text{padding:36px 32px;position:relative;z-index:1;display:flex;flex-direction:column;justify-content:center}
.hero-eyebrow{font-size:9px;letter-spacing:.22em;text-transform:uppercase;color:rgba(255,255,255,.4);font-weight:600;margin-bottom:10px}
.hero-title{font-family:'Cormorant Garamond',serif;font-size:34px;font-weight:600;color:#fff;line-height:1.1;margin-bottom:10px}
.hero-title em{color:var(--amber-mid);font-style:italic}
.hero-sub{font-size:12px;color:rgba(255,255,255,.45);font-weight:300;line-height:1.65;max-width:380px}
.hero-stats{display:flex;gap:0;margin-top:22px}
.hero-stat{text-align:center;padding:0 20px}.hero-stat:first-child{padding-left:0}
.hero-stat+.hero-stat{border-left:1px solid rgba(255,255,255,.1)}
.hero-stat-val{font-family:'Cormorant Garamond',serif;font-size:26px;font-weight:600;color:#fff;line-height:1}
.hero-stat-label{font-size:9px;color:rgba(255,255,255,.38);text-transform:uppercase;letter-spacing:.1em;margin-top:3px}
.hero-illus{display:flex;align-items:center;justify-content:center;padding:20px;font-size:80px;
  position:relative;z-index:1;opacity:.7}

/* ── TABS ── */
.tabs-row{display:flex;gap:6px;margin-bottom:28px;flex-wrap:wrap}
.tab-btn{display:flex;align-items:center;gap:8px;padding:11px 22px;border:1.5px solid var(--border);
  border-radius:50px;background:var(--card);color:var(--muted);font-family:'Nunito',sans-serif;
  font-size:12.5px;font-weight:600;cursor:pointer;transition:all .25s;white-space:nowrap}
.tab-btn:hover{border-color:rgba(194,105,58,.35);color:var(--terracotta)}
.tab-btn.active{background:linear-gradient(135deg,var(--terracotta),#9c4a1e);border-color:transparent;color:#fff;box-shadow:0 4px 16px rgba(194,105,58,.3)}
.tab-count{background:rgba(255,255,255,.22);padding:1px 8px;border-radius:50px;font-size:10px}
.tab-btn:not(.active) .tab-count{background:rgba(194,105,58,.1);color:var(--terracotta)}
.tab-panel{display:none;animation:fadeUp .35s ease both}
.tab-panel.active{display:block}

/* ── SECTION TITLE ── */
.section-title{font-family:'Cormorant Garamond',serif;font-size:18px;font-weight:600;color:var(--midnight);
  margin-bottom:18px;display:flex;align-items:center;gap:10px}
.section-title::before{content:'';width:3px;height:18px;border-radius:2px;
  background:linear-gradient(to bottom,var(--terracotta),var(--amber-mid));flex-shrink:0}

/* ── FILTER BAR ── */
.filter-bar{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap}
.search-wrap{flex:1;min-width:180px;position:relative}
.search-wrap input{width:100%;padding:10px 16px 10px 38px;background:var(--card);border:1px solid var(--border);
  border-radius:50px;color:var(--text);font-family:'Nunito',sans-serif;font-size:12px;outline:none;transition:border-color .2s}
.search-wrap input:focus{border-color:var(--terracotta)}
.search-wrap input::placeholder{color:var(--muted)}
.si{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px;pointer-events:none}
.fsel{padding:10px 34px 10px 16px;background:var(--card);border:1px solid var(--border);
  border-radius:50px;color:var(--text);font-family:'Nunito',sans-serif;font-size:12px;outline:none;
  cursor:pointer;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239a8080' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right 13px center}

/* ── ITEM CARDS (browse) ── */
.items-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px}
.item-card{background:var(--card);border:1px solid var(--border);border-radius:18px;overflow:hidden;
  transition:transform .3s,box-shadow .3s,border-color .3s;animation:fadeUp .4s ease both;display:flex;flex-direction:column;cursor:pointer}
.item-card:hover{transform:translateY(-4px);box-shadow:0 14px 34px rgba(45,20,44,.11);border-color:rgba(194,105,58,.3)}
.item-card-top{height:110px;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:center}
.item-card-top-bg{position:absolute;inset:0;background:linear-gradient(135deg,#1a0a0a,#3d1a0a 60%,var(--terracotta))}
.item-cat-icon{font-size:46px;position:relative;z-index:1;opacity:.85}
.item-card-top .status-chip{position:absolute;top:10px;right:10px;z-index:2;
  padding:3px 10px;border-radius:50px;font-size:9.5px;font-weight:700}
.sc-unclaimed{background:rgba(46,158,104,.15);color:#1a7a4e;border:1px solid rgba(46,158,104,.25)}
.sc-claimed{background:rgba(217,119,6,.15);color:#92580a;border:1px solid rgba(217,119,6,.25)}
.sc-completed{background:rgba(99,102,241,.15);color:#4338ca;border:1px solid rgba(99,102,241,.25)}
.item-body{padding:15px 17px;flex:1;display:flex;flex-direction:column;gap:8px}
.item-title{font-family:'Cormorant Garamond',serif;font-size:16px;font-weight:600;color:var(--midnight)}
.item-meta{display:flex;flex-wrap:wrap;gap:5px}
.imtag{padding:3px 10px;border-radius:50px;font-size:10px;font-weight:600}
.imt-loc{background:var(--sand);color:var(--terracotta)}
.imt-date{background:rgba(45,20,44,.06);color:var(--midnight)}
.imt-q{background:rgba(217,119,6,.08);color:var(--amber)}
.item-desc{font-size:11.5px;color:var(--muted);line-height:1.55;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.founder-row{display:flex;align-items:center;gap:8px;border-top:1px solid var(--border);padding-top:9px;margin-top:auto}
.f-ava{width:24px;height:24px;border-radius:50%;background:linear-gradient(135deg,var(--terracotta),var(--amber-mid));
  display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:#fff;flex-shrink:0}
.f-name{font-size:10.5px;font-weight:600;color:var(--text)}
.f-rating{margin-left:auto;font-size:11px;color:var(--amber)}

/* ── CLAIM MODAL ── */
.modal-overlay{position:fixed;inset:0;background:rgba(45,20,44,.5);backdrop-filter:blur(4px);
  z-index:1000;display:none;align-items:center;justify-content:center;padding:20px}
.modal-overlay.open{display:flex}
.modal{background:var(--card);border-radius:20px;width:100%;max-width:520px;max-height:88vh;
  overflow-y:auto;box-shadow:0 24px 60px rgba(0,0,0,.25);animation:slideUp .35s ease}
.modal::-webkit-scrollbar{width:4px}
.modal::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px}
.modal-head{background:linear-gradient(125deg,#1a0a0a,#3d1a0a 55%,var(--terracotta));
  padding:24px 26px;position:relative;overflow:hidden}
.modal-head-deco{position:absolute;width:140px;height:140px;border-radius:50%;
  background:rgba(242,120,56,.12);top:-40px;right:-20px}
.modal-cat-icon{font-size:32px;margin-bottom:8px;display:block;position:relative;z-index:1}
.modal-title{font-family:'Cormorant Garamond',serif;font-size:20px;font-weight:600;color:#fff;
  margin-bottom:4px;position:relative;z-index:1}
.modal-subtitle{font-size:11px;color:rgba(255,255,255,.45);position:relative;z-index:1}
.modal-close{position:absolute;top:16px;right:16px;width:28px;height:28px;border-radius:50%;
  background:rgba(255,255,255,.12);border:none;color:#fff;font-size:14px;cursor:pointer;
  display:flex;align-items:center;justify-content:center;transition:background .2s;z-index:2}
.modal-close:hover{background:rgba(255,255,255,.2)}
.modal-body{padding:24px 26px}
.info-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:18px}
.info-box{background:var(--sand);border-radius:10px;padding:11px 14px}
.info-label{font-size:9px;font-weight:700;color:var(--terracotta);text-transform:uppercase;letter-spacing:.1em;margin-bottom:3px}
.info-value{font-size:12px;font-weight:600;color:var(--midnight)}
.modal-desc-title{font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;margin-bottom:7px}
.modal-desc{font-size:12.5px;color:var(--text);line-height:1.6;margin-bottom:20px;
  padding:13px;background:var(--bg);border-radius:10px;border:1px solid var(--border)}
.questions-section{margin-bottom:20px}
.q-intro{font-size:12px;color:var(--muted);margin-bottom:14px;line-height:1.5;
  padding:11px 14px;background:rgba(217,119,6,.06);border:1px solid rgba(217,119,6,.15);border-radius:10px}
.q-item{margin-bottom:14px}
.q-text{font-size:12px;font-weight:700;color:var(--midnight);margin-bottom:6px;display:flex;gap:7px;align-items:flex-start}
.q-num{width:20px;height:20px;border-radius:50%;background:linear-gradient(135deg,var(--terracotta),var(--amber-mid));
  display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:800;color:#fff;flex-shrink:0;margin-top:1px}
.q-input{width:100%;padding:9px 13px;background:var(--bg);border:1.5px solid var(--border);
  border-radius:9px;color:var(--text);font-family:'Nunito',sans-serif;font-size:12px;
  outline:none;transition:border-color .2s;resize:none}
.q-input:focus{border-color:var(--terracotta)}
.modal-submit-btn{width:100%;padding:13px;border:none;border-radius:12px;
  background:linear-gradient(135deg,var(--terracotta),#9c4a1e);color:#fff;
  font-family:'Nunito',sans-serif;font-size:13px;font-weight:700;cursor:pointer;
  transition:all .25s;box-shadow:0 4px 16px rgba(194,105,58,.3)}
.modal-submit-btn:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(194,105,58,.4)}

/* ── POST ITEM FORM ── */
.post-grid{display:grid;grid-template-columns:1fr 340px;gap:22px;align-items:start}
.form-card{background:var(--card);border:1px solid var(--border);border-radius:18px;overflow:hidden}
.form-card-head{background:linear-gradient(125deg,#1a0a0a,#3d1a0a 55%,var(--terracotta));
  padding:22px;position:relative;overflow:hidden;display:grid;grid-template-columns:1fr 120px;min-height:120px}
.fch-deco{position:absolute;width:130px;height:130px;border-radius:50%;background:rgba(242,120,56,.1);top:-35px;right:-20px}
.fch-text{position:relative;z-index:1;display:flex;flex-direction:column;justify-content:center}
.fch-title{font-family:'Cormorant Garamond',serif;font-size:20px;font-weight:600;color:#fff;margin-bottom:5px}
.fch-sub{font-size:11px;color:rgba(255,255,255,.42);font-weight:300;line-height:1.5}
.fch-illus{display:flex;align-items:center;justify-content:center;font-size:50px;opacity:.6;position:relative;z-index:1}
.form-body{padding:22px}
.fg{margin-bottom:14px}
.fl{display:block;font-size:9.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px}
.fc{width:100%;padding:10px 13px;background:var(--bg);border:1px solid var(--border);
  border-radius:10px;color:var(--text);font-family:'Nunito',sans-serif;font-size:12.5px;
  outline:none;transition:border-color .2s,box-shadow .2s;appearance:none}
.fc:focus{border-color:var(--terracotta);box-shadow:0 0 0 3px rgba(194,105,58,.08)}
textarea.fc{resize:vertical;min-height:78px;line-height:1.6}
.fc-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.cat-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:7px}
.cat-opt input{display:none}
.cat-opt label{display:flex;flex-direction:column;align-items:center;gap:3px;padding:9px 4px;
  border:1.5px solid var(--border);border-radius:10px;cursor:pointer;transition:all .2s;
  font-size:9.5px;font-weight:600;color:var(--muted);text-align:center}
.cat-opt label .ico{font-size:20px}
.cat-opt input:checked+label{border-color:var(--terracotta);background:rgba(194,105,58,.07);color:var(--terracotta)}
.cat-opt label:hover{border-color:rgba(194,105,58,.3);color:var(--terracotta)}
.btn-main{width:100%;padding:13px;border:none;border-radius:12px;
  background:linear-gradient(135deg,var(--terracotta),#9c4a1e);color:#fff;
  font-family:'Nunito',sans-serif;font-size:13px;font-weight:700;cursor:pointer;
  transition:all .25s;box-shadow:0 4px 16px rgba(194,105,58,.25)}
.btn-main:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(194,105,58,.38)}

/* ── QUESTIONS BUILDER ── */
.q-builder{border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:14px}
.q-builder-head{padding:11px 14px;background:var(--sand);display:flex;align-items:center;justify-content:space-between}
.q-builder-title{font-size:11px;font-weight:700;color:var(--terracotta)}
.q-list{padding:12px 14px;display:flex;flex-direction:column;gap:8px}
.q-row{display:flex;gap:8px;align-items:center}
.q-row input{flex:1;padding:8px 12px;background:var(--bg);border:1px solid var(--border);
  border-radius:8px;font-family:'Nunito',sans-serif;font-size:12px;color:var(--text);outline:none;transition:border-color .2s}
.q-row input:focus{border-color:var(--terracotta)}
.q-row input::placeholder{color:var(--muted)}
.q-del{background:none;border:none;color:var(--muted);font-size:15px;cursor:pointer;padding:4px;transition:color .2s;flex-shrink:0}
.q-del:hover{color:var(--coral)}
.add-q-btn{display:flex;align-items:center;gap:7px;padding:8px 14px;background:none;
  border:1.5px dashed rgba(194,105,58,.35);border-radius:9px;color:var(--terracotta);
  font-family:'Nunito',sans-serif;font-size:12px;font-weight:600;cursor:pointer;width:100%;
  justify-content:center;transition:all .2s;margin-top:4px}
.add-q-btn:hover{background:rgba(194,105,58,.05);border-color:var(--terracotta)}
.preset-btns{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
.preset-btn{padding:5px 12px;border:1px solid rgba(194,105,58,.25);border-radius:50px;
  background:none;font-family:'Nunito',sans-serif;font-size:10.5px;font-weight:600;
  color:var(--terracotta);cursor:pointer;transition:all .2s}
.preset-btn:hover{background:rgba(194,105,58,.08);border-color:var(--terracotta)}

/* ── MY ACTIVITY TABLES ── */
.activity-card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;margin-bottom:22px}
.activity-card-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.ac-title{font-family:'Cormorant Garamond',serif;font-size:16px;font-weight:600;color:var(--midnight);
  display:flex;align-items:center;gap:8px}
.ac-title::before{content:'';width:3px;height:16px;border-radius:2px;background:linear-gradient(to bottom,var(--terracotta),var(--amber-mid))}
table{width:100%;border-collapse:collapse;font-size:12px}
thead tr{background:var(--bg);border-bottom:1px solid var(--border)}
th{padding:11px 18px;text-align:left;font-size:9px;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);font-weight:700}
td{padding:12px 18px;border-bottom:1px solid rgba(238,222,222,.35);vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:rgba(194,105,58,.015)}
.td-title{font-weight:700;color:var(--midnight);font-size:12.5px}
.td-sub{font-size:10px;color:var(--muted);margin-top:1px}

/* ── BADGES ── */
.badge{display:inline-block;padding:3px 11px;border-radius:50px;font-size:10px;font-weight:700}
.b-unclaimed{background:rgba(46,158,104,.1);color:var(--green)}
.b-claimed{background:rgba(217,119,6,.1);color:var(--amber)}
.b-completed{background:rgba(99,102,241,.1);color:#6366f1}
.b-pending{background:rgba(238,69,64,.08);color:var(--coral)}
.b-confirmed{background:rgba(46,158,104,.1);color:var(--green)}
.b-rejected{background:rgba(154,128,128,.1);color:var(--muted)}
.b-expired{background:rgba(154,128,128,.1);color:var(--muted)}

/* ── CLAIM REVIEW CARD ── */
.claim-review-card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;margin-bottom:14px;animation:fadeUp .3s ease both}
.crc-head{padding:14px 18px;background:var(--bg);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px}
.crc-icon{font-size:22px}
.crc-info{flex:1}
.crc-title{font-weight:700;font-size:13px;color:var(--midnight)}
.crc-sub{font-size:10.5px;color:var(--muted)}
.crc-status{flex-shrink:0}
.crc-body{padding:16px 18px}
.qa-list{display:flex;flex-direction:column;gap:10px;margin-bottom:16px}
.qa-item{}
.qa-q{font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px}
.qa-a{font-size:13px;color:var(--midnight);padding:9px 12px;background:var(--bg);border-radius:8px;border:1px solid var(--border);line-height:1.5}
.action-row{display:flex;gap:9px;margin-top:14px}
.btn-confirm{flex:1;padding:10px;border:none;border-radius:10px;
  background:linear-gradient(135deg,var(--green),#1e7a50);color:#fff;
  font-family:'Nunito',sans-serif;font-size:12.5px;font-weight:700;cursor:pointer;transition:all .25s;
  box-shadow:0 4px 12px rgba(46,158,104,.2)}
.btn-confirm:hover{transform:translateY(-2px);box-shadow:0 7px 18px rgba(46,158,104,.3)}
.btn-reject{flex:1;padding:10px;border:1px solid rgba(238,69,64,.25);border-radius:10px;
  background:rgba(238,69,64,.06);color:var(--coral);
  font-family:'Nunito',sans-serif;font-size:12.5px;font-weight:700;cursor:pointer;transition:all .25s}
.btn-reject:hover{background:rgba(238,69,64,.12)}

/* ── PICKUP SCHEDULER ── */
.pickup-card{background:linear-gradient(135deg,rgba(46,158,104,.05),rgba(46,158,104,.02));
  border:1px solid rgba(46,158,104,.2);border-radius:14px;padding:18px 20px;margin-top:12px}
.pickup-title{font-size:12px;font-weight:700;color:var(--green);margin-bottom:12px;display:flex;align-items:center;gap:7px}
.pickup-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px}
.pickup-input{padding:9px 12px;background:var(--card);border:1px solid rgba(46,158,104,.2);
  border-radius:9px;font-family:'Nunito',sans-serif;font-size:12px;color:var(--text);
  outline:none;transition:border-color .2s;width:100%}
.pickup-input:focus{border-color:var(--green)}
.btn-pickup{width:100%;padding:10px;border:none;border-radius:9px;
  background:linear-gradient(135deg,var(--green),#1e7a50);color:#fff;
  font-family:'Nunito',sans-serif;font-size:12px;font-weight:700;cursor:pointer;transition:all .25s}
.btn-pickup:hover{transform:translateY(-1px)}

/* ── PICKUP INFO (claimer view) ── */
.pickup-info-box{background:rgba(46,158,104,.06);border:1px solid rgba(46,158,104,.2);
  border-radius:12px;padding:14px 16px;margin-top:10px}
.pib-label{font-size:9px;font-weight:700;color:var(--green);text-transform:uppercase;letter-spacing:.1em;margin-bottom:8px}
.pib-row{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--midnight);margin-bottom:5px}
.btn-complete{padding:8px 18px;border:none;border-radius:9px;background:var(--green);color:#fff;
  font-family:'Nunito',sans-serif;font-size:12px;font-weight:700;cursor:pointer;margin-top:10px;transition:all .2s}
.btn-complete:hover{transform:translateY(-1px)}

/* ── STAR RATING ── */
.rating-card{background:linear-gradient(135deg,rgba(217,119,6,.06),rgba(240,230,211,.4));
  border:1px solid rgba(217,119,6,.2);border-radius:14px;padding:18px 20px;margin-top:12px}
.rating-title{font-size:12px;font-weight:700;color:var(--amber);margin-bottom:14px;display:flex;align-items:center;gap:7px}
.star-picker{display:flex;gap:8px;margin-bottom:12px}
.star-picker input{display:none}
.star-picker label{font-size:28px;color:var(--border);cursor:pointer;transition:color .15s;line-height:1}
.star-picker input:checked~label,.star-picker label:hover,.star-picker label:hover~label{color:var(--amber-mid)}
.star-picker{direction:rtl}
.star-picker label:hover,.star-picker label:hover~label{color:var(--amber-mid)}
.rating-comment{width:100%;padding:9px 12px;background:var(--card);border:1.5px solid rgba(217,119,6,.2);
  border-radius:9px;font-family:'Nunito',sans-serif;font-size:12px;color:var(--text);
  outline:none;resize:none;min-height:55px;transition:border-color .2s;margin-bottom:10px}
.rating-comment:focus{border-color:var(--amber-mid)}
.btn-rate{width:100%;padding:10px;border:none;border-radius:9px;
  background:linear-gradient(135deg,var(--amber-mid),#b45309);color:#fff;
  font-family:'Nunito',sans-serif;font-size:12.5px;font-weight:700;cursor:pointer;
  box-shadow:0 4px 14px rgba(217,119,6,.25);transition:all .25s}
.btn-rate:hover{transform:translateY(-1px);box-shadow:0 7px 18px rgba(217,119,6,.35)}

/* ── EMPTY STATE ── */
.empty{text-align:center;padding:52px 20px;background:var(--card);border:1px solid var(--border);border-radius:18px;margin-bottom:20px}
.empty-ico{width:90px;height:90px;border-radius:50%;background:rgba(194,105,58,.06);
  border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:34px;margin:0 auto 14px}
.empty-title{font-family:'Cormorant Garamond',serif;font-size:18px;font-weight:600;color:var(--midnight);margin-bottom:5px}
.empty-sub{font-size:12px;color:var(--muted);font-weight:300}

/* ── MY RATING DISPLAY ── */
.my-rating-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:20px;margin-bottom:22px;
  display:flex;align-items:center;gap:16px}
.mr-stars{font-size:28px;color:var(--amber-mid);letter-spacing:3px;line-height:1}
.mr-avg{font-family:'Cormorant Garamond',serif;font-size:36px;font-weight:600;color:var(--midnight);line-height:1}
.mr-label{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;margin-top:3px}
.mr-count{font-size:12px;color:var(--muted)}

/* ── INFO CARD (how it works) ── */
.info-card{background:var(--card);border:1px solid var(--border);border-radius:18px;overflow:hidden}
.info-card-head{background:linear-gradient(125deg,#1a0a0a,#3d1a0a 55%,var(--terracotta));
  padding:20px 22px;position:relative;overflow:hidden}
.ich-deco{position:absolute;width:120px;height:120px;border-radius:50%;background:rgba(242,120,56,.1);top:-30px;right:-15px}
.ich-title{font-family:'Cormorant Garamond',serif;font-size:17px;font-weight:600;color:#fff;margin-bottom:3px;position:relative;z-index:1}
.ich-sub{font-size:10px;color:rgba(255,255,255,.38);position:relative;z-index:1}
.info-card-body{padding:20px 22px}
.istep{display:flex;gap:12px;align-items:flex-start;margin-bottom:13px}.istep:last-child{margin-bottom:0}
.snum{width:24px;height:24px;border-radius:50%;flex-shrink:0;
  background:linear-gradient(135deg,var(--terracotta),var(--amber-mid));
  display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;margin-top:1px}
.stxt{font-size:12px;color:var(--text);line-height:1.55}.stxt strong{color:var(--midnight)}
.tip-box{margin-top:14px;padding:11px 14px;background:rgba(217,119,6,.06);
  border:1px solid rgba(217,119,6,.15);border-radius:10px;font-size:11.5px;color:var(--amber);line-height:1.5}

/* ── NOTIFICATIONS BADGE ON TABS ── */
.notif-dot{width:8px;height:8px;border-radius:50%;background:var(--coral);flex-shrink:0}

@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
@keyframes slideUp{from{opacity:0;transform:translateY(24px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}

/* ── NOTIFICATION BELL ── */
.notif-bell-wrap{position:relative}
.notif-bell{background:var(--card);border:1px solid var(--border);border-radius:50px;
  padding:7px 13px;font-size:16px;cursor:pointer;position:relative;transition:border-color .2s;
  display:flex;align-items:center;gap:0}
.notif-bell:hover{border-color:var(--terracotta)}
.notif-badge{position:absolute;top:-5px;right:-5px;background:var(--coral);color:#fff;
  font-size:9px;font-weight:800;min-width:17px;height:17px;border-radius:50px;
  display:flex;align-items:center;justify-content:center;padding:0 4px;
  border:1.5px solid var(--bg);font-family:'Nunito',sans-serif}
.notif-panel{position:absolute;top:calc(100% + 10px);right:0;width:330px;
  background:var(--card);border:1px solid var(--border);border-radius:16px;
  box-shadow:0 12px 36px rgba(45,20,44,.14);z-index: 9999;display:none;
  max-height:400px;overflow-y:auto;animation:fadeUp .25s ease}
.notif-panel.open{display:block}
.notif-panel::-webkit-scrollbar{width:3px}
.notif-panel::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px}
.notif-panel-head{padding:13px 16px;border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;
  font-size:12px;font-weight:700;color:var(--midnight);position:sticky;top:0;background:var(--card)}
.notif-mark-all{background:none;border:none;color:var(--terracotta);font-size:10.5px;
  font-weight:700;cursor:pointer;font-family:'Nunito',sans-serif;padding:0}
.notif-mark-all:hover{text-decoration:underline}
.notif-empty{padding:32px 20px;text-align:center;font-size:12px;color:var(--muted)}
.notif-item{padding:13px 16px;border-bottom:1px solid rgba(238,222,222,.4);transition:background .2s}
.notif-item:last-child{border-bottom:none}
.notif-item.unread{background:rgba(194,105,58,.04)}
.notif-item.unread .notif-item-title::before{content:'● ';color:var(--terracotta);font-size:8px;vertical-align:middle}
.notif-item-title{font-size:12px;font-weight:700;color:var(--midnight);margin-bottom:4px}
.notif-item-msg{font-size:11.5px;color:var(--text);line-height:1.55;margin-bottom:5px}
.notif-item-time{font-size:10px;color:var(--muted)}
</style>
</head>
<body>
<div class="layout">

<!-- ── SIDEBAR ── -->
<aside class="sidebar">
  <div class="sb-brand">
    <div class="brand-img"><img src="icbt_logo.png" alt="ICBT"></div>
    <div><div class="brand-name">UniConnect</div><div class="brand-sub">ICBT Campus</div></div>
  </div>
  <div class="nav-scroll">
    <div class="nav-section-label">Main</div>
    <a class="nav-item" href="student_dashboard.php"><span class="ni">🏠</span> Dashboard</a>
    <div class="nav-section-label" style="margin-top:8px">Services</div>
    <a class="nav-item" href="student_link.php"><span class="ni">🔗</span> Student Link</a>
    <a class="nav-item" href="burrow_buddy.php"><span class="ni">📚</span> Burrow Buddy</a>
    <a class="nav-item active" href="reclaim.php"><span class="ni">♻️</span> Reclaim</a>
    <a class="nav-item" href="brain_bridge.php"><span class="ni">🧠</span> Brain Bridge</a>
    <div class="nav-section-label" style="margin-top:8px">Account</div>
    <a class="nav-item" href="student_profile.php"><span class="ni">👤</span> My Profile</a>
    <a class="nav-item" href="auth/logout.php"><span class="ni">🚪</span> Sign Out</a>
  </div>
  <div class="sidebar-foot">
    <div class="user-chip">
      <div class="ava"><?= $initials ?></div>
      <div><div class="user-chip-name"><?= $name ?></div><div class="user-chip-id"><?= $student_id ?></div></div>
    </div>
  </div>
</aside>

<!-- ── MAIN ── -->
<!-- ✅ FIXED -->
 <div class="main">
<div class="topbar">
  <div class="page-title">♻️ Reclaim — Lost &amp; Found</div>
  <div class="topbar-right">
    <a href="student_dashboard.php" class="back-btn">← Back to Dashboard</a>

    <div class="notif-bell-wrap" id="notifWrap">
      <button class="notif-bell" onclick="toggleNotifPanel()" title="Notifications">
        🔔
        <?php if ($unread_count > 0): ?>
          <span class="notif-badge"><?= $unread_count ?></span>
        <?php endif; ?>
      </button>
      <div class="notif-panel" id="notifPanel">
        <div class="notif-panel-head">
          <span>Notifications</span>
          <?php if ($unread_count > 0): ?>
            <form method="POST" style="margin:0">
              <input type="hidden" name="action" value="mark_notifs_read"/>
              <button type="submit" class="notif-mark-all">Mark all read</button>
            </form>
          <?php endif; ?>
        </div>
        <?php if (empty($notifs)): ?>
          <div class="notif-empty">No notifications yet</div>
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

    <div class="profile-chip">
      <div class="ava-sm"><?= $initials ?></div>
      <span class="chip-name"><?= $first ?></span>
    </div>
  </div><!-- /topbar-right -->
</div><!-- /topbar -->
 
<div class="scroll-area">   <!-- ← now correctly AFTER the topbar -->
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
        <div class="hero-eyebrow">Reclaim · Lost &amp; Found</div>
        <div class="hero-title">Someone found it.<br/><em>Let's reunite it.</em></div>
        <div class="hero-sub">If you found something left behind, post it here. If you lost something, browse and prove it's yours by answering verification questions only the real owner would know.</div>
        <div class="hero-stats">
          <div class="hero-stat">
            <div class="hero-stat-val"><?= $total_found ?></div>
            <div class="hero-stat-label">Waiting to be claimed</div>
          </div>
          <div class="hero-stat">
            <div class="hero-stat-val"><?= $total_returned ?></div>
            <div class="hero-stat-label">Successfully returned</div>
          </div>
          <?php if ($my_rating_row['cnt'] > 0): ?>
          <div class="hero-stat">
            <div class="hero-stat-val"><?= number_format($my_rating_row['avg'],1) ?>⭐</div>
            <div class="hero-stat-label">Your finder rating</div>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <div class="hero-illus">♻️</div>
    </div>

    <!-- TABS -->
    <div class="tabs-row">
      <button class="tab-btn active" onclick="switchTab('browse',this)">
        🔍 Browse Found Items <span class="tab-count"><?= count($browse_items) ?></span>
      </button>
      <button class="tab-btn" onclick="switchTab('post',this)">
        📍 I Found Something
      </button>
      <button class="tab-btn" onclick="switchTab('my-items',this)">
        📋 My Posted Items
        <?php if (array_sum(array_column($my_items,'pending_claims')) > 0): ?>
          <span class="tab-count"><?= array_sum(array_column($my_items,'pending_claims')) ?></span>
        <?php endif; ?>
      </button>
      <button class="tab-btn" onclick="switchTab('my-claims',this)">
        🙋 My Claims
        <?php if (!empty($my_claims)): ?>
          <span class="tab-count"><?= count($my_claims) ?></span>
        <?php endif; ?>
      </button>
    </div>

    <!-- ══════════ TAB: BROWSE ══════════ -->
    <div class="tab-panel active" id="panel-browse">
      <div class="filter-bar">
        <div class="search-wrap">
          <span class="si">🔍</span>
          <input type="text" id="itemSearch" placeholder="Search by title or location…" oninput="filterItems()"/>
        </div>
        <select class="fsel" id="catFilter" onchange="filterItems()">
          <option value="">All categories</option>
          <option value="bag">🎒 Bag</option>
          <option value="bottle">🍶 Bottle</option>
          <option value="electronics">💻 Electronics</option>
          <option value="clothing">👕 Clothing</option>
          <option value="keys">🔑 Keys</option>
          <option value="stationery">✏️ Stationery</option>
          <option value="id_card">🪪 ID Card</option>
          <option value="wallet">👛 Wallet</option>
          <option value="jewellery">💍 Jewellery</option>
          <option value="other">📦 Other</option>
        </select>
      </div>

      <?php if (empty($browse_items)): ?>
        <div class="empty">
          <div class="empty-ico">🔍</div>
          <div class="empty-title">Nothing to claim right now</div>
          <div class="empty-sub">All found items have been returned. Check back later!</div>
        </div>
      <?php else: ?>
        <div class="items-grid" id="itemsGrid">
          <?php foreach ($browse_items as $i => $item):
            $icon = $category_icons[$item['category']] ?? '📦';
            $fw = array_filter(explode(' ', trim($item['finder_name'])));
            $fi = strtoupper(substr($fw[0]??'F',0,1).substr(end($fw)??'',0,1));
          ?>
          <div class="item-card"
               style="animation-delay:<?= $i * 0.06 ?>s"
               data-title="<?= strtolower(htmlspecialchars($item['title'])) ?>"
               data-loc="<?= strtolower(htmlspecialchars($item['location_found'])) ?>"
               data-cat="<?= $item['category'] ?>"
               onclick="openClaimModal(<?= $item['id'] ?>)">
            <div class="item-card-top">
              <div class="item-card-top-bg"></div>
              <div class="item-cat-icon"><?= $icon ?></div>
              <div class="status-chip sc-unclaimed">Available</div>
            </div>
            <div class="item-body">
              <div class="item-title"><?= htmlspecialchars($item['title']) ?></div>
              <div class="item-meta">
                <span class="imtag imt-loc">📍 <?= htmlspecialchars($item['location_found']) ?></span>
                <span class="imtag imt-date">📅 <?= date('d M Y', strtotime($item['found_date'])) ?><?= $item['found_time'] ? ' · '.$item['found_time'] : '' ?></span>
                <span class="imtag imt-q">❓ <?= $item['question_count'] ?> questions</span>
              </div>
              <div class="item-desc"><?= htmlspecialchars($item['description']) ?></div>
              <div class="founder-row">
                <div class="f-ava"><?= $fi ?></div>
                <div>
                  <div class="f-name"><?= htmlspecialchars($item['finder_name']) ?></div>
                </div>
                <?php if ($item['finder_rating_count'] > 0): ?>
                  <div class="f-rating">
                    <?= str_repeat('★', round($item['finder_rating'])) ?><?= str_repeat('☆', 5-round($item['finder_rating'])) ?>
                    <span style="color:var(--muted);font-size:9.5px">(<?= $item['finder_rating_count'] ?>)</span>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="empty" id="noItemsMsg" style="display:none">
          <div class="empty-ico">🔎</div>
          <div class="empty-title">No matches found</div>
          <div class="empty-sub">Try adjusting your search or filter.</div>
        </div>
      <?php endif; ?>
    </div><!-- /browse -->

    <!-- ══════════ TAB: POST ITEM ══════════ -->
    <div class="tab-panel" id="panel-post">
      <div class="post-grid">
        <div class="form-card">
          <div class="form-card-head">
            <div class="fch-deco"></div>
            <div class="fch-text">
              <div class="fch-title">Report a Found Item</div>
              <div class="fch-sub">Describe what you found and where. Add smart questions only the true owner can answer.</div>
            </div>
            <div class="fch-illus">📍</div>
          </div>
          <div class="form-body">
            <form method="POST" id="postForm">
              <input type="hidden" name="action" value="post_item"/>

              <div class="fg">
                <label class="fl">Category</label>
                <div class="cat-grid">
                  <?php foreach ($category_icons as $cat => $ico): ?>
                  <div class="cat-opt">
                    <input type="radio" name="category" id="cat_<?= $cat ?>" value="<?= $cat ?>"
                           <?= $cat==='other'?'checked':'' ?>
                           onchange="loadPresets('<?= $cat ?>')"/>
                    <label for="cat_<?= $cat ?>">
                      <span class="ico"><?= $ico ?></span>
                      <?= ucfirst(str_replace('_',' ',$cat)) ?>
                    </label>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>

              <div class="fg">
                <label class="fl" for="item_title">Item Title / Short Description</label>
                <input type="text" name="title" id="item_title" class="fc"
                       placeholder="e.g. Blue Nalgene Water Bottle, Grey Laptop Bag…" required/>
              </div>

              <div class="fg">
                <label class="fl" for="item_desc">Full Description</label>
                <textarea name="description" id="item_desc" class="fc" required
                  placeholder="Describe exactly what you found — color, size, condition, brand, anything notable…"></textarea>
              </div>

              <div class="fc-row">
                <div class="fg" style="margin-bottom:0">
                  <label class="fl" for="loc_found">Where was it found?</label>
                  <input type="text" name="location_found" id="loc_found" class="fc"
                         placeholder="e.g. Library 2nd floor, Cafeteria table 4…" required/>
                </div>
                <div class="fg" style="margin-bottom:0">
                  <label class="fl" for="found_date">Date Found</label>
                  <input type="date" name="found_date" id="found_date" class="fc"
                         value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required/>
                </div>
              </div>

              <div class="fg" style="margin-top:14px">
                <label class="fl" for="found_time">Time Found <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:9px">(optional)</span></label>
                <input type="text" name="found_time" id="found_time" class="fc" placeholder="e.g. Around 2:30 PM"/>
              </div>

              <div class="fg">
                <label class="fl" for="image_url">Image URL <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:9px">(optional)</span></label>
                <input type="url" name="image_url" id="image_url" class="fc" placeholder="https://…"/>
              </div>

              <!-- QUESTIONS BUILDER -->
              <div class="fg">
                <label class="fl">Verification Questions <span style="color:var(--coral)">*</span></label>
                <p style="font-size:11.5px;color:var(--muted);margin-bottom:10px;line-height:1.55">
                  Add questions only the real owner can answer. This protects against false claims.
                  <strong style="color:var(--terracotta)">Minimum 2 required.</strong>
                </p>

                <div style="margin-bottom:10px">
                  <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:7px">
                    💡 Quick-load presets for this category:
                  </div>
                  <div class="preset-btns" id="presetBtns"></div>
                </div>

                <div class="q-builder">
                  <div class="q-builder-head">
                    <div class="q-builder-title">Your questions</div>
                    <span style="font-size:10px;color:var(--muted)" id="qcount">0 questions</span>
                  </div>
                  <div class="q-list" id="qList"></div>
                </div>
                <button type="button" class="add-q-btn" onclick="addQuestion('')">
                  ＋ Add a question
                </button>
              </div>

              <button type="submit" class="btn-main">📍 Post Found Item →</button>
            </form>
          </div>
        </div>

        <!-- HOW IT WORKS -->
        <div class="info-card">
          <div class="info-card-head">
            <div class="ich-deco"></div>
            <div class="ich-title">How Reclaim Works</div>
            <div class="ich-sub">A trusted process to reunite lost items</div>
          </div>
          <div class="info-card-body">
            <div class="istep"><div class="snum">1</div><div class="stxt">
              <strong>Finder posts the item</strong> — describes it and adds verification questions only the true owner would know.
            </div></div>
            <div class="istep"><div class="snum">2</div><div class="stxt">
              <strong>Owner spots their item</strong> — browses the found items list and clicks "This is mine."
            </div></div>
            <div class="istep"><div class="snum">3</div><div class="stxt">
              <strong>Owner answers questions</strong> — only someone who genuinely owned the item can answer correctly.
            </div></div>
            <div class="istep"><div class="snum">4</div><div class="stxt">
              <strong>Finder reviews &amp; confirms</strong> — if the answers match, the finder confirms the claim.
            </div></div>
            <div class="istep"><div class="snum">5</div><div class="stxt">
              <strong>Pickup is scheduled</strong> — finder sets a campus location and time for the handover.
            </div></div>
            <div class="istep"><div class="snum">6</div><div class="stxt">
              <strong>Item returned, rating left</strong> — after the handover, the owner rates the finder ⭐.
            </div></div>
            <div class="tip-box">
              💡 <strong>Good questions to ask:</strong> specific colours, number of items inside, scratches/damage, brand name, what's written on it, or where the owner last used it.
            </div>
          </div>
        </div>
      </div>
    </div><!-- /post -->

    <!-- ══════════ TAB: MY POSTED ITEMS ══════════ -->
    <div class="tab-panel" id="panel-my-items">

      <?php if ($my_rating_row['cnt'] > 0): ?>
      <div class="my-rating-card" style="margin-bottom:22px">
        <div>
          <div class="mr-avg"><?= number_format($my_rating_row['avg'],1) ?></div>
          <div class="mr-label">Your Finder Rating</div>
          <div class="mr-count"><?= $my_rating_row['cnt'] ?> rating<?= $my_rating_row['cnt']!=1?'s':'' ?></div>
        </div>
        <div class="mr-stars">
          <?php for ($s=1;$s<=5;$s++) echo $s <= round($my_rating_row['avg']) ? '★' : '☆'; ?>
        </div>
        <div style="margin-left:16px;font-size:12px;color:var(--muted);line-height:1.6;max-width:320px">
          Students you've reunited with their belongings have rated your helpfulness. Keep up the great work!
        </div>
      </div>
      <?php endif; ?>

      <!-- PENDING CLAIMS on my items -->
      <?php if (!empty($pending_on_my_items)): ?>
        <div class="section-title">
          Claims to Review
          <?php $pending_cnt = count(array_filter($pending_on_my_items, fn($c) => $c['status']==='pending')); ?>
          <?php if ($pending_cnt): ?>
            <span style="font-family:'Nunito';font-size:12px;font-weight:700;color:var(--coral);
              background:rgba(238,69,64,.08);padding:2px 10px;border-radius:50px">
              <?= $pending_cnt ?> awaiting review
            </span>
          <?php endif; ?>
        </div>

        <?php foreach ($pending_on_my_items as $claim):
          $icon = $category_icons[$claim['category']] ?? '📦';
          $cw = array_filter(explode(' ', trim($claim['claimer_name'])));
          $ci = strtoupper(substr($cw[0]??'C',0,1).substr(end($cw)??'',0,1));
          $answers = json_decode($claim['answers'] ?? '[]', true);

          // Get questions for this item
          $qs = $pdo->prepare("SELECT question FROM reclaim_questions WHERE item_id=? ORDER BY sort_order");
          $qs->execute([$claim['item_id']]);
          $questions = $qs->fetchAll(PDO::FETCH_COLUMN);
        ?>
        <div class="claim-review-card">
          <div class="crc-head">
            <div class="crc-icon"><?= $icon ?></div>
            <div class="crc-info">
              <div class="crc-title"><?= htmlspecialchars($claim['title']) ?></div>
              <div class="crc-sub">
                Claimed by <strong><?= htmlspecialchars($claim['claimer_name']) ?></strong>
                (<?= htmlspecialchars($claim['claimer_sid']) ?>)
                · <?= date('d M Y, g:i a', strtotime($claim['created_at'])) ?>
              </div>
            </div>
            <div class="crc-status">
              <span class="badge b-<?= $claim['status'] ?>"><?= ucfirst($claim['status']) ?></span>
            </div>
          </div>
          <div class="crc-body">
            <!-- Q&A Review -->
            <?php if (!empty($questions)): ?>
              <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;margin-bottom:10px">
                Their answers to your verification questions:
              </div>
              <div class="qa-list">
                <?php foreach ($questions as $qi => $q): ?>
                <div class="qa-item">
                  <div class="qa-q">Q<?= $qi+1 ?>: <?= htmlspecialchars($q) ?></div>
                  <div class="qa-a"><?= htmlspecialchars($answers[$qi] ?? '(no answer provided)') ?></div>
                </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if ($claim['status'] === 'pending'): ?>
              <div class="action-row">
                <form method="POST" style="flex:1">
                  <input type="hidden" name="action" value="respond_claim"/>
                  <input type="hidden" name="claim_id" value="<?= $claim['id'] ?>"/>
                  <input type="hidden" name="response" value="confirm"/>
                  <button type="submit" class="btn-confirm" onclick="return confirm('Confirm this claim? The item will be marked as claimed.')">
                    ✓ Confirm — It\'s Theirs
                  </button>
                </form>
                <form method="POST" style="flex:1">
                  <input type="hidden" name="action" value="respond_claim"/>
                  <input type="hidden" name="claim_id" value="<?= $claim['id'] ?>"/>
                  <input type="hidden" name="response" value="reject"/>
                  <button type="submit" class="btn-reject" onclick="return confirm('Reject this claim?')">
                    ✕ Reject Claim
                  </button>
                </form>
              </div>

            <?php elseif ($claim['status'] === 'confirmed'): ?>
              <!-- Schedule pickup -->
              <?php if (!$claim['pickup_place']): ?>
                <div class="pickup-card">
                  <div class="pickup-title">📅 Schedule the Pickup</div>
                  <form method="POST">
                    <input type="hidden" name="action" value="set_pickup"/>
                    <input type="hidden" name="claim_id" value="<?= $claim['id'] ?>"/>
                    <div class="pickup-row">
                      <input type="text" name="pickup_place" class="pickup-input"
                             placeholder="Location on campus (e.g. Library Entrance)" required/>
                      <input type="datetime-local" name="pickup_time" class="pickup-input"
                             min="<?= date('Y-m-d\TH:i') ?>" required/>
                    </div>
                    <button type="submit" class="btn-pickup">📍 Set Pickup Time &amp; Place →</button>
                  </form>
                </div>
              <?php else: ?>
                <div class="pickup-info-box">
                  <div class="pib-label">📅 Pickup Scheduled</div>
                  <div class="pib-row">📍 <?= htmlspecialchars($claim['pickup_place']) ?></div>
                  <div class="pib-row">🕐 <?= date('d M Y, g:i a', strtotime($claim['pickup_time'])) ?></div>
                  <?php if (!$claim['pickup_confirmed']): ?>
                    <form method="POST">
                      <input type="hidden" name="action" value="complete_pickup"/>
                      <input type="hidden" name="claim_id" value="<?= $claim['id'] ?>"/>
                      <button type="submit" class="btn-complete" onclick="return confirm('Mark this item as successfully returned?')">
                        🎉 Mark as Returned
                      </button>
                    </form>
                  <?php else: ?>
                    <div style="margin-top:10px;font-size:12px;color:var(--green);font-weight:600">✓ Successfully returned!</div>
                    <?php if ($claim['given_rating']): ?>
                      <div style="font-size:12px;color:var(--amber);margin-top:4px">
                        Rated by claimer: <?= str_repeat('★',$claim['given_rating']) ?><?= str_repeat('☆',5-$claim['given_rating']) ?>
                      </div>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <!-- My posted items list -->
      <div class="section-title" style="margin-top:<?= empty($pending_on_my_items)?'0':'24px' ?>">My Found Items (<?= count($my_items) ?>)</div>
      <?php if (empty($my_items)): ?>
        <div class="empty">
          <div class="empty-ico">📍</div>
          <div class="empty-title">You haven't posted any found items yet</div>
          <div class="empty-sub">If you find something unattended, use "I Found Something" to post it.</div>
        </div>
      <?php else: ?>
        <div class="activity-card">
          <table>
            <thead>
              <tr><th>#</th><th>Item</th><th>Category</th><th>Found At</th><th>Status</th><th>Claims</th><th>Posted</th><th></th></tr>
            </thead>
            <tbody>
              <?php foreach ($my_items as $i => $it): ?>
              <tr>
                <td style="color:var(--muted);font-weight:600"><?= $i+1 ?></td>
                <td>
                  <div class="td-title"><?= htmlspecialchars($it['title']) ?></div>
                  <div class="td-sub">📍 <?= htmlspecialchars($it['location_found']) ?></div>
                </td>
                <td><?= $category_icons[$it['category']]??'📦' ?> <?= ucfirst(str_replace('_',' ',$it['category'])) ?></td>
                <td style="font-size:11px;color:var(--muted)"><?= date('d M Y', strtotime($it['found_date'])) ?></td>
                <td><span class="badge b-<?= $it['status'] ?>"><?= ucfirst($it['status']) ?></span></td>
                <td style="text-align:center">
                  <strong style="color:var(--terracotta)"><?= $it['claim_count'] ?></strong>
                  <?php if ($it['pending_claims'] > 0): ?>
                    <span style="font-size:10px;color:var(--coral);font-weight:700"> · <?= $it['pending_claims'] ?> pending</span>
                  <?php endif; ?>
                </td>
                <td style="font-size:11px;color:var(--muted)"><?= date('d M Y', strtotime($it['created_at'])) ?></td>
                <td>
                  <?php if ($it['status'] === 'unclaimed'): ?>
                    <form method="POST" onsubmit="return confirm('Remove this item listing?')">
                      <input type="hidden" name="action" value="delete_item"/>
                      <input type="hidden" name="item_id" value="<?= $it['id'] ?>"/>
                      <button type="submit"
                        style="background:none;border:1px solid rgba(238,69,64,.2);color:var(--coral);padding:4px 11px;border-radius:7px;font-family:'Nunito',sans-serif;font-size:10px;font-weight:700;cursor:pointer">
                        Remove
                      </button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div><!-- /my-items -->

    <!-- ══════════ TAB: MY CLAIMS ══════════ -->
    <div class="tab-panel" id="panel-my-claims">
      <?php if (empty($my_claims)): ?>
        <div class="empty">
          <div class="empty-ico">🙋</div>
          <div class="empty-title">No claims submitted yet</div>
          <div class="empty-sub">Found your lost item in the browse section? Click it and prove it's yours!</div>
        </div>
      <?php else: ?>
        <?php foreach ($my_claims as $claim):
          $icon = $category_icons[$claim['category']] ?? '📦';
          $answers = json_decode($claim['answers'] ?? '[]', true);
          $fw = array_filter(explode(' ', trim($claim['finder_name'])));
          $fi = strtoupper(substr($fw[0]??'F',0,1).substr(end($fw)??'',0,1));
        ?>
        <div class="claim-review-card" style="margin-bottom:16px">
          <div class="crc-head">
            <div class="crc-icon"><?= $icon ?></div>
            <div class="crc-info">
              <div class="crc-title"><?= htmlspecialchars($claim['title']) ?></div>
              <div class="crc-sub">
                Found by <strong><?= htmlspecialchars($claim['finder_name']) ?></strong>
                (<?= htmlspecialchars($claim['finder_sid']) ?>)
                · Claimed <?= date('d M Y', strtotime($claim['created_at'])) ?>
              </div>
            </div>
            <div class="crc-status">
              <span class="badge b-<?= $claim['status'] ?>"><?= ucfirst($claim['status']) ?></span>
            </div>
          </div>
          <div class="crc-body">

            <?php if ($claim['status'] === 'pending'): ?>
              <div style="padding:12px 14px;background:rgba(217,119,6,.06);border:1px solid rgba(217,119,6,.15);border-radius:10px;font-size:12px;color:var(--amber)">
                ⏳ Your claim is awaiting review. The finder will check your answers and confirm or reject your claim.
              </div>

            <?php elseif ($claim['status'] === 'rejected'): ?>
              <div style="padding:12px 14px;background:rgba(238,69,64,.06);border:1px solid rgba(238,69,64,.15);border-radius:10px;font-size:12px;color:var(--coral)">
                ✕ Your claim was rejected. Your answers may not have matched. If this is a mistake, contact the finder directly.
              </div>

            <?php elseif ($claim['status'] === 'confirmed'): ?>
              <?php if ($claim['pickup_place'] && $claim['pickup_time']): ?>
                <div class="pickup-info-box">
                  <div class="pib-label">📅 Pickup Arranged</div>
                  <div class="pib-row">📍 <?= htmlspecialchars($claim['pickup_place']) ?></div>
                  <div class="pib-row">🕐 <?= date('d M Y, g:i a', strtotime($claim['pickup_time'])) ?></div>
                  <?php if (!$claim['pickup_confirmed']): ?>
                    <div style="font-size:11.5px;color:var(--muted);margin-top:8px">
                      Please be at the pickup location on time. Mark as received after you collect your item.
                    </div>
                    <form method="POST">
                      <input type="hidden" name="action" value="complete_pickup"/>
                      <input type="hidden" name="claim_id" value="<?= $claim['id'] ?>"/>
                      <button type="submit" class="btn-complete" onclick="return confirm('Confirm you have received your item?')">
                        ✓ I Received My Item
                      </button>
                    </form>
                  <?php else: ?>
                    <div style="margin-top:8px;font-size:12px;color:var(--green);font-weight:600">✓ Item received!</div>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <div style="padding:12px 14px;background:rgba(46,158,104,.06);border:1px solid rgba(46,158,104,.2);border-radius:10px;font-size:12px;color:var(--green)">
                  ✓ Your claim was confirmed! The finder is scheduling a pickup location and time — check back soon.
                </div>
              <?php endif; ?>

              <!-- Rating section (only after pickup complete, no rating yet) -->
              <?php if ($claim['pickup_confirmed'] && !$claim['my_rating']): ?>
                <div class="rating-card">
                  <div class="rating-title">⭐ Rate Your Finder</div>
                  <p style="font-size:12px;color:var(--muted);margin-bottom:14px;line-height:1.55">
                    How was your experience with <strong><?= htmlspecialchars($claim['finder_name']) ?></strong>? Your rating is displayed publicly on their profile.
                  </p>
                  <form method="POST">
                    <input type="hidden" name="action" value="rate_founder"/>
                    <input type="hidden" name="claim_id" value="<?= $claim['id'] ?>"/>
                    <div class="star-picker">
                      <?php for ($s=5;$s>=1;$s--): ?>
                        <input type="radio" name="stars" id="star<?= $s ?>_<?= $claim['id'] ?>" value="<?= $s ?>" <?= $s===5?'checked':'' ?>/>
                        <label for="star<?= $s ?>_<?= $claim['id'] ?>">★</label>
                      <?php endfor; ?>
                    </div>
                    <textarea name="comment" class="rating-comment"
                      placeholder="Leave a comment about your experience (optional)…"></textarea>
                    <button type="submit" class="btn-rate">⭐ Submit Rating →</button>
                  </form>
                </div>
              <?php elseif ($claim['pickup_confirmed'] && $claim['my_rating']): ?>
                <div style="padding:12px 14px;background:rgba(217,119,6,.06);border:1px solid rgba(217,119,6,.15);border-radius:10px;font-size:12px;color:var(--amber);margin-top:10px">
                  You rated this finder: <?= str_repeat('★',$claim['my_rating']) ?><?= str_repeat('☆',5-$claim['my_rating']) ?> — Thank you!
                </div>
              <?php endif; ?>
            <?php endif; ?>

          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div><!-- /my-claims -->

  </div><!-- /scroll-area -->
</div><!-- /main -->
</div><!-- /layout -->

<!-- ══════════════════════════════
     CLAIM MODAL (dynamically loaded)
══════════════════════════════ -->
<div class="modal-overlay" id="claimModal">
  <div class="modal" id="modalContent">
    <!-- filled by JS -->
  </div>
</div>

<!-- Embed item+questions data as JSON for JS -->
<script>
const ITEMS_DATA = <?php
  $js_items = [];
  $all_browse = $pdo->query("
      SELECT ri.id, ri.title, ri.category, ri.description, ri.location_found,
             ri.found_date, ri.found_time, ri.image_url, ri.finder_id,
             u.full_name AS finder_name
      FROM reclaim_items ri JOIN users u ON u.id=ri.finder_id
      WHERE ri.status='unclaimed' AND ri.finder_id != " . $user_id
  )->fetchAll();
  foreach ($all_browse as $it) {
      $qs = $pdo->prepare("SELECT id,question FROM reclaim_questions WHERE item_id=? ORDER BY sort_order");
      $qs->execute([$it['id']]);
      $it['questions'] = $qs->fetchAll(PDO::FETCH_ASSOC);
      $js_items[] = $it;
  }
  echo json_encode($js_items);
?>;

const CATEGORY_ICONS = <?php echo json_encode($category_icons); ?>;

const PRESET_QUESTIONS = <?php echo json_encode($preset_questions); ?>;

/* ── TAB SWITCHER ── */
function switchTab(id, btn) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('panel-' + id).classList.add('active');
  btn.classList.add('active');
}

/* ── FILTER ITEMS ── */
function filterItems() {
  const q   = document.getElementById('itemSearch').value.toLowerCase();
  const cat = document.getElementById('catFilter').value;
  let vis = 0;
  document.querySelectorAll('.item-card').forEach(c => {
    const ok = (c.dataset.title.includes(q) || c.dataset.loc.includes(q))
             && (!cat || c.dataset.cat === cat);
    c.style.display = ok ? '' : 'none';
    if (ok) vis++;
  });
  const msg = document.getElementById('noItemsMsg');
  if (msg) msg.style.display = vis === 0 ? 'block' : 'none';
}

/* ── CLAIM MODAL ── */
function openClaimModal(itemId) {
  const item = ITEMS_DATA.find(i => i.id == itemId);
  if (!item) return;
  const icon = CATEGORY_ICONS[item.category] || '📦';
  const mc   = document.getElementById('modalContent');

  let questionsHTML = '';
  if (item.questions && item.questions.length > 0) {
    questionsHTML = `
      <div class="questions-section">
        <div class="q-intro">
          🔒 To prove this item is yours, please answer all questions below.
          Only the true owner would know these answers.
        </div>
        ${item.questions.map((q, i) => `
          <div class="q-item">
            <div class="q-text"><div class="q-num">${i+1}</div>${escHtml(q.question)}</div>
            <textarea name="answers[]" class="q-input" rows="2"
              placeholder="Your answer…" required></textarea>
          </div>
        `).join('')}
      </div>`;
  }

  mc.innerHTML = `
    <form method="POST">
      <input type="hidden" name="action" value="submit_claim"/>
      <input type="hidden" name="item_id" value="${item.id}"/>
      <div class="modal-head">
        <div class="modal-head-deco"></div>
        <button type="button" class="modal-close" onclick="closeModal()">✕</button>
        <span class="modal-cat-icon">${icon}</span>
        <div class="modal-title">${escHtml(item.title)}</div>
        <div class="modal-subtitle">Found by ${escHtml(item.finder_name)}</div>
      </div>
      <div class="modal-body">
        <div class="info-row">
          <div class="info-box">
            <div class="info-label">📍 Found at</div>
            <div class="info-value">${escHtml(item.location_found)}</div>
          </div>
          <div class="info-box">
            <div class="info-label">📅 Date found</div>
            <div class="info-value">${formatDate(item.found_date)}${item.found_time ? ' · '+item.found_time : ''}</div>
          </div>
        </div>
        <div class="modal-desc-title">Description from finder</div>
        <div class="modal-desc">${escHtml(item.description)}</div>
        ${questionsHTML}
        <button type="submit" class="modal-submit-btn">🙋 Submit My Claim →</button>
      </div>
    </form>`;

  document.getElementById('claimModal').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeModal() {
  document.getElementById('claimModal').classList.remove('open');
  document.body.style.overflow = '';
}

document.getElementById('claimModal').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

function escHtml(str) {
  const d = document.createElement('div');
  d.textContent = str || '';
  return d.innerHTML;
}

function formatDate(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr + 'T00:00:00');
  return d.toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'});
}

/* ── QUESTION BUILDER ── */
let questionCount = 0;

function addQuestion(text) {
  questionCount++;
  const list = document.getElementById('qList');
  const row = document.createElement('div');
  row.className = 'q-row';
  row.dataset.qid = questionCount;
  row.innerHTML = `
    <input type="text" name="questions[]" placeholder="e.g. What color is it?"
           value="${text.replace(/"/g,'&quot;')}" maxlength="300"/>
    <button type="button" class="q-del" onclick="removeQuestion(this)" title="Remove">✕</button>`;
  list.appendChild(row);
  updateQCount();
  row.querySelector('input').focus();
}

function removeQuestion(btn) {
  btn.closest('.q-row').remove();
  updateQCount();
}

function updateQCount() {
  const cnt = document.querySelectorAll('#qList .q-row').length;
  document.getElementById('qcount').textContent = cnt + ' question' + (cnt !== 1 ? 's' : '');
}

function loadPresets(category) {
  const presets = PRESET_QUESTIONS[category] || PRESET_QUESTIONS['other'];
  const container = document.getElementById('presetBtns');
  container.innerHTML = presets.map(q =>
    `<button type="button" class="preset-btn" onclick="addQuestion(${JSON.stringify(q)})">${q.substring(0,45)}${q.length>45?'…':''}</button>`
  ).join('');
}

/* ── INIT ── */
document.addEventListener('DOMContentLoaded', () => {
  // Load presets for default category (other)
  loadPresets('other');
  // Add 2 blank questions to start
  addQuestion('');
  addQuestion('');
  // Category radio listeners
  document.querySelectorAll('input[name="category"]').forEach(r => {
    r.addEventListener('change', () => loadPresets(r.value));
  });
});

/* ── AUTO-DISMISS ALERTS ── */
setTimeout(() => {
  document.querySelectorAll('.alert').forEach(a => {
    a.style.transition = 'opacity .4s';
    a.style.opacity    = '0';
    setTimeout(() => a.remove(), 400);
  });
}, 4500);

/* ── NOTIFICATION PANEL TOGGLE ── */
function toggleNotifPanel() {
  const panel = document.getElementById('notifPanel');
  panel.classList.toggle('open');
}

// Close when clicking outside
document.addEventListener('click', function(e) {
  const wrap = document.getElementById('notifWrap');
  if (wrap && !wrap.contains(e.target)) {
    document.getElementById('notifPanel').classList.remove('open');
  }
});
</script>
</body>
</html>