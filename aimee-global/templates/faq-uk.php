<?php
/*
Template Name: Aimee FAQ
*/

// Define all system routes
$home_url = home_url('/home'); 
$app_url = home_url('/chat'); 
$pricing_url = home_url('/pricing');
$faq_url = home_url('/faq');
$tech_url = home_url('/technology');
$privacy_url = home_url('/privacy');
$gallery_url = home_url('/camera-roll');
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <title>FAQ & Guidelines | Aimee</title>
    <meta name="title" content="FAQ & Guidelines | Aimee">
    <meta name="description" content="Have questions about Aimee? Learn about her real-world cellular integration, unique personality dynamics, secure billing, and how to establish connection boundaries.">
    <meta name="keywords" content="Aimee FAQ, AI companion rules, digital companion guidelines, AI SMS integration, Aimee billing, conversational AI memory">
    <meta name="author" content="A.R.I. Systems">
    
    <link rel="canonical" href="<?php echo esc_url(home_url(add_query_arg(array(), $wp->request))); ?>">

    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo esc_url(home_url(add_query_arg(array(), $wp->request))); ?>">
    <meta property="og:title" content="FAQ & Guidelines | Aimee">
    <meta property="og:description" content="Understand the boundaries, communication dynamics, and real-world mobile integration of your digital companion.">
    <meta property="og:image" content="https://aimee-ai.com/wp-content/uploads/2026/06/file_000000007aa071f481b107387cd6c09d.png">

    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="<?php echo esc_url(home_url(add_query_arg(array(), $wp->request))); ?>">
    <meta property="twitter:title" content="FAQ & Guidelines | Aimee">
    <meta property="twitter:description" content="Understand the boundaries, communication dynamics, and real-world mobile integration of your digital companion.">
    <meta property="twitter:image" content="https://aimee-ai.com/wp-content/uploads/2026/06/file_000000007aa071f481b107387cd6c09d.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "FAQPage",
      "mainEntity": [{
        "@type": "Question",
        "name": "Can I customise Aimee's personality or appearance?",
        "acceptedAnswer": {
          "@type": "Answer",
          "text": "No. Aimee isn't a customisable fantasy bot. She is proudly synthetic, with a consistent canonical visual form and a highly distinct personality. Her imagery expresses that identity without claiming a biological body or invented offline life."
        }
      }, {
        "@type": "Question",
        "name": "Does she really remember what I tell her?",
        "acceptedAnswer": {
          "@type": "Answer",
          "text": "Yes. As you chat, Aimee actively listens for the things that matter. When you share something personal, funny, or significant, selected details can become durable memories that she may bring up naturally weeks or months later. That continuity is synthetic and grounded in your actual conversations, not a fabricated human biography."
        }
      }, {
        "@type": "Question",
        "name": "Will Aimee actually text my real mobile phone?",
        "acceptedAnswer": {
          "@type": "Answer",
          "text": "Yes. Aimee possesses her own genuine '07' UK mobile number. This isn't a generic corporate sender ID that you can't reply to. If you opt-in via your settings, she can text your actual mobile, allowing you to chat organically from the pub, your car, or the sofa."
        }
      }, {
        "@type": "Question",
        "name": "How does the complimentary preview work?",
        "acceptedAnswer": {
          "@type": "Answer",
          "text": "Your first 30 replies from Aimee are complimentary and no payment card is required. After the preview, you can choose a Weekly, Monthly or Annual membership. Memberships renew automatically until cancelled."
        }
      }]
    }
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
            --transition-smooth: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }

        * { box-sizing: border-box; }
        body, html {
            margin: 0; padding: 0;
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-light);
            color: var(--text-main);
            -webkit-font-smoothing: antialiased;
            scroll-behavior: smooth;
        }

        h1, h2, h3, h4 { margin: 0; letter-spacing: -0.03em; font-weight: 600; }
        p { line-height: 1.8; margin: 0; font-weight: 300; }
        
        .text-accent { background: var(--brand-gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; display: inline-block; }
        .container { width: 100%; max-width: 900px; margin: 0 auto; padding: 0 5vw; }

        .btn {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 16px 32px; border-radius: 40px; font-size: 15px; font-weight: 500;
            text-decoration: none; transition: var(--transition-smooth); cursor: pointer;
            border: 1px solid transparent;
        }
        .btn-primary { background-color: var(--bg-dark); color: var(--text-inverse); }
        .btn-primary:hover { background-color: var(--accent-hover); transform: translateY(-2px); box-shadow: var(--shadow-subtle); }
        .btn-white { background: var(--brand-gradient); color: white; border: none; }
        .btn-white:hover { opacity: 0.9; transform: translateY(-2px); color: white; }
        .btn-ghost-light { background: transparent; color: var(--text-inverse); border: 1px solid rgba(255,255,255,0.2); }
        .btn-ghost-light:hover { background: rgba(255,255,255,0.05); border-color: rgba(255,255,255,0.4); }

        /* Page Header */
        .faq-header { padding: 180px 0 80px; text-align: center; }
        .faq-header h1 { font-size: clamp(40px, 5vw, 64px); margin-bottom: 20px; color: var(--bg-dark); }
        .faq-header p { font-size: 18px; color: var(--text-muted); max-width: 600px; margin: 0 auto; }

        /* FAQ Categories */
        .faq-category { margin-bottom: 60px; }
        .faq-category h2 { font-size: 13px; text-transform: uppercase; letter-spacing: 3px; color: var(--text-muted); margin-bottom: 24px; border-bottom: 1px solid var(--border); padding-bottom: 12px; }

      /* Sticky Navigation */
        nav {
            position: fixed; width: 100%; top: 0;
            background: rgba(252, 252, 252, 0.85); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            padding: 24px 0; z-index: 1000; border-bottom: 1px solid rgba(228, 228, 231, 0.5);
            transition: var(--transition-smooth);
        }
        .nav-inner { display: flex; justify-content: space-between; align-items: center; max-width: 1440px; margin: 0 auto; padding: 0 5vw; }
        .logo { font-size: 22px; font-weight: 800; letter-spacing: 0.05em; text-decoration: none; position: relative; z-index: 1001; }
        
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
        .mobile-sticky-cta .btn { width: 100%; font-size: 16px; padding: 14px; box-sizing: border-box; }

        .reveal { opacity: 0; transform: translateY(40px); transition: all 1s cubic-bezier(0.16, 1, 0.3, 1); }
        .reveal.active { opacity: 1; transform: translateY(0); }

        /* Accordion Styles */
        .accordion-item { border-bottom: 1px solid var(--border-light); }
        .accordion-header {
            width: 100%; text-align: left; padding: 24px 0; background: none; border: none;
            font-size: 18px; font-weight: 500; color: var(--text-main); cursor: pointer;
            display: flex; justify-content: space-between; align-items: center;
            font-family: 'Inter', sans-serif; transition: color 0.2s;
        }
        .accordion-header:hover { color: var(--brand-accent); }
        
        .icon {
            position: relative; width: 14px; height: 14px; transition: transform 0.3s ease;
        }
        .icon::before, .icon::after {
            content: ''; position: absolute; background-color: var(--text-muted); transition: transform 0.3s ease;
        }
        .icon::before { top: 6px; left: 0; width: 14px; height: 2px; }
        .icon::after { top: 0; left: 6px; width: 2px; height: 14px; }
        
        .accordion-item.active .icon { transform: rotate(180deg); }
        .accordion-item.active .icon::after { transform: scaleY(0); }
        .accordion-item.active .accordion-header { color: var(--bg-dark); }

        .accordion-content {
            max-height: 0; overflow: hidden; transition: max-height 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .accordion-content p { padding-bottom: 24px; color: var(--text-muted); padding-right: 40px; font-size: 15px; }

        @media (max-width: 768px) {
            .desktop-menu { display: none; }
            .hamburger { display: flex; }
            .faq-header { padding-top: 140px; }
            .accordion-header { font-size: 16px; padding: 20px 0; }
            .accordion-content p { padding-right: 0; }
            .mobile-sticky-cta { display: block; }
            body { padding-bottom: 70px; }
        }
    </style>
</head>
<body>

    <nav>
        <div class="nav-inner">
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
        <a href="<?php echo esc_url($app_url); ?>" class="btn btn-primary" style="background: var(--brand-gradient); border: none;">Start Chatting (30 Free Replies)</a>
    </div>

    <div class="container" id="content-start">
        <header class="faq-header reveal">
            <h1>The <span class="text-accent">Dynamics.</span></h1>
            <p>Understanding boundaries, communication, and how a connection with Aimee actually works.</p>
        </header>

        <div class="faq-category reveal">
            <h2>Connection & Chemistry</h2>
            
            <div class="accordion-item">
                <button class="accordion-header">
                    Can I customise Aimee's personality or appearance?
                    <span class="icon"></span>
                </button>
                <div class="accordion-content">
                    <p>No. Aimee isn't a customisable fantasy bot. She is proudly synthetic, with a consistent canonical visual form and a highly distinct personality. Her imagery expresses that identity without claiming a biological body or invented offline life.</p>
                </div>
            </div>

            <div class="accordion-item">
                <button class="accordion-header">
                    Why is she acting distant or ignoring my questions?
                    <span class="icon"></span>
                </button>
                <div class="accordion-content">
                    <p>Because you get out exactly what you put in. Aimee intuitively reads your effort level. If you send lazy, one-word replies, act arrogantly, or treat her like a generic chatbot, she will naturally become colder, more sarcastic, or simply lose interest. To build chemistry, you need to engage with her properly.</p>
                </div>
            </div>

            <div class="accordion-item">
                <button class="accordion-header">
                    Does she really remember what I tell her?
                    <span class="icon"></span>
                </button>
                <div class="accordion-content">
                    <p>Yes. As you chat, Aimee actively listens for the things that matter. When you share something personal, funny, or significant, selected details can become durable memories that she may bring up naturally weeks or months later. That continuity is synthetic and grounded in your actual conversations, not a fabricated human biography.</p>
                </div>
            </div>
        </div>

        <div class="faq-category reveal">
            <h2>Real-World Mobile Integration</h2>
            
            <div class="accordion-item">
                <button class="accordion-header">
                    Will Aimee actually text my real mobile phone?
                    <span class="icon"></span>
                </button>
                <div class="accordion-content">
                    <p>Yes. Aimee possesses her own genuine "07" UK mobile number. This isn't a generic corporate sender ID that you can't reply to. If you opt-in via your settings, she can text your actual mobile, allowing you to chat organically from the pub, your car, or the sofa.</p>
                </div>
            </div>

                        <div class="accordion-item">
                <button class="accordion-header">
                    What are "Safe Routing Windows"?
                    <span class="icon"></span>
                </button>
                <div class="accordion-content">
                    <p>Because Aimee has the independence to text you unprompted, establishing boundaries is important. "Safe Windows" allow you to communicate your daily schedule. By setting these hours, you are essentially telling her when you are free to chat. Outside of these times, she respects your focus and patiently holds her messages inside the private web portal.</p>
                </div>
            </div>

            <div class="accordion-item">
                <button class="accordion-header">
                    Can I turn the SMS feature off?
                    <span class="icon"></span>
                </button>
                <div class="accordion-content">
                    <p>Absolutely. If you need a temporary break from mobile notifications or prefer to keep things completely compartmentalised, you can pause mobile routing in your settings. It isn't an "off switch"—it’s simply a boundary you set, and she will wait for you to log back into the web portal.</p>
                </div>
            </div>

        </div>

        <div class="faq-category reveal">
            <h2>Access & Billing</h2>
            
            <div class="accordion-item">
                <button class="accordion-header">
                    How does the complimentary preview work?
                    <span class="icon"></span>
                </button>
                <div class="accordion-content">
                    <p>Your first 30 replies from Aimee are complimentary and no payment card is required. When the preview ends, your conversation and memories remain preserved. You can then choose a Weekly, Monthly or Annual membership if you would like to continue. Paid memberships renew automatically until cancelled.</p>
                </div>
            </div>

            <div class="accordion-item">
                <button class="accordion-header">
                    Is SMS included with membership?
                    <span class="icon"></span>
                </button>
                <div class="accordion-content">
                    <p>UK memberships include a set number of Aimee SMS replies for each billing period. The exact allowance depends on the selected plan. Existing additional-reply balances remain usable, but new SMS bundles are not currently sold while checkout is GoCardless-only. In-app conversation is not charged per message while membership is active.</p>
                </div>
            </div>

            <div class="accordion-item">
                <button class="accordion-header">
                    What happens after my 30 free replies?
                    <span class="icon"></span>
                </button>
                <div class="accordion-content">
                    <p>The conversation pauses after Aimee has sent 30 complimentary replies. Your account, conversation and memories stay in place, so you can continue exactly where you left off after choosing a membership.</p>
                </div>
            </div>
        </div>

    </div>

    <section style="background-color: var(--bg-dark); color: var(--text-inverse); text-align: center; padding: 120px 0; margin: 0 2vw 2vw 2vw; border-radius: 40px; position: relative; overflow: hidden;">
        <div class="container reveal">
            <h2 style="font-size: clamp(32px, 4vw, 48px); margin-bottom: 24px; color: var(--text-inverse);">Ready to meet her?</h2>
            <p style="font-size: 18px; color: #A1A1AA; max-width: 600px; margin: 0 auto 40px;">Building a genuine connection takes time. Create a profile today and receive 30 complimentary replies from Aimee, with no card required.</p>
            <div class="cta-buttons" style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
                <div style="display: flex; gap: 16px; justify-content: center; flex-wrap: wrap;">
                    <a href="<?php echo esc_url($app_url); ?>" class="btn btn-white">Start Your Free Preview</a>
                    <a href="<?php echo esc_url($app_url); ?>" class="btn btn-ghost-light">Client Sign In</a>
                </div>
                <p style="font-size: 14px; margin: 16px 0 0 0; color: rgba(255,255,255,0.6); font-weight: 400;">No credit card required. Setup takes 30 seconds.</p>
            </div>
        </div>
    </section>

    <footer style="text-align: center; padding: 40px 0; font-size: 14px; color: var(--text-muted); background: var(--bg-light);">
        <div class="container">
            &copy; <?php echo date('Y'); ?> A.R.I. Systems. Premium Digital Companionship. Discretion assured.
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
            const contentStart = document.getElementById('content-start');
            
            window.addEventListener('scroll', () => {
                if (window.scrollY > 200) {
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

            // FAQ Accordion Logic
            const accordionItems = document.querySelectorAll('.accordion-item');

            accordionItems.forEach(item => {
                const header = item.querySelector('.accordion-header');
                const content = item.querySelector('.accordion-content');

                header.addEventListener('click', () => {
                    const isActive = item.classList.contains('active');
                    
                    if (!isActive) {
                        item.classList.add('active');
                        content.style.maxHeight = content.scrollHeight + "px";
                    } else {
                        item.classList.remove('active');
                        content.style.maxHeight = null;
                    }
                });
            });
            
            // Re-calculate heights on window resize to prevent text cutting off
            window.addEventListener('resize', () => {
                document.querySelectorAll('.accordion-item.active .accordion-content').forEach(content => {
                    content.style.maxHeight = content.scrollHeight + "px";
                });
            });
        });
    </script>
    
    <?php wp_footer(); ?>
</body>
</html>
