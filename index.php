<?php
session_start();
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . ($_SESSION['role'] === 'admin' ? 'admin_dashboard.php' : 'student_dashboard.php'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>UniConnect — Portal</title>
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
  --offwhite: #fafafa;
  --text:     #2D142C;
  --muted:    #9a8a8a;
  --border:   #eddada;
}

html, body {
  height: 100%; min-height: 100vh;
  font-family: 'Nunito', sans-serif;
  background: #f0e8e8;
  display: flex; align-items: center; justify-content: center;
  overflow: hidden;
}

/* ── soft ambient bg ── */
body::before {
  content: '';
  position: fixed; inset: 0; z-index: 0;
  background:
    radial-gradient(ellipse 80% 60% at 10% 20%, rgba(238,69,64,0.1) 0%, transparent 55%),
    radial-gradient(ellipse 60% 80% at 90% 90%, rgba(81,10,50,0.12) 0%, transparent 55%);
}

/* ════════════════════════════
   OUTER SHELL
════════════════════════════ */
.shell {
  position: relative; z-index: 1;
  width: 960px;
  height: 580px;
  border-radius: 28px;
  overflow: visible;
  animation: shellIn 1s cubic-bezier(.16,1,.3,1) both;
}
@keyframes shellIn {
  from { opacity:0; transform: translateY(30px) scale(.97); }
  to   { opacity:1; transform: translateY(0) scale(1); }
}

/* ════════════════════════════
   LEFT PANEL — full background
════════════════════════════ */
.left-panel {
  position: absolute; inset: 0;
  background: linear-gradient(145deg, var(--coral) 0%, var(--crimson) 40%, var(--wine) 72%, var(--plum) 100%);
  border-radius: 28px;
  overflow: hidden;
  box-shadow: 0 28px 70px rgba(45,20,44,0.4);
}

/* decorative blobs */
.lp-blob {
  position: absolute; border-radius: 50%;
  background: rgba(255,255,255,0.08);
  animation: blobDrift var(--t,10s) ease-in-out infinite alternate;
}
.lp-blob-1 { width:260px; height:260px; top:-70px; right:-60px; --t:13s; }
.lp-blob-2 { width:180px; height:180px; bottom:-50px; left:-40px; --t:9s; animation-direction:alternate-reverse; }
.lp-blob-3 { width:130px; height:130px; top:45%; left:25%; --t:11s; }
@keyframes blobDrift {
  from { transform: translate(0,0) scale(1); }
  to   { transform: translate(14px,20px) scale(1.06); }
}

.lp-inner {
  position: relative; z-index: 2;
  height: 100%;
  width: 48%;
  display: flex; flex-direction: column;
  justify-content: center;
  padding: 32px 28px 32px 36px;
}

/* Illustration placeholder */
.illustration-area {
  flex: 1;
  display: flex; align-items: center; justify-content: center;
  margin: 16px 0;
  position: relative;
  width: 100%;
}
.illustration-area img.illus-img {
  max-width: 100%; max-height: 320px;
  object-fit: contain;
  filter: drop-shadow(0 14px 30px rgba(0,0,0,0.3));
  animation: floatImg 4s ease-in-out infinite alternate;
  display: block;
  position: relative; z-index: 2;
}
@keyframes floatImg {
  from { transform: translateY(0); }
  to   { transform: translateY(-10px); }
}
.illus-placeholder {
  width: 90%; height: 200px;
  border: 2px dashed rgba(255,255,255,0.25);
  border-radius: 16px;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  gap: 10px;
  color: rgba(255,255,255,0.45);
}
.illus-placeholder svg { opacity: .5; }
.illus-placeholder span { font-size: 11px; letter-spacing: .08em; font-weight: 300; }

/* Headline bottom */
.lp-headline {
  font-family: 'Cormorant Garamond', serif;
  font-size: 26px; font-weight: 600;
  color: #fff;
  line-height: 1.25;
  letter-spacing: .01em;
  margin-bottom: 6px;
}
.lp-sub {
  font-size: 12px; font-weight: 300;
  color: rgba(255,255,255,0.6);
  line-height: 1.65;
  letter-spacing: .03em;
}

/* ════════════════════════════
   WHITE CARD — floating on top
   of the image panel, right side
════════════════════════════ */
.card-wrap {
  position: absolute;
  right: 32px;
  top: 50%;
  transform: translateY(-50%);
  width: 52%;
  height: calc(100% - 60px);
  perspective: 1400px;
  z-index: 10;
}

.card-flipper {
  width: 100%; height: 100%;
  position: relative;
  transform-style: preserve-3d;
  transition: transform 0.85s cubic-bezier(0.65, 0, 0.35, 1);
  will-change: transform;
}
.card-flipper.flipped { transform: rotateY(180deg); }

/* Front = Register, Back = Login */
.card-face {
  position: absolute; inset: 0;
  background: var(--offwhite);
  border-radius: 22px;
  backface-visibility: hidden;
  -webkit-backface-visibility: hidden;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  padding: 36px 44px;
  box-shadow:
    0 40px 80px rgba(45,20,44,0.35),
    0 12px 30px rgba(45,20,44,0.18),
    0 2px 8px rgba(45,20,44,0.1),
    0 0 0 1.5px rgba(138, 43, 226, 0.55),
    0 0 12px rgba(138, 43, 226, 0.25),
    0 0 28px rgba(138, 43, 226, 0.12);
}
.card-face.back { transform: rotateY(180deg); }

/* ── Shared form styles ── */
.card-logo {
  margin-bottom: 12px;
}
.card-logo img {
  width: 50px; height: 50px;
  object-fit: contain; border-radius: 50%;
  border: 2px solid var(--border);
  padding: 5px; background: #fff;
  box-shadow: 0 3px 12px rgba(199,44,65,0.12);
}

.card-title {
  font-family: 'Cormorant Garamond', serif;
  font-size: 26px; font-weight: 600;
  color: var(--text);
  margin-bottom: 3px;
  text-align: center;
  letter-spacing: .01em;
}
.card-sub {
  font-size: 11.5px; font-weight: 300;
  color: var(--muted);
  margin-bottom: 22px;
  text-align: center;
  letter-spacing: .04em;
}

/* Fields */
.field {
  width: 100%;
  position: relative;
  margin-bottom: 12px;
}
.field-icon {
  position: absolute; left: 14px; top: 50%;
  transform: translateY(-50%);
  font-size: 14px; color: #cdb4b4;
  pointer-events: none;
  transition: color .3s;
}
.field:focus-within .field-icon { color: var(--crimson); }

input[type=text],
input[type=email],
input[type=password] {
  width: 100%; height: 44px;
  padding: 0 16px 0 40px;
  border: 1.5px solid var(--border);
  border-radius: 10px;
  font-family: 'Nunito', sans-serif;
  font-size: 13px; font-weight: 400;
  color: var(--text); background: #fff;
  outline: none;
  transition: border-color .3s, box-shadow .3s;
}
input::placeholder { color: #ccb8b8; font-weight: 300; font-size: 12.5px; }
input:focus {
  border-color: var(--crimson);
  box-shadow: 0 0 0 3px rgba(199,44,65,0.09);
}
input[type=checkbox] { display: none !important; }
input.valid   { border-color: #5caa80; }
input.invalid { border-color: var(--coral); }

/* Password toggle */
.pw-toggle {
  position: absolute; right: 13px; top: 50%;
  transform: translateY(-50%);
  cursor: pointer; color: #cdb4b4; font-size: 14px;
  transition: color .2s;
  user-select: none;
}
.pw-toggle:hover { color: var(--crimson); }

/* Meta row */
.meta-row {
  display: flex; align-items: center; justify-content: space-between;
  width: 100%; margin-bottom: 16px;
}
.remember { display: flex; align-items: center; gap: 7px; cursor: pointer; }
.chkbox {
  width: 15px; height: 15px;
  border: 1.5px solid var(--border); border-radius: 4px;
  background: #fff; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  transition: background .2s, border-color .2s;
}
.remember input:checked + .chkbox { background: var(--crimson); border-color: var(--crimson); }
.remember input:checked + .chkbox::after { content:'✓'; color:#fff; font-size:9px; font-weight:700; }
.remember span { font-size: 11.5px; color: var(--muted); font-weight: 400; }
.forgot { font-size: 11.5px; color: var(--crimson); text-decoration: none; font-weight: 500; }
.forgot:hover { text-decoration: underline; }

/* Submit */
.submit-btn {
  width: 100%; height: 46px;
  background: linear-gradient(130deg, var(--coral) 0%, var(--crimson) 55%, var(--wine) 100%);
  border: none; border-radius: 50px;
  color: #fff; font-family: 'Nunito', sans-serif;
  font-size: 12px; font-weight: 700;
  letter-spacing: .18em; text-transform: uppercase;
  cursor: pointer;
  position: relative; overflow: hidden;
  box-shadow: 0 6px 22px rgba(199,44,65,0.38);
  transition: transform .2s, box-shadow .3s;
  margin-bottom: 16px;
}
.submit-btn::before {
  content: ''; position: absolute; inset: 0;
  background: linear-gradient(130deg, var(--crimson), var(--plum));
  opacity: 0; transition: opacity .4s;
}
.submit-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(199,44,65,0.48); }
.submit-btn:hover::before { opacity: 1; }
.submit-btn:active { transform: scale(.98); }
.submit-btn .btn-label { position: relative; z-index: 1; }
.submit-btn.loading .btn-label { opacity: 0; }
.submit-btn.loading::after {
  content: ''; position: absolute;
  width: 18px; height: 18px;
  border: 2px solid rgba(255,255,255,.35);
  border-top-color: #fff; border-radius: 50%;
  top: 50%; left: 50%;
  transform: translate(-50%,-50%);
  animation: spin .7s linear infinite; z-index: 2;
}
@keyframes spin { to { transform: translate(-50%,-50%) rotate(360deg); } }

/* Switch link */
.switch-row {
  font-size: 12px; color: var(--muted);
  font-weight: 400; text-align: center;
}
.switch-row a {
  color: var(--crimson); font-weight: 600;
  text-decoration: none; cursor: pointer;
}
.switch-row a:hover { text-decoration: underline; }

/* Alert */
.alert {
  width: 100%; padding: 9px 14px; border-radius: 8px;
  font-size: 12px; font-weight: 400; margin-bottom: 10px;
  display: none; animation: alertIn .3s ease;
}
.alert.error   { background: #ffe8e8; color: #b03030; border: 1px solid #f5c6c6; }
.alert.success { background: #e8f6ee; color: #2a8050; border: 1px solid #b2dfc4; }
.alert.show    { display: block; }
@keyframes alertIn { from{opacity:0;transform:translateY(-4px)} to{opacity:1;transform:none} }

/* ── content fade-in after flip ── */
.card-face .inner-content {
  width: 100%;
  display: flex; flex-direction: column; align-items: center;
  opacity: 0;
  transform: translateY(8px);
  transition: opacity .45s ease, transform .45s ease;
}
.card-face.face-ready .inner-content {
  opacity: 1;
  transform: translateY(0);
}

/* Responsive */
@media (max-width: 980px) {
  .shell { width: 96vw; }
}
@media (max-width: 700px) {
  .shell { flex-direction: column; height: auto; width: 96vw; }
  .left-panel { width: 100%; height: 220px; border-radius: 22px 22px 0 0; }
  .card-wrap { position: relative; right: auto; top: auto; transform: none;
    width: 100%; height: auto; margin-top: -24px; padding: 0 12px 20px; }
  .card-face { position: relative; padding: 30px 28px; border-radius: 18px; }
  .card-flipper { height: auto; }
  .card-face.back { display: none; }
  .card-flipper.flipped .card-face.front { display: none; }
  .card-flipper.flipped .card-face.back  { display: flex; transform: none; }
}
</style>
</head>
<body>

<div class="shell">

  <!-- ══ LEFT PANEL ══ -->
  <div class="left-panel">
    <div class="lp-blob lp-blob-1"></div>
    <div class="lp-blob lp-blob-2"></div>
    <div class="lp-blob lp-blob-3"></div>
    <div class="lp-inner">

      <!-- Illustration area -->
      <div class="illustration-area">
        <img src="illustration.png" alt="Illustration" class="illus-img" id="illusImg"
          onload="document.getElementById('illusPlaceholder').style.display='none';"
          onerror="this.style.display='none'; document.getElementById('illusPlaceholder').style.display='flex';">
        <div class="illus-placeholder" id="illusPlaceholder" style="display:none;">
          <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
            <rect x="3" y="3" width="18" height="18" rx="3"/>
            <circle cx="8.5" cy="8.5" r="1.5"/>
            <polyline points="21 15 16 10 5 21"/>
          </svg>
          <span>Add illustration.png to your folder</span>
        </div>
      </div>

      <!-- Headline -->
      <div>
        <div class="lp-headline">Your Campus,<br>Connected.</div>
        <div class="lp-sub">connect with the campus<br>all in one place.</div>
      </div>

    </div>
  </div>

  <!-- ══ HOVERING WHITE CARD ══ -->
  <div class="card-wrap">
    <div class="card-flipper" id="flipper">

      <!-- FRONT — Register -->
      <!--<div class="card-face front face-ready" id="faceFront">
        <div class="inner-content" id="contentFront">
          <div class="card-logo"><img src="icbt_logo.png" alt="ICBT"></div>
          <div class="card-title">Create Account</div>
          <div class="card-sub">Join the UniConnect community today</div>

          <div class="alert" id="alertReg"></div>

          <form onsubmit="doRegister(event)" style="width:100%">
            <div class="field">
              <span class="field-icon">🎓</span>
              <input type="text" name="student_id" placeholder="Student ID" required
                oninput="lv(this, v=>v.trim().length>=2)">
            </div>
            <div class="field">
              <span class="field-icon">👤</span>
              <input type="text" name="full_name" placeholder="Full Name" required
                oninput="lv(this, v=>v.trim().length>=2)">
            </div>
            <div class="field">
              <span class="field-icon">✉</span>
              <input type="email" name="email" placeholder="Email Address" required
                oninput="lv(this, v=>/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v))">
            </div>
            <div class="field">
              <span class="field-icon">🔒</span>
              <input type="password" name="password" id="rPass" placeholder="Password" required>
              <span class="pw-toggle" onclick="togglePw('rPass',this)">👁</span>
            </div>
            <div class="field">
              <span class="field-icon">🔒</span>
              <input type="password" name="confirm_password" id="rConf" placeholder="Confirm Password" required
                oninput="lv(this, v=>v===document.getElementById('rPass').value&&v.length>0)">
              <span class="pw-toggle" onclick="togglePw('rConf',this)">👁</span>
            </div>
            <button type="submit" class="submit-btn" id="btnReg">
              <span class="btn-label">Sign Up</span>
            </button>
          </form>

          <div class="switch-row">Already have an account? <a onclick="flipToLogin()">Sign In</a></div>
        </div>
      </div>-->

      <!-- BACK — Login -->
      <div class="card-face front face-ready" id="faceBack">
  <div class="inner-content" id="contentBack">
          <div class="card-logo"><img src="icbt_logo.png" alt="ICBT"></div>
          <div class="card-title">Welcome Back</div>
          <div class="card-sub">Sign in to your UniConnect account</div>

          <div class="alert" id="alertLogin"></div>

          <form onsubmit="doLogin(event)" style="width:100%">
            <div class="field">
              <span class="field-icon">🎓</span>
              <input type="text" name="student_id" placeholder="Student ID" required autocomplete="username">
            </div>
            <div class="field">
              <span class="field-icon">🔒</span>
              <input type="password" name="password" id="lPass" placeholder="Password" required autocomplete="current-password">
              <span class="pw-toggle" onclick="togglePw('lPass',this)">👁</span>
            </div>
           <!-- <div class="meta-row">
              <label class="remember">
                <input type="checkbox" name="remember">
                <div class="chkbox"></div>
                <span>Remember me</span>
              </label>
              <a href="forgot-password.php" class="forgot">Forgot password?</a>
            </div>-->
            <button type="submit" class="submit-btn" id="btnLogin">
              <span class="btn-label">Sign In</span>
            </button>
          </form>

          <!--<div class="switch-row">Don't have an account? <a onclick="flipToRegister()">Sign Up</a></div>-->
        </div>
      </div>

    </div>
  </div><!-- /card-wrap -->

</div><!-- /shell -->

<script>
function togglePw(id, el) {
  const inp = document.getElementById(id);
  if (inp.type === 'password') { inp.type = 'text'; el.textContent = '🙈'; }
  else                          { inp.type = 'password'; el.textContent = '👁'; }
}

function lv(el, fn) {
  if (!el.value) { el.classList.remove('valid','invalid'); return; }
  el.classList.toggle('valid',   fn(el.value));
  el.classList.toggle('invalid', !fn(el.value));
}

function showAlert(id, msg, type) {
  const el = document.getElementById(id);
  el.textContent = msg;
  el.className = 'alert ' + type + ' show';
}
function clearAlert(id) { document.getElementById(id).className = 'alert'; }
function setLoad(btn, on) { btn.classList.toggle('loading', on); btn.disabled = on; }

async function doLogin(e) {
  e.preventDefault();
  clearAlert('alertLogin');
  const btn = document.getElementById('btnLogin');
  setLoad(btn, true);
  try {
    const res  = await fetch('auth/login.php', { method: 'POST', body: new FormData(e.target) });
    const text = await res.text();
    let json;
    try { json = JSON.parse(text); }
    catch { showAlert('alertLogin', 'Server error: ' + text.substring(0,120), 'error'); setLoad(btn,false); return; }
    if (json.success) {
      showAlert('alertLogin', '✓ Welcome back! Redirecting…', 'success');
      setTimeout(() => { window.location.href = json.redirect || 'student_dashboard.php'; }, 1200);
    } else {
      showAlert('alertLogin', json.message || 'Incorrect Student ID or password.', 'error');
    }
  } catch {
    showAlert('alertLogin', 'Could not reach server. Open via http://localhost/…', 'error');
  }
  setLoad(btn, false);
}

  
</script>
</body>
</html>