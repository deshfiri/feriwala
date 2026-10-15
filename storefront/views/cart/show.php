<?php

use Storefront\Support\Money;

/** @var list<array<string, mixed>> $items */
/** @var array<string, mixed>|null $quote */
/** @var array<string, array<string, mixed>> $prices */
/** @var array<string, string> $lineProblems */
/** @var string|null $problem */
$blocked = $lineProblems !== [] || $problem === 'cart.not_accepting';
?>
<div class="page-head"><h1><?= e(t('cart.title')) ?></h1></div>

<?php if ($items === []) { ?>
    <div class="empty">
        <p><?= e(t('cart.empty')) ?></p>
        <a class="btn" href="/products"><?= e(t('cart.continue')) ?></a>
    </div>
<?php } else { ?>
    <?php if ($problem !== null) { ?><p class="flash flash-warning" role="status"><?= e(t($problem)) ?></p><?php } ?>

    <div class="layout-cart">
        <ul class="lines">
            <?php foreach ($items as $line) { ?>
                <?php $price = $prices[$line['sku']] ?? null; ?>
                <li class="line">
                    <a class="line-media" href="/product/<?= e(rawurlencode($line['slug'])) ?>">
                        <?php if (safe_url($line['image']) !== '') { ?><img src="<?= e(safe_url($line['image'])) ?>" alt="" width="96" height="96" loading="lazy"><?php } else { ?><span class="placeholder"><?= e(mb_substr($line['name'], 0, 1)) ?></span><?php } ?>
                    </a>
                    <div class="line-body">
                        <a class="line-name" href="/product/<?= e(rawurlencode($line['slug'])) ?>"><?= e($line['name']) ?></a>
                        <?php if ($line['variant'] !== null) { ?><p class="muted small"><?= e($line['variant']) ?></p><?php } ?>
                        <?php if ($price !== null && $price['unit_price'] !== null) { ?><p class="muted small"><?= e(money($price['unit_price'])) ?></p><?php } ?>
                        <?php if (isset($lineProblems[$line['sku']])) { ?><p class="error small" role="alert"><?= e(t($lineProblems[$line['sku']])) ?></p><?php } ?>

                        <div class="line-actions">
                            <form action="/cart/update" method="post" class="qty">
                                <?= csrf_field() ?>
                                <input type="hidden" name="sku" value="<?= e($line['sku']) ?>">
                                <label class="sr-only" for="q-<?= e($line['sku']) ?>"><?= e(t('product.quantity')) ?></label>
                                <input id="q-<?= e($line['sku']) ?>" name="quantity" type="number" min="1" max="1000" value="<?= e((string) $line['quantity']) ?>">
                                <button type="submit" class="btn btn-quiet"><?= e(t('cart.update')) ?></button>
                            </form>
                            <form action="/cart/remove" method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="sku" value="<?= e($line['sku']) ?>">
                                <button type="submit" class="link danger"><?= e(t('cart.remove')) ?></button>
                            </form>
                        </div>
                    </div>
                    <div class="line-total">
                        <?php if ($price !== null && $price['line_total'] !== null) { ?><strong><?= e(money($price['line_total'])) ?></strong><?php } ?>
                    </div>
                </li>
            <?php } ?>
        </ul>

        <aside class="summary-box" aria-labelledby="sum">
            <h2 id="sum"><?= e(t('cart.summary')) ?></h2>
            <?php if ($quote !== null) { ?>
                <dl class="totals">
                    <div><dt><?= e(t('totals.subtotal')) ?></dt><dd><?= e(money($quote['subtotal'])) ?></dd></div>
                    <?php if (! Money::isZero($quote['discount'])) { ?>
                        <div><dt><?= e(t('totals.discount')) ?></dt><dd>− <?= e(money($quote['discount'])) ?></dd></div>
                    <?php } ?>
                    <div><dt><?= e(t('totals.shipping')) ?></dt><dd><?= e(money($quote['shipping'])) ?></dd></div>
                    <?php if (! Money::isZero($quote['tax'])) { ?>
                        <div><dt><?= e(t('totals.tax')) ?></dt><dd><?= e(money($quote['tax'])) ?></dd></div>
                    <?php } ?>
                    <div class="grand"><dt><?= e(t('totals.total')) ?></dt><dd><?= e(money($quote['grand_total'])) ?></dd></div>
                </dl>
                <p class="muted small"><?= e(t('cart.price_note')) ?></p>
            <?php } else { ?>
                <p class="muted"><?= e(t('cart.no_quote')) ?></p>
            <?php } ?>

            <?php if ($blocked) { ?>
                <button class="btn btn-wide" disabled><?= e(t('cart.checkout')) ?></button>
            <?php } else { ?>
                <a class="btn btn-accent btn-wide" href="/checkout"><?= e(t('cart.checkout')) ?></a>
            <?php } ?>
            <a class="btn btn-quiet btn-wide" href="/products"><?= e(t('cart.continue')) ?></a>
        </aside>
    </div>
<?php } ?>
