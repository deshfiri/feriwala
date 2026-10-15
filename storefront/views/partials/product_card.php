<?php
/** @var array<string, mixed> $product */

use Storefront\Domain\Catalog;

$image = safe_url(Catalog::primaryImage($product));
$price = Catalog::startingPrice($product);
$compare = null;

foreach ((array) ($product['variants'] ?? []) as $variant) {
    if (is_array($variant) && is_array($variant['compare_at_price'] ?? null)) {
        $compare = $variant['compare_at_price'];
        break;
    }
}

$inStock = Catalog::inStock($product);
$slug = rawurlencode((string) ($product['slug'] ?? ''));
?>
<article class="card">
    <a class="card-media" href="/product/<?= e($slug) ?>" tabindex="-1" aria-hidden="true">
        <?php if ($image !== '') { ?>
            <img src="<?= e($image) ?>" alt="" loading="lazy" width="400" height="400">
        <?php } else { ?>
            <span class="placeholder"><?= e(mb_substr((string) ($product['name'] ?? ''), 0, 1)) ?></span>
        <?php } ?>
        <?php if (! $inStock) { ?><span class="tag tag-muted"><?= e(t('product.sold_out')) ?></span>
        <?php } elseif ($compare !== null) { ?><span class="tag"><?= e(t('product.sale')) ?></span><?php } ?>
    </a>
    <div class="card-body">
        <h3><a href="/product/<?= e($slug) ?>"><?= e($product['name'] ?? '') ?></a></h3>
        <?php if (! empty($product['brand']['name'])) { ?><p class="muted"><?= e($product['brand']['name']) ?></p><?php } ?>
        <?php if ($price !== null) { ?>
            <p class="price">
                <?php if (count((array) ($product['variants'] ?? [])) > 1) { ?><span class="muted"><?= e(t('product.from')) ?></span><?php } ?>
                <strong><?= e(money($price)) ?></strong>
                <?php if ($compare !== null) { ?><s class="muted"><?= e(money($compare)) ?></s><?php } ?>
            </p>
        <?php } ?>
    </div>
</article>
