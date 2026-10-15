<?php
use Storefront\Support\Money;

/** @var array<string, mixed> $order */
/** @var array<string, mixed> $local */
/** @var bool $stale */
/** @var string $paymentReturn */
$status = (string) ($order['status'] ?? $local['status']);
$statusKey = 'status.'.$status;
$statusLabel = t($statusKey) !== $statusKey ? t($statusKey) : ucfirst(str_replace('_', ' ', $status));
$payment = is_array($order['payment'] ?? null) ? $order['payment'] : [];
$method = (string) ($payment['method'] ?? $local['payment_method']);
$totals = is_array($order['totals'] ?? null) ? $order['totals'] : [];
$items = array_values(array_filter((array) ($order['items'] ?? []), 'is_array'));
$timeline = array_values(array_filter((array) ($order['timeline'] ?? []), 'is_array'));
$confirmation = is_array($order['confirmation'] ?? null) ? $order['confirmation'] : null;
$returnable = is_array($order['returnable'] ?? null) ? $order['returnable'] : [];
$returns = array_values(array_filter((array) ($order['returns'] ?? []), 'is_array'));
$reservation = is_array($order['stock_reservation'] ?? null) ? $order['stock_reservation'] : null;

$awaitingPayment = $status === 'payment_pending';
$awaitingCode = $status === 'customer_verification_pending';
$token = (string) $local['access_token'];
$base = '/order/'.rawurlencode((string) $local['public_id']);
?>
<div class="page-head">
    <h1><?= e(t('order.title', ['ref' => (string) ($order['reference'] ?? '')])) ?></h1>
    <p class="status status-<?= e(preg_replace('/[^a-z_]/', '', $status) ?? '') ?>"><?= e($statusLabel) ?></p>
</div>

<?php if ($stale) { ?><p class="flash flash-warning" role="status"><?= e(t('order.stale')) ?></p><?php } ?>

<?php if ($paymentReturn !== '' && $awaitingPayment) { ?>
    <p class="flash flash-info" role="status"><?= e(t('order.payment_processing')) ?></p>
<?php } ?>

<?php if ($awaitingPayment) { ?>
    <section class="panel highlight" aria-labelledby="pay">
        <h2 id="pay"><?= e(t('order.pay_title')) ?></h2>
        <p><?= e(t('order.pay_body')) ?></p>
        <?php if ($reservation !== null && ! empty($reservation['expires_at'])) { ?>
            <p class="muted small"><?= e(t('order.held_until', ['time' => gmdate('j M Y, H:i', (int) strtotime((string) $reservation['expires_at'])).' UTC'])) ?></p>
        <?php } ?>
        <div class="actions">
            <form action="<?= e($base) ?>/pay?t=<?= e($token) ?>" method="post"><?= csrf_field() ?><button class="btn btn-accent" type="submit"><?= e(t('order.pay_now')) ?></button></form>
            <form action="<?= e($base) ?>/cancel?t=<?= e($token) ?>" method="post" data-confirm="<?= e(t('order.cancel_confirm')) ?>"><?= csrf_field() ?><button class="btn btn-quiet" type="submit"><?= e(t('order.cancel')) ?></button></form>
        </div>
    </section>
<?php } ?>

<?php if ($awaitingCode) { ?>
    <section class="panel highlight" aria-labelledby="cod">
        <h2 id="cod"><?= e(t('order.cod_title')) ?></h2>
        <p><?= e(t('order.cod_body')) ?></p>
        <?php if ($confirmation !== null && ! empty($confirmation['expires_at'])) { ?>
            <p class="muted small"><?= e(t('order.cod_expires', ['time' => gmdate('j M Y, H:i', (int) strtotime((string) $confirmation['expires_at'])).' UTC'])) ?></p>
        <?php } ?>

        <form class="inline" action="<?= e($base) ?>/confirm?t=<?= e($token) ?>" method="post" data-once>
            <?= csrf_field() ?>
            <label for="code"><?= e(t('order.code')) ?></label>
            <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="8" required>
            <button class="btn btn-accent" type="submit"><?= e(t('order.confirm')) ?></button>
        </form>

        <div class="actions">
            <form action="<?= e($base) ?>/code?t=<?= e($token) ?>" method="post"><?= csrf_field() ?><button class="btn btn-quiet" type="submit"><?= e(t('order.send_code')) ?></button></form>
            <form action="<?= e($base) ?>/cancel?t=<?= e($token) ?>" method="post" data-confirm="<?= e(t('order.cancel_confirm')) ?>"><?= csrf_field() ?><button class="btn btn-quiet" type="submit"><?= e(t('order.cancel')) ?></button></form>
        </div>
    </section>
<?php } ?>

<div class="layout-cart">
    <div>
        <?php if ($items !== []) { ?>
            <section class="panel" aria-labelledby="items">
                <h2 id="items"><?= e(t('review.items')) ?></h2>
                <ul class="lines compact">
                    <?php foreach ($items as $item) { ?>
                        <li class="line">
                            <div class="line-body">
                                <strong><?= e($item['name'] ?? $item['sku'] ?? '') ?></strong>
                                <?php if (! empty($item['variant'])) { ?><p class="muted small"><?= e($item['variant']) ?></p><?php } ?>
                                <p class="muted small"><?= e(t('review.qty', ['n' => num((int) ($item['quantity'] ?? 0))])) ?><?= is_array($item['unit_price'] ?? null) ? ' × '.e(money($item['unit_price'])) : '' ?></p>
                            </div>
                            <div class="line-total"><?= is_array($item['line_total'] ?? null) ? '<strong>'.e(money($item['line_total'])).'</strong>' : '' ?></div>
                        </li>
                    <?php } ?>
                </ul>
            </section>
        <?php } ?>

        <?php if ($timeline !== []) { ?>
            <section class="panel" aria-labelledby="tl">
                <h2 id="tl"><?= e(t('order.timeline')) ?></h2>
                <ol class="timeline">
                    <?php foreach (array_reverse($timeline) as $entry) { ?>
                        <li>
                            <strong><?= e(t('status.'.($entry['status'] ?? '')) !== 'status.'.($entry['status'] ?? '') ? t('status.'.$entry['status']) : ucfirst(str_replace('_', ' ', (string) ($entry['status'] ?? '')))) ?></strong>
                            <span class="muted small"><?= e(gmdate('j M Y, H:i', (int) strtotime((string) ($entry['at'] ?? 'now')))) ?> UTC</span>
                            <?php if (! empty($entry['note'])) { ?><p><?= e($entry['note']) ?></p><?php } ?>
                        </li>
                    <?php } ?>
                </ol>
            </section>
        <?php } ?>

        <?php if (! empty($order['tracking_number'])) { ?>
            <section class="panel"><h2><?= e(t('order.tracking')) ?></h2><p><?= e($order['tracking_number']) ?></p></section>
        <?php } ?>

        <?php if ($returns !== []) { ?>
            <section class="panel" aria-labelledby="rets">
                <h2 id="rets"><?= e(t('return.yours')) ?></h2>
                <ul>
                    <?php foreach ($returns as $return) { ?>
                        <li><?= e(t('return.status.'.($return['status'] ?? '')) !== 'return.status.'.($return['status'] ?? '') ? t('return.status.'.$return['status']) : ucfirst(str_replace('_', ' ', (string) ($return['status'] ?? '')))) ?></li>
                    <?php } ?>
                </ul>
            </section>
        <?php } ?>

        <?php if ($returnable['eligible'] ?? false) { ?>
            <section class="panel">
                <h2><?= e(t('return.title')) ?></h2>
                <?php if (! empty($returnable['window_closes_at'])) { ?><p class="muted small"><?= e(t('return.until', ['date' => gmdate('j M Y', (int) strtotime((string) $returnable['window_closes_at']))])) ?></p><?php } ?>
                <a class="btn" href="<?= e($base) ?>/return?t=<?= e($token) ?>"><?= e(t('return.start')) ?></a>
            </section>
        <?php } ?>
    </div>

    <aside class="summary-box" aria-labelledby="sum">
        <h2 id="sum"><?= e(t('cart.summary')) ?></h2>
        <dl class="totals">
            <?php if (is_array($totals['subtotal'] ?? null)) { ?><div><dt><?= e(t('totals.subtotal')) ?></dt><dd><?= e(money($totals['subtotal'])) ?></dd></div><?php } ?>
            <?php if (is_array($totals['discount'] ?? null) && ! Money::isZero($totals['discount'])) { ?><div><dt><?= e(t('totals.discount')) ?></dt><dd>− <?= e(money($totals['discount'])) ?></dd></div><?php } ?>
            <?php if (is_array($totals['shipping'] ?? null)) { ?><div><dt><?= e(t('totals.shipping')) ?></dt><dd><?= e(money($totals['shipping'])) ?></dd></div><?php } ?>
            <?php if (is_array($totals['tax'] ?? null) && ! Money::isZero($totals['tax'])) { ?><div><dt><?= e(t('totals.tax')) ?></dt><dd><?= e(money($totals['tax'])) ?></dd></div><?php } ?>
            <div class="grand"><dt><?= e(t('totals.total')) ?></dt><dd><?= e(money(is_array($totals['grand_total'] ?? null) ? $totals['grand_total'] : null)) ?></dd></div>
        </dl>
        <p class="muted small"><?= e(t('payment.'.$method)) ?></p>
        <p class="muted small"><?= e(t('order.placed', ['date' => gmdate('j M Y, H:i', (int) strtotime((string) ($order['placed_at'] ?? 'now'))).' UTC'])) ?></p>
    </aside>
</div>
