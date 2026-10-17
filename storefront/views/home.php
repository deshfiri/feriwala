<?php
use Storefront\Http\View;

/** @var array<string, mixed> $site */
/** @var list<array<string, mixed>> $featured */
/** @var list<array<string, mixed>> $newest */
/** @var list<array<string, mixed>> $deals */
/** @var bool $featuredIsNewest */
$banner = safe_url($site['branding']['banner_url'] ?? null);
$link = is_array($site['branding']['banner_link'] ?? null) ? $site['branding']['banner_link'] : null;
$label = t('home.banner_label', ['name' => (string) $site['name']]);
?>
<?php if ($banner !== '') { ?>
    <?php /* The picture is the whole control: nothing is drawn over it. */ ?>
    <section class="banner" aria-label="<?= e($label) ?>">
        <?php if ($link !== null) { ?>
            <a class="banner-link" href="<?= e($link['url']) ?>"<?= $link['external'] ? ' target="_blank" rel="noopener noreferrer"' : '' ?> aria-label="<?= e($label) ?>">
                <img class="banner-image" src="<?= e($banner) ?>" alt="" width="1600" height="560" fetchpriority="high" data-fallback="banner-fallback">
            </a>
        <?php } else { ?>
            <img class="banner-image" src="<?= e($banner) ?>" alt="" width="1600" height="560" fetchpriority="high" data-fallback="banner-fallback">
        <?php } ?>
        <div class="banner-fallback" hidden>
            <h1><?= e($site['name']) ?></h1>
            <?php if (! empty($site['branding']['tagline'])) { ?><p><?= e($site['branding']['tagline']) ?></p><?php } ?>
        </div>
    </section>
<?php } else { ?>
    <section class="banner banner-plain" aria-label="<?= e($label) ?>">
        <div class="banner-fallback">
            <h1><?= e($site['name']) ?></h1>
            <?php if (! empty($site['branding']['tagline'])) { ?><p><?= e($site['branding']['tagline']) ?></p><?php } ?>
        </div>
    </section>
<?php } ?>

<?php if ($deals !== []) { ?>
    <section aria-labelledby="deals">
        <div class="section-head">
            <h2 id="deals"><?= e(t('home.deals')) ?></h2>
        </div>
        <div class="grid">
            <?php foreach ($deals as $product) { ?><?= View::partial('product_card', ['product' => $product]) ?><?php } ?>
        </div>
    </section>
<?php } ?>

<section aria-labelledby="featured">
    <div class="section-head">
        <h2 id="featured"><?= e(t($featuredIsNewest ? 'home.new_arrivals' : 'home.featured')) ?></h2>
        <a href="/products"><?= e(t('home.view_all')) ?></a>
    </div>
    <?php if ($featured === []) { ?>
        <div class="empty">
            <svg viewBox="0 0 24 24" width="40" height="40" aria-hidden="true" focusable="false"><path d="M4 8l8-4 8 4v8l-8 4-8-4V8zm0 0l8 4m8-4l-8 4m0 8v-8" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
            <p><?= e(t('catalog.empty')) ?></p>
        </div>
    <?php } else { ?>
        <div class="grid">
            <?php foreach ($featured as $product) { ?><?= View::partial('product_card', ['product' => $product]) ?><?php } ?>
        </div>
    <?php } ?>
</section>

<?php if ($newest !== []) { ?>
    <section aria-labelledby="newest">
        <div class="section-head">
            <h2 id="newest"><?= e(t('home.new_arrivals')) ?></h2>
            <a href="/products?sort=newest"><?= e(t('home.view_all')) ?></a>
        </div>
        <div class="grid">
            <?php foreach ($newest as $product) { ?><?= View::partial('product_card', ['product' => $product]) ?><?php } ?>
        </div>
    </section>
<?php } ?>
