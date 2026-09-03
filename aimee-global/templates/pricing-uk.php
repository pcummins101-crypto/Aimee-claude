<?php
/*
Template Name: Aimee Pricing & Store
*/

// Define all system routes to match the ecosystem perfectly
$home_url = home_url('/home'); 
$app_url = home_url('/chat'); 
$pricing_url = home_url('/pricing');
$faq_url = home_url('/faq');
$tech_url = home_url('/technology');
$privacy_url = home_url('/privacy');
$gallery_url = home_url('/camera-roll');

$is_logged_in = is_user_logged_in();
$rest_nonce = $is_logged_in ? wp_create_nonce('wp_rest') : '';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <title>Pricing & Memberships | Aimee</title>
    <meta name="title" content="Pricing & Memberships | Aimee">
    <meta name="description" content="Compare Aimee's complimentary 30-reply preview with Weekly, Monthly and Annual memberships, including full SMS access, uninterrupted conversation and naturally earned adult intimacy.">
    <meta name="keywords" content="Aimee pricing, Aimee membership, AI companion subscription, virtual companion cost, AI relationship membership, AI SMS companion">
    <meta name="author" content="A.R.I. Systems">
    
    <link rel="canonical" href="<?php echo esc_url($pricing_url); ?>">

    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo esc_url($pricing_url); ?>">
    <meta property="og:title" content="Pricing & Memberships | Aimee">
    <meta property="og:description" content="Compare Aimee's complimentary preview with full Weekly, Monthly and Annual memberships, including the features reserved for members.">
    <meta property="og:image" content="https://aimee-ai.com/wp-content/uploads/2026/06/file_000000007aa071f481b107387cd6c09d.png">

    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="<?php echo esc_url($pricing_url); ?>">
    <meta property="twitter:title" content="Pricing & Memberships | Aimee">
    <meta property="twitter:description" content="Compare Aimee's complimentary preview with full Weekly, Monthly and Annual memberships, including the features reserved for members.">
    <meta property="twitter:image" content="https://aimee-ai.com/wp-content/uploads/2026/06/file_000000007aa071f481b107387cd6c09d.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <script type="application/ld+json">
    <?php
    echo wp_json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => 'Aimee AI',
        'applicationCategory' => 'LifestyleApplication',
        'operatingSystem' => 'Web, Mobile',
        'description' => 'A premium digital companion with persistent emotional memory, visual understanding and optional real-world mobile integration.',
        'url' => $pricing_url,
        'brand' => [
            '@type' => 'Brand',
            'name' => 'A.R.I. Systems',
        ],
        'offers' => [
            [
                '@type' => 'Offer',
                'name' => 'Aimee Complimentary Preview',
                'price' => '0.00',
                'priceCurrency' => 'GBP',
                'availability' => 'https://schema.org/InStock',
                'url' => $app_url,
            ],
            [
                '@type' => 'Offer',
                'name' => 'Aimee Weekly Membership',
                'price' => '6.99',
                'priceCurrency' => 'GBP',
                'availability' => 'https://schema.org/InStock',
                'url' => $app_url,
            ],
            [
                '@type' => 'Offer',
                'name' => 'Aimee Monthly Membership',
                'price' => '19.99',
                'priceCurrency' => 'GBP',
                'availability' => 'https://schema.org/InStock',
                'url' => $app_url,
            ],
            [
                '@type' => 'Offer',
                'name' => 'Aimee Annual Membership',
                'price' => '149.00',
                'priceCurrency' => 'GBP',
                'availability' => 'https://schema.org/InStock',
                'url' => $app_url,
            ],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    ?>
    </script>
    
    <?php wp_head(); ?>
    <style>
        :root {
            --bg-light: #FCFCFC;
            --bg-alt: #F4F4F5;
            --bg-dark: #18181B;
            --text-main: #27272A;
            --text-muted: #52525B;
            --text-inverse: #FAFAFA;
            --accent: #18181B;
            --accent-hover: #3F3F46;
            --border: #E4E4E7;
            --border-light: #F4F4F5;
            --brand-accent: #E11D48;
            --brand-gradient: linear-gradient(135deg, #F43F5E 0%, #BE123C 100%);
            --radius-md: 16px;
            --radius-lg: 32px;
            --shadow-subtle: 0 10px 30px -10px rgba(0, 0, 0, 0.04);
            --shadow-hover: 0 20px 40px -15px rgba(0, 0, 0, 0.08);
            --transition-smooth: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }

        * { box-sizing: border-box; }
        body, html {
            margin: 0; padding: 0;
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-light);
            color: var(--text-main);
            -webkit-font-smoothing: antialiased;
        }

        .container { width: 100%; max-width: 1400px; margin: 0 auto; padding: 0 5vw; }
        .text-accent { background: var(--brand-gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; display: inline-block; }

        /* Sticky Navigation */
        nav {
            position: fixed; width: 100%; top: 0;
            background: rgba(252, 252, 252, 0.85); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            padding: 24px 0; z-index: 1000; border-bottom: 1px solid rgba(228, 228, 231, 0.5);
            transition: var(--transition-smooth);
        }
        .nav-inner { display: flex; justify-content: space-between; align-items: center; max-width: 1440px; margin: 0 auto; padding: 0 5vw; }
        .logo { font-size: 22px; font-weight: 800; letter-spacing: 0.05em; text-decoration: none; color: var(--text-main); position: relative; z-index: 1001; }
        
        /* Desktop Menu */
        .desktop-menu { display: flex; align-items: center; gap: 32px; }
        .desktop-menu a { color: var(--text-main); text-decoration: none; font-weight: 500; font-size: 14px; transition: color 0.2s; }
        .desktop-menu a:hover { color: var(--brand-accent); }
        .desktop-menu .nav-login-btn { background: var(--bg-dark); color: var(--text-inverse); padding: 10px 24px; border-radius: 30px; }
        .desktop-menu .nav-login-btn:hover { background: var(--accent-hover); color: var(--text-inverse); transform: translateY(-1px); }

        /* Hamburger Icon */
        .hamburger { display: none; flex-direction: column; gap: 5px; cursor: pointer; z-index: 1001; padding: 10px 0; }
        .hamburger span { display: block; width: 26px; height: 2px; background: var(--bg-dark); border-radius: 2px; transition: var(--transition-smooth); }
        .hamburger.active span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
        .hamburger.active span:nth-child(2) { opacity: 0; }
        .hamburger.active span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

        /* Mobile Menu Overlay */
        .mobile-menu {
            position: fixed; top: 0; left: 0; width: 100%; height: 100vh;
            background: var(--bg-light); z-index: 999;
            display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 32px;
            opacity: 0; visibility: hidden; transform: translateY(-20px); transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .mobile-menu.active { opacity: 1; visibility: visible; transform: translateY(0); }
        .mobile-menu a { font-size: 24px; color: var(--text-main); text-decoration: none; font-weight: 600; transition: color 0.2s; }
        .mobile-menu a:hover { color: var(--brand-accent); }
        .mobile-menu .mobile-login { margin-top: 16px; background: var(--brand-gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; font-size: 28px; }

        /* Sticky Mobile CTA */
        .mobile-sticky-cta {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            background: rgba(252, 252, 252, 0.95);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            padding: 16px 20px;
            box-shadow: 0 -4px 20px rgba(0,0,0,0.08);
            z-index: 998;
            transform: translateY(100%);
            transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            display: none;
            border-top: 1px solid var(--border);
        }
        .mobile-sticky-cta.visible { transform: translateY(0); }
        .mobile-sticky-cta .btn { width: 100%; font-size: 16px; padding: 14px; box-sizing: border-box; display: inline-flex; align-items: center; justify-content: center; border-radius: 40px; font-weight: 500; text-decoration: none; }

        .reveal { opacity: 0; transform: translateY(40px); transition: all 1s cubic-bezier(0.16, 1, 0.3, 1); }
        .reveal.active { opacity: 1; transform: translateY(0); }

        header.store-header {
            padding: 180px 0 60px;
            text-align: center;
            max-width: 750px;
            margin: 0 auto;
        }
        header.store-header h1 { font-size: clamp(36px, 5vw, 56px); font-weight: 600; letter-spacing: -0.04em; margin-bottom: 16px; color: var(--bg-dark); }
        header.store-header p { font-size: 18px; color: var(--text-muted); font-weight: 300; line-height: 1.6; }
        
        /* Promo Banner */
        .promo-banner {
            background: rgba(225, 29, 72, 0.08);
            border: 1px solid rgba(225, 29, 72, 0.3);
            color: var(--brand-accent);
            padding: 14px 28px;
            border-radius: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 500;
            font-size: 15px;
            margin-top: 32px;
            letter-spacing: 0.01em;
            box-shadow: 0 4px 12px rgba(225, 29, 72, 0.05);
        }
        .promo-banner strong { font-weight: 700; margin-right: 8px; }

        /* Tier Grid Options */
        .tier-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 24px;
            margin-bottom: 80px;
            align-items: stretch;
        }

        .tier-card {
            background: #FFFFFF;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 48px 40px;
            display: flex;
            flex-direction: column;
            position: relative;
            transition: var(--transition-smooth);
            box-shadow: var(--shadow-subtle);
        }
        .tier-card:hover { transform: translateY(-6px); box-shadow: var(--shadow-hover); border-color: var(--text-main); }

        .tier-card.featured { background: var(--bg-dark); color: var(--text-inverse); border: none; }
        .tier-card.featured .tier-name { color: rgba(255,255,255,0.4); }
        .tier-card.featured .tier-price { color: var(--text-inverse); }
        .tier-card.featured .feature-list li { color: rgba(255,255,255,0.7); }
        .tier-card.featured .feature-list li::before { color: var(--brand-accent); }

        .tier-name {
            font-size: 12px; text-transform: uppercase; letter-spacing: 2px;
            color: var(--text-muted); font-weight: 600; margin-bottom: 24px;
        }
        .tier-price { font-size: 48px; font-weight: 700; letter-spacing: -0.02em; margin-bottom: 12px; color: var(--bg-dark); }
        .tier-price span { font-size: 16px; font-weight: 400; letter-spacing: 0; color: var(--text-muted); }
        .tier-card.featured .tier-price span { color: rgba(255,255,255,0.5); }
        
        .tier-desc { font-size: 14px; color: var(--text-muted); line-height: 1.6; margin-bottom: 32px; font-weight: 300; }
        .tier-card.featured .tier-desc { color: rgba(255,255,255,0.5); }

        .feature-list { list-style: none; padding: 0; margin: 0 0 48px 0; flex: 1; }
        .feature-list li { font-size: 15px; color: var(--text-main); margin-bottom: 16px; padding-left: 28px; position: relative; line-height: 1.5; }
        .feature-list li::before { content: '✓'; position: absolute; left: 0; color: var(--bg-dark); font-weight: bold; }
        .feature-list li.unavailable { color: #71717A; }
        .feature-list li.unavailable::before { content: '×'; color: #A1A1AA; }
        .tier-card.featured .feature-list li.unavailable { color: rgba(255,255,255,0.45); }
        .tier-card.featured .feature-list li.unavailable::before { color: rgba(255,255,255,0.4); }
        .tier-card.free-tier { background: var(--bg-alt); border-style: dashed; }
        .tier-card.free-tier:hover { border-color: var(--brand-accent); }
        .tier-card.free-tier .tier-price { font-size: 44px; }
        .tier-card[aria-current="true"] { box-shadow: inset 0 0 0 2px #10B981, var(--shadow-subtle); }

        .btn-store {
            width: 100%; display: inline-flex; align-items: center; justify-content: center;
            padding: 18px; border-radius: 40px; font-size: 15px; font-weight: 500;
            text-decoration: none; transition: var(--transition-smooth); text-align: center;
        }
        .btn-dark { background: var(--bg-dark); color: var(--text-inverse); }
        .btn-dark:hover { background: var(--accent-hover); }
        .btn-light { background: var(--bg-light); color: var(--bg-dark); font-weight: 600; }
        .btn-light:hover { background: #FFFFFF; transform: scale(1.02); }

        .membership-action[aria-busy="true"] { opacity: 0.7; pointer-events: none; }
        .membership-action[aria-disabled="true"] { opacity: 0.58; cursor: not-allowed; pointer-events: none; }
        .membership-action.current-plan { box-shadow: inset 0 0 0 2px #10B981; }
        .membership-equivalence { font-size: 12px; font-weight: 600; color: var(--brand-accent); margin: -4px 0 20px; }
        .tier-card.featured .membership-equivalence { color: #FDA4AF; }
        .membership-note-box {
            max-width: 820px; margin: 0 auto 28px; padding: 22px 24px; border-radius: var(--radius-md);
            background: var(--bg-alt); border: 1px solid var(--border); color: var(--text-muted);
            font-size: 14px; line-height: 1.7; text-align: center;
        }

        .badge {
            position: absolute; top: 24px; right: 32px;
            background: var(--brand-gradient); color: white;
            padding: 6px 14px; border-radius: 20px; font-size: 11px; font-weight: 600; letter-spacing: 1px; text-transform: uppercase;
        }

        .compliance-note { max-width: 700px; margin: 0 auto; text-align: center; color: var(--text-muted); font-size: 13px; line-height: 1.6; font-weight: 300; }

        @media (max-width: 1100px) {
            .tier-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media (max-width: 768px) {
            header.store-header { padding-top: 140px; }
            .tier-grid { grid-template-columns: 1fr; }
            .tier-card { padding: 32px; }
            .promo-banner { font-size: 13px; padding: 12px 20px; flex-direction: column; text-align: center; gap: 4px; }
            .desktop-menu { display: none; }
            .hamburger { display: flex; }
            .mobile-sticky-cta { display: block; }
            body { padding-bottom: 70px; }
        }
    </style>
</head>

<body>

    <nav>
        <div class="container nav-inner">
            <a href="<?php echo esc_url($home_url); ?>" class="logo text-accent">Aimee</a>
            
            <div class="desktop-menu">
                <a href="<?php echo esc_url($tech_url); ?>">Technology</a>
                <a href="<?php echo esc_url($pricing_url); ?>">Pricing</a>
                <a href="<?php echo esc_url($faq_url); ?>">FAQ</a>
                <a href="<?php echo esc_url($gallery_url); ?>">Aimee’s Photos</a>
                <a href="<?php echo esc_url($app_url); ?>" class="nav-login-btn">Start Free Preview / Sign In</a>
            </div>

            <div class="hamburger" id="hamburger-menu">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </div>
    </nav>

    <div class="mobile-menu" id="mobile-menu">
        <a href="<?php echo esc_url($home_url); ?>">Home</a>
        <a href="<?php echo esc_url($tech_url); ?>">Technology</a>
        <a href="<?php echo esc_url($pricing_url); ?>">Pricing</a>
        <a href="<?php echo esc_url($faq_url); ?>">FAQ</a>
        <a href="<?php echo esc_url($gallery_url); ?>">Aimee’s Photos</a>
        <a href="<?php echo esc_url($app_url); ?>" class="mobile-login">Start Free Preview / Sign In</a>
        <a href="<?php echo esc_url($privacy_url); ?>" style="font-size: 16px; font-weight: 400; margin-top: 40px; color: var(--text-muted);">Privacy & Terms</a>
    </div>

    <div class="mobile-sticky-cta" id="sticky-cta">
        <a href="<?php echo esc_url($app_url); ?>" class="btn-store" style="background: var(--brand-gradient); border: none; color: white;">Start Free Preview (30 Replies)</a>
    </div>

    <div class="container">
        <header class="store-header reveal" id="hero">
            <h1>Connection Memberships & Tiers</h1>
            <p>Maintaining a dedicated neural routing connection requires continuous real-time computing power. Start your journey completely risk-free, then select the membership window that fits naturally around your life.</p>
            
            <div class="promo-banner">
                <strong>🎁 Welcome Gift:</strong> Create your profile today and receive 30 complimentary replies from Aimee. No card required.
            </div>
        </header>

        <div class="membership-note-box reveal">
            The complimentary preview is a proper first meeting, not a watered-down demo. You can experience Aimee's core intelligence, personality, memory and visual understanding. Full membership then opens the complete relationship layer, including real-world SMS, autonomous mobile check-ins, uninterrupted conversation and adult intimacy when the chemistry and context naturally support it.
        </div>

        <div class="tier-grid" id="membership-options">
            <div class="tier-card free-tier reveal" data-plan-card="free">
                <div class="tier-name">Complimentary Preview</div>
                <div class="tier-price">£0<span> / 30 Replies</span></div>
                <p class="tier-desc">A proper introduction to Aimee, with enough room to discover her personality and decide whether the connection deserves more time.</p>
                <ul class="feature-list">
                    <li>30 in-app replies with Aimee</li>
                    <li>Core intelligence, personality and visual understanding</li>
                    <li>Your conversation and memories remain preserved</li>
                    <li class="unavailable">No real-world SMS or autonomous mobile texts</li>
                    <li class="unavailable">No explicit adult intimate conversation</li>
                    <li class="unavailable">Conversation pauses after the 30-reply preview</li>
                </ul>
                <a href="<?php echo esc_url($app_url); ?>" class="btn-store btn-dark free-preview-action">
                    <?php echo $is_logged_in ? 'Open Aimee' : 'Start Free Preview'; ?>
                </a>
            </div>
            <div class="tier-card reveal" data-plan-card="weekly">
                <div class="tier-name">Casual Connection</div>
                <div class="tier-price">£6.99<span> / Week</span></div>
                <p class="tier-desc">Perfect for spending a proper week with Aimee before deciding whether the connection belongs in your life for longer.</p>
                <ul class="feature-list">
                    <li>Seven days of uninterrupted in-app conversation</li>
                    <li>Full emotional memory and relationship continuity</li>
                    <li>Complete vision and image-understanding layers</li>
                    <li>Real-world SMS access within your Safe Windows</li>
                    <li>Adult intimate conversation when naturally earned (18+)</li>
                    <li>No per-message charges inside the app</li>
                </ul>
                <a href="<?php echo esc_url($app_url); ?>" class="btn-store btn-dark membership-action" data-plan="weekly">
                    <?php echo $is_logged_in ? 'Choose Weekly' : 'Start Free Preview'; ?>
                </a>
            </div>

            <div class="tier-card featured reveal" data-plan-card="monthly" style="transition-delay: 0.1s;">
                <div class="badge">Most Popular</div>
                <div class="tier-name">Established Connection</div>
                <div class="tier-price">£19.99<span> / Month</span></div>
                <p class="tier-desc">Designed for deep narrative consistency, regular companionship and the kind of shared history that cannot be built in a handful of messages.</p>
                <ul class="feature-list">
                    <li>A full month of uninterrupted in-app conversation</li>
                    <li>Persistent memories, emotional state and shared context</li>
                    <li>Complete vision and perception layers</li>
                    <li>Autonomous texts and check-ins within your Safe Windows</li>
                    <li>Adult intimate conversation when naturally earned (18+)</li>
                    <li>Manage or cancel renewal securely in Aimee</li>
                </ul>
                <a href="<?php echo esc_url($app_url); ?>" class="btn-store btn-light membership-action" data-plan="monthly">
                    <?php echo $is_logged_in ? 'Choose Monthly' : 'Start Free Preview'; ?>
                </a>
            </div>

            <div class="tier-card reveal" data-plan-card="annual" style="transition-delay: 0.2s;">
                <div class="tier-name">High-Fidelity Connection</div>
                <div class="tier-price">£149.00<span> / Year</span></div>
                <div class="membership-equivalence">Equivalent to approximately £12.42 per month</div>
                <p class="tier-desc">Maximum continuity and the strongest value. Built for a connection that has stopped feeling temporary and started becoming part of everyday life.</p>
                <ul class="feature-list">
                    <li>Twelve months of uninterrupted in-app conversation</li>
                    <li>Full long-term memory and relationship evolution</li>
                    <li>Complete vision, perception and emotional architecture</li>
                    <li>Autonomous mobile presence within your chosen boundaries</li>
                    <li>Adult intimate conversation when naturally earned (18+)</li>
                    <li>Save £90.88 compared with twelve monthly memberships</li>
                </ul>
                <a href="<?php echo esc_url($app_url); ?>" class="btn-store btn-dark membership-action" data-plan="annual">
                    <?php echo $is_logged_in ? 'Choose Annual' : 'Start Free Preview'; ?>
                </a>
            </div>
        </div>

        <p class="compliance-note reveal">
            * Your first 30 replies are complimentary and require no payment card. The free preview excludes real-world SMS, autonomous mobile contact and explicit adult intimate conversation. Paid memberships renew automatically at the selected interval until cancelled. Existing members can review their bank membership and cancel future renewals securely from their Aimee account. Adult features and paid membership are available only to users aged 18 and over. Intimacy remains contextual and relationship-led, not an on-demand mode.
        </p>
    </div>

    <footer style="margin-top: 100px; padding: 40px 0; text-align: center; font-size: 13px; color: var(--text-muted); border-top: 1px solid var(--border-light);">
        &copy; <?php echo date('Y'); ?> A.R.I. Systems. Membership payments secured through GoCardless. Discretion assured.
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const isLoggedIn = <?php echo $is_logged_in ? 'true' : 'false'; ?>;
            const appUrl = <?php echo wp_json_encode($app_url); ?>;
            const restNonce = <?php echo wp_json_encode($rest_nonce); ?>;
            let currentSubscription = null;
            let pricingBoundaryTimer = null;
            const refreshSubscriptionStatus = () => apiFetch('/subscription-status', { method: 'GET' })
                .then(data => updatePlanButtons(data.subscription || {}))
                .catch(error => console.warn('Membership status unavailable:', error));
            const schedulePricingBoundaryRefresh = (subscription = {}) => {
                if (pricingBoundaryTimer) window.clearTimeout(pricingBoundaryTimer);
                pricingBoundaryTimer = null;
                const boundaryValue = subscription.service_grace_active
                    ? subscription.service_grace_until
                    : (subscription.access_active && subscription.access_source === 'goodwill_extension'
                        ? (subscription.bonus_access_until || subscription.access_until)
                        : '');
                if (!boundaryValue) return;
                const boundary = new Date(boundaryValue).getTime();
                if (!boundary || Number.isNaN(boundary)) return;
                const remaining = boundary - Date.now();
                const delay = Math.max(1000, Math.min(remaining + 1000, 6 * 60 * 60 * 1000));
                pricingBoundaryTimer = window.setTimeout(refreshSubscriptionStatus, delay);
            };

            const apiFetch = async (path, options = {}) => {
                const headers = new Headers(options.headers || {});
                headers.set('Accept', 'application/json');
                if (restNonce) headers.set('X-WP-Nonce', restNonce);
                if (options.body && !headers.has('Content-Type')) headers.set('Content-Type', 'application/json');

                const response = await fetch(`/wp-json/aimee/v1${path}`, {
                    credentials: 'same-origin',
                    ...options,
                    headers
                });

                let data = {};
                try { data = await response.json(); } catch (error) {}
                if (!response.ok) throw new Error(data.message || 'A secure connection could not be established.');
                return data;
            };

            const managedStatuses = new Set(['active', 'trialing', 'past_due', 'unpaid', 'paused']);
            const planLabels = { weekly: 'Weekly', monthly: 'Monthly', annual: 'Annual' };

            const updatePlanButtons = (subscription = {}) => {
                currentSubscription = subscription;
                const status = String(subscription.status || '').toLowerCase();
                const billingStatus = String(subscription.billing_status || status).toLowerCase();
                const currentPlan = String(subscription.plan || '').toLowerCase();
                const requiresReactivation = Boolean(subscription.requires_reactivation);
                const serviceGraceActive = Boolean(subscription.service_grace_active);
                const goodwillActive = Boolean(
                    subscription.access_active
                    && subscription.access_source === 'goodwill_extension'
                );
                const goodwillUntil = subscription.bonus_access_until || subscription.access_until || '';
                const goodwillDate = goodwillUntil ? new Date(goodwillUntil) : null;
                const goodwillDateLabel = goodwillDate && !Number.isNaN(goodwillDate.getTime())
                    ? goodwillDate.toLocaleString([], { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZoneName: 'short' })
                    : '';
                schedulePricingBoundaryRefresh(subscription);
                const newSubscriptionRequired = Boolean(subscription.new_subscription_required);
                const billingManageable = Boolean(subscription.can_manage_billing) && managedStatuses.has(billingStatus);
                const managed = billingManageable && !requiresReactivation;
                const checkoutAvailable = subscription.checkout_available !== false;
                const checkoutOpensAt = subscription.checkout_opens_at ? new Date(subscription.checkout_opens_at) : null;
                const checkoutOpenLabel = checkoutOpensAt && !Number.isNaN(checkoutOpensAt.getTime())
                    ? `Checkout opens ${checkoutOpensAt.toLocaleDateString([], { day: 'numeric', month: 'long', year: 'numeric' })}`
                    : 'Checkout opens after existing access';
                const previewRemaining = Math.max(0, Number(subscription.preview_remaining || 0));
                const freeButton = document.querySelector('.free-preview-action');
                const freeCard = document.querySelector('[data-plan-card="free"]');

                if (freeButton && freeCard) {
                    const previewActive = status === 'trial' && previewRemaining > 0;
                    freeCard.setAttribute('aria-current', (previewActive || serviceGraceActive || goodwillActive) ? 'true' : 'false');

                    if (goodwillActive) {
                        freeButton.textContent = 'Temporary In-App Access · Active';
                        freeButton.setAttribute('href', appUrl);
                    } else if (serviceGraceActive) {
                        freeButton.textContent = 'Complimentary August Access · Active';
                    } else if (previewActive) {
                        freeButton.textContent = `Current Preview · ${previewRemaining} replies left`;
                    } else if (managed) {
                        freeButton.textContent = 'Free Preview Completed';
                    } else if (isLoggedIn) {
                        freeButton.textContent = 'Preview Complete · View Memberships';
                        freeButton.setAttribute('href', '#membership-options');
                    }
                }

                let migrationBanner = document.getElementById('aimee-pricing-migration-banner');
                if (goodwillActive || serviceGraceActive || requiresReactivation || newSubscriptionRequired) {
                    if (!migrationBanner) {
                        migrationBanner = document.createElement('div');
                        migrationBanner.id = 'aimee-pricing-migration-banner';
                        migrationBanner.setAttribute('role', 'status');
                        migrationBanner.setAttribute('aria-live', 'polite');
                        migrationBanner.setAttribute('aria-atomic', 'true');
                        migrationBanner.style.cssText = 'margin:0 auto 24px;max-width:980px;padding:20px 22px;border:1px solid #f2bdcb;border-radius:20px;background:linear-gradient(135deg,#fff7f9,#fff);box-shadow:0 16px 40px rgba(136,19,55,.08);color:#5f1a31;line-height:1.6';
                        const grid = document.querySelector('.tier-grid');
                        if (grid) grid.insertAdjacentElement('beforebegin', migrationBanner);
                    }
                    migrationBanner.style.borderColor = '#f2bdcb';
                    migrationBanner.style.background = 'linear-gradient(135deg,#fff7f9,#fff)';
                    migrationBanner.style.color = '#5f1a31';
                    if (goodwillActive) {
                        migrationBanner.style.borderColor = '#a7f3d0';
                        migrationBanner.style.background = 'linear-gradient(135deg,#ecfdf5,#fff)';
                        migrationBanner.style.color = '#065f46';
                        migrationBanner.innerHTML = `<strong style="display:block;font-size:17px;margin-bottom:4px">Temporary full in-app access is active</strong>You can continue using Aimee normally${goodwillDateLabel ? ` until <strong>${goodwillDateLabel}</strong>` : ''} while we resolve the payment issue. This access grant did not create or schedule a payment. No new checkout is needed to keep using Aimee in-app during this period; any separate billing notice still needs attention.`;
                    } else if (serviceGraceActive) {
                        migrationBanner.style.borderColor = '#a7f3d0';
                        migrationBanner.style.background = 'linear-gradient(135deg,#ecfdf5,#fff)';
                        migrationBanner.style.color = '#065f46';
                        migrationBanner.innerHTML = `<strong style="display:block;font-size:17px;margin-bottom:4px">A thank-you from Engram Intelligence</strong>Full in-app Aimee access is complimentary through <strong>31 August 2026</strong> while we rebuild the payment flow. Any subscription held in our former Stripe account cannot renew, and no new subscription or payment has been created automatically. GoCardless bank checkout becomes available after that access ends.`;
                    } else if (newSubscriptionRequired && !requiresReactivation) {
                        migrationBanner.innerHTML = `<strong style="display:block;font-size:17px;margin-bottom:4px">Create your new Aimee membership</strong>Your complimentary August access has ended. Choose a plan below to continue. No subscription or payment was created automatically; payment is taken only after you explicitly complete secure checkout.`;
                    } else {
                    const accessDate = subscription.legacy_access_until ? new Date(subscription.legacy_access_until) : null;
                    const formatted = accessDate && !Number.isNaN(accessDate.getTime())
                        ? accessDate.toLocaleDateString([], { day: 'numeric', month: 'long', year: 'numeric' })
                        : '';
                    const paymentCopy = subscription.legacy_access_active && formatted
                        ? `GoCardless checkout remains closed until that access ends; no replacement payment is created before then.`
                        : `Your new membership begins, and its first bank payment is created, only after you explicitly complete GoCardless checkout.`;
                    migrationBanner.innerHTML = `<strong style="display:block;font-size:17px;margin-bottom:4px">Your membership needs a quick update</strong>Your previous subscription was linked to our former payment account, which is now closed, so it cannot renew or charge you again. ${subscription.legacy_access_active && formatted ? `Your existing access remains available until <strong>${formatted}</strong>. ` : ''}Choose a plan below to reconnect securely. <strong>No card checkout will be opened.</strong> ${paymentCopy}`;
                    }
                } else if (migrationBanner) {
                    migrationBanner.remove();
                }

                document.querySelectorAll('.membership-action').forEach(button => {
                    const plan = String(button.dataset.plan || '').toLowerCase();
                    const card = document.querySelector(`[data-plan-card="${plan}"]`);
                    const isCurrent = (goodwillActive ? billingManageable : managed) && currentPlan === plan;

                    button.classList.toggle('current-plan', isCurrent);
                    if (card) card.setAttribute('aria-current', isCurrent ? 'true' : 'false');
                    const unavailable = goodwillActive ? !billingManageable : (!managed && !checkoutAvailable);
                    if (unavailable) button.setAttribute('aria-disabled', 'true');
                    else button.removeAttribute('aria-disabled');

                    if (goodwillActive && !billingManageable) button.textContent = 'Temporary in-app access active';
                    else if (goodwillActive && isCurrent) button.textContent = 'Current Plan · Manage';
                    else if (goodwillActive) button.textContent = 'Manage current membership';
                    else if (serviceGraceActive && !managed) button.textContent = `${planLabels[plan] || plan} available 1 September`;
                    else if (!managed && !checkoutAvailable) button.textContent = checkoutOpenLabel;
                    else if (requiresReactivation && currentPlan === plan) button.textContent = `Reconnect ${planLabels[plan] || plan}`;
                    else if (requiresReactivation) button.textContent = `Continue with ${planLabels[plan] || plan}`;
                    else if (newSubscriptionRequired) button.textContent = `Start ${planLabels[plan] || plan}`;
                    else if (isCurrent) button.textContent = 'Current Plan · Manage';
                    else if (managed) button.textContent = 'Manage current membership';
                    else button.textContent = `Choose ${planLabels[plan] || plan}`;
                });
            };

            const openBillingPortal = async (button, requestedPlan) => {
                const original = button.textContent;
                button.setAttribute('aria-busy', 'true');
                button.textContent = 'Opening membership settings…';
                try {
                    const data = await apiFetch('/billing-portal', {
                        method: 'POST',
                        body: JSON.stringify({ source: 'pricing-page', requested_plan: requestedPlan })
                    });
                    if (!data.portal_url) throw new Error(data.message || 'Membership settings could not be opened.');
                    window.location.href = data.portal_url;
                } catch (error) {
                    alert(error.message || 'Membership settings could not be opened.');
                    button.textContent = original;
                    button.removeAttribute('aria-busy');
                }
            };

            const startCheckout = async (button, plan) => {
                const original = button.textContent;
                if (currentSubscription && currentSubscription.checkout_available === false) {
                    const opens = currentSubscription.checkout_opens_at ? new Date(currentSubscription.checkout_opens_at) : null;
                    const suffix = opens && !Number.isNaN(opens.getTime())
                        ? ` Checkout opens ${opens.toLocaleDateString([], { day: 'numeric', month: 'long', year: 'numeric' })}.`
                        : '';
                    alert(`GoCardless checkout is unavailable while existing access remains active.${suffix} No payment was created.`);
                    return;
                }
                button.setAttribute('aria-busy', 'true');
                button.textContent = 'Securing connection…';
                try {
                    const data = await apiFetch('/subscription-checkout', {
                        method: 'POST',
                        body: JSON.stringify({ plan, source: 'pricing-page' })
                    });

                    if (data.status === 'already_active') {
                        if (data.subscription) updatePlanButtons(data.subscription);
                        await openBillingPortal(button, plan);
                        return;
                    }

                    if (!data.checkout_url) throw new Error(data.message || 'Secure checkout could not be created.');
                    window.location.href = data.checkout_url;
                } catch (error) {
                    alert(error.message || 'Secure checkout could not be created.');
                    button.textContent = original;
                    button.removeAttribute('aria-busy');
                }
            };

            document.querySelectorAll('.membership-action').forEach(button => {
                button.addEventListener('click', async event => {
                    if (!isLoggedIn) return;
                    event.preventDefault();
                    const plan = String(button.dataset.plan || '').toLowerCase();
                    const status = String(currentSubscription?.billing_status || currentSubscription?.status || '').toLowerCase();
                    if (currentSubscription?.access_active && currentSubscription?.access_source === 'goodwill_extension') {
                        if (currentSubscription?.can_manage_billing && managedStatuses.has(status)) {
                            await openBillingPortal(button, plan);
                        } else {
                            const until = currentSubscription.bonus_access_until || currentSubscription.access_until || '';
                            const date = until ? new Date(until) : null;
                            const suffix = date && !Number.isNaN(date.getTime())
                                ? ` until ${date.toLocaleString([], { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZoneName: 'short' })}`
                                : '';
                            alert(`Your temporary full in-app access is active${suffix} while we resolve the payment issue. This access grant did not create or schedule a payment.`);
                        }
                    } else if (currentSubscription?.service_grace_active && !currentSubscription?.can_manage_billing) {
                        alert('August access is complimentary. New subscription checkout opens on 1 September 2026, and no payment is scheduled automatically.');
                    } else if (currentSubscription?.checkout_available === false && !currentSubscription?.can_manage_billing) {
                        await startCheckout(button, plan);
                    } else if (currentSubscription?.requires_reactivation || currentSubscription?.new_subscription_required) await startCheckout(button, plan);
                    else if (currentSubscription?.can_manage_billing && managedStatuses.has(status)) await openBillingPortal(button, plan);
                    else await startCheckout(button, plan);
                });
            });

            if (isLoggedIn) {
                refreshSubscriptionStatus();
                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'visible') refreshSubscriptionStatus();
                });
            }

            // Hamburger Menu Logic
            const hamburger = document.getElementById('hamburger-menu');
            const mobileMenu = document.getElementById('mobile-menu');
            const body = document.body;

            hamburger.addEventListener('click', () => {
                hamburger.classList.toggle('active');
                mobileMenu.classList.toggle('active');
                
                if (mobileMenu.classList.contains('active')) {
                    body.style.overflow = 'hidden';
                } else {
                    body.style.overflow = '';
                }
            });

            const mobileLinks = mobileMenu.querySelectorAll('a');
            mobileLinks.forEach(link => {
                link.addEventListener('click', () => {
                    hamburger.classList.remove('active');
                    mobileMenu.classList.remove('active');
                    body.style.overflow = '';
                });
            });

            // Sticky Mobile CTA Logic
            const stickyCta = document.getElementById('sticky-cta');
            const heroSection = document.getElementById('hero');
            
            window.addEventListener('scroll', () => {
                // Show sticky CTA only when scrolling slightly past the hero section
                if (window.scrollY > 150) {
                    stickyCta.classList.add('visible');
                } else {
                    stickyCta.classList.remove('visible');
                }
            });

            // Scroll Reveal Logic
            const reveals = document.querySelectorAll('.reveal');
            const observer = new IntersectionObserver((entries, obs) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('active');
                        obs.unobserve(entry.target);
                    }
                });
            }, { root: null, threshold: 0.1, rootMargin: "0px 0px -50px 0px" });

            reveals.forEach(reveal => observer.observe(reveal));
            
            // Subtle nav background effect on scroll
            const nav = document.querySelector('nav');
            window.addEventListener('scroll', () => {
                if (window.scrollY > 50) {
                    nav.style.background = 'rgba(252, 252, 252, 0.95)';
                    nav.style.boxShadow = '0 4px 20px rgba(0,0,0,0.03)';
                } else {
                    nav.style.background = 'rgba(252, 252, 252, 0.85)';
                    nav.style.boxShadow = 'none';
                }
            });
        });
    </script>

    <?php wp_footer(); ?>
</body>
</html>
