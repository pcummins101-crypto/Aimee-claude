<?php
/*
Template Name: Aimee Consumer Privacy & Safeguarding
Description: Consolidated searchable public privacy, AI transparency, human review, safeguarding, rights and complaints page for Aimee.
*/

$document_status = apply_filters('aimee_policy_document_status', 'Draft v1.1');
$document_issue_date = apply_filters('aimee_policy_issue_date', '22 July 2026');
$document_review_date = apply_filters('aimee_policy_review_date', '22 January 2027');
$aimee_market = isset($aimee_market) ? $aimee_market : 'uk';
$c = aimee_global_market_config($aimee_market);
$canonical_url = aimee_global_route('privacy',$aimee_market);
$home_url = aimee_global_route('home',$aimee_market);
$app_url = aimee_global_route('chat',$aimee_market);
$privacy_email = apply_filters('aimee_privacy_contact_email', 'privacy@engramintelligence.com');
$form_message = '';
$form_error = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aimee_privacy_form'])) {
    $nonce_ok = isset($_POST['aimee_privacy_nonce']) && wp_verify_nonce(
        sanitize_text_field(wp_unslash($_POST['aimee_privacy_nonce'])),
        'aimee_privacy_contact'
    );
    $honeypot = isset($_POST['company_website']) ? trim((string) wp_unslash($_POST['company_website'])) : '';
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
    $rate_key = 'aimee_privacy_form_' . md5($ip);
    $rate_count = (int) get_transient($rate_key);

    if (!$nonce_ok || $honeypot !== '') {
        $form_error = true;
        $form_message = 'We could not verify that request. Please refresh and try again.';
    } elseif ($rate_count >= 5) {
        $form_error = true;
        $form_message = 'Too many requests have been sent from this connection. Please try later or email us directly.';
    } else {
        set_transient($rate_key, $rate_count + 1, HOUR_IN_SECONDS);
        $type = isset($_POST['request_type']) ? sanitize_text_field(wp_unslash($_POST['request_type'])) : '';
        $name = isset($_POST['full_name']) ? sanitize_text_field(wp_unslash($_POST['full_name'])) : '';
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $account = isset($_POST['account_reference']) ? sanitize_text_field(wp_unslash($_POST['account_reference'])) : '';
        $details = isset($_POST['details']) ? sanitize_textarea_field(wp_unslash($_POST['details'])) : '';
        $allowed = ['privacy_complaint', 'rights_request', 'safeguarding_concern', 'privacy_question'];

        if (!in_array($type, $allowed, true) || !$name || !is_email($email) || mb_strlen($details) < 20) {
            $form_error = true;
            $form_message = 'Please complete your name, a valid email address, the request type and at least 20 characters of detail.';
        } else {
            $reference = 'AIM-' . gmdate('Ymd-His') . '-' . strtoupper(wp_generate_password(5, false, false));
            $subject = sprintf('[%s] Aimee privacy/safeguarding request: %s', $reference, $type);
            $body = "Reference: {$reference}
Type: {$type}
Name: {$name}
Email: {$email}
Account reference: {$account}
Submitted: " . current_time('mysql') . "

Details:
{$details}
";
            $sent = wp_mail($privacy_email, $subject, $body, ['Reply-To: ' . $name . ' <' . $email . '>']);

            if ($sent) {
                wp_mail(
                    $email,
                    'We have received your Aimee request (' . $reference . ')',
                    "Hello {$name},

We have received your request. Your reference is {$reference}.

Please do not email identity documents unless we ask for them through an appropriate route.

Engram Intelligence
An Ampera EV Ltd brand"
                );
                $form_message = 'Thank you. Your request has been sent. Reference: ' . $reference;
            } else {
                $form_error = true;
                $form_message = 'The message could not be sent. Please contact ' . $privacy_email . ' directly.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Privacy, AI &amp; Safeguarding | Aimee</title>
<meta name="description" content="How Aimee uses personal information, AI profiling, adult-content routing, human review, safeguarding, retention, data rights and complaints.">
<meta name="robots" content="index,follow,max-image-preview:large">
<link rel="canonical" href="<?php echo esc_url($canonical_url); ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<?php wp_head(); ?>
<style>
:root{--ink:#1f2028;--muted:#656772;--paper:#f7f7f9;--white:#fff;--line:#e5e6eb;--rose:#b4235a;--rose-dark:#83143e;--rose-soft:#fff0f5;--night:#17181e;--green:#146647;--green-soft:#ebf8f2;--amber:#8b5600;--amber-soft:#fff7e8;--blue:#285ea8;--blue-dark:#183e75;--blue-soft:#edf4ff;--shadow:0 24px 80px rgba(31,32,40,.09);--radius:24px}
*{box-sizing:border-box}html{scroll-behavior:smooth;scroll-padding-top:92px}body{margin:0;background:var(--paper);color:var(--ink);font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.68;-webkit-font-smoothing:antialiased}a{color:var(--rose-dark);text-underline-offset:3px}.internal a{color:var(--blue-dark)}button,input,select,textarea{font:inherit}.wrap{width:min(1240px,calc(100% - 36px));margin:auto}.topbar{position:sticky;top:0;z-index:100;background:rgba(247,247,249,.92);backdrop-filter:blur(18px);border-bottom:1px solid rgba(229,230,235,.9)}.nav{min-height:70px;display:flex;align-items:center;justify-content:space-between;gap:18px}.brand{font-size:25px;font-weight:900;letter-spacing:-.05em;color:var(--rose);text-decoration:none}.internal .brand{color:var(--blue-dark)}.nav-right{display:flex;align-items:center;gap:16px}.nav-right a{font-size:13px;text-decoration:none;color:#51535e}.nav-right .button{background:var(--night);color:#fff;padding:9px 16px;border-radius:999px;font-weight:750}.hero{padding:76px 0 52px;background:radial-gradient(circle at 84% 8%,#fbdbe8 0,transparent 34%),linear-gradient(180deg,#fff 0,#fafafd 100%)}.internal .hero{background:radial-gradient(circle at 84% 8%,#dfeaff 0,transparent 36%),linear-gradient(180deg,#fff 0,#f8faff 100%)}.eyebrow{display:inline-flex;align-items:center;gap:8px;padding:7px 11px;border-radius:999px;background:var(--rose-soft);color:var(--rose-dark);font-size:11px;font-weight:850;text-transform:uppercase;letter-spacing:.09em}.internal .eyebrow{background:var(--blue-soft);color:var(--blue-dark)}.hero h1{max-width:980px;font-size:clamp(40px,6vw,72px);line-height:1.01;letter-spacing:-.06em;margin:20px 0 17px}.hero p{max-width:850px;font-size:18px;color:var(--muted);margin:0}.meta{display:flex;flex-wrap:wrap;gap:8px;margin-top:23px}.chip{display:inline-flex;align-items:center;padding:7px 11px;border-radius:999px;border:1px solid var(--line);background:#fff;font-size:12px;color:#5c5e68}.chip.draft{border-color:#efbed0;background:var(--rose-soft);color:var(--rose-dark);font-weight:800}.internal .chip.draft{border-color:#c8d8f4;background:var(--blue-soft);color:var(--blue-dark)}.notice{margin-top:24px;border:1px solid #efc2d1;background:#fff5f8;color:#71304a;padding:15px 17px;border-radius:17px;font-size:13px}.internal .notice{border-color:#c8d7f2;background:#f3f7ff;color:#294f88}.workspace{display:grid;grid-template-columns:300px minmax(0,1fr);gap:38px;padding:46px 0 90px}.sidebar{position:sticky;top:91px;align-self:start;max-height:calc(100vh - 112px);overflow:auto;padding-right:4px}.searchbox,.toc{background:#fff;border:1px solid var(--line);border-radius:20px;box-shadow:0 10px 34px rgba(31,32,40,.045)}.searchbox{padding:14px;margin-bottom:13px}.search-label{display:block;font-size:11px;font-weight:850;letter-spacing:.08em;text-transform:uppercase;color:#737580;margin:0 0 8px}.search-row{display:flex;gap:7px}.search-row input{min-width:0;width:100%;border:1px solid var(--line);border-radius:12px;padding:10px 11px;background:#fafafd;color:var(--ink)}.search-row button{border:0;border-radius:12px;padding:0 12px;background:var(--night);color:#fff;cursor:pointer;font-weight:750}.search-status{font-size:11px;color:#767884;margin:8px 2px 0}.toc{padding:14px}.toc-title{display:block;padding:4px 8px 9px;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#737580;font-weight:850}.toc a{display:block;padding:8px 9px;border-radius:10px;text-decoration:none;color:#555762;font-size:12px;line-height:1.35}.toc a:hover,.toc a.active{background:var(--rose-soft);color:var(--rose-dark)}.internal .toc a:hover,.internal .toc a.active{background:var(--blue-soft);color:var(--blue-dark)}.sidebar-note{margin-top:13px;border-radius:17px;padding:14px;background:var(--night);color:#d8d9df;font-size:11px}.toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap}.toolbar-group{display:flex;gap:8px;flex-wrap:wrap}.toolbutton{border:1px solid var(--line);background:#fff;border-radius:999px;padding:9px 13px;cursor:pointer;font-weight:750;color:#454751;font-size:12px}.toolbutton:hover{border-color:#cfd1d9}.document-stack{display:grid;gap:16px}.policy-document{background:#fff;border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden}.policy-document[hidden]{display:none}.document-summary{list-style:none;cursor:pointer;padding:24px 28px;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:20px;align-items:center}.document-summary::-webkit-details-marker{display:none}.document-summary:hover{background:#fffafd}.internal .document-summary:hover{background:#f8faff}.document-kicker{display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:10px;font-weight:850;letter-spacing:.08em;text-transform:uppercase;color:#878994;margin-bottom:5px}.document-title{display:block;font-size:clamp(22px,3vw,32px);line-height:1.16;letter-spacing:-.035em;font-weight:850}.document-description{display:block;color:var(--muted);font-size:13px;margin-top:7px;max-width:820px}.chevron{width:38px;height:38px;border:1px solid var(--line);border-radius:50%;display:grid;place-items:center;transition:.2s ease;background:#fff}.chevron:before{content:"+";font-size:22px;line-height:1}.policy-document[open] .chevron{transform:rotate(45deg);background:var(--rose-soft);border-color:#f0bfd0}.internal .policy-document[open] .chevron{background:var(--blue-soft);border-color:#cbd9f2}.document-body{border-top:1px solid var(--line);padding:clamp(23px,4vw,48px)}.document-body h2{font-size:clamp(24px,3vw,37px);line-height:1.17;letter-spacing:-.04em;margin:42px 0 14px}.document-body h2:first-child{margin-top:0}.document-body h3{font-size:20px;line-height:1.3;margin:31px 0 10px}.document-body h4{font-size:16px;margin:25px 0 8px}.document-body p{margin:0 0 15px}.document-body ul,.document-body ol{margin:12px 0 20px;padding-left:24px}.document-body li{margin:7px 0}.lead{font-size:18px;color:#444650}.callout{margin:20px 0;padding:18px 20px;border-radius:16px;border-left:4px solid var(--rose);background:var(--rose-soft)}.internal .callout{border-left-color:var(--blue);background:var(--blue-soft)}.callout.warning{border-left-color:var(--amber);background:var(--amber-soft)}.callout.safe{border-left-color:var(--green);background:var(--green-soft)}.policy-note{margin:20px 0;padding:17px 19px;border:1px solid #efc3d2;background:#fff7fa;border-radius:17px}.internal .policy-note{border-color:#cfdaf0;background:#f6f8ff}.small{font-size:12px;color:#71737e}.table-wrap{width:100%;overflow:auto;margin:20px 0 28px;border:1px solid var(--line);border-radius:17px;background:#fff}.policy-table{border-collapse:collapse;width:100%;min-width:700px;font-size:13px}.policy-table th,.policy-table td{padding:12px 13px;border-bottom:1px solid var(--line);border-right:1px solid var(--line);vertical-align:top;text-align:left}.policy-table th{background:#f5f5f8;color:#3f414b;font-weight:800}.internal .policy-table th{background:#f0f4fb}.policy-table tr:last-child td{border-bottom:0}.policy-table th:last-child,.policy-table td:last-child{border-right:0}.source-list{padding-left:0!important;list-style:none}.source-list li{padding:11px 0;border-bottom:1px solid var(--line);font-size:13px;overflow-wrap:anywhere}.contact-panel{background:var(--night);color:#fff;border-radius:24px;padding:clamp(22px,4vw,32px);margin-top:24px;scroll-margin-top:100px}.contact-panel h2{color:#fff}.contact-panel p{color:#d6d7dd}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:13px}.field{display:flex;flex-direction:column;gap:6px}.field.full{grid-column:1/-1}.field label{font-size:12px;font-weight:750}.field input,.field select,.field textarea{width:100%;border:1px solid #50515b;background:#24252b;color:#fff;border-radius:12px;padding:12px}.field textarea{min-height:150px;resize:vertical}.submit{border:0;border-radius:999px;padding:13px 19px;background:#fda4af;color:#3d0b1d;font-weight:850;cursor:pointer}.form-message{padding:13px;border-radius:12px;background:#173d31;margin-bottom:14px}.form-message.error{background:#4b1f2c}.hp{position:absolute!important;left:-9999px!important}.empty-state{display:none;background:#fff;border:1px dashed #cfd1d8;border-radius:22px;padding:40px;text-align:center;color:var(--muted)}.empty-state.visible{display:block}.footer{border-top:1px solid var(--line);background:#fff;padding:27px 0}.footer-row{display:flex;align-items:center;justify-content:space-between;gap:20px}.footer strong{display:block}.footer span{display:block;font-size:12px;color:#71737e}.footer-links{display:flex;flex-wrap:wrap;gap:15px;font-size:12px}.admin-badge{display:inline-flex;align-items:center;gap:7px;border-radius:999px;padding:7px 11px;background:#fff;border:1px solid #cbd7ee;color:#315789;font-size:11px;font-weight:800}
@media(max-width:980px){.workspace{grid-template-columns:1fr}.sidebar{position:static;max-height:none;padding:0}.toc{display:flex;gap:4px;overflow:auto}.toc-title,.sidebar-note{display:none}.toc a{white-space:nowrap}.searchbox{position:sticky;top:80px;z-index:20}.nav-right a:not(.button){display:none}}
@media(max-width:650px){.wrap{width:min(100% - 22px,1240px)}.hero{padding:54px 0 37px}.hero h1{font-size:42px}.hero p{font-size:16px}.workspace{padding-top:26px}.document-summary{padding:20px 18px;grid-template-columns:minmax(0,1fr) auto}.document-body{padding:24px 18px}.form-grid{grid-template-columns:1fr}.field.full{grid-column:auto}.footer-row{align-items:flex-start;flex-direction:column}.policy-table{min-width:620px}.chevron{width:34px;height:34px}}
@media print{.topbar,.sidebar,.toolbar,.footer,.contact-panel{display:none!important}.hero{padding:15px 0;background:#fff}.workspace{display:block;padding:0}.policy-document{display:block!important;border:0;box-shadow:none;break-before:page}.policy-document:first-child{break-before:auto}.document-summary{padding:18px 0}.chevron{display:none}.document-body{display:block!important;border-top:1px solid #ddd;padding:18px 0}.wrap{width:100%}body{background:#fff}.table-wrap{overflow:visible}.policy-table{min-width:0;font-size:9px}}
</style>
</head>
<body <?php body_class('aimee-policy-page public'); ?>>
<?php wp_body_open(); ?>
<header class="topbar"><div class="wrap nav"><a class="brand" href="<?php echo esc_url($home_url); ?>">Aimee</a><nav class="nav-right"><a href="#privacy-notice">Privacy</a><a href="#safeguarding">Safeguarding</a><a href="#rights-complaints">Your rights</a><a href="https://engramintelligence.com" target="_blank" rel="noopener">Engram Intelligence</a><a class="button" href="<?php echo esc_url($app_url); ?>">Open Aimee</a></nav></div></header>
<section class="hero"><div class="wrap"><span class="eyebrow">Privacy, AI &amp; safeguarding</span><h1>Everything important, in one clear place.</h1><p>How Aimee remembers conversations, uses artificial intelligence, protects private information, permits carefully governed human review and responds to safeguarding concerns.</p><div class="meta"><span class="chip draft"><?php echo esc_html($document_status); ?></span><span class="chip">Issued <?php echo esc_html($document_issue_date); ?></span><span class="chip">Review <?php echo esc_html($document_review_date); ?></span><span class="chip">Controller: Ampera EV Ltd</span></div><div class="notice"><strong>Draft v1.1.</strong> Business ownership, privacy contact and ICO registration details have been completed. Supplier contracts and transfer checks, consent controls, final age-assurance implementation and specialist legal review remain conditions before unconditional adoption.</div></div></section>
<div class="wrap workspace">
<aside class="sidebar"><div class="searchbox"><label class="search-label" for="policy-search">Search this page</label><div class="search-row"><input id="policy-search" type="search" placeholder="Try “voice notes”, “human review” or “delete”" autocomplete="off"><button id="policy-clear" type="button">Clear</button></div><div class="search-status" id="policy-search-status" aria-live="polite"></div></div><nav class="toc"><span class="toc-title">On this page</span><a href="#privacy-notice">Privacy notice</a>
<a href="#ai-human-review">AI, profiling and human review</a>
<a href="#safeguarding">Safeguarding and age assurance</a>
<a href="#rights-complaints">Your rights, complaints and contact</a>
<a href="#official-sources">Official sources</a></nav><div class="sidebar-note">For immediate danger, call <?php echo esc_html($c['emergency']); ?>. Aimee is not an emergency service and this page is not monitored continuously.</div></aside>
<main><div class="toolbar"><div class="toolbar-group"><button class="toolbutton" id="expand-all" type="button">Expand all</button><button class="toolbutton" id="collapse-all" type="button">Collapse all</button></div><button class="toolbutton" id="print-page" type="button">Print / save PDF</button></div>
<div class="callout">“I remember what you tell me because continuity is part of what makes our conversations feel real. That does not mean people are sitting behind the screen reading along. Human access is restricted, usually pseudonymous, and permitted only for specific quality, support, safeguarding or legal reasons.”</div>
<div class="document-stack"><details class="policy-document" id="privacy-notice" open>
<summary class="document-summary"><span><span class="document-kicker"><span>Public policy document</span><span>•</span><span><?php echo esc_html($document_status); ?></span></span><span class="document-title">Privacy notice</span><span class="document-description">Who controls your information, what we collect, why we use it, providers, transfers, security and retention.</span></span><span class="chevron" aria-hidden="true"></span></summary>
<div class="document-body"><h2>1. Who is responsible for your information</h2>
<p>Ampera EV Ltd, trading through its Engram Intelligence brand is the data controller for Aimee. Ampera EV Ltd is registered in England and Wales under company number 16439998. Registered office: 34–40 Witham, Witham, Hull, England, HU9 1BY. Privacy contact: <a href="mailto:<?php echo esc_attr($privacy_email); ?>"><?php echo esc_html($privacy_email); ?></a>. ICO registration: Z3572599.</p><h2>3. Information we collect</h2>
<p>We may collect account and contact information, age or age-assurance status, profile details, text conversations, memories and preferences, images, voice notes and transcripts, optional SMS content, subscription and payment references, device/security logs, relationship indicators, routing metadata, safety flags, support requests, complaints and audit records. Conversations may contain sensitive information you choose to share, including health or sexual information, and information about other people.</p><h2>4. Why we use it</h2>
<p>We use information to provide and personalise Aimee, maintain conversation continuity, deliver voice/SMS/image features, manage subscriptions, protect accounts, apply boundaries and safeguards, investigate complaints, conduct tightly controlled quality reviews, comply with law and improve reliability.</p><h2>5. Our lawful bases</h2>
<p>We normally rely on contract to provide the service, legal obligation for statutory duties and records, and legitimate interests for security, platform integrity and proportionate quality assurance. Where special-category information is processed for the service or optional quality review, we ask for explicit consent where required. Safeguarding, prevention of unlawful acts, vital interests and legal claims may rely on separate legal conditions and do not always depend on consent.</p><h2>9. Service providers and transfers</h2>
<p>Depending on the feature, information may be processed by contracted providers supporting hosting, email, analytics, conversational AI, adult-model routing, voice transcription, speech generation, SMS, payment processing, live information and age assurance. Current providers include WPX Hosting for website hosting and email, Google Analytics for website analytics, Anthropic, OpenRouter and its selected model provider, Deepgram, ElevenLabs, FireText, and GoCardless for all currently available new paid checkout. Stripe is retained only to manage, reconcile or close memberships and purchases created before the GoCardless-only cutover and to retain required financial records; no new Stripe checkout is offered. Didit is the intended age-assurance provider for adults-only functionality. We use contracts and, where required, UK transfer safeguards. The exact provider list is maintained in our internal processing register and this notice will be updated after material changes.</p><h2>10. How long we keep information</h2>
<p>We keep account conversations and related media while the account is active so Aimee can maintain continuity. After verified account closure or a valid erasure request, live conversation data is normally deleted within 30 days and expires from backups within 90 days, unless a documented legal, complaint or safeguarding hold applies. Financial records are normally retained for six years. Safety, complaint and audit records use shorter or longer periods according to seriousness and legal need.</p><h2>13. Security and breaches</h2>
<p>We use access controls, pseudonymisation, authenticated media access, audit logging and restricted senior review. No system is completely secure. We maintain a breach-response process and will notify the ICO and affected people where the law requires it.</p><h2>15. Changes</h2>
<p>We will update this notice when the service, providers, review arrangements or legal requirements materially change. The effective date and prior versions should be retained.</p></div>
</details>
<details class="policy-document" id="ai-human-review">
<summary class="document-summary"><span><span class="document-kicker"><span>Public policy document</span><span>•</span><span><?php echo esc_html($document_status); ?></span></span><span class="document-title">AI, profiling and human review</span><span class="document-description">How Aimee adapts conversations, routes eligible adult content and permits tightly governed human review.</span></span><span class="chevron" aria-hidden="true"></span></summary>
<div class="document-body"><h2>2. Who Aimee is</h2>
<p>Aimee is an artificial-intelligence companion. She is not a human being, medical professional, therapist, emergency service, lawyer or law-enforcement service. AI replies and safety classifications can be inaccurate.</p><h2>6. AI, profiling and adult-content routing</h2>
<p>Aimee analyses conversation context and maintains relationship indicators to adapt her replies. These indicators are not used to decide employment, credit, insurance or other legal entitlements. Consensual adult intimacy may be routed to a specialist model. Adult functionality is for verified adults only and must be protected by highly effective age assurance; a simple age declaration is not treated as sufficient.</p><h2>7. Human review</h2>
<p>Full chat logs are available only to approved Senior Safeguarding reviewers or HQ Directors. Reviews are not continuous and are not a routine word-for-word assessment of every account. They are sampled or triggered by a complaint, support issue, previous safeguarding concern, higher-than-average adult specialist use, higher-than-average user imagery, or another documented safety/quality reason. Users are pseudonymised by default. A reviewer must start a reasoned, time-limited and audited session. Name, email and phone are hidden unless a separate audited request explains why identity is genuinely necessary. Optional sensitive quality review is subject to the consent controls described when you join or in settings. Safeguarding and legal review may still occur where lawful and necessary.</p><div class="callout"><strong>Important:</strong> A relationship indicator, adult-content route or safeguarding flag is a system signal, not proof of a user’s character, intent or unlawful conduct.</div></div>
</details>
<details class="policy-document" id="safeguarding">
<summary class="document-summary"><span><span class="document-kicker"><span>Public policy document</span><span>•</span><span><?php echo esc_html($document_status); ?></span></span><span class="document-title">Safeguarding and age assurance</span><span class="document-description">How safety indicators, escalation, urgent help and adults-only access are handled.</span></span><span class="chevron" aria-hidden="true"></span></summary>
<div class="document-body"><h2>8. Safeguarding</h2>
<p>Aimee uses automated and human safeguards for concerns such as coercion, non-consent, minors, exploitation, grooming, serious violence, self-harm, stalking, doxxing and potentially unlawful activity. A flag is an indicator, not proof. Where there is a serious and credible risk, authorised staff may review relevant information and may share the minimum necessary information with emergency services, law enforcement or another appropriate safeguarding body where lawful. Aimee cannot guarantee that a risk will be detected or that emergency help can be sent.</p><h2>14. Children and age assurance</h2>
<p>Aimee is an adults-only service. Adult/pornographic features must not be made available on the basis of self-declared age alone. Didit is our intended age-assurance provider. Adults-only functionality must remain unavailable until the selected verification method has been contracted, configured, tested and approved as appropriate for the feature. Children must not use Aimee or submit personal information.</p><h2>Contact and urgent help</h2>
<p>Privacy, rights or complaints: <a href="mailto:<?php echo esc_attr($privacy_email); ?>"><?php echo esc_html($privacy_email); ?></a>. Post: 34–40 Witham, Witham, Hull, England, HU9 1BY. For immediate danger call <?php echo esc_html($c['emergency']); ?>. <?php echo esc_html($c['crisis']); ?>. Aimee is not monitored continuously and is not an emergency channel.</p><div class="callout warning"><strong>Emergency:</strong> If someone is in immediate danger, call <?php echo esc_html($c['emergency']); ?>. Aimee is not continuously monitored and is not an emergency channel.</div></div>
</details>
<details class="policy-document" id="rights-complaints">
<summary class="document-summary"><span><span class="document-kicker"><span>Public policy document</span><span>•</span><span><?php echo esc_html($document_status); ?></span></span><span class="document-title">Your rights, complaints and contact</span><span class="document-description">How to access, correct or delete information, withdraw consent, complain or report a safeguarding concern.</span></span><span class="chevron" aria-hidden="true"></span></summary>
<div class="document-body"><?php if ($aimee_market === 'us'): ?><div class="policy-note"><strong>United States market addendum.</strong> Aimee is operated by a UK company and personal information may be processed in the United Kingdom and by contracted providers in other countries. Depending on the state in which you live and whether its law applies to Aimee, you may have additional rights to know, access, correct, delete or obtain a copy of personal information, and to opt out of certain sale, sharing or targeted-advertising practices. Aimee does not describe conversational data as being sold to advertisers. Submit a request through the form below. We will verify and assess it under the law that applies to your circumstances. This draft requires US privacy counsel review before launch in individual states.</div><?php endif; ?><h2>11. Your choices and rights</h2>
<p>You may have rights to access, correct, erase, restrict, object to or receive certain personal information, and to withdraw consent. You can also complain about how we use your information. Some rights depend on the lawful basis and circumstances. We may need to verify your identity, but we will not ask for more evidence than is reasonably necessary.</p><h2>12. Data protection complaints</h2>
<p>Use the electronic form on the website or contact the privacy address above. We will acknowledge a formal data-protection complaint within 30 days and respond without undue delay. You may also complain to the Information Commissioner’s Office.</p><h2>Contact and urgent help</h2>
<p>Privacy, rights or complaints: <a href="mailto:<?php echo esc_attr($privacy_email); ?>"><?php echo esc_html($privacy_email); ?></a>. Post: 34–40 Witham, Witham, Hull, England, HU9 1BY. For immediate danger call <?php echo esc_html($c['emergency']); ?>. <?php echo esc_html($c['crisis']); ?>. Aimee is not monitored continuously and is not an emergency channel.</p><div class="contact-panel" id="contact"><h2>Contact the privacy and safeguarding team</h2><p>Use this form to exercise a right, make a data-protection complaint, report a safeguarding concern or ask a privacy question.</p><?php if ($form_message): ?><div class="form-message<?php echo $form_error ? ' error' : ''; ?>"><?php echo esc_html($form_message); ?></div><?php endif; ?><form method="post" action="#contact"><input type="hidden" name="aimee_privacy_form" value="1"><?php wp_nonce_field('aimee_privacy_contact','aimee_privacy_nonce'); ?><div class="hp" aria-hidden="true"><label>Company website<input type="text" name="company_website" tabindex="-1" autocomplete="off"></label></div><div class="form-grid"><div class="field"><label for="request_type">What do you need?</label><select id="request_type" name="request_type" required><option value="">Choose one</option><option value="privacy_complaint">Data protection complaint</option><option value="rights_request">Access, deletion or another right</option><option value="safeguarding_concern">Safeguarding concern</option><option value="privacy_question">Privacy question</option></select></div><div class="field"><label for="account_reference">Aimee username or account reference (optional)</label><input id="account_reference" name="account_reference" type="text" autocomplete="username"></div><div class="field"><label for="full_name">Your name</label><input id="full_name" name="full_name" type="text" required autocomplete="name"></div><div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required autocomplete="email"></div><div class="field full"><label for="details">Tell us what happened or what you need</label><textarea id="details" name="details" minlength="20" required></textarea><span class="small">Do not include passwords, identity documents or more sensitive information than necessary.</span></div><div class="field full"><button class="submit" type="submit">Send securely</button></div></div></form><p class="small">Messages are sent to <?php echo esc_html($privacy_email); ?>. If anyone is in immediate danger, call emergency services rather than waiting for this form.</p></div></div>
</details>
<details class="policy-document" id="official-sources">
<summary class="document-summary"><span><span class="document-kicker"><span>Public policy document</span><span>•</span><span><?php echo esc_html($document_status); ?></span></span><span class="document-title">Official sources</span><span class="document-description">The legislation and regulator guidance used to prepare this draft.</span></span><span class="chevron" aria-hidden="true"></span></summary>
<div class="document-body"><h2>Official source references</h2>
<p class="small">1. ICO, Guidance on AI and data protection: <a href="https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/artificial-intelligence/guidance-on-ai-and-data-protection/" target="_blank" rel="noopener noreferrer">https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/artificial-intelligence/guidance-on-ai-and-data-protection/</a></p>
<p class="small">2. ICO, When do we need to do a DPIA?: <a href="https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/accountability-and-governance/data-protection-impact-assessments-dpias/when-do-we-need-to-do-a-dpia/" target="_blank" rel="noopener noreferrer">https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/accountability-and-governance/data-protection-impact-assessments-dpias/when-do-we-need-to-do-a-dpia/</a></p>
<p class="small">3. ICO, Documentation and records of processing activities: <a href="https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/accountability-and-governance/documentation/" target="_blank" rel="noopener noreferrer">https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/accountability-and-governance/documentation/</a></p>
<p class="small">4. ICO, Special category data: <a href="https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/lawful-basis/a-guide-to-lawful-basis/special-category-data/" target="_blank" rel="noopener noreferrer">https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/lawful-basis/a-guide-to-lawful-basis/special-category-data/</a></p>
<p class="small">5. ICO, Appropriate policy document template: <a href="https://ico.org.uk/media2/migrated/2616286/appropriate-policy-document.docx" target="_blank" rel="noopener noreferrer">https://ico.org.uk/media2/migrated/2616286/appropriate-policy-document.docx</a></p>
<p class="small">6. ICO, Right to be informed / privacy information: <a href="https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/individual-rights/the-right-to-be-informed/" target="_blank" rel="noopener noreferrer">https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/individual-rights/the-right-to-be-informed/</a></p>
<p class="small">7. ICO, Data protection complaints procedure: <a href="https://ico.org.uk/for-organisations/how-to-deal-with-data-protection-complaints/" target="_blank" rel="noopener noreferrer">https://ico.org.uk/for-organisations/how-to-deal-with-data-protection-complaints/</a></p>
<p class="small">8. ICO, Personal data breaches: <a href="https://ico.org.uk/for-organisations/report-a-breach/personal-data-breach/" target="_blank" rel="noopener noreferrer">https://ico.org.uk/for-organisations/report-a-breach/personal-data-breach/</a></p>
<p class="small">9. ICO, International transfers: <a href="https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/international-transfers/" target="_blank" rel="noopener noreferrer">https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/international-transfers/</a></p>
<p class="small">10. Data Protection Act 2018, Schedule 1: <a href="https://www.legislation.gov.uk/ukpga/2018/12/schedule/1" target="_blank" rel="noopener noreferrer">https://www.legislation.gov.uk/ukpga/2018/12/schedule/1</a></p>
<p class="small">11. Data (Use and Access) Act 2025: <a href="https://www.legislation.gov.uk/ukpga/2025/18/contents" target="_blank" rel="noopener noreferrer">https://www.legislation.gov.uk/ukpga/2025/18/contents</a></p>
<p class="small">12. Ofcom, AI chatbots and online regulation: <a href="https://www.ofcom.org.uk/online-safety/illegal-and-harmful-content/ai-chatbots-and-online-regulation-what-you-need-to-know" target="_blank" rel="noopener noreferrer">https://www.ofcom.org.uk/online-safety/illegal-and-harmful-content/ai-chatbots-and-online-regulation-what-you-need-to-know</a></p>
<p class="small">13. Ofcom, Age checks to protect children online: <a href="https://www.ofcom.org.uk/online-safety/protecting-children/age-checks-to-protect-children-online" target="_blank" rel="noopener noreferrer">https://www.ofcom.org.uk/online-safety/protecting-children/age-checks-to-protect-children-online</a></p>
<p class="small">14. Companies House, Ampera EV Ltd: <a href="https://find-and-update.company-information.service.gov.uk/company/16439998" target="_blank" rel="noopener noreferrer">https://find-and-update.company-information.service.gov.uk/company/16439998</a></p>
<div class="policy-note">Source status: Official guidance and legislation checked for this draft on 22 July 2026. Re-check before final adoption because AI, data-protection and online-safety guidance is developing quickly.</div></div>
</details></div><div class="empty-state" id="policy-empty"><strong>No matching document found.</strong><br>Try a shorter or more general search term.</div></main>
</div>
<footer class="footer"><div class="wrap footer-row"><div><strong>Aimee</strong><span>A product of Engram Intelligence. Engram Intelligence is an Ampera EV Ltd brand.</span></div><div class="footer-links"><a href="#privacy-notice">Privacy notice</a><a href="#rights-complaints">Contact</a><a href="https://engramintelligence.com" target="_blank" rel="noopener">Engram Intelligence</a></div></div></footer>
<?php wp_footer(); ?>
<script>
(function(){
  'use strict';
  const search = document.getElementById('policy-search');
  const clear = document.getElementById('policy-clear');
  const status = document.getElementById('policy-search-status');
  const docs = Array.from(document.querySelectorAll('.policy-document'));
  const empty = document.getElementById('policy-empty');
  const tocLinks = Array.from(document.querySelectorAll('.toc a'));
  const normalise = value => (value || '').toLocaleLowerCase().replace(/\s+/g,' ').trim();
  docs.forEach(doc => { doc.dataset.searchText = normalise(doc.textContent); });

  function filterDocuments(){
    const term = normalise(search.value);
    let shown = 0;
    docs.forEach(doc => {
      const match = !term || doc.dataset.searchText.includes(term);
      doc.hidden = !match;
      if(match){
        shown++;
        if(term) doc.open = true;
      }
    });
    tocLinks.forEach(link => {
      const target = document.querySelector(link.getAttribute('href'));
      link.hidden = !!(target && target.hidden);
    });
    status.textContent = term ? (shown + (shown === 1 ? ' matching document' : ' matching documents')) : (docs.length + ' documents');
    empty.classList.toggle('visible', shown === 0);
    const url = new URL(window.location.href);
    if(term) url.searchParams.set('q', search.value.trim()); else url.searchParams.delete('q');
    window.history.replaceState({}, '', url.pathname + url.search + url.hash);
  }
  search.addEventListener('input', filterDocuments);
  clear.addEventListener('click', function(){ search.value=''; filterDocuments(); search.focus(); });
  document.getElementById('expand-all').addEventListener('click', function(){ docs.filter(d=>!d.hidden).forEach(d=>d.open=true); });
  document.getElementById('collapse-all').addEventListener('click', function(){ docs.filter(d=>!d.hidden).forEach(d=>d.open=false); });
  document.getElementById('print-page').addEventListener('click', function(){ window.print(); });

  tocLinks.forEach(link => link.addEventListener('click', function(){
    const target = document.querySelector(this.getAttribute('href'));
    if(target){ target.hidden=false; target.open=true; }
  }));

  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if(entry.isIntersecting){
        tocLinks.forEach(a => a.classList.toggle('active', a.getAttribute('href') === '#' + entry.target.id));
      }
    });
  }, {rootMargin:'-20% 0px -70% 0px', threshold:0});
  docs.forEach(doc => observer.observe(doc));

  const initial = new URLSearchParams(window.location.search).get('q');
  if(initial){ search.value=initial; }
  filterDocuments();

  if(window.location.hash){
    const targetId = decodeURIComponent(window.location.hash.slice(1));
    const target = document.getElementById(targetId);
    if(target){
      const parentDocument = target.closest('.policy-document');
      if(parentDocument){ parentDocument.hidden=false; parentDocument.open=true; }
      window.setTimeout(function(){ target.scrollIntoView({block:'start'}); }, 80);
    }
  }

  let printState=[];
  window.addEventListener('beforeprint', function(){ printState=docs.map(d=>d.open); docs.forEach(d=>{d.hidden=false;d.open=true;}); });
  window.addEventListener('afterprint', function(){ docs.forEach((d,i)=>d.open=printState[i]); filterDocuments(); });
})();
</script>
</body></html>
