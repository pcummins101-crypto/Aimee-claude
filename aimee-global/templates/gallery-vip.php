<?php
/* Template Name: Aimee Photo Gallery - Staff Review */
defined('ABSPATH') || exit;
$gallery_access = function_exists('aimee_security_require_gallery_access')
    ? aimee_security_require_gallery_access('uk')
    : null;
if (!is_array($gallery_access)) {
    wp_die(
        esc_html__('The private gallery is temporarily unavailable.', 'aimee-global'),
        esc_html__('Aimee gallery unavailable', 'aimee-global'),
        ['response' => 503]
    );
}
// This staff-only template keeps its stricter capability gate in addition to
// the normal signed-in, per-item gallery policy.
if (!current_user_can('upload_files')) { status_header(404); exit('Not found.'); }
$gallery_albums = aimee_security_gallery_albums(
    intval($gallery_access['user_id']),
    $gallery_access['profile']
);
$aimee_market = 'uk';
$home_url=aimee_global_route('home','uk'); $app_url=aimee_global_route('chat','uk'); $pricing_url=aimee_global_route('pricing','uk'); $faq_url=aimee_global_route('faq','uk'); $tech_url=aimee_global_route('technology','uk'); $privacy_url=aimee_global_route('privacy','uk');
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <title>Aimee AI | VIP Moments & Gallery</title>
    <meta name="title" content="Aimee AI | VIP Moments & Gallery">
    
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
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            -webkit-font-smoothing: antialiased;
            background-color: var(--bg-light); color: var(--text-main);
        }

        h1, h2, h3 { margin: 0; letter-spacing: -0.03em; font-weight: 600; }
        p { line-height: 1.8; margin: 0 0 24px 0; }
        
        .text-accent {
            background: var(--brand-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: inline-block;
        }

        .container { width: 100%; max-width: 1440px; margin: 0 auto; padding: 0 5vw; }

        /* UI Elements & Buttons */
        .btn {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 16px 32px; border-radius: 40px; font-size: 15px; font-weight: 500;
            cursor: pointer; border: 1px solid transparent; transition: var(--transition-smooth);
        }
        .btn-primary { background-color: var(--bg-dark); color: var(--text-inverse); }
        .btn-primary:hover { background-color: var(--accent-hover); transform: translateY(-2px); }

        /* Navigation omitted for brevity in CSS (matches previous version) */
        nav { position: fixed; width: 100%; top: 0; background: rgba(252, 252, 252, 0.85); backdrop-filter: blur(12px); padding: 24px 0; z-index: 1000; border-bottom: 1px solid rgba(228, 228, 231, 0.5); }
        .nav-inner { display: flex; justify-content: space-between; align-items: center; }
        .logo { font-size: 22px; font-weight: 800; text-decoration: none; }
        .desktop-menu { display: flex; align-items: center; gap: 32px; }
        .desktop-menu a { color: var(--text-main); text-decoration: none; font-weight: 500; font-size: 14px; }
        .desktop-menu .nav-login-btn { background: var(--bg-dark); color: var(--text-inverse); padding: 10px 24px; border-radius: 30px; }
        .hamburger { display: none; }

        section { padding: 140px 0; }

        .reveal { opacity: 0; transform: translateY(40px); transition: all 1s cubic-bezier(0.16, 1, 0.3, 1); }
        .reveal.active { opacity: 1; transform: translateY(0); }

        /* Password Box */
        .password-section { min-height: 80vh; display: flex; align-items: center; justify-content: center; }
        .password-box { max-width: 450px; width: 100%; text-align: center; padding: 48px; background: #ffffff; border-radius: var(--radius-md); box-shadow: var(--shadow-subtle); border: 1px solid var(--border); }
        .password-input { width: 100%; padding: 16px 20px; margin-bottom: 24px; border: 1px solid var(--border); border-radius: 8px; font-family: inherit; font-size: 16px; }

        /* Gallery & Upload Styles */
        .gallery-header { text-align: center; max-width: 800px; margin: 0 auto 40px; padding-top: 60px; }
        .gallery-header h1 { font-size: clamp(48px, 6vw, 72px); margin-bottom: 24px; color: var(--bg-dark); }
        .gallery-header p { font-size: clamp(18px, 2.5vw, 22px); color: var(--text-muted); font-weight: 300; }

        .gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 24px; padding-bottom: 80px; }
        .gallery-item { position: relative; border-radius: var(--radius-md); overflow: hidden; aspect-ratio: 4/5; box-shadow: var(--shadow-subtle); background-color: var(--bg-alt); transition: var(--transition-smooth); }
        .gallery-item:hover { transform: translateY(-5px); box-shadow: var(--shadow-hover); }
        .gallery-img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform 0.5s ease; }
        .gallery-item:hover .gallery-img { transform: scale(1.03); }

        footer { padding: 60px 0; text-align: center; background: var(--bg-light); border-top: 1px solid var(--border-light); margin-top: 20px;}
        .footer-links { margin-bottom: 24px; display: flex; justify-content: center; gap: 24px; flex-wrap: wrap; }
        .footer-links a { color: var(--text-muted); text-decoration: none; font-size: 14px; font-weight: 500; }
        .footer-copy { color: var(--text-muted); font-size: 13px; }

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
                <a href="<?php echo esc_url($app_url); ?>" class="nav-login-btn">Start Free Preview / Sign In</a>
            </div>
        </div>
    </nav>


        <section class="gallery-section">
            <div class="container">
                <div class="gallery-header reveal">
                    <h1>Moments.</h1>
                    <p>The authorised Aimee visual catalogue, grouped for staff review.</p>
                </div>

                <?php include AIMEE_GLOBAL_DIR . 'templates/shared/gallery-albums.php'; ?>
            </div>
        </section>


    <footer>
        <div class="container">
            <div class="footer-copy">
                &copy; <?php echo date('Y'); ?> A.R.I. Systems. All rights reserved. Discretion assured.
            </div>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
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
        });
    </script>
    <?php wp_footer(); ?>
</body>
</html>
