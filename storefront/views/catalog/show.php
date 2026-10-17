<?php
use Storefront\Controllers\CartController;
use Storefront\Domain\Catalog;

/** @var array<string, mixed> $product */
$variants = array_values(array_filter((array) ($product['variants'] ?? []), 'is_array'));
$media = array_values(array_filter(
    (array) ($product['media'] ?? []),
    static fn ($m) => is_array($m) && ($m['type'] ?? 'image') === 'image' && safe_url($m['url'] ?? null) !== '',
));
usort($media, static fn (array $a, array $b): int => (int) ($a['position'] ?? 0) <=> (int) ($b['position'] ?? 0));

$min = max(1, (int) ($product['min_order_quantity'] ?? 1));
$max = (int) ($product['max_order_quantity'] ?? 0);

$firstInStock = null;
foreach ($variants as $variant) {
    if ($variant['availability']['in_stock'] ?? false) {
        $firstInStock = $variant;
        break;
    }
}
$selected = $firstInStock ?? ($variants[0] ?? null);
$anyStock = $firstInStock !== null;
$selectedQty = (int) ($selected['availability']['quantity'] ?? 0);
$low = Catalog::LOW_STOCK;
$brand = is_array($product['brand'] ?? null) ? $product['brand'] : null;
$categories = array_values(array_filter((array) ($product['categories'] ?? []), 'is_array'));
?>
<nav class="crumbs" aria-label="<?= e(t('product.breadcrumb')) ?>">
    <a href="/"><?= e(t('nav.home')) ?></a> ›
    <a href="/products"><?= e(t('nav.all')) ?></a>
    <?php if ($categories !== []) { ?> › <a href="/category/<?= e(rawurlencode((string) ($categories[0]['slug'] ?? ''))) ?>"><?= e($categories[0]['name'] ?? '') ?></a><?php } ?>
</nav>

<div class="product">
    <div class="gallery">
        <?php if ($media !== []) { ?>
            <img id="hero-image" class="hero-image" src="<?= e(safe_url($media[0]['url'])) ?>" alt="<?= e($media[0]['alt'] ?? $product['name'] ?? '') ?>" width="800" height="800" data-fallback="hero-fallback">
            <div id="hero-fallback" class="hero-image placeholder" role="img" aria-label="<?= e(t('ui.image_missing')) ?>" hidden><span><?= e(mb_substr((string) ($product['name'] ?? ''), 0, 1)) ?></span></div>
            <?php if (count($media) > 1) { ?>
                <ul class="thumbs">
                    <?php foreach ($media as $index => $item) { ?>
                        <li>
                            <a href="<?= e(safe_url($item['url'])) ?>" data-gallery="<?= e(safe_url($item['url'])) ?>" data-alt="<?= e($item['alt'] ?? '') ?>" <?= $index === 0 ? 'aria-current="true"' : '' ?>>
                                <img src="<?= e(safe_url($item['url'])) ?>" alt="<?= e($item['alt'] ?? t('product.photo', ['n' => (string) ($index + 1)])) ?>" loading="lazy" width="96" height="96">
                            </a>
                        </li>
                    <?php } ?>
                </ul>
            <?php } ?>
        <?php } else { ?>
            <div class="hero-image placeholder" role="img" aria-label="<?= e(t('ui.image_missing')) ?>"><span><?= e(mb_substr((string) ($product['name'] ?? ''), 0, 1)) ?></span></div>
        <?php } ?>
    </div>

    <div class="summary">
        <?php if ($brand !== null && ! empty($brand['name'])) { ?><p class="muted"><?= e($brand['name']) ?></p><?php } ?>
        <h1><?= e($product['name'] ?? '') ?></h1>

        <?php if ($selected !== null) { ?>
            <p class="price big" id="price" aria-live="polite">
                <strong><?= e(money($selected['price'] ?? null)) ?></strong>
                <?php if (is_array($selected['compare_at_price'] ?? null)) { ?><s class="muted"><?= e(money($selected['compare_at_price'])) ?></s><?php } ?>
            </p>
        <?php } ?>

        <?php if (! empty($product['short_description'])) { ?>
            <p class="lead"><?= e(plain_text((string) $product['short_description'])) ?></p>
        <?php } ?>

        <?php if ($variants === []) { ?>
            <p class="flash flash-warning"><?= e(t('product.unavailable')) ?></p>
        <?php } else { ?>
            <form class="buy" action="/cart/add" method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="product" value="<?= e($product['slug'] ?? '') ?>">

                <?php if (count($variants) > 1) { ?>
                    <label for="sku"><?= e(t('product.option')) ?></label>
                    <select id="sku" name="sku" data-variants>
                        <?php foreach ($variants as $variant) { ?>
                            <?php
                            $in = (bool) ($variant['availability']['in_stock'] ?? false);
                            $label = CartController::variantLabel($variant) ?? (string) ($variant['sku'] ?? '');
                            ?>
                            <option value="<?= e($variant['sku'] ?? '') ?>"
                                    data-price="<?= e(money($variant['price'] ?? null)) ?>"
                                    data-compare="<?= e(money(is_array($variant['compare_at_price'] ?? null) ? $variant['compare_at_price'] : null)) ?>"
                                    data-stock="<?= $in ? '1' : '0' ?>"
                                    data-qty="<?= e((string) (int) ($variant['availability']['quantity'] ?? 0)) ?>"
                                    <?= ($selected['sku'] ?? null) === ($variant['sku'] ?? null) ? 'selected' : '' ?>>
                                <?= e($label) ?><?= $in ? '' : ' — '.e(t('product.out_of_stock')) ?>
                            </option>
                        <?php } ?>
                    </select>
                <?php } else { ?>
                    <input type="hidden" name="sku" value="<?= e($variants[0]['sku'] ?? '') ?>">
                <?php } ?>

                <p id="stock" class="stock <?= ! $anyStock ? 'out' : ($selectedQty > 0 && $selectedQty <= $low ? 'low' : 'in') ?>" aria-live="polite"
                   data-in="<?= e(t('product.in_stock')) ?>" data-out="<?= e(t('product.out_of_stock')) ?>" data-low="<?= e(t('product.low_stock', ['n' => ':n'])) ?>" data-threshold="<?= e((string) $low) ?>">
                    <?php if (! $anyStock) { ?><?= e(t('product.out_of_stock')) ?>
                    <?php } elseif ($selectedQty > 0 && $selectedQty <= $low) { ?><?= e(t('product.low_stock', ['n' => num((string) $selectedQty)])) ?>
                    <?php } else { ?><?= e(t('product.in_stock')) ?><?php } ?>
                </p>

                <label for="quantity"><?= e(t('product.quantity')) ?></label>
                <div class="stepper" data-stepper>
                    <button type="button" class="step" data-step="-1" aria-label="<?= e(t('product.qty_less')) ?>" hidden>−</button>
                    <input id="quantity" name="quantity" type="number" inputmode="numeric" min="<?= e((string) $min) ?>" <?= $max > 0 ? 'max="'.e((string) $max).'"' : '' ?> data-max="<?= e((string) $max) ?>" value="<?= e((string) $min) ?>">
                    <button type="button" class="step" data-step="1" aria-label="<?= e(t('product.qty_more')) ?>" hidden>+</button>
                </div>
                <?php if ($min > 1 || $max > 0) { ?>
                    <p class="muted small"><?= e(t('product.quantity_range', ['min' => (string) $min, 'max' => $max > 0 ? (string) $max : '∞'])) ?></p>
                <?php } ?>

                <button class="btn btn-accent btn-wide btn-large" type="submit" id="add" <?= $anyStock ? '' : 'disabled' ?>><?= e(t('product.add')) ?></button>
            </form>
        <?php } ?>

        <?php if (! empty($product['sku'])) { ?><p class="muted small"><?= e(t('product.code')) ?>: <?= e($product['sku']) ?></p><?php } ?>
    </div>
</div>

<?php $paras = paragraphs((string) ($product['description'] ?? '')); ?>
<?php if ($paras !== []) { ?>
    <section class="description" aria-labelledby="desc">
        <h2 id="desc"><?= e(t('product.description')) ?></h2>
        <?php foreach ($paras as $paragraph) { ?><p><?= e($paragraph) ?></p><?php } ?>
    </section>
<?php } ?>
