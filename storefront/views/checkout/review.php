<?php
use Storefront\Support\Money;

/** @var array<string, mixed> $checkout */
/** @var array<string, mixed> $quote */
/** @var bool $repriced */
/** @var list<string> $stockProblems */
/** @var bool $codBlocked */
/** @var list<array<string, mixed>> $items */
$customer = (array) $checkout['customer_json'];
$address = (array) $checkout['shipping_json'];
$lines = [];

foreach ((array) $quote['items'] as $line) {
    $lines[(string) $line['sku']] = $line;
}

$canPlace = $stockProblems === [] && ! $codBlocked;
?>
<div class="page-head"><h1><?= e(t('review.title')) ?></h1></div>

<?php if ($repriced) { ?>
    <div class="flash flash-warning" role="alert">
        <strong><?= e(t('review.repriced_title')) ?></strong>
        <p><?= e(t('review.repriced_body')) ?></p>
    </div>
<?php } ?>

<div class="layout-cart">
    <div>
        <section class="panel" aria-labelledby="items">
            <h2 id="items"><?= e(t('review.items')) ?></h2>
            <ul class="lines compact">
                <?php foreach ($items as $item) { ?>
                    <?php $line = $lines[(string) $item['sku']] ?? null; ?>
                    <li class="line">
                        <div class="line-body">
                            <strong><?= e($item['name']) ?></strong>
                            <?php if (! empty($item['variant'])) { ?><p class="muted small"><?= e($item['variant']) ?></p><?php } ?>
                            <p class="muted small"><?= e(t('review.qty', ['n' => num((int) $item['quantity'])])) ?><?= $line !== null && $line['unit_price'] !== null ? ' × '.e(money($line['unit_price'])) : '' ?></p>
                            <?php if (in_array((string) $item['sku'], $stockProblems, true)) { ?><p class="error small" role="alert"><?= e(t('cart.out_of_stock')) ?></p><?php } ?>
                        </div>
                        <div class="line-total"><?= $line !== null && $line['line_total'] !== null ? '<strong>'.e(money($line['line_total'])).'</strong>' : '' ?></div>
                    </li>
                <?php } ?>
            </ul>
        </section>

        <section class="panel" aria-labelledby="ship">
            <h2 id="ship"><?= e(t('review.deliver_to')) ?></h2>
            <p>
                <strong><?= e($customer['name'] ?? '') ?></strong><br>
                <?= e($customer['phone'] ?? '') ?><?= ! empty($customer['email']) ? '<br>'.e($customer['email']) : '' ?>
            </p>
            <p>
                <?= e($address['line1'] ?? '') ?><?= ! empty($address['line2']) ? ', '.e($address['line2']) : '' ?><br>
                <?= e($address['city'] ?? '') ?><?= ! empty($address['district']) ? ', '.e($address['district']) : '' ?><?= ! empty($address['postcode']) ? ' '.e($address['postcode']) : '' ?>
            </p>
            <?php if (! empty($checkout['customer_note'])) { ?><p class="muted"><?= e($checkout['customer_note']) ?></p><?php } ?>
            <p><a href="/checkout"><?= e(t('review.change')) ?></a></p>
        </section>

        <section class="panel" aria-labelledby="pay">
            <h2 id="pay"><?= e(t('checkout.payment')) ?></h2>
            <p><strong><?= e(t('payment.'.$checkout['payment_method'])) ?></strong><br><span class="muted"><?= e(t('payment.'.$checkout['payment_method'].'_help')) ?></span></p>
            <?php if ($codBlocked) { ?>
                <p class="error" role="alert"><?= e(t('review.cod_blocked')) ?></p>
                <?php if (is_array($quote['cod_maximum'] ?? null)) { ?><p class="muted small"><?= e(t('review.cod_max', ['amount' => money($quote['cod_maximum'])])) ?></p><?php } ?>
            <?php } ?>
        </section>
    </div>

    <aside class="summary-box" aria-labelledby="sum">
        <h2 id="sum"><?= e(t('cart.summary')) ?></h2>
        <dl class="totals">
            <div><dt><?= e(t('totals.subtotal')) ?></dt><dd><?= e(money($quote['subtotal'])) ?></dd></div>
            <?php if (! Money::isZero($quote['discount'])) { ?><div><dt><?= e(t('totals.discount')) ?></dt><dd>− <?= e(money($quote['discount'])) ?></dd></div><?php } ?>
            <div><dt><?= e(t('totals.shipping')) ?></dt><dd><?= e(money($quote['shipping'])) ?></dd></div>
            <?php if (! Money::isZero($quote['tax'])) { ?><div><dt><?= e(t('totals.tax')) ?></dt><dd><?= e(money($quote['tax'])) ?></dd></div><?php } ?>
            <div class="grand"><dt><?= e(t('totals.total')) ?></dt><dd><?= e(money($quote['grand_total'])) ?></dd></div>
        </dl>

        <form action="/checkout/<?= e($checkout['token']) ?>/place" method="post" data-once>
            <?= csrf_field() ?>
            <p class="muted small">
                <?= e(t($checkout['payment_method'] === 'cod' ? 'review.cod_note' : 'review.online_note')) ?>
            </p>
            <button class="btn btn-accent btn-wide" type="submit" <?= $canPlace ? '' : 'disabled' ?>>
                <?= e(t($repriced ? 'review.confirm_new_total' : 'review.place')) ?>
            </button>
        </form>
        <a class="btn btn-quiet btn-wide" href="/cart"><?= e(t('review.back_to_cart')) ?></a>
    </aside>
</div>
