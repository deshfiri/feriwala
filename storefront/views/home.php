<?php
use Storefront\Http\View;

/** @var array<string, mixed> $site */
/** @var list<array<string, mixed>> $featured */
/** @var list<array<string, mixed>> $newest */
/** @var list<array<string, mixed>> $deals */
/** @var bool $featuredIsNewest */
$banner = safe_url($site['branding']['banner_url'] ?? null);
?>
<section class="hero"<?= $banner !== '' ? ' style="background-image:linear-gradient(90deg,rgba(0,0,0,.55),rgba(0,0,0,.15)),url(\''.e($banner).'\')"' : '' ?>>
    <div class="hero-copy">
        <h1><?= e($site['name']) ?></h1>
        <?php if (! empty($site['branding']['tagline'])) { ?><p><?= e($site['branding']['tagline']) ?></p><?php } ?>
        <a class="btn btn-accent" href="/products"><?= e(t('home.shop_now')) ?></a>
    </div>
</section>

<?php if ($deals !== []) { ?>
    <section aria-labelledby="deals">
        <h2 id="deals"><?= e(t('home.deals')) ?></h2>
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
        <p class="empty"><?= e(t('catalog.empty')) ?></p>
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
