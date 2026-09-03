<?php
defined('ABSPATH') || exit;
$d = aimee_engine_chat_page_data($GLOBALS['aimee_engine_chat_market'] ?? 'uk');
$is_us = $d['is_us'];
$config_json = wp_json_encode([
    'rest'     => esc_url_raw($d['urls']['rest']),
    'stream'   => esc_url_raw($d['urls']['stream']),
    'nonce'    => $d['nonce'],
    'market'   => $d['market'],
    'uid'      => $d['uid'],
    'checkout' => $d['checkout_supported'],
    'pricing'  => esc_url_raw($d['urls']['pricing']),
    'chat'     => esc_url_raw($d['urls']['chat']),
    'subscription' => $d['subscription'],
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!doctype html>
<html lang="<?php echo esc_attr($d['locale']); ?>">
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0066cc">
<title>Aimee | Connected</title>
<?php wp_head(); ?>
<style>
:root{--rose:#e11d48;--rose-dark:#be123c;--rose-soft:#fff1f4;--ink:#18181b;--muted:#667085;--line:#e4e4e7;--panel:#fff;--chat:#efeae2;--user:#dcecff;--blue:#0066cc;--blue-dark:#0052a3;--shadow:0 28px 90px rgba(24,24,27,.16);--radius:26px;--ease:cubic-bezier(.16,1,.3,1);--safe-b:env(safe-area-inset-bottom,0px)}
*{box-sizing:border-box}
html,body{margin:0;min-height:100%;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#ececef;color:var(--ink);-webkit-font-smoothing:antialiased;-webkit-text-size-adjust:100%}
body{min-height:100dvh;overscroll-behavior-y:none}
#wpadminbar{display:none!important}html{margin-top:0!important}
button,input,textarea{font:inherit}button{touch-action:manipulation;cursor:pointer}
.shell{min-height:100dvh;display:grid;place-items:center;padding:14px}
.brand{font-size:25px;font-weight:900;letter-spacing:-.05em;color:var(--rose);text-decoration:none}
.app{width:min(1180px,100%);height:calc(100dvh - 28px);background:#fff;border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow);display:grid;grid-template-columns:300px 1fr;border:1px solid rgba(228,228,231,.8)}
.side{background:#fff;border-right:1px solid #e7e7e9;padding:24px;display:flex;flex-direction:column;min-height:0}
.side .market{font-size:10px;font-weight:850;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-top:5px}
.profile{margin-top:26px;display:flex;align-items:center;gap:12px;padding:14px;background:#fafafa;border-radius:18px}
.profile img,.profile-placeholder{width:48px;height:48px;border-radius:50%;object-fit:cover}.profile-placeholder{display:grid;place-items:center;background:#e4e4e7;color:#52525b;font-weight:900}
.profile strong{display:block}.profile small{display:block;color:var(--muted);margin-top:3px;font-size:12px}
.side nav{display:grid;gap:8px;margin-top:22px}
.side nav a,.side nav button{border:0;background:#f5f5f6;color:#27272a;text-decoration:none;padding:13px 15px;border-radius:14px;text-align:left;font-weight:700;font-size:14px;display:flex;align-items:center;gap:10px}
.side nav a:hover,.side nav button:hover{background:#ececef}
.side nav .gallery{background:var(--rose-soft);color:#9f1239}.side nav .gallery:hover{background:#ffe4ea}
.side .danger{margin-top:auto;color:#9f1239;text-decoration:none;font-weight:700;font-size:13px;padding:12px 3px}
.access-pill{margin-top:14px;padding:11px 13px;border-radius:14px;background:#f7f7f8;color:#3f3f46;font-size:12px;line-height:1.45}
.access-pill strong{display:block;color:var(--ink);font-size:13px;margin-bottom:2px}
.access-pill.preview{background:var(--rose-soft);color:#7f1d3d}.access-pill.preview strong{color:#9f1239}
.chat{display:flex;flex-direction:column;min-width:0;min-height:0;background:var(--chat)}
.chat-head{min-height:70px;background:var(--blue);color:#fff;display:flex;align-items:center;gap:12px;padding:10px 12px 10px 16px;box-shadow:0 2px 8px rgba(0,0,0,.12);z-index:3;flex-wrap:wrap}
.chat-head img{width:46px;height:46px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.25)}
.chat-head .who{min-width:0}.chat-head strong{display:block;font-size:16px;line-height:1.15}.chat-head span{font-size:12px;opacity:.85;display:block}
.chat-head .status.busy::after{content:"";display:inline-block;width:6px;height:6px;margin-left:6px;border-radius:50%;background:#fff;animation:pulse 1s infinite}
@keyframes pulse{50%{opacity:.2}}
.head-actions{margin-left:auto;display:flex;align-items:center;gap:8px}
#aimee-chat-gallery-link{display:inline-flex;min-height:40px;padding:8px 13px;align-items:center;gap:7px;border-radius:999px;background:#fff;color:#27272a;text-decoration:none;font-size:12px;font-weight:850;box-shadow:0 6px 18px rgba(24,24,27,.14);white-space:nowrap}
#aimee-chat-gallery-link:hover{background:var(--rose-soft);color:#9f1239}
.head-menu{display:none;width:40px;height:40px;border-radius:50%;border:0;background:rgba(255,255,255,.16);color:#fff;font-size:20px;line-height:1}
.messages{flex:1;min-height:0;overflow:auto;padding:18px 16px 12px;background-color:var(--chat);background-image:radial-gradient(rgba(0,0,0,.028) 1px,transparent 1px);background-size:18px 18px;overscroll-behavior:contain}
.day{display:flex;justify-content:center;margin:14px 0 8px}.day span{font-size:11px;font-weight:700;color:#6b7280;background:rgba(255,255,255,.75);padding:5px 10px;border-radius:999px}
.row{display:flex;margin:6px 0;animation:rise .25s var(--ease)}.row.user{justify-content:flex-end}
@keyframes rise{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.bubble{position:relative;max-width:min(640px,84%);background:#fff;border-radius:6px 14px 14px 14px;padding:9px 12px 20px;box-shadow:0 1px 2px rgba(0,0,0,.13);white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.48;font-size:15px}
.row.user .bubble{background:var(--user);border-radius:14px 6px 14px 14px}
.bubble time{position:absolute;right:10px;bottom:5px;font-size:10px;color:#8a8f98}
.bubble img{display:block;max-width:100%;border-radius:10px;margin:6px 0 4px}.bubble audio{display:block;max-width:100%;margin:6px 0 2px}
.bubble.live{padding-bottom:20px}.bubble.live::after{content:"";display:inline-block;width:7px;height:15px;margin-left:2px;vertical-align:-2px;background:#9ca3af;border-radius:2px;animation:blink 1s steps(2) infinite}
@keyframes blink{50%{opacity:0}}
.typing{display:inline-flex;gap:4px;padding:6px 2px}.typing i{width:7px;height:7px;background:#777;border-radius:50%;animation:b 1s infinite}.typing i:nth-child(2){animation-delay:.15s}.typing i:nth-child(3){animation-delay:.3s}@keyframes b{50%{transform:translateY(-4px)}}
.compose-wrap{background:#f0f2f5;padding:8px 10px calc(8px + var(--safe-b))}
.preview{display:none;align-items:center;gap:10px;padding:6px 4px 10px}.preview img{height:64px;border-radius:8px}.preview button{border:0;background:#fff;border-radius:999px;padding:8px 12px;font-size:12px;font-weight:700}
.compose{display:flex;align-items:flex-end;gap:8px}
.compose textarea{flex:1;border:0;border-radius:22px;padding:12px 15px;resize:none;min-height:46px;max-height:140px;line-height:1.4;outline:none;background:#fff;font-size:16px}
.icon,.send{border:0;border-radius:50%;width:46px;height:46px;font-size:19px;flex:0 0 auto;display:grid;place-items:center}
.icon{background:#fff;color:#5f6b72}.icon.rec{background:#fee2e2;color:#b91c1c}
.send{background:var(--blue);color:#fff}.send:disabled{opacity:.55}
.gallery-question-context{display:flex;align-items:center;gap:10px;margin:0 0 8px;padding:10px 12px;border:1px solid rgba(225,29,72,.2);border-radius:14px;background:#fff8fa;color:#4b1628;font-size:12px;font-weight:650;line-height:1.4}
.gallery-question-context span{flex:1;min-width:0}.gallery-question-context button{border:0;border-radius:999px;padding:7px 10px;background:#18181b;color:#fff;font-size:11px;font-weight:750}
.notice{margin:0 0 8px;padding:10px 12px;border-radius:14px;background:#fff;border:1px solid var(--line);font-size:12px;color:#3f3f46;display:flex;gap:10px;align-items:center}
.notice button{margin-left:auto;border:0;background:#18181b;color:#fff;border-radius:999px;padding:7px 11px;font-size:11px;font-weight:750}
.toast{position:fixed;left:50%;bottom:calc(24px + var(--safe-b));transform:translateX(-50%) translateY(20px);background:#18181b;color:#fff;padding:12px 16px;border-radius:14px;font-size:13px;font-weight:600;opacity:0;pointer-events:none;transition:.25s var(--ease);z-index:1200;max-width:min(520px,92vw);text-align:center}
.toast.show{opacity:1;transform:translateX(-50%)}
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);display:none;place-items:center;z-index:999;padding:18px}.overlay.open{display:grid}
.modal{width:min(760px,100%);max-height:92dvh;overflow:auto;background:#fff;border-radius:24px;padding:26px;position:relative}
.modal h2{font-size:28px;letter-spacing:-.04em;margin:0 44px 6px 0}.modal>p{color:var(--muted);margin:0 0 6px;line-height:1.55}
.close{position:absolute;right:18px;top:18px;border:0;background:#eee;border-radius:50%;width:38px;height:38px;font-size:18px}
.membership-status-card{margin:16px 0 4px;padding:14px 16px;border-radius:16px;background:#f7f7f8;border:1px solid var(--line)}
.membership-status-card strong{display:block;font-size:14px}.membership-status-card p{margin:5px 0 0;font-size:13px;color:#3f3f46;line-height:1.5}
.plans{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:18px}
.plan{border:1px solid var(--line);border-radius:18px;padding:16px;display:flex;flex-direction:column;gap:6px}
.plan h3{margin:0;font-size:14px;color:var(--muted);font-weight:700}.plan strong{font-size:24px}.plan small{color:var(--muted);font-size:12px;min-height:30px}
.plan button{margin-top:auto;width:100%;border:0;background:#18181b;color:#fff;border-radius:999px;padding:11px;font-weight:800}.plan button:disabled{opacity:.5}
.plan.featured{border-color:var(--rose);box-shadow:0 0 0 3px rgba(225,29,72,.08)}
.checkout-unavailable{display:block;width:100%;border-radius:999px;padding:11px;background:#e4e4e7;color:#52525b;text-align:center;font-size:12px;font-weight:800}
.billing-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px}
.billing-actions button{border:0;border-radius:999px;padding:11px 16px;font-weight:750;background:#f4f4f5;color:#27272a}.billing-actions button.danger{color:#9f1239}
.settings-form{display:grid;gap:14px;margin-top:18px}.settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.field{display:grid;gap:7px}.field label{font-size:13px;font-weight:750;color:#3f3f46}
.field input{width:100%;border:1px solid var(--line);background:#fff;border-radius:14px;padding:13px 14px;outline:none;font-size:16px}.field input:focus{border-color:#a1a1aa;box-shadow:0 0 0 4px rgba(24,24,27,.055)}
.field-note{font-size:11px;color:#85858e;line-height:1.5}
.sms-notice{padding:12px 14px;border:1px solid #f1c3cf;border-radius:15px;background:#fff5f7;color:#7f1d3d;font-size:11px;line-height:1.6}.sms-notice strong{display:block;color:#9f1239;font-size:12px}
.toggle-line{display:flex;align-items:flex-start;gap:10px;padding:13px 14px;border:1px solid var(--line);border-radius:15px;background:#fafafa}.toggle-line input{margin-top:3px}.toggle-line strong{display:block;font-size:13px}.toggle-line span{display:block;color:var(--muted);font-size:11px;line-height:1.5;margin-top:3px}
.settings-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-top:4px}.settings-status{font-size:12px;color:var(--muted);margin-right:auto}
.primary,.secondary{border:0;border-radius:999px;padding:12px 18px;font-weight:800}.primary{background:#18181b;color:#fff}.secondary{background:#f4f4f5;color:#27272a}
.sheet{position:fixed;inset:0;z-index:1000;display:none}.sheet.open{display:block}
.sheet .backdrop{position:absolute;inset:0;background:rgba(0,0,0,.45)}
.sheet .panel{position:absolute;left:0;right:0;bottom:0;background:#fff;border-radius:22px 22px 0 0;padding:14px 14px calc(18px + var(--safe-b));display:grid;gap:8px;animation:up .25s var(--ease)}
@keyframes up{from{transform:translateY(30px);opacity:0}to{transform:none;opacity:1}}
.sheet .panel a,.sheet .panel button{border:0;background:#f5f5f6;color:#27272a;text-decoration:none;padding:15px 16px;border-radius:14px;text-align:left;font-weight:700;font-size:15px;display:flex;align-items:center;gap:10px}
.sheet .panel .gallery{background:var(--rose-soft);color:#9f1239}.sheet .panel .danger{color:#9f1239;background:#fff;border:1px solid var(--line)}
.sheet .grip{width:40px;height:4px;border-radius:999px;background:#d4d4d8;margin:0 auto 8px}
@media(max-width:860px){
  html,body{height:100%;overflow:hidden;position:fixed;inset:0;width:100%}
  .shell{padding:0;display:block;height:var(--app-h,100dvh)}.app{height:var(--app-h,100dvh);border-radius:0;grid-template-columns:1fr;box-shadow:none;border:0}
  .side{display:none}.head-menu{display:grid;place-items:center}.chat-head{min-height:64px;padding-left:12px}
  .chat-head img{width:42px;height:42px}#aimee-chat-gallery-link span{display:none}#aimee-chat-gallery-link{min-width:40px;padding:8px 11px}
  .messages{padding:12px 10px 10px}.bubble{max-width:88%}.plans{grid-template-columns:1fr}.modal{padding:20px;border-radius:20px}.settings-grid{grid-template-columns:1fr}
}
@media(prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
</style>
</head>
<body <?php body_class('aimee-chat aimee-engine-chat aimee-market-' . $d['market']); ?>>
<?php wp_body_open(); ?>
<div class="shell">
<div class="app">
  <aside class="side">
    <a class="brand" href="<?php echo esc_url($d['urls']['home']); ?>">Aimee</a>
    <span class="market"><?php echo $is_us ? 'US experience' : 'UK experience'; ?></span>
    <div class="profile"><?php if ($d['photo']): ?><img src="<?php echo esc_url($d['photo']); ?>" alt="Your profile photo"><?php else: ?><span class="profile-placeholder" aria-hidden="true">You</span><?php endif; ?><div><strong><?php echo esc_html($d['first']); ?></strong><small>Private connection</small></div></div>
    <div class="access-pill" id="access-pill" hidden><strong id="settings-membership-label"></strong><span id="settings-membership-detail"></span></div>
    <nav>
      <button type="button" class="open-membership-btn" id="membership">💳 Membership</button>
      <button type="button" id="settings">⚙️ Account settings</button>
      <a class="gallery" href="<?php echo esc_url($d['urls']['gallery']); ?>">📸 Aimee’s photos</a>
      <a href="<?php echo esc_url($d['urls']['privacy']); ?>">🔒 Privacy &amp; safeguarding</a>
    </nav>
    <a class="danger" href="<?php echo esc_url($d['urls']['logout']); ?>">Sign out</a>
  </aside>
  <main class="chat">
    <header class="chat-head">
      <img src="<?php echo esc_url($d['portrait']); ?>" alt="Aimee">
      <div class="who"><strong>Aimee</strong><span class="status" id="status">online</span></div>
      <div class="head-actions">
        <a id="aimee-chat-gallery-link" href="<?php echo esc_url($d['urls']['gallery']); ?>" aria-label="Open Aimee’s photo gallery">📸 <span>Photos</span></a>
        <button type="button" class="head-menu" id="menu-btn" aria-label="Menu" aria-haspopup="true">⋮</button>
      </div>
    </header>
    <div class="messages" id="messages" role="log" aria-live="polite" aria-relevant="additions"></div>
    <div class="compose-wrap">
      <div id="notice-slot"></div>
      <div class="preview" id="preview"><img id="preview-img" alt="Selected image"><button type="button" id="remove-image">Remove</button></div>
      <div class="compose" id="message-composer">
        <button type="button" class="icon" id="image-btn" title="Add photo" aria-label="Add photo">＋</button>
        <input id="image-input" type="file" accept="image/*" hidden>
        <button type="button" class="icon" id="voice-btn" title="Record voice note" aria-label="Record voice note">🎙</button>
        <textarea id="composer-text" rows="1" placeholder="Message Aimee" autocomplete="off" enterkeyhint="send"></textarea>
        <button type="button" class="send" id="send" aria-label="Send">➤</button>
      </div>
    </div>
  </main>
</div>
</div>

<div class="sheet" id="menu-sheet" aria-hidden="true">
  <div class="backdrop" data-close-sheet></div>
  <div class="panel" role="menu">
    <div class="grip"></div>
    <button type="button" class="open-membership-btn" data-open="paywall">💳 Membership</button>
    <button type="button" data-open="settings-modal">⚙️ Account settings</button>
    <a class="gallery" href="<?php echo esc_url($d['urls']['gallery']); ?>">📸 Aimee’s photos</a>
    <a href="<?php echo esc_url($d['urls']['privacy']); ?>">🔒 Privacy &amp; safeguarding</a>
    <a class="danger" href="<?php echo esc_url($d['urls']['logout']); ?>">Sign out</a>
  </div>
</div>

<div class="overlay" id="settings-modal"><div class="modal"><button type="button" class="close" id="close-settings" aria-label="Close">×</button><h2>Account settings</h2><p>Choose how Aimee can contact you and when texts are welcome.</p>
  <form class="settings-form" id="settings-form" data-aimee-privacy-consent-settings="1"><input type="hidden" name="first_name" value="<?php echo esc_attr($d['first']); ?>">
    <div class="field"><label><?php echo $is_us ? 'US mobile number' : 'UK mobile number'; ?></label><input name="phone_number" inputmode="tel" value="<?php echo esc_attr($d['phone']); ?>" placeholder="<?php echo $is_us ? '+1 212 555 0123' : '07…'; ?>"><div class="field-note">The mobile number that will text Aimee.</div></div>
    <?php if ($is_us): ?><div class="sms-notice"><strong>International text charges may apply</strong>Aimee uses a UK +44 number. Your carrier may charge texts to or from it outside any included SMS package.</div><?php endif; ?>
    <label class="toggle-line"><input type="checkbox" name="sms_opt_in" value="1" <?php checked($d['sms_opt_in'], 1); ?> <?php disabled(!$d['sms_verified']); ?>><span><strong>Enable SMS with Aimee</strong><span><?php echo $d['sms_verified'] ? 'Aimee may reply to your texts and occasionally message first inside your Safe Window.' : 'Texts stay off until this mobile number has been verified.'; ?></span></span></label>
    <div class="settings-grid">
      <div class="field"><label>Safe Window starts</label><input name="safe_start" type="number" inputmode="numeric" min="0" max="23" value="<?php echo esc_attr($d['safe_start']); ?>"><div class="field-note">24-hour clock</div></div>
      <div class="field"><label>Safe Window ends</label><input name="safe_end" type="number" inputmode="numeric" min="0" max="23" value="<?php echo esc_attr($d['safe_end']); ?>"><div class="field-note">24-hour clock</div></div>
    </div>
    <label class="toggle-line"><input type="checkbox" name="sms_override" value="1" <?php checked($d['sms_override'], 1); ?>><span><strong>Allow important messages outside the Safe Window</strong><span>Aimee can occasionally text outside the preferred hours when it genuinely matters.</span></span></label>
    <p class="field-note">Read Aimee’s <a href="<?php echo esc_url($d['urls']['privacy']); ?>" target="_blank" rel="noopener">privacy notice</a> at any time. You do not need to acknowledge it to use ordinary chat.</p>
    <label class="toggle-line"><input type="checkbox" name="special_category_consent" value="1" <?php checked($d['special_consent']); ?>><span><strong>Optional sensitive-information consent</strong><span>Tick to enable specialist sensitive/adult processing. Unticking and saving withdraws consent immediately; ordinary chat remains available.</span></span></label>
    <div class="settings-actions"><span class="settings-status" id="settings-status"></span><button type="button" class="secondary" id="cancel-settings">Cancel</button><button type="submit" class="primary">Save settings</button></div>
  </form>
</div></div>

<div class="overlay" id="paywall"><div class="modal"><button type="button" class="close" id="close-paywall" aria-label="Close">×</button>
  <h2 id="membership-title">Membership</h2><p id="membership-modal-copy">Your first 30 replies are complimentary.</p>
  <div class="membership-status-card"><strong id="membership-status-display">Checking your membership…</strong><p id="membership-status-detail"></p></div>
  <?php if ($d['checkout_supported']): ?>
  <div class="plans" id="plans">
    <?php foreach ($d['plans'] as $key => $plan): ?>
      <div class="plan <?php echo $key === 'monthly' ? 'featured' : ''; ?>">
        <h3><?php echo esc_html($plan['label'] ?? ucfirst($key)); ?></h3>
        <strong><?php echo esc_html(aimee_engine_money($plan['amount_pence'] ?? 0, $d['symbol'])); ?></strong>
        <small><?php echo esc_html(['weekly' => 'Billed weekly. Cancel any time.', 'monthly' => 'Billed monthly. Our most popular.', 'annual' => 'One payment a year. Best value.'][$key] ?? ''); ?></small>
        <button type="button" class="membership-checkout-btn" data-plan="<?php echo esc_attr($key); ?>"><span class="membership-plan-action">Choose <?php echo esc_html(ucfirst($key)); ?></span></button>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="field-note" style="margin-top:12px">UK membership is set up by Direct Debit through GoCardless. You will be taken to GoCardless and brought straight back here. Your access starts as soon as the mandate is authorised; the first collection follows in a few working days.</p>
  <?php else: ?>
  <span class="checkout-unavailable" style="margin-top:16px">New paid membership checkout is currently available for UK profiles only.</span>
  <?php endif; ?>
  <div class="billing-actions" id="billing-actions" hidden>
    <button type="button" id="manage-membership-btn" data-billing-action="portal" hidden>Manage billing</button>
    <button type="button" class="danger" id="cancel-membership-btn" data-billing-action="cancel" hidden>Cancel renewal</button>
  </div>
</div></div>

<div class="toast" id="toast" role="status"></div>

<script>window.AIMEE_ENGINE_CHAT=<?php echo $config_json; ?>;</script>
<script>
(function(cfg){
"use strict";
var q=function(s){return document.querySelector(s)};
var msgs=q('#messages'),text=q('#composer-text'),sendBtn=q('#send'),imageInput=q('#image-input'),preview=q('#preview'),previewImg=q('#preview-img'),statusEl=q('#status'),toastEl=q('#toast');
var image=null,imageEventId='',sending=false,recorder=null,chunks=[],lastDayKey='',historyFingerprint='',subscription=cfg.subscription||null,lastMessageId=0;
var coarse=window.matchMedia&&window.matchMedia('(pointer:coarse)').matches;

function api(path,opt){opt=opt||{};opt.headers=Object.assign({'Content-Type':'application/json','X-WP-Nonce':cfg.nonce},opt.headers||{});opt.credentials='same-origin';return fetch(cfg.rest+path,opt).then(function(r){return r.json().catch(function(){return{}}).then(function(data){if(!r.ok){var e=new Error(data.message||'Request failed');e.data=data;e.status=r.status;throw e}return data})})}
function toast(message,ms){toastEl.textContent=message;toastEl.classList.add('show');clearTimeout(toast.t);toast.t=setTimeout(function(){toastEl.classList.remove('show')},ms||3200)}
function setStatus(label,busy){statusEl.textContent=label;statusEl.classList.toggle('busy',!!busy)}
function scrollBottom(force){var near=(msgs.scrollHeight-msgs.scrollTop-msgs.clientHeight)<220;if(force||near)msgs.scrollTop=msgs.scrollHeight}
function fmtTime(iso){if(!iso)return'';var d=parseDate(iso);return d?d.toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'}):''}
function parseDate(v){if(!v)return null;var s=String(v);if(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(s))s=s.replace(' ','T')+'Z';var d=new Date(s);return isNaN(d)?null:d}
function dayLabel(d){var today=new Date(),y=new Date();y.setDate(today.getDate()-1);var k=d.toDateString();if(k===today.toDateString())return'Today';if(k===y.toDateString())return'Yesterday';return d.toLocaleDateString([],{weekday:'long',day:'numeric',month:'long'})}
function daySeparator(iso){var d=parseDate(iso);if(!d)return;var key=d.toDateString();if(key===lastDayKey)return;lastDayKey=key;var el=document.createElement('div');el.className='day';var s=document.createElement('span');s.textContent=dayLabel(d);el.append(s);msgs.append(el)}
function row(textValue,who,iso){var r=document.createElement('div');r.className='row '+who;var b=document.createElement('div');b.className='bubble';if(textValue)b.append(document.createTextNode(textValue));var t=document.createElement('time');t.textContent=fmtTime(iso||new Date().toISOString());b.append(t);r.append(b);msgs.append(r);return b}
function setBubbleText(b,value){var t=b.querySelector('time');b.textContent='';if(value)b.append(document.createTextNode(value));if(t)b.append(t)}
function attachMedia(b,media){if(!media||!media.url)return;var i=new Image;i.src=media.url;i.alt=media.alt||'';if(media.delivery_id)i.setAttribute('data-delivery-id',media.delivery_id);i.loading='lazy';var t=b.querySelector('time');b.insertBefore(i,t)}
function render(m){var who=(m.sender==='user'||String(m.sender)===String(cfg.uid))?'user':'aimee';daySeparator(m.created_at);var b=row(m.message_text||'',who,m.created_at);attachMedia(b,m.media);if(m.voice_note&&m.voice_note.audio_url){var a=document.createElement('audio');a.controls=true;a.src=m.voice_note.audio_url;b.insertBefore(a,b.querySelector('time'))}if(m.message_id)lastMessageId=Math.max(lastMessageId,Number(m.message_id)||0)}
function signature(list){return JSON.stringify((list||[]).map(function(m){return[m.message_id||0,m.sender||'',m.created_at||'',(m.message_text||'').length,m.media&&(m.media.key||m.media.url)||'']}))}

function history(force){if(sending)return Promise.resolve();return api('/history?_t='+Date.now()).then(function(d){if(d.subscription)applySubscription(d.subscription);var list=d.messages||[];var sig=signature(list);if(!force&&sig===historyFingerprint)return;historyFingerprint=sig;var stick=force||(msgs.scrollHeight-msgs.scrollTop-msgs.clientHeight)<220;msgs.innerHTML='';lastDayKey='';list.forEach(render);if(stick)scrollBottom(true)}).catch(function(e){if(!force)return;toast('Could not load the conversation. Pull to refresh.')})}

function applySubscription(s){if(!s)return;subscription=s;var pill=q('#access-pill'),label=q('#settings-membership-label'),detail=q('#settings-membership-detail');var text='',title='',preview=false;
 if(s.status==='trial'){preview=true;title=(s.preview_remaining||0)+' of '+(s.preview_message_limit||30)+' free replies left';text='Membership opens everything up.'}
 else if(s.access_active){title='Member';text=s.access_until?'Access until '+new Date(s.access_until).toLocaleDateString([],{day:'numeric',month:'short',year:'numeric'}):'Full access.';if(s.cancel_at_period_end)text='Renewal cancelled. '+text}
 else if(s.status==='past_due'){title='Payment overdue';text='Update your billing to continue.'}
 else if(s.status==='migration_required'||s.requires_reactivation){title='Reconnect membership';text='Your previous billing account closed.'}
 else{title='Preview ended';text='Choose a membership to continue.'}
 label.textContent=title;detail.textContent=text;pill.hidden=false;pill.classList.toggle('preview',preview);renderMembership(s)}

function renderMembership(s){var head=q('#membership-status-display'),det=q('#membership-status-detail'),title=q('#membership-title'),copy=q('#membership-modal-copy'),actions=q('#billing-actions'),manage=q('#manage-membership-btn'),cancel=q('#cancel-membership-btn'),plans=q('#plans');
 var untilText=s.access_until?new Date(s.access_until).toLocaleDateString([],{day:'numeric',month:'long',year:'numeric'}):'';
 if(s.status==='trial'){title.textContent='Continue with Aimee';copy.textContent='Your first '+(s.preview_message_limit||30)+' replies are complimentary.';head.textContent=(s.preview_remaining||0)+' free replies left';det.textContent='Membership unlocks unlimited conversation, private photos and texting.'}
 else if(s.access_active&&s.access_source==='managed_subscription'){title.textContent='Your membership';copy.textContent='Thank you for being with Aimee.';head.textContent='Active'+(s.plan?' · '+s.plan.charAt(0).toUpperCase()+s.plan.slice(1):'');det.textContent=(s.cancel_at_period_end?'Renewal is cancelled. Access continues until ':'Renews on ')+untilText+'.'}
 else if(s.access_active){title.textContent='Your access';copy.textContent='';head.textContent=s.access_source==='administrator'?'Administrator':s.access_source==='free_preview'?'Preview':'Complimentary access';det.textContent=untilText?'Until '+untilText+'.':''}
 else if(s.status==='migration_required'||s.requires_reactivation){title.textContent='Reconnect your membership';copy.textContent='Your previous subscription was linked to our former payment account, which is now closed.';head.textContent='Needs a new membership';det.textContent='Everything Aimee remembers is safe. Choose a plan to carry on where you left off.'}
 else if(s.status==='past_due'){title.textContent='Payment overdue';copy.textContent='';head.textContent='Past due';det.textContent='Your last payment did not complete. Manage billing to sort it out.'}
 else{title.textContent='Continue with Aimee';copy.textContent='Your complimentary preview has ended.';head.textContent='Preview ended';det.textContent='Choose a membership and we carry on exactly where we left off.'}
 if(plans){var available=cfg.checkout&&s.checkout_available!==false&&!(s.access_active&&s.access_source==='managed_subscription');plans.querySelectorAll('[data-plan]').forEach(function(b){b.disabled=!available});if(s.checkout_opens_at&&!available){det.textContent+=' Checkout opens on '+new Date(s.checkout_opens_at).toLocaleDateString([],{day:'numeric',month:'long'})+'.'}}
 var manageable=!!s.can_manage_billing&&['active','trialing','past_due','unpaid','paused'].indexOf(String(s.billing_status||s.status||'').toLowerCase())>=0;
 actions.hidden=!manageable;manage.hidden=!manageable;cancel.hidden=!manageable||!!s.cancel_at_period_end}

function openModal(id){q('#'+id).classList.add('open');if(id==='paywall'&&subscription)renderMembership(subscription)}
function closeModals(){document.querySelectorAll('.overlay.open').forEach(function(o){o.classList.remove('open')});q('#menu-sheet').classList.remove('open')}
document.querySelectorAll('.overlay').forEach(function(o){o.addEventListener('click',function(e){if(e.target===o)closeModals()})});
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeModals()});
q('#close-settings').onclick=q('#cancel-settings').onclick=closeModals;q('#close-paywall').onclick=closeModals;
q('#settings').onclick=function(){openModal('settings-modal')};q('#membership').onclick=function(){openModal('paywall')};
q('#menu-btn').onclick=function(){q('#menu-sheet').classList.add('open')};
document.querySelectorAll('[data-close-sheet]').forEach(function(el){el.onclick=closeModals});
document.querySelectorAll('#menu-sheet [data-open]').forEach(function(b){b.onclick=function(){closeModals();openModal(b.getAttribute('data-open'))}});

/* Camera Roll reference handed over from the gallery page. */
var galleryKey='aimeeGalleryQuestion:1',galleryMaxAge=10*60*1000,galleryDefault='What’s the story behind this photo?',galleryReference=null;
function galleryFresh(r){var key=r&&typeof r.key==='string'?r.key:'',c=r?Number(r.created_at||0):0,now=Date.now();return/^[a-z0-9_-]{1,191}$/.test(key)&&isFinite(c)&&c<=now+60000&&c>=now-galleryMaxAge}
function clearGallery(clearText){galleryReference=null;try{sessionStorage.removeItem(galleryKey)}catch(e){}var chip=q('#gallery-question-context');if(chip)chip.remove();if(clearText&&text.value===galleryDefault){text.value='';autosize()}}
function mountGallery(){var v=null;try{v=JSON.parse(sessionStorage.getItem(galleryKey)||'null')}catch(e){}var c={key:v&&typeof v.key==='string'?v.key:'',created_at:v?Number(v.created_at||0):0};if(!galleryFresh(c)){clearGallery(false);return}galleryReference=c;if(!text.value.trim()){text.value=galleryDefault;autosize()}var chip=document.createElement('div');chip.id='gallery-question-context';chip.className='gallery-question-context';chip.setAttribute('role','status');var l=document.createElement('span');l.textContent='Asking Aimee about this Camera Roll photo';var x=document.createElement('button');x.type='button';x.textContent='Cancel';x.onclick=function(){clearGallery(true)};chip.append(l,x);q('#notice-slot').append(chip)}

/* Composer */
function autosize(){text.style.height='auto';text.style.height=Math.min(140,text.scrollHeight)+'px'}
text.addEventListener('input',autosize);
text.addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey&&!coarse){e.preventDefault();sendMessage()}});
function newImageEventId(){return window.crypto&&crypto.randomUUID?crypto.randomUUID():'img-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2)}
function clearImage(){image=null;imageEventId='';imageInput.value='';preview.style.display='none';previewImg.removeAttribute('src')}
q('#image-btn').onclick=function(){imageInput.click()};q('#remove-image').onclick=clearImage;
imageInput.onchange=function(){var f=imageInput.files[0];if(!f){clearImage();return}clearGallery(true);var rd=new FileReader;rd.onload=function(){image=rd.result;imageEventId=newImageEventId();previewImg.src=image;preview.style.display='flex'};rd.readAsDataURL(f)};

function typingBubble(){var b=row('','aimee');b.id='typing';var t=b.querySelector('time');b.textContent='';b.innerHTML='<span class="typing"><i></i><i></i><i></i></span>';if(t)b.append(t);scrollBottom(true);return b}

function handleDone(d,bubble){var r=bubble.parentElement;if(d.reply||d.media){bubble.classList.remove('live');bubble.id='';setBubbleText(bubble,d.reply||'');attachMedia(bubble,d.media);var t=bubble.querySelector('time');if(t)t.textContent=fmtTime(new Date().toISOString())}else{r.remove()}
 if(d.subscription)applySubscription(d.subscription);
 if(['trial_ended','subscription_required','billing_reactivation_required','insufficient_funds'].indexOf(d.status)>=0){openModal('paywall')}
 scrollBottom(true)}

function streamTurn(payload,bubble){var live=false;function ensureLive(){if(live)return;live=true;bubble.classList.add('live');setBubbleText(bubble,'')}
 return fetch(cfg.stream,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce,'Accept':'text/event-stream'},body:JSON.stringify(payload)}).then(function(r){
  var ct=(r.headers.get('content-type')||'').toLowerCase();
  if(ct.indexOf('text/event-stream')<0||!r.body){return r.json().then(function(d){if(!r.ok){var e=new Error(d.message||'Request failed');e.data=d;throw e}return d})}
  var reader=r.body.getReader(),dec=new TextDecoder(),buf='',done=null,failed=null,current='';
  function handle(ev,data){if(ev==='delta'){ensureLive();current+=data.text||'';setBubbleText(bubble,current);scrollBottom(false)}else if(ev==='replace'){ensureLive();current=data.text||'';setBubbleText(bubble,current)}else if(ev==='status'){setStatus(data.state==='photo'?'choosing a photo…':data.state==='writing'?'typing…':'thinking…',true)}else if(ev==='done'){done=data}else if(ev==='error'){failed=data}}
  function pump(){return reader.read().then(function(res){if(res.done)return;buf+=dec.decode(res.value,{stream:true});var parts=buf.split('\n\n');buf=parts.pop();parts.forEach(function(block){var ev='message',data='';block.split('\n').forEach(function(line){if(line.indexOf('event:')===0)ev=line.slice(6).trim();else if(line.indexOf('data:')===0)data+=line.slice(5).trim()});if(!data)return;try{handle(ev,JSON.parse(data))}catch(e){}});return pump()})}
  return pump().then(function(){if(failed){var e=new Error(failed.message||'Request failed');e.data=failed;throw e}if(!done)throw new Error('The connection closed before Aimee finished.');return done})})}

function sendMessage(){if(sending)return;if(galleryReference&&!galleryFresh(galleryReference))clearGallery(true);
 var message=text.value.trim(),outImage=image,outEventId=imageEventId;
 if(outImage&&galleryReference){if(message===galleryDefault)message='';clearGallery(true)}
 if(galleryReference&&!outImage&&!message)message=galleryDefault;
 var refKey=galleryReference&&!outImage?galleryReference.key:'';
 if(!message&&!outImage)return;if(refKey)clearGallery(false);
 sending=true;sendBtn.disabled=true;
 daySeparator(new Date().toISOString());if(message||outImage){var ub=row(message,'user');if(outImage){var i=new Image;i.src=outImage;i.alt='';ub.insertBefore(i,ub.querySelector('time'))}}
 text.value='';autosize();clearImage();var bubble=typingBubble();setStatus('thinking…',true);
 var payload={message:message,image:outImage,image_event_id:outImage?outEventId:'',market:cfg.market,request_id:newImageEventId()};if(refKey)payload.referenced_media_key=refKey;
 streamTurn(payload,bubble).then(function(d){handleDone(d,bubble)}).catch(function(e){var d=e&&e.data||{};if(d.status&&['trial_ended','subscription_required','billing_reactivation_required'].indexOf(d.status)>=0){handleDone(d,bubble);return}bubble.parentElement.remove();toast(e.message||'Connection interrupted. Please try again.',4200);history(true)}).then(function(){sending=false;sendBtn.disabled=false;setStatus('online',false);if(!coarse)text.focus({preventScroll:true});scrollBottom(true)})}
sendBtn.onclick=function(){if(coarse)text.blur();sendMessage()};
/* Keep the thread above the on-screen keyboard: size the app to the visual viewport and pin the page. */
(function(){var vv=window.visualViewport;if(!vv)return;var raf=0;function fit(){cancelAnimationFrame(raf);raf=requestAnimationFrame(function(){var h=Math.round(vv.height);document.documentElement.style.setProperty('--app-h',h+'px');if(window.scrollY)window.scrollTo(0,0);scrollBottom(false)})}vv.addEventListener('resize',fit);vv.addEventListener('scroll',fit);window.addEventListener('orientationchange',function(){setTimeout(fit,350)});fit()})();
text.addEventListener('focus',function(){setTimeout(function(){scrollBottom(true)},350)});

/* Voice notes stay on Aimee Global's endpoint. */
q('#voice-btn').onclick=function(){var btn=q('#voice-btn');if(recorder&&recorder.state==='recording'){recorder.stop();btn.classList.remove('rec');btn.textContent='🎙';return}clearGallery(true);
 navigator.mediaDevices.getUserMedia({audio:true}).then(function(stream){recorder=new MediaRecorder(stream);chunks=[];recorder.ondataavailable=function(e){chunks.push(e.data)};recorder.onstop=function(){stream.getTracks().forEach(function(t){t.stop()});var blob=new Blob(chunks,{type:recorder.mimeType});var fd=new FormData;fd.append('audio',blob,'voice-note.webm');setStatus('listening…',true);fetch(cfg.rest+'/voice-note/send',{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':cfg.nonce},body:fd}).then(function(r){return r.json().then(function(d){if(!r.ok)throw new Error(d.message||'Voice note failed');row('Voice note sent','user');scrollBottom(true);setTimeout(function(){history(true);setStatus('online',false)},2500)})}).catch(function(e){setStatus('online',false);toast(e.message)})};recorder.start();btn.classList.add('rec');btn.textContent='■'}).catch(function(){toast('Microphone access was not available.')})};

/* Membership */
document.querySelectorAll('[data-plan]').forEach(function(b){b.onclick=function(){if(!cfg.checkout){toast('New membership checkout is available for UK profiles only.');return}b.disabled=true;api('/subscription-checkout',{method:'POST',body:JSON.stringify({plan:b.dataset.plan,source:'chat',market:cfg.market})}).then(function(d){if(d.checkout_url){location.href=d.checkout_url}else if(d.status==='already_active'){toast('Your membership is already active.');if(d.subscription)applySubscription(d.subscription)}else{toast(d.message||'Checkout could not start.')}}).catch(function(e){var d=e.data||{};toast((e.message||'Checkout could not start.')+(d.diagnostic?' — '+d.diagnostic:''),d.diagnostic?12000:4500);if(d.subscription)applySubscription(d.subscription)}).then(function(){b.disabled=false})}});
q('#manage-membership-btn').onclick=function(){var b=this;b.disabled=true;api('/billing-portal',{method:'POST',body:JSON.stringify({market:cfg.market})}).then(function(d){if(d.url||d.portal_url)location.href=d.url||d.portal_url;else toast(d.message||'Billing settings are not available right now.')}).catch(function(e){toast(e.message,4500)}).then(function(){b.disabled=false})};
q('#cancel-membership-btn').onclick=function(){if(!confirm('Stop your membership renewing? You keep access until the end of the current period.'))return;var b=this;b.disabled=true;api('/subscription-cancel',{method:'POST',body:JSON.stringify({market:cfg.market})}).then(function(d){toast(d.message||'Renewal cancelled.');if(d.subscription)applySubscription(d.subscription);else history(true)}).catch(function(e){toast(e.message,4500)}).then(function(){b.disabled=false})};

function checkoutReturn(){var p=new URLSearchParams(location.search);if(!p.has('membership'))return;var outcome=p.get('membership');window.history.replaceState(null,'',location.pathname);
 if(outcome!=='success'){toast('Checkout was cancelled. Nothing was charged.',4500);return}
 var attempts=0;function verify(){attempts++;return api('/subscription-status?_t='+Date.now()).then(function(d){if(d.subscription)applySubscription(d.subscription);if(d.verified||(d.subscription&&d.subscription.access_active)){toast('Membership active. Welcome in.',4500);closeModals();return}if(d.pending&&attempts<6){setTimeout(verify,2500);return}toast('Your bank authorisation is being confirmed. This can take a moment.',5000)}).catch(function(e){toast(e.message||'Membership could not be confirmed yet.',5000)})}
 toast('Confirming your membership…');verify()}

/* Settings */
var settingsForm=q('#settings-form'),settingsStatus=q('#settings-status'),phoneField=settingsForm.elements.phone_number,smsField=settingsForm.elements.sms_opt_in,initialPhone=phoneField.value;
phoneField.addEventListener('input',function(){if(phoneField.value.trim()!==initialPhone.trim()&&smsField)smsField.checked=false});
settingsForm.addEventListener('submit',function(e){e.preventDefault();var f=new FormData(settingsForm);var payload={first_name:f.get('first_name')||'',phone_number:f.get('phone_number')||'',sms_opt_in:f.has('sms_opt_in'),sms_override:f.has('sms_override'),safe_start:Number(f.get('safe_start')||9),safe_end:Number(f.get('safe_end')||17),sms_timezone:Intl.DateTimeFormat().resolvedOptions().timeZone||'',market:cfg.market};
 var save=settingsForm.querySelector('[type="submit"]');save.disabled=true;settingsStatus.textContent='Saving…';
 api('/privacy-consent',{method:'POST',body:JSON.stringify({special_category_consent:f.has('special_category_consent')})}).then(function(pr){settingsForm.elements.special_category_consent.checked=!!pr.special_category_consent;return api('/settings',{method:'POST',body:JSON.stringify(payload)})}).then(function(d){settingsStatus.textContent='Saved';initialPhone=phoneField.value;if(d.subscription)applySubscription(d.subscription);setTimeout(closeModals,400)}).catch(function(err){settingsStatus.textContent=err.message||'Could not save'}).then(function(){save.disabled=false})});

/* Boot */
if(subscription)applySubscription(subscription);
mountGallery();history(true).then(checkoutReturn);
setInterval(function(){if(!document.hidden&&!sending)history(false)},10000);
document.addEventListener('visibilitychange',function(){if(!document.hidden&&!sending)history(false)});
window.addEventListener('focus',function(){if(!sending)history(false)});
})(window.AIMEE_ENGINE_CHAT);
</script>
<?php echo aimee_engine_chat_page_injections($d['market']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<?php wp_footer(); ?>
</body>
</html>
