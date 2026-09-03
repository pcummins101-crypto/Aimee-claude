<?php
defined('ABSPATH') || exit;

$c = aimee_global_market_config($aimee_market);
$plans = aimee_membership_plans($aimee_market);
$error = '';
$is_us = $aimee_market === 'us';
$checkout_market_supported = !$is_us;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aimee_login_submit'])) {
    $nonce_ok = isset($_POST['aimee_login_nonce']) && wp_verify_nonce(
        sanitize_text_field(wp_unslash($_POST['aimee_login_nonce'])),
        'aimee_login'
    );

    if (!$nonce_ok) {
        $error = 'Please refresh the page and try again.';
    } else {
        $login = sanitize_text_field(wp_unslash($_POST['aimee_login_id'] ?? ''));
        // Passwords are opaque credentials. Sanitising changes valid strong
        // passphrases and can make an otherwise correct login impossible.
        $pin = isset($_POST['aimee_pin']) && is_string($_POST['aimee_pin'])
            ? wp_unslash($_POST['aimee_pin'])
            : '';
        // The global authenticate filter resolves mobile aliases and applies one
        // shared throttle. One wp_signon call prevents a single form submission
        // from being counted several times while trying equivalent aliases.
        $user = wp_signon([
            'user_login' => $login,
            'user_password' => $pin,
            'remember' => true,
        ], is_ssl());

        if (is_wp_error($user)) {
            $error = 'Those details did not match an account.';
        } else {
            wp_safe_redirect(aimee_global_route('chat', $aimee_market));
            exit;
        }
    }
}

$is_auth = is_user_logged_in();
$uid = get_current_user_id();
$nonce = $is_auth ? wp_create_nonce('wp_rest') : '';
$first = '';
$photo = '';
$profile = null;
$phone_value = '';
$sms_opt_in = 0;
$sms_override = 0;
$safe_start = 9;
$safe_end = 17;
$sms_verified = false;
$special_category_consent = false;

if ($is_auth) {
    global $wpdb;
    $profile = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM aimee_user_profiles WHERE user_id = %d',
        $uid
    ));
    $first = $profile && $profile->first_name
        ? $profile->first_name
        : wp_get_current_user()->display_name;
    // Never render a database URL. It may still contain the public 1.8.2
    // uploads URL while the verified one-time migration is retrying. Only an
    // owner-bound, revalidated private file enables the authenticated endpoint.
    $photo = function_exists('aimee_profile_media_file_for_user')
        && aimee_profile_media_file_for_user($uid)
        && function_exists('aimee_profile_media_url')
            ? aimee_profile_media_url()
            : '';
    $phone_value = $profile ? (string) ($profile->phone_number ?? '') : '';
    if ($is_us && preg_match('/^1[0-9]{10}$/', $phone_value)) {
        $phone_value = '+' . $phone_value;
    } elseif (!$is_us && preg_match('/^44[0-9]{10}$/', $phone_value)) {
        $phone_value = '+' . $phone_value;
    }
    $sms_opt_in = $profile ? intval($profile->sms_opt_in ?? 0) : 0;
    $sms_override = $profile ? intval($profile->sms_override ?? 0) : 0;
    $safe_start = $profile ? intval($profile->sms_safe_start_hour ?? 9) : 9;
    $safe_end = $profile ? intval($profile->sms_safe_end_hour ?? 17) : 17;
    $sms_verified = $profile
        && function_exists('aimee_global_sms_profile_is_verified')
        && aimee_global_sms_profile_is_verified($profile);
    $special_category_consent = $profile
        && function_exists('aimee_special_category_consent_is_active')
        && aimee_special_category_consent_is_active($profile);
}

$portrait = AIMEE_GLOBAL_URL . 'assets/pwa/aimee-icon-512.png';
$home_url = aimee_global_route('home', $aimee_market);
$chat_url = aimee_global_route('chat', $aimee_market);
$gallery_url = aimee_global_route('gallery', $aimee_market);
$privacy_url = aimee_global_route('privacy', $aimee_market);

if ($is_us) {
    $join_intro = 'Create your private connection with Aimee. Use a US mobile number for optional SMS later, or choose an email address or memorable username for web chat.';
    $login_label = 'US mobile number, email address or username';
    $login_placeholder = '+1 212 555 0123, email, or username';
    $login_help = 'Using your US mobile lets Aimee recognise your texts. You can also use an email address or username for web chat.';
    $art_kicker = 'Your first conversation starts here';
    $art_note = 'Aimee remembers the details that matter, forms her own opinions and remains unmistakably British wherever you are.';
    $status_line = 'online';
    $market_badge = 'US experience';
} else {
    $join_intro = 'Create your private connection with Aimee. Use a UK mobile number for optional SMS later, or choose a memorable username for web chat.';
    $login_label = 'UK mobile number or username';
    $login_placeholder = '07… or choose a username';
    $login_help = 'A UK mobile number can unlock optional texts from Aimee. A username works perfectly for web chat.';
    $art_kicker = 'Your first conversation starts here';
    $art_note = 'Aimee remembers the details that matter, forms her own opinions and does not simply agree with everything you say.';
    $status_line = 'online';
    $market_badge = 'UK experience';
}
?>
<!doctype html>
<html lang="<?php echo esc_attr($c['locale']); ?>">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <title>Aimee | Connected</title>
    <?php wp_head(); ?>
    <style>
        :root{
            --rose:#e11d48;--rose-dark:#be123c;--rose-soft:#fff1f4;--ink:#18181b;--muted:#667085;
            --line:#e4e4e7;--paper:#fcfcfc;--panel:#fff;--chat:#efeae2;--user:#e3f2fd;
            --blue:#0066cc;--blue-soft:#eaf4ff;--shadow:0 28px 90px rgba(24,24,27,.16);
            --radius:30px;--ease:cubic-bezier(.16,1,.3,1)
        }
        *{box-sizing:border-box}
        html,body{margin:0;min-height:100%;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#ececef;color:var(--ink);-webkit-font-smoothing:antialiased}
        body{min-height:100dvh}
        #wpadminbar{display:none!important}html{margin-top:0!important}
        button,input,textarea{font:inherit}button{touch-action:manipulation}
        .shell{min-height:100dvh;display:grid;place-items:center;padding:14px}
        .brand{font-size:25px;font-weight:900;letter-spacing:-.05em;color:var(--rose);text-decoration:none}
        .free-pill{display:inline-flex;align-items:center;gap:8px;width:max-content;padding:8px 12px;border-radius:999px;background:var(--rose-soft);color:var(--rose-dark);font-size:12px;font-weight:850;letter-spacing:.01em}
        .free-pill::before{content:"";width:7px;height:7px;border-radius:50%;background:var(--rose);box-shadow:0 0 0 5px rgba(225,29,72,.1)}

        /* Unauthenticated experience */
        .auth{width:min(1180px,100%);min-height:min(800px,calc(100dvh - 28px));background:var(--panel);border-radius:var(--radius);display:grid;grid-template-columns:minmax(360px,.9fr) minmax(470px,1.1fr);overflow:hidden;box-shadow:var(--shadow);border:1px solid rgba(228,228,231,.85)}
        .auth-art{position:relative;min-height:720px;background:#15151a;overflow:hidden;isolation:isolate}
        .auth-art::before{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(8,8,12,.05),rgba(8,8,12,.22) 42%,rgba(8,8,12,.96));z-index:1}
        .auth-art::after{content:"";position:absolute;width:360px;height:360px;left:-120px;bottom:80px;border-radius:50%;background:radial-gradient(circle,rgba(225,29,72,.42),transparent 67%);filter:blur(8px);z-index:1}
        .auth-art>img{width:100%;height:100%;object-fit:cover;object-position:center top;position:absolute;inset:0;transform:scale(1.015)}
        .art-top{position:absolute;z-index:3;left:28px;right:28px;top:28px;display:flex;justify-content:space-between;align-items:center}
        .art-top .brand{color:#fff;text-shadow:0 2px 18px rgba(0,0,0,.28)}
        .market-chip{padding:8px 11px;border:1px solid rgba(255,255,255,.24);border-radius:999px;background:rgba(20,20,25,.28);backdrop-filter:blur(12px);color:#fff;font-size:11px;font-weight:750;letter-spacing:.06em;text-transform:uppercase}
        .art-content{position:absolute;z-index:3;left:32px;right:32px;bottom:34px;color:#fff}
        .art-kicker{font-size:12px;font-weight:800;letter-spacing:.11em;text-transform:uppercase;color:rgba(255,255,255,.72);margin-bottom:12px}
        .art-name{display:flex;align-items:baseline;gap:10px;margin-bottom:10px}.art-name h2{font-size:42px;letter-spacing:-.055em;margin:0}.art-name span{font-size:24px;font-weight:300;color:rgba(255,255,255,.75)}
        .art-content>p{max-width:430px;margin:0;color:rgba(255,255,255,.76);font-size:14px;line-height:1.65}
        .art-features{display:flex;flex-wrap:wrap;gap:8px;margin-top:20px}.art-features span{padding:8px 11px;border-radius:999px;border:1px solid rgba(255,255,255,.17);background:rgba(255,255,255,.1);backdrop-filter:blur(12px);font-size:11px;font-weight:700}
        .sample-chat{margin:24px 0 0;display:grid;gap:8px;max-width:370px}.sample-chat div{width:max-content;max-width:90%;padding:10px 13px;border-radius:15px;font-size:12px;line-height:1.45;box-shadow:0 10px 25px rgba(0,0,0,.16)}
        .sample-chat .aimee{background:#fff;color:#27272a;border-bottom-left-radius:4px}.sample-chat .visitor{justify-self:end;background:#dbeafe;color:#1e3a5f;border-bottom-right-radius:4px}

        .auth-panel{min-width:0;display:flex;flex-direction:column;background:linear-gradient(145deg,#fff 0%,#fff 65%,#fff7f9 100%)}
        .auth-nav{display:flex;align-items:center;justify-content:space-between;padding:26px 34px 10px}.auth-nav .brand{display:none}.nav-signin{border:0;background:transparent;color:var(--ink);font-weight:750;cursor:pointer;padding:10px 0}
        .auth-box{flex:1;min-height:0;padding:28px clamp(34px,5vw,72px) 42px;overflow:auto;display:flex;flex-direction:column;justify-content:center}
        .view{animation:fadeUp .45s var(--ease)}.onboard{display:none}
        @keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
        .eyebrow{display:block;color:var(--rose);font-size:12px;font-weight:850;letter-spacing:.1em;text-transform:uppercase;margin-bottom:15px}
        .auth-box h1{font-size:clamp(40px,5vw,62px);line-height:.98;letter-spacing:-.065em;margin:0 0 18px;font-weight:500;max-width:650px}
        .auth-box .intro{font-size:16px;line-height:1.75;color:var(--muted);max-width:620px;margin:0 0 24px}
        .trust-row{display:flex;flex-wrap:wrap;gap:9px;margin:0 0 26px}.trust-row span{display:inline-flex;align-items:center;gap:7px;padding:8px 10px;background:#f7f7f8;border:1px solid #ececef;border-radius:999px;color:#52525b;font-size:11px;font-weight:700}.trust-row span::before{content:"✓";color:#047857;font-weight:900}
        .error{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239;padding:12px 14px;border-radius:14px;margin-bottom:15px;font-size:13px}
        .field{display:grid;gap:7px;margin:0 0 16px}.field-row{display:grid;grid-template-columns:1fr 150px;gap:14px}
        .field label{font-size:13px;font-weight:750;color:#3f3f46}.field small{font-weight:500;color:#92929a}
        .field input,.field textarea{width:100%;border:1px solid var(--line);background:#fff;border-radius:16px;padding:15px 16px;color:var(--ink);outline:none;transition:border-color .2s,box-shadow .2s,transform .2s}.field textarea{min-height:112px;resize:vertical;line-height:1.5}.field input:focus,.field textarea:focus{border-color:#a1a1aa;box-shadow:0 0 0 4px rgba(24,24,27,.055)}
        .field-note{font-size:11px;color:#85858e;line-height:1.55;margin-top:-1px}
        .sms-notice{margin:12px 0 2px;padding:12px 14px;border:1px solid #f1c3cf;border-radius:15px;background:#fff5f7;color:#7f1d3d;font-size:11px;line-height:1.6}.sms-notice strong{display:block;color:#9f1239;font-size:12px;margin-bottom:2px}
        .primary,.secondary{border:0;border-radius:999px;padding:15px 20px;font-weight:800;cursor:pointer;transition:transform .2s var(--ease),box-shadow .2s var(--ease),background .2s}.primary{background:#18181b;color:#fff;box-shadow:0 12px 28px rgba(24,24,27,.15)}.primary:hover{transform:translateY(-1px);box-shadow:0 16px 34px rgba(24,24,27,.2)}.secondary{background:#f4f4f5;color:#27272a}.switch{border:0;background:none;color:var(--rose-dark);font-weight:800;cursor:pointer;padding:0}.button-row{display:flex;justify-content:space-between;gap:12px;margin-top:24px}.button-row .primary{margin-left:auto;min-width:145px}
        .login-form .primary{width:100%;margin-top:6px}.sub-link{font-size:13px;color:var(--muted);margin:20px 0 0;text-align:center}
        .progress-wrap{margin-bottom:24px}.progress-meta{display:flex;align-items:center;justify-content:space-between;margin-bottom:9px;font-size:11px;font-weight:750;color:#7b7b84}.progress{height:5px;border-radius:999px;background:#f0f0f2;overflow:hidden}.progress-bar{height:100%;width:33.333%;background:linear-gradient(90deg,var(--rose),#fb7185);border-radius:inherit;transition:width .4s var(--ease)}
        .step{display:none}.step.active{display:block;animation:fadeUp .42s var(--ease)}.step h2{font-size:30px;line-height:1.08;letter-spacing:-.045em;margin:0 0 10px}.step>p{margin:0 0 22px;color:var(--muted);font-size:14px;line-height:1.65}
        .photo-drop{display:grid;place-items:center;min-height:150px;border:1px dashed #c7c7cd;border-radius:20px;background:#fafafa;text-align:center;padding:18px;cursor:pointer;transition:.2s}.photo-drop:hover{border-color:var(--rose);background:#fff8fa}.photo-drop input{display:none}.photo-preview{display:none;width:92px;height:92px;border-radius:50%;object-fit:cover;margin-bottom:12px;box-shadow:0 10px 25px rgba(24,24,27,.14)}.photo-drop strong{font-size:14px}.photo-drop span{font-size:11px;color:var(--muted);margin-top:5px}
        .loader{display:none;margin-top:14px;padding:13px 15px;border-radius:14px;background:#f7f7f8;color:#5c5c65;font-size:12px}.loader::before{content:"";display:inline-block;width:12px;height:12px;border-radius:50%;border:2px solid #d4d4d8;border-top-color:var(--rose);animation:spin .8s linear infinite;margin-right:8px;vertical-align:-2px}@keyframes spin{to{transform:rotate(360deg)}}

        /* Authenticated chat */
        .app{width:min(1180px,100%);height:calc(100dvh - 28px);background:#fff;border-radius:26px;overflow:hidden;box-shadow:var(--shadow);display:grid;grid-template-columns:310px 1fr;border:1px solid rgba(228,228,231,.8)}
        .side{background:#fff;border-right:1px solid #e7e7e9;padding:25px;display:flex;flex-direction:column}.side .market{font-size:10px;font-weight:850;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-top:5px}.profile{margin-top:32px;display:flex;align-items:center;gap:12px;padding:14px;background:#fafafa;border-radius:18px}.profile img,.profile-placeholder{width:50px;height:50px;border-radius:50%;object-fit:cover}.profile-placeholder{display:grid;place-items:center;background:#e4e4e7;color:#52525b;font-weight:900}.profile strong{display:block}.profile small{display:block;color:var(--muted);margin-top:3px}.side nav{display:grid;gap:8px;margin-top:24px}.side nav a,.side button{border:0;background:#f5f5f6;color:#27272a;text-decoration:none;padding:13px 15px;border-radius:14px;text-align:left;font-weight:750;cursor:pointer}.side nav a:hover,.side button:hover{background:#ececef}.side .danger{margin-top:auto;color:#9f1239;text-decoration:none;font-weight:700;font-size:13px;padding:12px 3px}.chat{display:flex;flex-direction:column;min-width:0;background:var(--chat)}.chat-head{height:75px;background:var(--blue);color:#fff;display:flex;align-items:center;gap:12px;padding:11px 17px;box-shadow:0 2px 8px rgba(0,0,0,.1);z-index:2}.chat-head img{width:50px;height:50px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.25)}.chat-head strong{display:block;font-size:16px}.chat-head span{font-size:12px;opacity:.84}.messages{flex:1;min-height:0;overflow:auto;padding:22px;background-color:var(--chat);background-image:radial-gradient(rgba(0,0,0,.028) 1px,transparent 1px);background-size:18px 18px}.row{display:flex;margin:8px 0}.row.user{justify-content:flex-end}.bubble{max-width:min(650px,82%);background:#fff;border-radius:7px 10px 10px 10px;padding:9px 11px;box-shadow:0 1px 2px rgba(0,0,0,.13);white-space:pre-wrap;line-height:1.45;font-size:14px}.row.user .bubble{background:var(--user);border-radius:10px 7px 10px 10px}.bubble img{max-width:100%;border-radius:10px;margin-top:8px}.bubble audio{max-width:100%;margin-top:7px}.composer{background:#f0f2f5;padding:10px;display:flex;align-items:flex-end;gap:8px}.composer textarea{flex:1;border:0;border-radius:23px;padding:12px 15px;resize:none;min-height:46px;max-height:130px;font:inherit;outline:none}.icon,.send{border:0;border-radius:50%;width:46px;height:46px;cursor:pointer;font-size:19px;flex:0 0 auto}.icon{background:#fff;color:#5f6b72}.send{background:var(--blue);color:#fff}.preview{display:none;padding:8px 14px;background:#fff;border-top:1px solid #ddd}.preview img{height:70px;border-radius:8px}.preview button{margin-left:8px;border:0;background:#f4f4f5;border-radius:999px;padding:8px 11px;cursor:pointer}.gallery-question-context{display:flex;align-items:center;gap:10px;margin:8px 10px;padding:10px 12px;border:1px solid rgba(225,29,72,.2);border-radius:14px;background:#fff8fa;color:#4b1628;font-size:12px;font-weight:650;line-height:1.4}.gallery-question-context span{flex:1;min-width:0}.gallery-question-context button{border:0;border-radius:999px;padding:7px 10px;background:#18181b;color:#fff;font-size:11px;font-weight:750;cursor:pointer}
        .side nav .side-gallery-link{background:#fff1f4;color:#9f1239}.side nav .side-gallery-link:hover{background:#ffe4ea}.chat-gallery-shortcut{display:inline-flex;min-width:44px;min-height:44px;margin-inline-start:auto;padding:9px 13px;align-items:center;justify-content:center;gap:7px;border-radius:999px;background:#fff;color:#27272a;text-decoration:none;font-size:12px;font-weight:850;box-shadow:0 6px 18px rgba(24,24,27,.14)}.chat-gallery-shortcut:hover{background:#fff1f4;color:#9f1239}.chat-gallery-shortcut:focus-visible{outline:3px solid rgba(255,255,255,.8);outline-offset:2px}
        .paywall{position:fixed;inset:0;background:rgba(0,0,0,.64);display:none;place-items:center;z-index:999;padding:18px}.paywall.open{display:grid}.modal{width:min(780px,100%);background:#fff;border-radius:26px;padding:28px}.modal h2{font-size:32px;letter-spacing:-.04em;margin:0 0 8px}.modal>p{color:var(--muted)}.plans{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:22px}.plan{border:1px solid var(--line);border-radius:18px;padding:18px}.plan strong{font-size:24px;display:block}.plan button{width:100%;border:0;background:#18181b;color:#fff;border-radius:999px;padding:11px;font-weight:800;cursor:pointer}.checkout-unavailable{display:block;width:100%;border-radius:999px;padding:11px;background:#e4e4e7;color:#52525b;text-align:center;font-size:12px;font-weight:800}.close{float:right;border:0;background:#eee;border-radius:50%;width:38px;height:38px;cursor:pointer}.typing{display:inline-flex;gap:4px;padding:5px}.typing i{width:7px;height:7px;background:#777;border-radius:50%;animation:b 1s infinite}.typing i:nth-child(2){animation-delay:.15s}.typing i:nth-child(3){animation-delay:.3s}@keyframes b{50%{transform:translateY(-4px)}}
        .settings-form{display:grid;gap:14px;margin-top:20px}.settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.toggle-line{display:flex;align-items:flex-start;gap:10px;padding:13px 14px;border:1px solid var(--line);border-radius:15px;background:#fafafa}.toggle-line input{margin-top:3px}.toggle-line strong{display:block;font-size:13px}.toggle-line span{display:block;color:var(--muted);font-size:11px;line-height:1.5;margin-top:3px}.settings-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-top:4px}.settings-status{font-size:12px;color:var(--muted);margin-right:auto}@media(max-width:620px){.settings-grid{grid-template-columns:1fr}.modal{padding:22px}.plans{grid-template-columns:1fr}}

        @media(max-width:820px){
            .shell{padding:0;display:block}.auth{min-height:100dvh;border-radius:0;grid-template-columns:1fr;box-shadow:none;border:0}.auth-art{min-height:235px;height:235px}.auth-art>img{object-position:center 18%}.art-top{top:18px;left:20px;right:20px}.art-content{left:21px;right:21px;bottom:18px}.art-kicker,.art-content>p,.art-features,.sample-chat{display:none}.art-name{margin:0}.art-name h2{font-size:34px}.art-name span{font-size:20px}.auth-nav{padding:17px 22px 0}.auth-nav .brand{display:block}.auth-box{padding:24px 22px 42px;justify-content:flex-start;overflow:visible}.auth-box h1{font-size:43px}.auth-box .intro{font-size:15px}.field-row{grid-template-columns:1fr 100px}.trust-row{margin-bottom:22px}.app{height:100dvh;border-radius:0;grid-template-columns:1fr;box-shadow:none;border:0}.side{display:none}.chat-head{height:68px}.messages{padding:14px}.bubble{max-width:88%}.plans{grid-template-columns:1fr}.modal{max-height:92dvh;overflow:auto}.button-row{position:sticky;bottom:-1px;background:linear-gradient(180deg,rgba(255,255,255,0),#fff 26%);padding-top:25px;padding-bottom:3px}
        }
        @media(max-width:430px){.auth-art{height:205px;min-height:205px}.auth-box h1{font-size:39px}.field-row{grid-template-columns:1fr}.auth-box{padding-left:18px;padding-right:18px}.auth-nav{padding-left:18px;padding-right:18px}.art-content{left:18px}.art-top{left:18px;right:18px}.trust-row span:nth-child(3){display:none}}
        @media(prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important;scroll-behavior:auto!important}}
    </style>
</head>
<body <?php body_class('aimee-chat aimee-market-' . $aimee_market); ?>>
<?php wp_body_open(); ?>
<div class="shell">
<?php if (!$is_auth): ?>
    <div class="auth">
        <section class="auth-art" aria-label="Meet Aimee">
            <img src="<?php echo esc_url($portrait); ?>" alt="Aimee">
            <div class="art-top">
                <a class="brand" href="<?php echo esc_url($home_url); ?>">Aimee</a>
                <span class="market-chip"><?php echo esc_html($market_badge); ?></span>
            </div>
            <div class="art-content">
                <div class="art-kicker"><?php echo esc_html($art_kicker); ?></div>
                <div class="art-name"><h2>Aimee</h2><span>28</span></div>
                <p><?php echo esc_html($art_note); ?></p>
                <div class="art-features"><span>Persistent memory</span><span>Her own opinions</span><span>Private voice notes</span></div>
                <div class="sample-chat" aria-hidden="true">
                    <div class="aimee">Tell me one thing I should remember about you.</div>
                    <div class="visitor">I never say no to a Sunday drive.</div>
                </div>
            </div>
        </section>

        <section class="auth-panel">
            <div class="auth-nav">
                <a class="brand" href="<?php echo esc_url($home_url); ?>">Aimee</a>
                <button type="button" class="nav-signin" data-show="login">Already connected? Sign in</button>
            </div>
            <div class="auth-box">
                <div class="view" id="login">
                    <span class="eyebrow">Welcome back</span>
                    <h1>Pick up where you left off.</h1>
                    <p class="intro">Sign in with the Login ID and password you chose when you first met Aimee. Existing six-digit passcodes still work.</p>
                    <div class="free-pill">30 free replies for new connections</div>
                    <?php if ($error): ?><div class="error"><?php echo esc_html($error); ?></div><?php endif; ?>
                    <form method="post" class="login-form" style="margin-top:24px">
                        <?php wp_nonce_field('aimee_login', 'aimee_login_nonce'); ?>
                        <div class="field"><label>Login ID</label><input name="aimee_login_id" autocomplete="username" required></div>
                        <div class="field"><label>Password</label><input name="aimee_pin" type="password" autocomplete="current-password" required></div>
                        <button class="primary" name="aimee_login_submit">Sign in</button>
                    </form>
                    <p class="sub-link">New here? <button type="button" class="switch" data-show="onboard">Meet Aimee</button></p>
                </div>

                <div class="view onboard" id="onboard">
                    <div class="progress-wrap">
                        <div class="progress-meta"><span id="step-label">Step 1 of 3</span><span>Private setup</span></div>
                        <div class="progress"><div class="progress-bar" id="progress-bar"></div></div>
                    </div>
                    <span class="eyebrow">30 replies, on us</span>
                    <h1>Your first meeting.</h1>
                    <p class="intro"><?php echo esc_html($join_intro); ?></p>
                    <div class="trust-row"><span>No card required</span><span>Private by design</span><span>Adults 18+</span><?php if ($is_us): ?><span>Optional SMS via +44</span><?php endif; ?></div>

                    <form id="join" data-aimee-registration="1" data-aimee-native-privacy-choices="1" novalidate>
                        <section class="step active" data-step="1">
                            <h2>Let’s start with you.</h2>
                            <p>Just enough for Aimee to greet you properly. You can add more detail in a moment.</p>
                            <div class="field-row">
                                <div class="field"><label>First name</label><input name="first_name" autocomplete="given-name" required></div>
                                <div class="field"><label>Age</label><input name="age" type="number" inputmode="numeric" min="18" max="100" required></div>
                            </div>
                            <div class="button-row"><button type="button" class="secondary" data-show="login">Back to sign in</button><button type="button" class="primary next-step">Continue</button></div>
                        </section>

                        <section class="step" data-step="2">
                            <h2>Your private sign-in.</h2>
                            <p>Choose the details that will bring you back to this exact conversation.</p>
                            <div class="field">
                                <label><?php echo esc_html($login_label); ?></label>
                                <input name="phone_number" placeholder="<?php echo esc_attr($login_placeholder); ?>" autocomplete="username" required>
                                <div class="field-note"><?php echo esc_html($login_help); ?></div>
                                <?php if ($is_us): ?>
                                    <div class="sms-notice"><strong>International text charges may apply</strong>Aimee’s mobile number begins +44 because she uses a UK number. Your cell provider may charge texts to or from it as international messages, and they may sit outside any included SMS package. Please check your plan before opting in.</div>
                                <?php endif; ?>
                            </div>
                            <div class="field"><label>Six-digit passcode</label><input name="passcode" type="password" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="new-password" placeholder="Choose six numbers" data-aimee-passcode="1" required><div class="field-note">Avoid easy combinations such as 123456 or repeating digits.</div></div>
                            <div class="button-row"><button type="button" class="secondary prev-step">Back</button><button type="button" class="primary next-step">Continue</button></div>
                        </section>

                        <section class="step" data-step="3">
                            <h2>Give her something real.</h2>
                            <p>This is optional, but it helps Aimee’s opening message feel less like a form and more like a meeting.</p>
                            <div class="field"><label>Your world <small>(optional)</small></label><textarea name="hobbies" maxlength="1200" placeholder="Work, interests, music, cars, films, the things you can happily talk about…"></textarea></div>
                            <div class="field"><label>What brings you here? <small>(optional)</small></label><textarea name="looking_for" maxlength="600" placeholder="Conversation, company, curiosity, or something harder to define…"></textarea></div>
                            <label class="photo-drop">
                                <img class="photo-preview" id="photo-preview" alt="Your selected profile photo">
                                <strong>Add a profile photo <small>(optional)</small></strong>
                                <span>Aimee can use it to recognise your appearance and make the introduction more personal.</span>
                                <input name="photo" type="file" accept="image/jpeg,image/png,image/gif,image/webp">
                            </label>
                            <p class="field-note">You can read Aimee’s <a href="<?php echo esc_url($privacy_url); ?>" target="_blank" rel="noopener">privacy notice</a> now or at any time from the chat menu. No acknowledgement is required to continue.</p>
                            <label class="toggle-line"><input name="special_category_consent" type="checkbox" value="1"><span><strong>Optional sensitive-information consent</strong><span>I explicitly consent to Aimee processing sensitive information I choose to share, which may include health, sexual-life or sexual-orientation information, for specialist personalisation. Ordinary chat remains available if I leave this unticked, and I can change it later in settings.</span></span></label>
                            <div class="button-row"><button type="button" class="secondary prev-step">Back</button><button class="primary" type="submit">Connect with Aimee</button></div>
                            <div class="loader" id="join-loader">Aimee is preparing your opening message…</div>
                            <div class="error" id="join-error" style="display:none;margin-top:12px"></div>
                        </section>
                    </form>
                </div>
            </div>
        </section>
    </div>
<?php else: ?>
    <div class="app">
        <aside class="side">
            <a class="brand" href="<?php echo esc_url($home_url); ?>">Aimee</a>
            <span class="market"><?php echo esc_html($market_badge); ?></span>
            <div class="profile"><?php if ($photo): ?><img src="<?php echo esc_url($photo); ?>" alt="Your profile photo"><?php else: ?><span class="profile-placeholder" aria-hidden="true">You</span><?php endif; ?><div><strong><?php echo esc_html($first); ?></strong><small>Private connection</small></div></div>
            <nav><button id="settings">Account settings</button><button id="membership">Membership</button><a class="side-gallery-link" href="<?php echo esc_url($gallery_url); ?>">📸 Aimee’s photos</a><a href="<?php echo esc_url($privacy_url); ?>">Privacy & safeguarding</a></nav>
            <a class="danger" href="<?php echo esc_url(wp_logout_url($chat_url)); ?>">Sign out</a>
        </aside>
        <main class="chat">
            <header class="chat-head"><img src="<?php echo esc_url($portrait); ?>" alt="Aimee"><div><strong>Aimee</strong><span><?php echo esc_html($status_line); ?></span></div><a id="aimee-chat-gallery-link" class="chat-gallery-shortcut" href="<?php echo esc_url($gallery_url); ?>" aria-label="Open Aimee’s photo gallery">📸 <span>Photos</span></a></header>
            <div class="messages" id="messages"></div>
            <div class="preview" id="preview"><img id="preview-img" alt="Selected image"><button id="remove-image">Remove</button></div>
            <div class="composer" id="message-composer"><button class="icon" id="image-btn" title="Add photo" aria-label="Add photo">＋</button><input id="image-input" type="file" accept="image/*" hidden><button class="icon" id="voice-btn" title="Record voice note" aria-label="Record voice note">🎙</button><textarea id="text" rows="1" placeholder="Message Aimee"></textarea><button class="send" id="send" aria-label="Send message">➤</button></div>
        </main>
    </div>
    <div class="paywall" id="settings-modal"><div class="modal"><button class="close" id="close-settings" aria-label="Close">×</button><h2>Account settings</h2><p>Choose how Aimee can contact you and when SMS is welcome.</p>
        <form class="settings-form" id="settings-form" data-aimee-privacy-consent-settings="1"><input type="hidden" name="first_name" value="<?php echo esc_attr($first); ?>">
            <div class="field"><label><?php echo $is_us ? 'US mobile number' : 'UK mobile number'; ?></label><input name="phone_number" value="<?php echo esc_attr($phone_value); ?>" placeholder="<?php echo $is_us ? '+1 212 555 0123' : '07…'; ?>"><div class="field-note">Use the mobile number that will send texts to Aimee.</div></div>
            <?php if ($is_us): ?><div class="sms-notice"><strong>International text charges may apply</strong>Aimee uses a UK +44 number. Your cell provider may charge texts to or from it outside any included SMS package.</div><?php endif; ?>
            <label class="toggle-line"><input type="checkbox" name="sms_opt_in" value="1" <?php checked($sms_opt_in, 1); ?> <?php disabled(!$sms_verified); ?>><span><strong>Enable SMS with Aimee</strong><span><?php echo $sms_verified ? 'Aimee may reply to your texts and occasionally message first inside your Safe Windows.' : 'Carrier SMS stays off until this mobile number has been securely verified. In-app chat is unaffected.'; ?></span></span></label>
            <div class="settings-grid">
                <div class="field"><label>Safe Window starts</label><input name="safe_start" type="number" min="0" max="23" value="<?php echo esc_attr($safe_start); ?>"><div class="field-note">24-hour clock</div></div>
                <div class="field"><label>Safe Window ends</label><input name="safe_end" type="number" min="0" max="23" value="<?php echo esc_attr($safe_end); ?>"><div class="field-note">24-hour clock</div></div>
            </div>
            <label class="toggle-line"><input type="checkbox" name="sms_override" value="1" <?php checked($sms_override, 1); ?>><span><strong>Allow important messages outside Safe Windows</strong><span>Aimee can occasionally send something outside the preferred hours when it genuinely matters.</span></span></label>
            <p class="field-note">Read Aimee’s <a href="<?php echo esc_url($privacy_url); ?>" target="_blank" rel="noopener">privacy notice</a> at any time. You do not need to acknowledge it to use ordinary chat.</p>
            <label class="toggle-line"><input type="checkbox" name="special_category_consent" value="1" <?php checked($special_category_consent); ?>><span><strong>Optional sensitive-information consent</strong><span>Tick to enable specialist sensitive/adult processing. Unticking and saving withdraws consent immediately; ordinary chat remains available.</span></span></label>
            <div class="settings-actions"><span class="settings-status" id="settings-status"></span><button type="button" class="secondary" id="cancel-settings">Cancel</button><button type="submit" class="primary">Save settings</button></div>
        </form>
    </div></div>
    <div class="paywall" id="paywall"><div class="modal"><button class="close" id="close-paywall" aria-label="Close">×</button><h2>Continue with Aimee</h2><?php if ($checkout_market_supported): ?><p>Your first 30 replies are complimentary. New UK membership checkout is provided only through GoCardless.</p><div class="plans"><?php foreach ($plans as $key => $plan): ?><div class="plan"><h3><?php echo esc_html(ucfirst($key)); ?></h3><strong><?php echo esc_html(aimee_global_money($plan['amount_pence'], $aimee_market)); ?></strong><p>per <?php echo esc_html($plan['interval']); ?></p><button data-plan="<?php echo esc_attr($key); ?>">Choose <?php echo esc_html(ucfirst($key)); ?></button></div><?php endforeach; ?></div><?php else: ?><p>New paid membership checkout is not currently available for US profiles. There is no card or Stripe checkout alternative.</p><div class="plans"><?php foreach ($plans as $key => $plan): ?><div class="plan"><h3><?php echo esc_html(ucfirst($key)); ?></h3><strong><?php echo esc_html(aimee_global_money($plan['amount_pence'], $aimee_market)); ?></strong><p>per <?php echo esc_html($plan['interval']); ?></p><span class="checkout-unavailable" aria-disabled="true">US checkout unavailable</span></div><?php endforeach; ?></div><?php endif; ?></div></div>
<?php endif; ?>
</div>

<?php if ($is_auth): ?>
<script>
(()=>{const root='<?php echo esc_js(rest_url('aimee/v1')); ?>',nonce='<?php echo esc_js($nonce); ?>',market='<?php echo esc_js($aimee_market); ?>',checkoutMarketSupported=market==='uk';const q=s=>document.querySelector(s),msgs=q('#messages'),text=q('#text'),send=q('#send'),imageInput=q('#image-input'),preview=q('#preview'),previewImg=q('#preview-img');let image=null,imageEventId='',sending=false,recorder=null,chunks=[];
function newImageEventId(){return window.crypto&&crypto.randomUUID?crypto.randomUUID():'img-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2)}
function clearImageSelection(){image=null;imageEventId='';imageInput.value='';preview.removeAttribute('data-image-event-id');preview.style.display='none'}
const galleryStorageKey='aimeeGalleryQuestion:1',galleryReferenceMaxAge=10*60*1000,galleryDefaultQuestion='What’s the story behind this photo?';let galleryReference=null;
function clearGalleryReference(clearDefaultQuestion=true){galleryReference=null;try{sessionStorage.removeItem(galleryStorageKey)}catch(e){}q('#gallery-question-context')?.remove();if(clearDefaultQuestion&&text.value===galleryDefaultQuestion){text.value='';text.dispatchEvent(new Event('input',{bubbles:true}))}}
function galleryReferenceIsFresh(reference){const key=reference&&typeof reference.key==='string'?reference.key:'',createdAt=reference?Number(reference.created_at||0):0,now=Date.now();return/^[a-z0-9_-]{1,191}$/.test(key)&&Number.isFinite(createdAt)&&createdAt<=now+60000&&createdAt>=now-galleryReferenceMaxAge}
function readGalleryReference(){let value=null;try{value=JSON.parse(sessionStorage.getItem(galleryStorageKey)||'null')}catch(e){}const candidate={key:value&&typeof value.key==='string'?value.key:'',created_at:value?Number(value.created_at||0):0};if(!galleryReferenceIsFresh(candidate)){clearGalleryReference(false);return null}return candidate}
function mountGalleryReference(){galleryReference=readGalleryReference();if(!galleryReference)return;if(!text.value.trim()){text.value=galleryDefaultQuestion;text.dispatchEvent(new Event('input',{bubbles:true}))}const context=document.createElement('div');context.id='gallery-question-context';context.className='gallery-question-context';context.setAttribute('role','status');const label=document.createElement('span');label.textContent='Asking Aimee about this Camera Roll photo';const cancel=document.createElement('button');cancel.type='button';cancel.textContent='Cancel';cancel.onclick=()=>clearGalleryReference(true);context.append(label,cancel);q('#message-composer').before(context)}
async function api(path,opt={}){opt.headers=Object.assign({'Content-Type':'application/json','X-WP-Nonce':nonce},opt.headers||{});const r=await fetch(root+path,opt);const data=await r.json();if(!r.ok)throw new Error(data.message||'Request failed');return data}
function row(m,who){const r=document.createElement('div');r.className='row '+who;const b=document.createElement('div');b.className='bubble';if(m)b.append(document.createTextNode(m));r.append(b);msgs.append(r);msgs.scrollTop=msgs.scrollHeight;return b}
function render(m){const who=(m.sender==='user'||String(m.sender)==='<?php echo (int) $uid; ?>')?'user':'aimee';const b=row(m.message_text||'',who);if(m.media&&m.media.url){const i=new Image;i.src=m.media.url;i.alt=m.media.alt||'';b.append(i)}if(m.voice_note&&m.voice_note.audio_url){const a=document.createElement('audio');a.controls=true;a.src=m.voice_note.audio_url;b.append(a)}}
async function history(){const d=await api('/history?_t='+Date.now());msgs.innerHTML='';(d.messages||[]).forEach(render);if(d.subscription&&['inactive','past_due','migration_required'].includes(d.subscription.status))q('#paywall').classList.add('open')}
function typing(){const b=row('','aimee');b.id='typing';b.innerHTML='<span class="typing"><i></i><i></i><i></i></span>'}
async function sendMessage(){if(sending)return;if(galleryReference&&!galleryReferenceIsFresh(galleryReference))clearGalleryReference(true);let message=text.value.trim();const outboundImage=image,outboundImageEventId=imageEventId;if(outboundImage&&galleryReference){if(message===galleryDefaultQuestion)message='';clearGalleryReference(true)}if(galleryReference&&!outboundImage&&!message)message=galleryDefaultQuestion;const referenceKey=galleryReference&&!outboundImage?galleryReference.key:'';if(!message&&!outboundImage)return;if(referenceKey)clearGalleryReference(false);sending=true;if(message)row(message,'user');text.value='';clearImageSelection();typing();send.disabled=true;try{const payload={message,image:outboundImage,image_event_id:outboundImage?outboundImageEventId:'',market};if(referenceKey)payload.referenced_media_key=referenceKey;const d=await api('/message',{method:'POST',body:JSON.stringify(payload)});q('#typing')?.parentElement.remove();if(d.reply||d.media)render({sender:'aimee',message_text:d.reply||'',media:d.media});if(['trial_ended','subscription_required','billing_reactivation_required','insufficient_funds'].includes(d.status))q('#paywall').classList.add('open')}catch(e){q('#typing')?.parentElement.remove();row('Connection interrupted. Select the photo again to retry.','aimee')}sending=false;send.disabled=false}
send.onclick=sendMessage;text.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendMessage()}});q('#image-btn').onclick=()=>imageInput.click();q('#remove-image').onclick=clearImageSelection;imageInput.onchange=()=>{const f=imageInput.files[0];if(!f){clearImageSelection();return}clearGalleryReference(true);const rd=new FileReader;rd.onload=()=>{image=rd.result;imageEventId=newImageEventId();preview.dataset.imageEventId=imageEventId;previewImg.src=image;preview.style.display='block'};rd.readAsDataURL(f)};
q('#membership').onclick=()=>q('#paywall').classList.add('open');q('#close-paywall').onclick=()=>q('#paywall').classList.remove('open');document.querySelectorAll('[data-plan]').forEach(b=>b.onclick=async()=>{if(!checkoutMarketSupported){alert('New membership checkout is currently available for UK profiles through GoCardless only.');return}b.disabled=true;try{const d=await api('/subscription-checkout',{method:'POST',body:JSON.stringify({plan:b.dataset.plan,source:'chat',market})});if(d.checkout_url)location.href=d.checkout_url;else if(d.status==='already_active')alert('Your membership is already active.')}catch(e){alert(e.message)}b.disabled=false});
const settingsModal=q('#settings-modal'),settingsForm=q('#settings-form'),settingsStatus=q('#settings-status'),settingsPhone=settingsForm.elements.phone_number,settingsSms=settingsForm.elements.sms_opt_in,initialSettingsPhone=settingsPhone.value;
function closeSettings(){settingsModal.classList.remove('open')}
settingsPhone.addEventListener('input',()=>{if(settingsPhone.value.trim()!==initialSettingsPhone.trim()&&settingsSms){settingsSms.checked=false}});
q('#settings').onclick=()=>settingsModal.classList.add('open');
q('#close-settings').onclick=q('#cancel-settings').onclick=closeSettings;
settingsModal.addEventListener('click',e=>{if(e.target===settingsModal)closeSettings()});
settingsForm.addEventListener('submit',async e=>{
    e.preventDefault();
    const f=new FormData(settingsForm);
    const payload={first_name:f.get('first_name')||'',phone_number:f.get('phone_number')||'',sms_opt_in:f.has('sms_opt_in'),sms_override:f.has('sms_override'),safe_start:Number(f.get('safe_start')||9),safe_end:Number(f.get('safe_end')||17),sms_timezone:Intl.DateTimeFormat().resolvedOptions().timeZone||'',market},privacyPayload={special_category_consent:f.has('special_category_consent')};
    const save=settingsForm.querySelector('[type="submit"]');save.disabled=true;settingsStatus.textContent='Saving…';
    try{
        const privacyResult=await api('/privacy-consent',{method:'POST',body:JSON.stringify(privacyPayload)});
        settingsForm.elements.special_category_consent.checked=!!privacyResult.special_category_consent;
        await api('/settings',{method:'POST',body:JSON.stringify(payload)});
        settingsStatus.textContent='Saved';setTimeout(closeSettings,300);
    }catch(err){settingsStatus.textContent=err.message||'Could not save'}finally{save.disabled=false}
});
q('#voice-btn').onclick=async()=>{if(recorder&&recorder.state==='recording'){recorder.stop();q('#voice-btn').textContent='🎙';return}clearGalleryReference(true);try{const stream=await navigator.mediaDevices.getUserMedia({audio:true});recorder=new MediaRecorder(stream);chunks=[];recorder.ondataavailable=e=>chunks.push(e.data);recorder.onstop=async()=>{stream.getTracks().forEach(t=>t.stop());const blob=new Blob(chunks,{type:recorder.mimeType});const fd=new FormData;fd.append('audio',blob,'voice-note.webm');try{const r=await fetch(root+'/voice-note/send',{method:'POST',headers:{'X-WP-Nonce':nonce},body:fd});const d=await r.json();if(!r.ok)throw new Error(d.message||'Voice note failed');row('Voice note sent','user');setTimeout(history,2500)}catch(e){alert(e.message)}};recorder.start();q('#voice-btn').textContent='■'}catch(e){alert('Microphone access was not available.')}};mountGalleryReference();history();})();
</script>
<?php else: ?>
<script>
(()=>{
    const q=s=>document.querySelector(s);
    const qa=s=>Array.from(document.querySelectorAll(s));
    const login=q('#login'), onboard=q('#onboard'), form=q('#join'), steps=qa('.step'), bar=q('#progress-bar'), label=q('#step-label');
    let current=1;

    function showView(name){
        login.style.display=name==='login'?'block':'none';
        onboard.style.display=name==='onboard'?'block':'none';
        if(name==='onboard')setStep(current);
        window.scrollTo({top:0,behavior:'smooth'});
    }
    qa('[data-show]').forEach(button=>button.addEventListener('click',()=>showView(button.dataset.show)));

    function setStep(number){
        current=Math.max(1,Math.min(3,number));
        steps.forEach(step=>step.classList.toggle('active',Number(step.dataset.step)===current));
        bar.style.width=(current/3*100)+'%';
        label.textContent='Step '+current+' of 3';
        const active=steps.find(step=>Number(step.dataset.step)===current);
        active?.querySelector('input,textarea')?.focus({preventScroll:true});
    }
    function validPasscode(input,report){
        if(!input)return true;
        const message=/^[0-9]{6}$/.test(input.value)?'':'Choose a six-digit passcode.';
        input.setCustomValidity(message);
        if(message&&report)input.reportValidity();
        return !message;
    }
    form.elements.passcode.addEventListener('input',()=>validPasscode(form.elements.passcode,false));
    function validStep(){
        const active=steps.find(step=>Number(step.dataset.step)===current);
        const passcode=active.querySelector('[data-aimee-passcode]');
        if(passcode&&!validPasscode(passcode,true))return false;
        const required=Array.from(active.querySelectorAll('[required]'));
        for(const input of required){
            if(!input.checkValidity()){input.reportValidity();return false;}
        }
        return true;
    }
    qa('.next-step').forEach(button=>button.addEventListener('click',()=>{if(validStep())setStep(current+1)}));
    qa('.prev-step').forEach(button=>button.addEventListener('click',()=>setStep(current-1)));

    const photoInput=form.elements.photo, preview=q('#photo-preview');
    photoInput.addEventListener('change',()=>{
        const file=photoInput.files[0];
        if(!file){preview.style.display='none';preview.removeAttribute('src');return;}
        const reader=new FileReader();
        reader.onload=()=>{preview.src=reader.result;preview.style.display='block'};
        reader.readAsDataURL(file);
    });

    form.addEventListener('submit',async event=>{
        event.preventDefault();
        if(!validStep())return;
        const loader=q('#join-loader'), error=q('#join-error'), submit=form.querySelector('[type="submit"]');
        loader.style.display='block';error.style.display='none';submit.disabled=true;
        const data=Object.fromEntries(new FormData(form));
        data.market='<?php echo esc_js($aimee_market); ?>';
        data.sms_timezone=Intl.DateTimeFormat().resolvedOptions().timeZone||'';
        data.special_category_consent=!!form.elements.special_category_consent?.checked;
        const photo=photoInput.files[0];
        if(photo){data.image=await new Promise(resolve=>{const reader=new FileReader();reader.onload=()=>resolve(reader.result);reader.readAsDataURL(photo)})}
        delete data.photo;
        try{
            const response=await fetch('<?php echo esc_js(rest_url('aimee/v1/profile')); ?>',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify(data)});
            const result=await response.json();
            if(!response.ok||result.status!=='success')throw new Error(result.message||'Could not create account');
            location.reload();
        }catch(errorMessage){
            error.textContent=errorMessage.message;error.style.display='block';loader.style.display='none';submit.disabled=false;
        }
    });
})();
</script>
<?php endif; ?>
<?php wp_footer(); ?>
</body>
</html>
