<?php
defined('ABSPATH') || exit;
$gallery_albums = isset($gallery_albums) && is_array($gallery_albums)
    ? $gallery_albums
    : [];
$app_url = isset($app_url) ? (string) $app_url : home_url('/chat/');
?>
<style id="aimee-gallery-albums-style">
.aimee-albums{display:grid;gap:76px;padding-bottom:80px}
.aimee-album{display:grid;gap:24px;scroll-margin-top:110px}
.aimee-album__heading{max-width:720px}
.aimee-album__heading h2{margin:0 0 8px;font-size:clamp(28px,4vw,42px);color:var(--bg-dark)}
.aimee-album__heading p{margin:0;color:var(--text-muted);font-size:16px;line-height:1.65}
.aimee-album__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:24px}
.aimee-album-card{position:relative;display:flex;min-width:0;flex-direction:column;overflow:hidden;border:1px solid var(--border-light);border-radius:var(--radius-md);background:#fff;box-shadow:var(--shadow-subtle);transition:var(--transition-smooth)}
.aimee-album-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-hover)}
.aimee-album-card__media{position:relative;overflow:hidden;aspect-ratio:4/5;background:var(--bg-alt)}
.aimee-album-card__media .gallery-overlay{z-index:2}
.aimee-album-card__media .gallery-img{width:100%;height:100%;object-fit:cover}
.aimee-album-card__media.is-unavailable{display:grid;place-items:center;padding:18px;text-align:center}
.aimee-album-card__media.is-unavailable .gallery-img{display:none}
.aimee-album-card__media.is-unavailable::after{content:"This photo is temporarily unavailable";max-width:190px;color:var(--text-muted);font-size:13px;font-weight:700;line-height:1.45}
.aimee-album-card__badge{position:absolute;z-index:3;top:12px;left:12px;padding:7px 10px;border-radius:999px;background:rgba(24,24,27,.82);color:#fff;font-size:11px;font-weight:800;letter-spacing:.04em;backdrop-filter:blur(8px)}
.aimee-album-card__body{display:flex;flex:1;flex-direction:column;gap:15px;padding:18px}
.aimee-album-card__title{margin:0;color:var(--text-main);font-size:15px;font-weight:650;line-height:1.45;letter-spacing:-.01em}
.aimee-ask-photo{position:relative;z-index:12;display:inline-flex;align-items:center;justify-content:center;min-height:46px;margin-top:auto;padding:11px 16px;border:1px solid var(--border);border-radius:999px;background:var(--bg-dark);color:#fff;text-align:center;text-decoration:none;font-size:13px;font-weight:750;transition:var(--transition-smooth)}
.aimee-ask-photo:hover{background:var(--accent-hover);transform:translateY(-1px)}
.aimee-ask-photo:focus-visible{outline:3px solid rgba(225,29,72,.28);outline-offset:3px}
.aimee-gallery-empty{grid-column:1/-1;margin:0;text-align:center;color:var(--text-muted);font-size:18px}
@media(max-width:768px){.aimee-albums{gap:56px}.aimee-album__grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.aimee-album-card__body{padding:13px;gap:12px}.aimee-album-card__title{font-size:13px}.aimee-ask-photo{min-height:42px;padding:9px 10px;font-size:11px}}
@media(max-width:390px){.aimee-album__grid{grid-template-columns:1fr}}
</style>

<div class="aimee-albums" data-aimee-gallery-albums>
    <?php if ($gallery_albums): ?>
        <?php foreach ($gallery_albums as $album): ?>
            <?php if (empty($album['items'])) continue; ?>
            <section class="aimee-album reveal" id="album-<?php echo esc_attr((string) $album['key']); ?>">
                <header class="aimee-album__heading">
                    <h2><?php echo esc_html((string) $album['label']); ?></h2>
                    <p><?php echo esc_html((string) $album['description']); ?></p>
                </header>
                <div class="aimee-album__grid">
                    <?php foreach ((array) $album['items'] as $gallery_item): ?>
                        <?php
                        $rating = sanitize_key((string) ($gallery_item['rating'] ?? 'safe'));
                        $badge = in_array($rating, ['erotic', 'explicit'], true)
                            ? __('Private', 'aimee-global')
                            : (in_array($rating, ['flirty', 'suggestive'], true)
                                ? __('Flirty', 'aimee-global')
                                : '');
                        ?>
                        <article class="aimee-album-card" data-content-rating="<?php echo esc_attr($rating); ?>" oncontextmenu="return false;">
                            <div class="aimee-album-card__media">
                                <div class="gallery-overlay" aria-hidden="true"></div>
                                <?php if ($badge !== ''): ?>
                                    <span class="aimee-album-card__badge"><?php echo esc_html($badge); ?></span>
                                <?php endif; ?>
                                <img src="<?php echo esc_url((string) $gallery_item['url']); ?>" class="gallery-img" data-aimee-gallery-image alt="<?php echo esc_attr((string) $gallery_item['alt']); ?>" draggable="false" loading="lazy">
                            </div>
                            <div class="aimee-album-card__body">
                                <h3 class="aimee-album-card__title"><?php echo esc_html((string) $gallery_item['alt']); ?></h3>
                                <a class="aimee-ask-photo" href="<?php echo esc_url($app_url . '#ask-aimee-about-photo'); ?>" data-aimee-ask-photo data-media-key="<?php echo esc_attr((string) $gallery_item['key']); ?>">Ask Aimee about this</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    <?php else: ?>
        <p class="aimee-gallery-empty">No Camera Roll moments are available to this account yet.</p>
    <?php endif; ?>
</div>

<script id="aimee-gallery-question-handoff">
(function(){
    "use strict";
    var storageKey="aimeeGalleryQuestion:1";
    function markUnavailable(image){
        var media=image&&image.parentElement;
        if(!media)return;
        media.classList.add("is-unavailable");
        media.setAttribute("role","img");
        media.setAttribute("aria-label","This photo is temporarily unavailable");
    }
    if(document.querySelectorAll){
        Array.prototype.forEach.call(document.querySelectorAll("[data-aimee-gallery-image]"),function(image){
            image.addEventListener("error",function(){markUnavailable(image);},{once:true});
            // A cached failure may complete before this footer script runs.
            if(image.complete&&image.naturalWidth===0)markUnavailable(image);
        });
    }
    document.addEventListener("click",function(event){
        var link=event.target&&event.target.closest?event.target.closest("[data-aimee-ask-photo]"):null;
        if(!link)return;
        var key=String(link.getAttribute("data-media-key")||"");
        if(!/^[a-z0-9_-]{1,191}$/.test(key)){
            event.preventDefault();
            return;
        }
        try{
            window.sessionStorage.setItem(storageKey,JSON.stringify({
                key:key,
                created_at:Date.now()
            }));
        }catch(error){}
    },true);
})();
</script>
