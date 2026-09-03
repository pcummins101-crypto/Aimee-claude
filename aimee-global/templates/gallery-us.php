<?php
defined('ABSPATH') || exit;
$aimee_market = 'us';
aimee_global_set_market('us');
/*
Template Name: Aimee Photo Gallery
*/

$aimee_market = 'us';
$home_url = aimee_global_route('home',$aimee_market);
$app_url = aimee_global_route('chat',$aimee_market);
$pricing_url = aimee_global_route('pricing',$aimee_market);
$faq_url = aimee_global_route('faq',$aimee_market);
$tech_url = aimee_global_route('technology',$aimee_market);
$privacy_url = aimee_global_route('privacy',$aimee_market);
$gallery_url = aimee_global_route('gallery',$aimee_market);
$gallery_access = function_exists('aimee_security_require_gallery_access')
    ? aimee_security_require_gallery_access($aimee_market)
    : null;
if (!is_array($gallery_access)) {
    wp_die(
        esc_html__('The private gallery is temporarily unavailable.', 'aimee-global'),
        esc_html__('Aimee gallery unavailable', 'aimee-global'),
        ['response' => 503]
    );
}
$gallery_albums = aimee_security_gallery_albums(
    intval($gallery_access['user_id']),
    $gallery_access['profile']
);
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <title>Aimee AI | Aimee’s Camera Roll</title>
    <meta name="title" content="Aimee AI | Aimee’s Camera Roll">
    <meta name="description" content="A visual glimpse into Aimee's world.">
    <meta name="author" content="A.R.I. Systems">
    
    <?php wp_head(); ?>
    <style>
        /* Premium Softer Palette & Variables */
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
            
            /* The Human Element Color Splash */
            --brand-accent: #E11D48; /* Warm, vibrant rose/crimson */
            --brand-gradient: linear-gradient(135deg, #F43F5E 0%, #BE123C 100%);
            
            --radius-md: 16px;
            --radius-lg: 32px;
            --shadow-subtle: 0 10px 30px -10px rgba(0, 0, 0, 0.04);
            --shadow-hover: 0 20px 40px -15px rgba(0, 0, 0, 0.08);
            --transition-smooth: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }

        * {
            box-sizing: border-box;
        }

        body, html {
            margin: 0;
            padding: 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            overflow-x: hidden;
            scroll-behavior: smooth;
            background-color: var(--bg-light);
            color: var(--text-main);
        }

        h1, h2, h3 { margin: 0; letter-spacing: -0.03em; font-weight: 600; }
        p { line-height: 1.8; margin: 0 0 24px 0; }
        
        /* Text Accent Utility */
        .text-accent {
            background: var(--brand-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: inline-block;
        }

        /* Improved Spacing Container for Large Screens */
        .container { width: 100%; max-width: 1440px; margin: 0 auto; padding: 0 5vw; }

        /* UI Elements & Buttons */
        .btn {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 18px 36px; border-radius: 40px; font-size: 15px; font-weight: 500;
            letter-spacing: 0.02em; text-decoration: none; transition: var(--transition-smooth);
            cursor: pointer; border: 1px solid transparent;
        }
        .btn-primary { background-color: var(--bg-dark); color: var(--text-inverse); box-shadow: var(--shadow-subtle); }
        .btn-primary:hover { background-color: var(--accent-hover); transform: translateY(-2px); box-shadow: var(--shadow-hover); }

        /* Sticky Navigation */
        nav {
            position: fixed; width: 100%; top: 0;
            background: rgba(252, 252, 252, 0.85); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            padding: 24px 0; z-index: 1000; border-bottom: 1px solid rgba(228, 228, 231, 0.5);
            transition: var(--transition-smooth);
        }
        .nav-inner { display: flex; justify-content: space-between; align-items: center; }
        .logo { font-size: 22px; font-weight: 800; letter-spacing: 0.05em; text-decoration: none; position: relative; z-index: 1001; }
        
        /* Desktop Menu */
        .desktop-menu { display: flex; align-items: center; gap: 32px; }
        .desktop-menu a { color: var(--text-main); text-decoration: none; font-weight: 500; font-size: 14px; transition: color 0.2s; }
        .desktop-menu a:hover { color: var(--brand-accent); }
        .desktop-menu .nav-login-btn { background: var(--bg-dark); color: var(--text-inverse); padding: 10px 24px; border-radius: 30px; }
        .desktop-menu .nav-login-btn:hover { background: var(--accent-hover); color: var(--text-inverse); transform: translateY(-1px); }

        /* Hamburger Icon */
        .hamburger { display: none; flex-direction: column; gap: 5px; cursor: pointer; z-index: 1001; padding: 10px 0; border: 0; background: transparent; }
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

        section { padding: 140px 0; }

        .reveal { opacity: 0; transform: translateY(40px); transition: all 1s cubic-bezier(0.16, 1, 0.3, 1); }
        .reveal.active { opacity: 1; transform: translateY(0); }

        /* Gallery specific styles */
        .gallery-header {
            text-align: center;
            max-width: 800px;
            margin: 0 auto 60px;
            padding-top: 60px; /* Offset for fixed nav */
        }
        .gallery-header h1 {
            font-size: clamp(48px, 6vw, 72px);
            margin-bottom: 24px;
            color: var(--bg-dark);
        }
        .gallery-header p {
            font-size: clamp(18px, 2.5vw, 22px);
            color: var(--text-muted);
            font-weight: 300;
        }

        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 24px;
            padding-bottom: 80px;
        }

        .gallery-item {
            position: relative;
            border-radius: var(--radius-md);
            overflow: hidden;
            aspect-ratio: 4/5;
            box-shadow: var(--shadow-subtle);
            background-color: var(--bg-alt); /* Placeholder color while loading */
            transition: var(--transition-smooth);
        }
        
        .gallery-item:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }

        /* Anti-download protective overlay */
        .gallery-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 10;
            background: transparent;
            /* Prevents long-press context menu on mobile */
            -webkit-touch-callout: none;
        }

        .gallery-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            /* Prevent dragging and interactions */
            pointer-events: none;
            user-select: none;
            -webkit-user-drag: none;
            -khtml-user-drag: none;
            -moz-user-drag: none;
            -o-user-drag: none;
        }

        /* Footer */
        footer { padding: 60px 0; text-align: center; background: var(--bg-light); border-top: 1px solid var(--border-light); margin-top: 20px;}
        .footer-links { margin-bottom: 24px; display: flex; justify-content: center; gap: 24px; flex-wrap: wrap; }
        .footer-links a { color: var(--text-muted); text-decoration: none; font-size: 14px; font-weight: 500; transition: color 0.2s; }
        .footer-links a:hover { color: var(--brand-accent); }
        .footer-copy { color: var(--text-muted); font-size: 13px; font-weight: 400; }

        @media (max-width: 768px) {
            section { padding: 100px 0; }
            .desktop-menu { display: none; }
            .hamburger { display: flex; }
            .gallery-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 16px; }
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
                <a href="<?php echo esc_url($gallery_url); ?>" aria-current="page">Camera Roll</a>
                <a href="<?php echo esc_url($app_url); ?>" class="nav-login-btn">Back to chat</a>
            </div>

            <button class="hamburger" id="hamburger-menu" type="button" aria-label="Open navigation" aria-expanded="false" aria-controls="mobile-menu">
                <span></span>
                <span></span>
                <span></span>
            </button>
        </div>
    </nav>

    <div class="mobile-menu" id="mobile-menu">
        <a href="<?php echo esc_url($home_url); ?>">Home</a>
        <a href="<?php echo esc_url($tech_url); ?>">Technology</a>
        <a href="<?php echo esc_url($pricing_url); ?>">Pricing</a>
        <a href="<?php echo esc_url($faq_url); ?>">FAQ</a>
        <a href="<?php echo esc_url($gallery_url); ?>" aria-current="page">Camera Roll</a>
        <a href="<?php echo esc_url($app_url); ?>" class="mobile-login">Back to chat</a>
        <a href="<?php echo esc_url($privacy_url); ?>" style="font-size: 16px; font-weight: 400; margin-top: 40px; color: var(--text-muted);">Privacy & Terms</a>
    </div>

    <section class="gallery-section">
        <div class="container">
            <div class="gallery-header reveal">
                <h1>Aimee’s Camera Roll</h1>
                <p>Browse the photos Aimee has chosen to share with you. Tap any photo to ask her about it.</p>
            </div>
            
            <?php include AIMEE_GLOBAL_DIR . 'templates/shared/gallery-albums.php'; ?>
        </div>
    </section>

    <footer>
        <div class="container">
            <div class="footer-links">
                <a href="<?php echo esc_url($tech_url); ?>">Architecture</a>
                <a href="<?php echo esc_url($pricing_url); ?>">Store</a>
                <a href="<?php echo esc_url($faq_url); ?>">FAQ</a>
                <a href="<?php echo esc_url($privacy_url); ?>">Privacy & Terms of Use</a>
            </div>
            <div class="footer-copy">
                &copy; <?php echo date('Y'); ?> A.R.I. Systems. All rights reserved. Discretion assured.
            </div>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Hamburger Menu Logic
            const hamburger = document.getElementById('hamburger-menu');
            const mobileMenu = document.getElementById('mobile-menu');
            const body = document.body;

            hamburger.addEventListener('click', () => {
                hamburger.classList.toggle('active');
                mobileMenu.classList.toggle('active');
                hamburger.setAttribute('aria-expanded', mobileMenu.classList.contains('active') ? 'true' : 'false');
                
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
                    hamburger.setAttribute('aria-expanded', 'false');
                    body.style.overflow = '';
                });
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
            
            // Extra layer of protection: prevent default drag behavior on the document level for images
            document.addEventListener('dragstart', function(e) {
                if (e.target.nodeName.toUpperCase() === 'IMG') {
                    e.preventDefault();
                }
            });
        });
    </script>
    
    <?php wp_footer(); ?>
</body>
</html>
