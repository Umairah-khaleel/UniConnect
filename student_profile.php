<?php
// student_profile.php
require_once __DIR__ . '/auth/db.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: index.php'); exit;
}

$viewer_id  = (int)$_SESSION['user_id'];
$profile_id = (int)($_GET['id'] ?? $viewer_id);

// ── Fetch profile user ────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT id, full_name, student_id, email, created_at FROM users WHERE id=? AND role='student'");
$stmt->execute([$profile_id]);
$profile = $stmt->fetch();
if (!$profile) { header('Location: student_dashboard.php'); exit; }

$p_name     = htmlspecialchars($profile['full_name']);
$p_sid      = htmlspecialchars($profile['student_id']);
$p_email    = htmlspecialchars($profile['email']);
$p_joined   = date('F Y', strtotime($profile['created_at']));
$p_words    = array_filter(explode(' ', trim($p_name)));
$p_initials = strtoupper(substr($p_words[0]??'S',0,1).substr(end($p_words)??'',0,1));
$is_own     = ($profile_id === $viewer_id);

// ── Viewer info (for topbar) ──────────────────────────────────────────────────
$v_name     = htmlspecialchars($_SESSION['full_name'] ?? 'Student');
$v_sid      = htmlspecialchars($_SESSION['student_id'] ?? '');
$v_first    = explode(' ',$v_name)[0];
$v_words    = array_filter(explode(' ', trim($v_name)));
$v_initials = strtoupper(substr($v_words[0]??'S',0,1).substr(end($v_words)??'',0,1));

// ── Fetch ratings per module ──────────────────────────────────────────────────
function getModuleRatings(PDO $pdo, int $uid, string $module): array {
    $s = $pdo->prepare("
        SELECT r.stars, r.comment, r.created_at, u.full_name AS rater_name
        FROM ratings r
        JOIN users u ON u.id = r.rater_id
        WHERE r.rated_id=? AND r.module=?
        ORDER BY r.created_at DESC
        LIMIT 10
    ");
    $s->execute([$uid, $module]);
    return $s->fetchAll();
}
function getModuleAvg(PDO $pdo, int $uid, string $module): array {
    $s = $pdo->prepare("
        SELECT COALESCE(AVG(stars),0) AS avg, COUNT(*) AS cnt
        FROM ratings WHERE rated_id=? AND module=?
    ");
    $s->execute([$uid, $module]);
    return $s->fetch();
}

// Burrow Buddy uses 'burrow_buddy', Brain Bridge uses 'peer_tutoring', Reclaim uses 'reclaim'
$bb_ratings  = getModuleRatings($pdo, $profile_id, 'burrow_buddy');
$pt_ratings  = getModuleRatings($pdo, $profile_id, 'peer_tutoring');
$rc_ratings  = getModuleRatings($pdo, $profile_id, 'reclaim');
$bb_avg      = getModuleAvg($pdo, $profile_id, 'burrow_buddy');
$pt_avg      = getModuleAvg($pdo, $profile_id, 'peer_tutoring');
$rc_avg      = getModuleAvg($pdo, $profile_id, 'reclaim');

// Also pull reclaim ratings directly from reclaim_ratings for any not yet migrated
$rc_extra = $pdo->prepare("
    SELECT rr.stars, rr.comment, rr.created_at, u.full_name AS rater_name
    FROM reclaim_ratings rr
    JOIN users u ON u.id = rr.rater_id
    WHERE rr.rated_id=?
      AND NOT EXISTS (
        SELECT 1 FROM ratings r2
        WHERE r2.rater_id=rr.rater_id AND r2.ref_id=rr.claim_id AND r2.module='reclaim'
      )
    ORDER BY rr.created_at DESC LIMIT 10
");
$rc_extra->execute([$profile_id]);
$rc_extra = $rc_extra->fetchAll();
// Merge with any unified ratings
$rc_ratings = array_merge($rc_ratings, $rc_extra);
// Recalculate avg if there were unmigrated reclaim ratings
if (!empty($rc_extra)) {
    $all_rc_stars = array_column(array_merge(
        $pdo->prepare("SELECT stars FROM ratings WHERE rated_id=? AND module='reclaim'")->execute([$profile_id]) ? [] : [],
        $pdo->query("SELECT stars FROM reclaim_ratings WHERE rated_id={$profile_id}")->fetchAll()
    ), 'stars');
    // Simpler: just re-query both
    $rc_combined = $pdo->prepare("
        SELECT COALESCE(AVG(s),0) AS avg, COUNT(*) AS cnt FROM (
            SELECT stars AS s FROM ratings WHERE rated_id=? AND module='reclaim'
            UNION ALL
            SELECT stars AS s FROM reclaim_ratings WHERE rated_id=?
              AND NOT EXISTS (SELECT 1 FROM ratings r2 WHERE r2.rater_id=rr2.rater_id AND r2.module='reclaim' AND r2.ref_id=rr2.claim_id)
        ) combined
    ");
    // Alias fix — simpler fallback
    $rc_avg_q = $pdo->prepare("
        SELECT COALESCE(AVG(stars),0) AS avg, COUNT(*) AS cnt FROM reclaim_ratings WHERE rated_id=?
    ");
    $rc_avg_q->execute([$profile_id]);
    $rc_avg_fallback = $rc_avg_q->fetch();
    if ($rc_avg_fallback['cnt'] > $rc_avg['cnt']) {
        $rc_avg = $rc_avg_fallback;
    }
}

// Overall score = weighted average of modules that have ratings
$scored = array_filter([$bb_avg['avg'], $pt_avg['avg'], $rc_avg['avg']], fn($v)=>$v>0);
$overall = count($scored) ? array_sum($scored)/count($scored) : 0;
$total_ratings = $bb_avg['cnt'] + $pt_avg['cnt'] + $rc_avg['cnt'];

// ── Burrow Buddy — listings & borrow activity ─────────────────────────────────
$bb_listings = $pdo->prepare("SELECT title, category, status, created_at FROM bb_listings WHERE lender_id=? ORDER BY created_at DESC LIMIT 8");
$bb_listings->execute([$profile_id]);
$bb_listings = $bb_listings->fetchAll();

// ── Peer tutoring — offers ────────────────────────────────────────────────────
$pt_offers = $pdo->prepare("
    SELECT m.module_name, m.module_code, t.preferred_mode, t.availability, t.status
    FROM tutor_offers t JOIN modules m ON m.id=t.module_id
    WHERE t.tutor_id=? AND t.status='active'
");
$pt_offers->execute([$profile_id]);
$pt_offers = $pt_offers->fetchAll();

$cat_icons = ['laptop'=>'💻','keyboard'=>'⌨️','mouse'=>'🖱️','pendrive'=>'💾','headphones'=>'🎧','charger'=>'🔌','other'=>'📦'];

function starsHtml(float $avg, string $color='#f59e0b'): string {
    $full  = floor($avg);
    $half  = ($avg - $full) >= 0.5;
    $empty = 5 - $full - ($half?1:0);
    return str_repeat('<span style="color:'.$color.'">★</span>', (int)$full)
         . ($half ? '<span style="color:'.$color.'">½</span>' : '')
         . str_repeat('<span style="color:#d1d5db">★</span>', (int)$empty);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title><?= $p_name ?> — UniConnect Profile</title>
<link rel="icon" type="image/png" href="icbt.png">

<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=Nunito:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --coral:#EE4540;--crimson:#C72C41;--wine:#801336;--plum:#510A32;--midnight:#2D142C;
  --bg:#f5eeee;--card:#ffffff;--text:#2D142C;--muted:#9a8080;--border:#eedede;
  --bb:#0e7490;--bb2:#0891b2;
  --pt:#7c3aed;--pt2:#8b5cf6;
  --rc:#b45309;--rc2:#f59e0b;
  --sidebar-w:230px;
}
html,body{height:100%;font-family:'Nunito',sans-serif;background:var(--bg);color:var(--text);overflow:hidden;}
.layout{display:grid;grid-template-columns:var(--sidebar-w) 1fr;height:100vh;}

/* ── SIDEBAR ── */
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
.nav-item.active{color:#fff;background:rgba(238,69,64,.14);border-left-color:var(--coral);}
.nav-item.active .ni{color:var(--coral);}
.ni{font-size:15px;width:18px;text-align:center;flex-shrink:0;}
.nav-pill{margin-left:auto;background:var(--coral);color:#fff;font-size:8.5px;font-weight:700;padding:2px 7px;border-radius:50px;}
.sidebar-foot{border-top:1px solid rgba(255,255,255,.06);padding:14px 16px 20px;flex-shrink:0;}
.user-chip{display:flex;align-items:center;gap:10px;padding:9px 11px;border-radius:10px;text-decoration:none;transition:background .25s;}
.user-chip:hover{background:rgba(255,255,255,.08);}
.ava{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--coral),var(--crimson));display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;box-shadow:0 2px 8px rgba(238,69,64,.4);}
.user-chip-name{font-size:11.5px;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.user-chip-id{font-size:9.5px;color:rgba(255,255,255,.35);}
.chip-arrow{margin-left:auto;color:rgba(255,255,255,.3);font-size:13px;}

/* ── MAIN ── */
.main{display:flex;flex-direction:column;height:100vh;overflow:hidden;}
.topbar{background:rgba(245,238,238,.92);backdrop-filter:blur(10px);border-bottom:1px solid var(--border);padding:0 32px;height:60px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.page-title{font-family:'Cormorant Garamond',serif;font-size:20px;font-weight:600;color:var(--midnight);}
.topbar-right{display:flex;align-items:center;gap:10px;}
.back-btn{display:inline-flex;align-items:center;gap:7px;padding:8px 18px;border:1px solid var(--border);border-radius:50px;font-size:12px;font-weight:600;color:var(--muted);text-decoration:none;background:var(--card);transition:all .2s;}
.back-btn:hover{border-color:var(--crimson);color:var(--crimson);}
.profile-chip{display:flex;align-items:center;gap:8px;padding:5px 12px 5px 6px;border:1px solid var(--border);border-radius:50px;background:var(--card);}
.ava-sm{width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,var(--coral),var(--crimson));display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;}
.chip-name{font-size:12px;font-weight:600;color:var(--text);}

.scroll-area{flex:1;overflow-y:auto;padding:28px 32px 48px;}
.scroll-area::-webkit-scrollbar{width:4px;}
.scroll-area::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px;}

/* ── PROFILE HERO ── */
.profile-hero{
  border-radius:20px;overflow:hidden;margin-bottom:28px;
  animation:fadeUp .5s ease both;
  position:relative;
}
.hero-banner{
  height:130px;
  background:linear-gradient(125deg,var(--midnight) 0%,var(--plum) 45%,var(--wine) 80%,var(--crimson) 100%);
  position:relative;
}
.hero-banner-deco1{position:absolute;width:200px;height:200px;border-radius:50%;background:rgba(238,69,64,.1);top:-60px;right:10%;pointer-events:none;}
.hero-banner-deco2{position:absolute;width:100px;height:100px;border-radius:50%;background:rgba(255,255,255,.04);bottom:-20px;left:15%;pointer-events:none;}

.profile-card-body{background:var(--card);border:1px solid var(--border);border-top:none;border-radius:0 0 20px 20px;padding:0 28px 26px;}
.avatar-wrap{margin-top:-44px;margin-bottom:14px;position:relative;width:fit-content;}
.big-ava{
  width:88px;height:88px;border-radius:50%;
  background:linear-gradient(135deg,var(--coral),var(--crimson));
  display:flex;align-items:center;justify-content:center;
  font-family:'Cormorant Garamond',serif;font-size:30px;font-weight:600;color:#fff;
  border:4px solid var(--card);
  box-shadow:0 4px 20px rgba(199,44,65,.3);
}
.online-dot{position:absolute;bottom:4px;right:4px;width:16px;height:16px;border-radius:50%;background:#22c55e;border:3px solid var(--card);}

.profile-info-row{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.profile-name{font-family:'Cormorant Garamond',serif;font-size:26px;font-weight:600;color:var(--midnight);margin-bottom:3px;}
.profile-sid{font-size:12px;color:var(--muted);margin-bottom:10px;}
.profile-meta{display:flex;gap:14px;flex-wrap:wrap;}
.pmeta-item{font-size:11px;color:var(--muted);display:flex;align-items:center;gap:5px;}
.pmeta-item strong{color:var(--text);}

/* overall score badge */
.overall-score{
  display:flex;flex-direction:column;align-items:center;
  background:linear-gradient(135deg,var(--midnight),var(--plum));
  border-radius:14px;padding:16px 22px;min-width:130px;text-align:center;
}
.overall-val{font-family:'Cormorant Garamond',serif;font-size:38px;font-weight:600;color:#fff;line-height:1;}
.overall-stars{font-size:14px;margin:4px 0;letter-spacing:2px;}
.overall-label{font-size:9px;text-transform:uppercase;letter-spacing:.12em;color:rgba(255,255,255,.4);}
.overall-count{font-size:10px;color:rgba(255,255,255,.35);margin-top:2px;}

/* ── MODULE SECTION GRID ── */
.modules-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:24px;}

.module-card{background:var(--card);border:1px solid var(--border);border-radius:18px;overflow:hidden;animation:fadeUp .5s ease both;}
.module-card:nth-child(1){animation-delay:.05s}
.module-card:nth-child(2){animation-delay:.1s}
.module-card:nth-child(3){animation-delay:.15s}

.module-head{padding:18px 18px 14px;border-bottom:1px solid var(--border);}
.module-head-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;}
.module-badge{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:50px;font-size:11px;font-weight:700;}
.mb-bb{background:rgba(14,116,144,.1);color:var(--bb);}
.mb-pt{background:rgba(124,58,237,.1);color:var(--pt);}
.mb-rc{background:rgba(180,83,9,.1);color:var(--rc);}
.module-avg-row{display:flex;align-items:center;gap:10px;}
.module-avg-num{font-family:'Cormorant Garamond',serif;font-size:28px;font-weight:600;color:var(--midnight);}
.module-avg-right{}
.module-stars{font-size:13px;letter-spacing:1px;margin-bottom:2px;}
.module-count{font-size:10px;color:var(--muted);}

/* activity in module card */
.module-body{padding:14px 18px;}
.module-activity-label{font-size:9px;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);font-weight:700;margin-bottom:10px;}
.activity-item{display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid rgba(238,222,222,.4);}
.activity-item:last-child{border-bottom:none;}
.act-icon{font-size:14px;flex-shrink:0;width:22px;text-align:center;}
.act-text{font-size:11.5px;color:var(--text);flex:1;}
.act-sub{font-size:10px;color:var(--muted);}

/* color accent bar top of module card */
.module-card-bb  .module-head{border-top:3px solid var(--bb);}
.module-card-pt  .module-head{border-top:3px solid var(--pt);}
.module-card-rc  .module-head{border-top:3px solid var(--rc);}

/* ── REVIEWS SECTION ── */
.reviews-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;}
.reviews-col{}
.reviews-col-title{font-family:'Cormorant Garamond',serif;font-size:16px;font-weight:600;color:var(--midnight);margin-bottom:14px;display:flex;align-items:center;gap:8px;}
.reviews-col-title::before{content:'';width:3px;height:16px;border-radius:2px;flex-shrink:0;}
.rcol-bb::before{background:linear-gradient(to bottom,var(--bb),var(--bb2));}
.rcol-pt::before{background:linear-gradient(to bottom,var(--pt),var(--pt2));}
.rcol-rc::before{background:linear-gradient(to bottom,var(--rc),var(--rc2));}

.review-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin-bottom:10px;transition:border-color .2s;}
.review-card:hover{border-color:rgba(45,20,44,.15);}
.review-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;}
.reviewer-name{font-size:12px;font-weight:600;color:var(--midnight);}
.review-stars{font-size:12px;letter-spacing:1px;}
.review-text{font-size:11.5px;color:var(--muted);line-height:1.55;font-style:italic;}
.review-date{font-size:10px;color:var(--border);margin-top:6px;}

.no-reviews{text-align:center;padding:24px 14px;background:var(--bg);border-radius:12px;border:1px dashed var(--border);}
.no-reviews-txt{font-size:11.5px;color:var(--muted);}

/* ── SECTION TITLE ── */
.section-title{font-family:'Cormorant Garamond',serif;font-size:18px;font-weight:600;color:var(--midnight);margin-bottom:18px;display:flex;align-items:center;gap:10px;}
.section-title::before{content:'';width:3px;height:18px;border-radius:2px;background:linear-gradient(to bottom,var(--coral),var(--crimson));flex-shrink:0;}

@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
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
      <a class="nav-item" href="burrow_buddy.php"><span class="ni">📚</span> Burrow Buddy</a>
      <a class="nav-item" href="reclaim.php"><span class="ni">♻️</span> Reclaim</a>
      <a class="nav-item" href="brain_bridge.php"><span class="ni">🧠</span> Brain Bridge</a>
      <div class="nav-section-label" style="margin-top:8px">Account</div>
      <a class="nav-item active" href="student_profile.php?id=<?= $viewer_id ?>"><span class="ni">👤</span> My Profile</a>
      <a class="nav-item" href="auth/logout.php"><span class="ni">🚪</span> Sign Out</a>
    </div>
    <div class="sidebar-foot">
      <div class="user-chip">
        <div class="ava"><?= $v_initials ?></div>
        <div><div class="user-chip-name"><?= $v_name ?></div><div class="user-chip-id"><?= $v_sid ?></div></div>
        <span class="chip-arrow">›</span>
      </div>
    </div>
  </aside>

  <!-- ── MAIN ── -->
  <div class="main">
    <div class="topbar">
      <div class="page-title"><?= $is_own ? 'My Profile' : 'Student Profile' ?></div>
      <div class="topbar-right">
        <a href="javascript:history.back()" class="back-btn">← Back</a>
        <div class="profile-chip">
          <div class="ava-sm"><?= $v_initials ?></div>
          <span class="chip-name"><?= $v_first ?></span>
        </div>
      </div>
    </div>

    <div class="scroll-area">

      <!-- ══ PROFILE HERO ══ -->
      <div class="profile-hero">
        <div class="hero-banner">
          <div class="hero-banner-deco1"></div>
          <div class="hero-banner-deco2"></div>
        </div>
        <div class="profile-card-body">
          <div class="avatar-wrap">
            <div class="big-ava"><?= $p_initials ?></div>
            <div class="online-dot"></div>
          </div>
          <div class="profile-info-row">
            <div>
              <div class="profile-name"><?= $p_name ?></div>
              <div class="profile-sid"><?= $p_sid ?></div>
              <div class="profile-meta">
                <div class="pmeta-item">✉️ <strong><?= $p_email ?></strong></div>
                <div class="pmeta-item">📅 Joined <strong><?= $p_joined ?></strong></div>
                <div class="pmeta-item">⭐ <strong><?= $total_ratings ?></strong> total rating<?= $total_ratings!=1?'s':'' ?></div>
              </div>
            </div>
            <!-- Overall Score Badge -->
            <div class="overall-score">
              <div class="overall-val"><?= $overall > 0 ? number_format($overall,1) : '—' ?></div>
              <div class="overall-stars" style="color:#f59e0b">
                <?php
                if ($overall > 0) {
                    $f = floor($overall);
                    echo str_repeat('★',$f) . ($overall-$f>=.5?'½':'') . str_repeat('☆', 5-$f-($overall-$f>=.5?1:0));
                } else { echo '☆☆☆☆☆'; }
                ?>
              </div>
              <div class="overall-label">Overall Score</div>
              <div class="overall-count"><?= $total_ratings ?> review<?= $total_ratings!=1?'s':'' ?></div>
            </div>
          </div>
        </div>
      </div>

      <!-- ══ MODULE SCORES ══ -->
      <div class="section-title">Module Ratings</div>
      <div class="modules-grid">

        <!-- BURROW BUDDY -->
        <div class="module-card module-card-bb">
          <div class="module-head">
            <div class="module-head-top">
              <span class="module-badge mb-bb">📚 Burrow Buddy</span>
            </div>
            <div class="module-avg-row">
              <div class="module-avg-num"><?= $bb_avg['avg']>0 ? number_format($bb_avg['avg'],1) : '—' ?></div>
              <div class="module-avg-right">
                <div class="module-stars"><?= starsHtml((float)$bb_avg['avg'], '#0e7490') ?></div>
                <div class="module-count"><?= $bb_avg['cnt'] ?> review<?= $bb_avg['cnt']!=1?'s':'' ?></div>
              </div>
            </div>
          </div>
          <div class="module-body">
            <div class="module-activity-label">Listings</div>
            <?php if (empty($bb_listings)): ?>
              <div style="font-size:11px;color:var(--muted)">No listings yet.</div>
            <?php else: ?>
              <?php foreach (array_slice($bb_listings,0,4) as $l): ?>
                <div class="activity-item">
                  <span class="act-icon"><?= $cat_icons[$l['category']]??'📦' ?></span>
                  <div>
                    <div class="act-text"><?= htmlspecialchars($l['title']) ?></div>
                    <div class="act-sub"><?= ucfirst($l['status']) ?> · <?= date('d M Y',strtotime($l['created_at'])) ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
              <?php if (count($bb_listings)>4): ?>
                <div style="font-size:10.5px;color:var(--muted);margin-top:6px;text-align:center">+<?= count($bb_listings)-4 ?> more</div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- STUDENT LINK / PEER TUTORING -->
        <div class="module-card module-card-pt">
          <div class="module-head">
            <div class="module-head-top">
<span class="module-badge mb-pt">🧠 Brain Bridge</span>
            </div>
            <div class="module-avg-row">
              <div class="module-avg-num"><?= $pt_avg['avg']>0 ? number_format($pt_avg['avg'],1) : '—' ?></div>
              <div class="module-avg-right">
                <div class="module-stars"><?= starsHtml((float)$pt_avg['avg'], '#7c3aed') ?></div>
                <div class="module-count"><?= $pt_avg['cnt'] ?> review<?= $pt_avg['cnt']!=1?'s':'' ?></div>
              </div>
            </div>
          </div>
          <div class="module-body">
            <div class="module-activity-label">Tutor Offers</div>
            <?php if (empty($pt_offers)): ?>
              <div style="font-size:11px;color:var(--muted)">Not tutoring any modules yet.</div>
            <?php else: ?>
              <?php foreach ($pt_offers as $o): ?>
                <div class="activity-item">
                  <span class="act-icon">📘</span>
                  <div>
                    <div class="act-text"><?= htmlspecialchars($o['module_code']) ?> — <?= htmlspecialchars($o['module_name']) ?></div>
                    <div class="act-sub"><?= ucfirst($o['preferred_mode']) ?><?= $o['availability']?' · '.$o['availability']:'' ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- RECLAIM -->
        <div class="module-card module-card-rc">
          <div class="module-head">
            <div class="module-head-top">
              <span class="module-badge mb-rc">♻️ Reclaim</span>
            </div>
            <div class="module-avg-row">
              <div class="module-avg-num"><?= $rc_avg['avg']>0 ? number_format($rc_avg['avg'],1) : '—' ?></div>
              <div class="module-avg-right">
                <div class="module-stars"><?= starsHtml((float)$rc_avg['avg'], '#b45309') ?></div>
                <div class="module-count"><?= $rc_avg['cnt'] ?> review<?= $rc_avg['cnt']!=1?'s':'' ?></div>
              </div>
            </div>
          </div>
          <div class="module-body">
            <div class="module-activity-label">Activity</div>
            <div style="font-size:11px;color:var(--muted);text-align:center;padding:10px 0">Reclaim activity will appear here once the module is live.</div>
          </div>
        </div>

      </div>

      <!-- ══ REVIEWS ══ -->
      <div class="section-title">Reviews</div>
      <div class="reviews-grid">

        <!-- BB reviews -->
        <div class="reviews-col">
          <div class="reviews-col-title rcol-bb">📚 Burrow Buddy</div>
          <?php if (empty($bb_ratings)): ?>
            <div class="no-reviews"><div class="no-reviews-txt">No reviews yet for Burrow Buddy.</div></div>
          <?php else: ?>
            <?php foreach ($bb_ratings as $r): ?>
            <div class="review-card">
              <div class="review-top">
                <span class="reviewer-name"><?= htmlspecialchars($r['rater_name']) ?></span>
                <span class="review-stars" style="color:#0e7490"><?= str_repeat('★',$r['stars']).str_repeat('☆',5-$r['stars']) ?></span>
              </div>
              <?php if ($r['comment']): ?>
                <div class="review-text">"<?= htmlspecialchars($r['comment']) ?>"</div>
              <?php endif; ?>
              <div class="review-date"><?= date('d M Y',strtotime($r['created_at'])) ?></div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- PT reviews -->
        <div class="reviews-col">
<div class="reviews-col-title rcol-pt">🧠 Brain Bridge</div>
          <?php if (empty($pt_ratings)): ?>
            <div class="no-reviews"><div class="no-reviews-txt">No reviews yet for Student Link.</div></div>
          <?php else: ?>
            <?php foreach ($pt_ratings as $r): ?>
            <div class="review-card">
              <div class="review-top">
                <span class="reviewer-name"><?= htmlspecialchars($r['rater_name']) ?></span>
                <span class="review-stars" style="color:#7c3aed"><?= str_repeat('★',$r['stars']).str_repeat('☆',5-$r['stars']) ?></span>
              </div>
              <?php if ($r['comment']): ?>
                <div class="review-text">"<?= htmlspecialchars($r['comment']) ?>"</div>
              <?php endif; ?>
              <div class="review-date"><?= date('d M Y',strtotime($r['created_at'])) ?></div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- RC reviews -->
        <div class="reviews-col">
          <div class="reviews-col-title rcol-rc">♻️ Reclaim</div>
          <?php if (empty($rc_ratings)): ?>
            <div class="no-reviews"><div class="no-reviews-txt">No reviews yet for Reclaim.</div></div>
          <?php else: ?>
            <?php foreach ($rc_ratings as $r): ?>
            <div class="review-card">
              <div class="review-top">
                <span class="reviewer-name"><?= htmlspecialchars($r['rater_name']) ?></span>
                <span class="review-stars" style="color:#b45309"><?= str_repeat('★',$r['stars']).str_repeat('☆',5-$r['stars']) ?></span>
              </div>
              <?php if ($r['comment']): ?>
                <div class="review-text">"<?= htmlspecialchars($r['comment']) ?>"</div>
              <?php endif; ?>
              <div class="review-date"><?= date('d M Y',strtotime($r['created_at'])) ?></div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

      </div>

    </div><!-- /scroll-area -->
  </div><!-- /main -->
</div><!-- /layout -->
</body>
</html>