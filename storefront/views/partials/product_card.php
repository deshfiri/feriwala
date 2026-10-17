<?php
/** @var array<string, mixed> $product */

use Storefront\Domain\Catalog;

$image = safe_url(Catalog::primaryImage($product));
$price = Catalog::startingPrice($product);
$compare = null;
$variants = array_values(array_filter((array) ($product['variants'] ?? []), 'is_array'));

foreach ($variants as $variant) {
    if (is_array($variant['compare_at_price'] ?? null)) {
        $compare = $variant['compare_at_price'];
        break;
    }
}

$inStock = Catalog::inStock($product);
$left = Catalog::unitsLeft($product);
$slug = rawurlencode((string) ($product['slug'] ?? ''));
$name = (string) ($product['name'] ?? '');
$quickAdd = $inStock && count($variants) === 1 && ($variants[0]['sku'] ?? '') !== '';
$min = max(1, (int) ($product['min_order_quantity'] ?? 1));
?>
<article class="card<?= $inStock ? '' : ' card-sold-out' ?>">
    <a class="card-media" href="/product/<?= e($slug) ?>" tabindex="-1" aria-hidden="true">
        <?php if ($image !== '') { ?>
            <img src="<?= e($image) ?>" alt="" loading="lazy" width="400" height="500" data-fallback="card-fallback">
        <?php } ?>
        <span class="placeholder card-fallback"<?= $image !== '' ? ' hidden' : '' ?>><?= e(mb_substr($name, 0, 1)) ?></span>
        <?php if (! $inStock) { ?><span class="tag tag-muted"><?= e(t('product.out_of_stock')) ?></span>
        <?php } elseif ($compare !== null) { ?><span class="tag"><?= e(t('product.sale')) ?></span><?php } ?>
    </a>
    <div class="card-body">
        <?php if (! empty($product['brand']['name'])) { ?><p class="card-brand"><?= e($product['brand']['name']) ?></p><?php } ?>
        <h3 class="card-title"><a href="/product/<?= e($slug) ?>"><?= e($name) ?></a></h3>
        <?php if (count($variants) > 1) { ?><p class="card-meta"><?= e(t('product.options_count', ['n' => num((string) count($variants))])) ?></p><?php } ?>

        <?php if ($price !== null) { ?>
            <p class="price">
                <?php if (count($variants) > 1) { ?><span class="muted small"><?= e(t('product.from')) ?></span><?php } ?>
                <strong><?= e(money($price)) ?></strong>
                <?php if ($compare !== null) { ?><s class="muted small"><?= e(money($compare)) ?></s><?php } ?>
            </p>
        <?php } ?>

        <?php if ($inStock && $left > 0 && $left <= Catalog::LOW_STOCK) { ?>
            <p class="stock-line low"><?= e(t('product.low_stock', ['n' => num((string) $left)])) ?></p>
        <?php } elseif (! $inStock) { ?>
            <p class="stock-line out"><?= e(t('product.out_of_stock')) ?></p>
        <?php } ?>

        <div class="card-actions">
            <?php if ($quickAdd) { ?>
                <form action="/cart/add" method="post" data-once>
                    <?= csrf_field() ?>
                    <input type="hidden" name="product" value="<?= e($product['slug'] ?? '') ?>">
                    <input type="hidden" name="sku" value="<?= e($variants[0]['sku']) ?>">
                    <input type="hidden" name="quantity" value="<?= e((string) $min) ?>">
                    <button class="btn btn-small btn-wide" type="submit"><?= e(t('product.quick_add')) ?></button>
                </form>
            <?php } else { ?>
                <a class="btn btn-small btn-quiet btn-wide" href="/product/<?= e($slug) ?>"><?= e(t($inStock ? 'product.view_options' : 'product.out_of_stock')) ?></a>
            <?php } ?>
        </div>
    </div>
</article>
