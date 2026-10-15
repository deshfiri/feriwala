<?php
/** @var array<string, mixed> $order */
/** @var array<string, mixed> $local */
/** @var array<string, mixed> $returnable */
/** @var string $idempotency */
/** @var array<string, string> $errors */
$lines = array_values(array_filter((array) ($returnable['lines'] ?? []), static fn ($l) => is_array($l) && (int) ($l['returnable'] ?? 0) > 0));
$names = [];

foreach ((array) ($order['items'] ?? []) as $item) {
    if (is_array($item) && isset($item['sku'])) {
        $names[(string) $item['sku']] = (string) ($item['name'] ?? $item['sku']);
    }
}

$reasons = ['damaged', 'wrong_item', 'not_as_described', 'missing_parts', 'changed_mind', 'other'];
$action = '/order/'.rawurlencode((string) $local['public_id']).'/return?t='.rawurlencode((string) $local['access_token']);
?>
<div class="page-head"><h1><?= e(t('return.title')) ?></h1></div>

<?php if (isset($errors['form'])) { ?><p class="flash flash-error" role="alert"><?= e(t($errors['form'])) ?></p><?php } ?>

<form class="checkout" action="<?= e($action) ?>" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="idempotency_key" value="<?= e($idempotency) ?>">

    <fieldset>
        <legend><?= e(t('return.what')) ?></legend>
        <?php foreach ($lines as $line) { ?>
            <?php $sku = (string) $line['sku']; ?>
            <div class="return-line">
                <label for="qty-<?= e($sku) ?>"><?= e($names[$sku] ?? $sku) ?></label>
                <input id="qty-<?= e($sku) ?>" name="qty[<?= e($sku) ?>]" type="number" min="0" max="<?= e((string) (int) $line['returnable']) ?>" value="0">
                <span class="muted small"><?= e(t('return.up_to', ['n' => num((int) $line['returnable'])])) ?></span>
            </div>
        <?php } ?>
    </fieldset>

    <fieldset>
        <legend><?= e(t('return.why')) ?></legend>
        <label for="reason"><?= e(t('return.reason')) ?></label>
        <select id="reason" name="reason" required>
            <option value=""><?= e(t('checkout.choose')) ?></option>
            <?php foreach ($reasons as $reason) { ?><option value="<?= e($reason) ?>"><?= e(t('return.reason.'.$reason)) ?></option><?php } ?>
        </select>

        <label for="note"><?= e(t('return.note')) ?> <span class="muted"><?= e(t('checkout.optional')) ?></span></label>
        <textarea id="note" name="note" rows="4" maxlength="1000"></textarea>
    </fieldset>

    <div class="actions">
        <a class="btn btn-quiet" href="/order/<?= e(rawurlencode((string) $local['public_id'])) ?>?t=<?= e((string) $local['access_token']) ?>"><?= e(t('return.back')) ?></a>
        <button class="btn btn-accent" type="submit" data-once><?= e(t('return.submit')) ?></button>
    </div>
</form>
