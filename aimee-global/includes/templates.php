<?php
defined('ABSPATH') || exit;

function aimee_global_public_statement_url() {
    return home_url('/synthetic-neuroanatomy/');
}

/**
 * Give landing-page visitors a compact route into Engram Intelligence's
 * current public statement without displacing the existing conversion flow.
 */
function aimee_global_landing_press_release_banner_markup() {
    $statement_url = esc_url(aimee_global_public_statement_url());

    return <<<HTML
<style id="aimee-public-statement-landing-style">
#aimee-public-statement-float{
    position:fixed;
    right:24px;
    bottom:24px;
    z-index:997;
    width:min(372px,calc(100vw - 32px));
    padding:17px 46px 17px 18px;
    overflow:hidden;
    border:1px solid rgba(255,255,255,.14);
    border-radius:20px;
    background:linear-gradient(145deg,rgba(24,24,27,.98),rgba(39,39,42,.98));
    box-shadow:0 24px 65px rgba(9,9,11,.26);
    color:#fff;
    font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    isolation:isolate;
    animation:aimeeStatementArrival .55s cubic-bezier(.16,1,.3,1) both;
}
#aimee-public-statement-float::before{
    content:"";
    position:absolute;
    z-index:-1;
    width:190px;
    height:190px;
    right:-88px;
    top:-115px;
    border-radius:50%;
    background:radial-gradient(circle,rgba(244,63,94,.55),rgba(190,18,60,0) 70%);
}
#aimee-public-statement-float[hidden]{display:none!important}
.aimee-public-statement-float__link{
    display:block;
    color:inherit;
    text-decoration:none;
}
.aimee-public-statement-float__eyebrow{
    display:flex;
    align-items:center;
    gap:8px;
    margin-bottom:8px;
    color:#FDA4AF;
    font-size:10px;
    font-weight:850;
    line-height:1.2;
    letter-spacing:.12em;
    text-transform:uppercase;
}
.aimee-public-statement-float__eyebrow::before{
    content:"";
    width:7px;
    height:7px;
    border-radius:50%;
    background:#FB7185;
    box-shadow:0 0 0 5px rgba(251,113,133,.12);
}
.aimee-public-statement-float__title{
    display:block;
    margin:0 0 7px;
    color:#fff;
    font-size:18px;
    font-weight:700;
    line-height:1.22;
    letter-spacing:-.025em;
}
.aimee-public-statement-float__copy{
    display:block;
    margin:0 0 11px;
    color:#D4D4D8;
    font-size:12px;
    line-height:1.5;
}
.aimee-public-statement-float__action{
    display:inline-flex;
    align-items:center;
    gap:6px;
    color:#fff;
    font-size:12px;
    font-weight:780;
}
.aimee-public-statement-float__link:hover .aimee-public-statement-float__action,
.aimee-public-statement-float__link:focus-visible .aimee-public-statement-float__action{
    color:#FDA4AF;
}
.aimee-public-statement-float__link:focus-visible{
    border-radius:10px;
    outline:2px solid #FDA4AF;
    outline-offset:5px;
}
.aimee-public-statement-float__dismiss{
    position:absolute;
    top:9px;
    right:9px;
    width:31px;
    height:31px;
    padding:0;
    border:0;
    border-radius:50%;
    background:rgba(255,255,255,.08);
    color:#D4D4D8;
    font:400 20px/31px Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    cursor:pointer;
}
.aimee-public-statement-float__dismiss:hover,
.aimee-public-statement-float__dismiss:focus-visible{
    background:rgba(255,255,255,.16);
    color:#fff;
    outline:none;
}
@keyframes aimeeStatementArrival{
    from{opacity:0;transform:translateY(18px) scale(.98)}
    to{opacity:1;transform:none}
}
@media(max-width:820px){
    #aimee-public-statement-float{
        right:12px;
        bottom:calc(var(--aimee-statement-mobile-clearance,96px) + env(safe-area-inset-bottom));
        width:calc(100vw - 24px);
        padding:13px 43px 13px 15px;
        border-radius:17px;
    }
    .aimee-public-statement-float__eyebrow{margin-bottom:6px;font-size:9px}
    .aimee-public-statement-float__title{margin-bottom:5px;font-size:15px}
    .aimee-public-statement-float__copy{margin-bottom:7px;font-size:11px;line-height:1.4}
    .aimee-public-statement-float__action{font-size:11px}
}
@media(max-width:390px),(max-height:620px){
    .aimee-public-statement-float__copy{display:none}
    .aimee-public-statement-float__title{margin-bottom:8px}
}
@media(prefers-reduced-motion:reduce){
    #aimee-public-statement-float{animation:none}
}
</style>
<aside id="aimee-public-statement-float" aria-label="Engram Intelligence press release">
    <button class="aimee-public-statement-float__dismiss" type="button" aria-label="Dismiss press release notice">×</button>
    <a class="aimee-public-statement-float__link" href="{$statement_url}">
        <span class="aimee-public-statement-float__eyebrow">Engram Intelligence · Press release</span>
        <strong class="aimee-public-statement-float__title">What if consciousness is the wrong question?</strong>
        <span class="aimee-public-statement-float__copy">How Aimee works—and why care should come before certainty.</span>
        <span class="aimee-public-statement-float__action">Read the public statement <span aria-hidden="true">→</span></span>
    </a>
</aside>
<script id="aimee-public-statement-landing-ui">
(function(){
    "use strict";
    var banner=document.getElementById("aimee-public-statement-float");
    if(!banner)return;
    var key="aimeePublicStatementLandingDismissed:1.4.7";
    function syncMobileClearance(){
        var mobile=typeof window.matchMedia==="function"
            ? window.matchMedia("(max-width:820px)").matches
            : window.innerWidth<=820;
        if(!mobile){
            banner.style.removeProperty("--aimee-statement-mobile-clearance");
            return;
        }
        var sticky=document.getElementById("sticky-cta");
        var height=sticky&&window.getComputedStyle(sticky).display!=="none"
            ? Math.ceil(sticky.getBoundingClientRect().height)
            : 78;
        banner.style.setProperty(
            "--aimee-statement-mobile-clearance",
            Math.max(96,height+12)+"px"
        );
    }
    try{
        if(window.localStorage.getItem(key)==="1"){
            banner.hidden=true;
            return;
        }
    }catch(error){}
    var dismiss=banner.querySelector(".aimee-public-statement-float__dismiss");
    if(dismiss)dismiss.addEventListener("click",function(){
        banner.hidden=true;
        try{window.localStorage.setItem(key,"1");}catch(error){}
    });
    syncMobileClearance();
    window.addEventListener("resize",syncMobileClearance,{passive:true});
    window.addEventListener("orientationchange",syncMobileClearance,{passive:true});
    if(window.visualViewport){
        window.visualViewport.addEventListener("resize",syncMobileClearance,{passive:true});
    }
    window.setTimeout(syncMobileClearance,250);
})();
</script>
HTML;
}

add_action('wp_footer', function () {
    if (is_admin() || !is_singular('page')) return;

    $template = get_page_template_slug(get_queried_object_id());
    if (!in_array($template, [
        'aimee-global-landing-uk.php',
        'aimee-global-landing-us.php',
    ], true)) {
        return;
    }

    echo aimee_global_landing_press_release_banner_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}, 25);

function aimee_global_template_registry() {
    return [
        'aimee-global-landing-uk.php' => ['Aimee Landing (UK)', 'landing-uk.php'],
        'aimee-global-chat-uk.php' => ['Aimee Chat (UK)', 'chat-uk.php'],
        'aimee-global-pricing-uk.php' => ['Aimee Pricing (UK)', 'pricing-uk.php'],
        'aimee-global-faq-uk.php' => ['Aimee FAQ (UK)', 'faq-uk.php'],
        'aimee-global-technology-uk.php' => ['Aimee Technology (UK)', 'technology-uk.php'],
        'aimee-global-synthetic-neuroanatomy.php' => ['Engram Synthetic Neuroanatomy Statement', 'synthetic-neuroanatomy.php'],
        'aimee-global-privacy-uk.php' => ['Aimee Privacy (UK)', 'privacy-uk.php'],
        'aimee-global-gallery-uk.php' => ['Aimee Gallery (UK)', 'gallery-uk.php'],
        'aimee-global-landing-us.php' => ['Aimee Landing (US)', 'landing-us.php'],
        'aimee-global-chat-us.php' => ['Aimee Chat (US)', 'chat-us.php'],
        'aimee-global-pricing-us.php' => ['Aimee Pricing (US)', 'pricing-us.php'],
        'aimee-global-faq-us.php' => ['Aimee FAQ (US)', 'faq-us.php'],
        'aimee-global-technology-us.php' => ['Aimee Technology (US)', 'technology-us.php'],
        'aimee-global-privacy-us.php' => ['Aimee Privacy (US)', 'privacy-us.php'],
        'aimee-global-gallery-us.php' => ['Aimee Gallery (US)', 'gallery-us.php'],
        'aimee-global-gallery-vip.php' => ['Aimee Gallery Uploads (Staff)', 'gallery-vip.php'],
        'aimee-global-governance.php' => ['Aimee Internal Governance Centre', 'governance.php'],
    ];
}

add_filter('theme_page_templates', function ($templates) {
    foreach (aimee_global_template_registry() as $slug => $item) $templates[$slug] = $item[0];
    return $templates;
});

add_filter('template_include', function ($template) {
    if (!is_singular('page')) return $template;
    $slug = get_page_template_slug(get_queried_object_id());
    $registry = aimee_global_template_registry();
    if (isset($registry[$slug])) {
        $file = AIMEE_GLOBAL_DIR . 'templates/' . $registry[$slug][1];
        if (is_readable($file)) return $file;
    }
    return $template;
}, 99);

function aimee_global_page_definitions() {
    return [
        ['home','Aimee','aimee-global-landing-uk.php'], ['chat','Chat with Aimee','aimee-global-chat-uk.php'],
        ['pricing','Pricing','aimee-global-pricing-uk.php'], ['faq','FAQ','aimee-global-faq-uk.php'],
        ['technology','Technology','aimee-global-technology-uk.php'], ['privacy','Privacy','aimee-global-privacy-uk.php'],
        ['synthetic-neuroanatomy','Synthetic Neuroanatomy','aimee-global-synthetic-neuroanatomy.php'],
        ['camera-roll','Camera Roll','aimee-global-gallery-uk.php'], ['governance','Governance','aimee-global-governance.php'],
        ['usa','Aimee USA','aimee-global-landing-us.php'], ['chat-us','Chat with Aimee USA','aimee-global-chat-us.php'],
        ['pricing-us','US Pricing','aimee-global-pricing-us.php'], ['faq-us','US FAQ','aimee-global-faq-us.php'],
        ['technology-us','US Technology','aimee-global-technology-us.php'], ['privacy-us','US Privacy','aimee-global-privacy-us.php'],
        ['camera-roll-us','US Camera Roll','aimee-global-gallery-us.php'],
    ];
}

function aimee_global_create_pages() {
    foreach (aimee_global_page_definitions() as [$slug,$title,$template]) {
        $page = get_page_by_path($slug, OBJECT, 'page');
        if (!$page) {
            $id = wp_insert_post(['post_title'=>$title,'post_name'=>$slug,'post_type'=>'page','post_status'=>'publish','post_content'=>'']);
        } else {
            $id = $page->ID;
        }
        if (is_wp_error($id)) return $id;
        if (!$id) {
            return new WP_Error(
                'aimee_managed_page_create_failed',
                sprintf('The managed page "%s" could not be created.', $slug)
            );
        }

        if ((string) get_post_meta($id, '_wp_page_template', true) !== $template) {
            $updated = update_post_meta($id, '_wp_page_template', $template);
            if ($updated === false) {
                return new WP_Error(
                    'aimee_managed_page_template_failed',
                    sprintf('The managed page template for "%s" could not be assigned.', $slug)
                );
            }
        }
    }
    return true;
}
