<?php

use Storefront\Http\View;

/** @var list<array<string, mixed>> $items */
/** @var array<string, string> $errors */
/** @var list<string> $methods */
/** @var list<array{name: string, label: string}> $gateways */
/** @var array<string, mixed> $site */
$error = static function (string $field) use ($errors): string {
    return isset($errors[$field])
        ? '<p class="error small" id="err-'.e($field).'" role="alert">'.e(t($errors[$field])).'</p>'
        : '';
};
$invalid = static fn (string $field): string => isset($errors[$field]) ? ' aria-invalid="true" aria-describedby="err-'.e($field).'"' : '';
$chosen = old('payment_method', (string) ($methods[0] ?? ''));
?>
<div class="page-head"><h1><?= e(t('checkout.title')) ?></h1></div>
<?= View::partial('steps', ['current' => 2]) ?>

<form class="checkout" action="/checkout" method="post" novalidate data-once>
    <?= csrf_field() ?>

    <fieldset>
        <legend><?= e(t('checkout.contact')) ?></legend>

        <label for="name"><?= e(t('checkout.name')) ?></label>
        <input id="name" name="name" autocomplete="name" required maxlength="160" value="<?= e(old('name')) ?>"<?= $invalid('name') ?>>
        <?= $error('name') ?>

        <label for="phone"><?= e(t('checkout.phone')) ?></label>
        <input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required maxlength="32" placeholder="01XXXXXXXXX" value="<?= e(old('phone')) ?>"<?= $invalid('phone') ?>>
        <?= $error('phone') ?>

        <label for="email"><?= e(t('checkout.email')) ?> <span class="muted"><?= e(t('checkout.optional')) ?></span></label>
        <input id="email" name="email" type="email" autocomplete="email" maxlength="254" value="<?= e(old('email')) ?>"<?= $invalid('email') ?>>
        <?= $error('email') ?>
    </fieldset>

    <fieldset>
        <legend><?= e(t('checkout.delivery')) ?></legend>

        <div class="areas" data-areas hidden>
            <p class="muted small"><?= e(t('checkout.area_help')) ?></p>
            <label for="area-division"><?= e(t('checkout.division')) ?></label>
            <select id="area-division" data-level="0"><option value=""><?= e(t('checkout.choose')) ?></option></select>
            <label for="area-district"><?= e(t('checkout.district')) ?></label>
            <select id="area-district" data-level="1" disabled><option value=""><?= e(t('checkout.choose')) ?></option></select>
            <label for="area-upazila"><?= e(t('checkout.upazila')) ?></label>
            <select id="area-upazila" data-level="2" disabled><option value=""><?= e(t('checkout.choose')) ?></option></select>
        </div>
        <input type="hidden" name="area_id" id="area_id" value="<?= e(old('area_id')) ?>">

        <label for="line1"><?= e(t('checkout.address')) ?></label>
        <input id="line1" name="line1" autocomplete="address-line1" required maxlength="255" value="<?= e(old('line1')) ?>"<?= $invalid('line1') ?>>
        <?= $error('line1') ?>

        <label for="line2"><?= e(t('checkout.address2')) ?> <span class="muted"><?= e(t('checkout.optional')) ?></span></label>
        <input id="line2" name="line2" autocomplete="address-line2" maxlength="255" value="<?= e(old('line2')) ?>">

        <div class="two">
            <div>
                <label for="city"><?= e(t('checkout.city')) ?></label>
                <input id="city" name="city" autocomplete="address-level2" required maxlength="120" value="<?= e(old('city')) ?>"<?= $invalid('city') ?>>
                <?= $error('city') ?>
            </div>
            <div>
                <label for="district"><?= e(t('checkout.district_text')) ?></label>
                <input id="district" name="district" autocomplete="address-level1" maxlength="120" value="<?= e(old('district')) ?>">
            </div>
        </div>

        <label for="postcode"><?= e(t('checkout.postcode')) ?> <span class="muted"><?= e(t('checkout.optional')) ?></span></label>
        <input id="postcode" name="postcode" autocomplete="postal-code" inputmode="numeric" maxlength="20" value="<?= e(old('postcode')) ?>">

        <label for="note"><?= e(t('checkout.note')) ?> <span class="muted"><?= e(t('checkout.optional')) ?></span></label>
        <textarea id="note" name="note" rows="3" maxlength="1000"><?= e(old('note')) ?></textarea>
    </fieldset>

    <fieldset>
        <legend><?= e(t('checkout.payment')) ?></legend>
        <?php if ($methods === []) { ?>
            <p class="error"><?= e(t('checkout.no_methods')) ?></p>
        <?php } ?>
        <?php foreach ($methods as $method) { ?>
            <label class="choice">
                <input type="radio" name="payment_method" value="<?= e($method) ?>" <?= $chosen === $method ? 'checked' : '' ?>>
                <span>
                    <strong><?= e(t('payment.'.$method)) ?></strong>
                    <span class="muted small"><?= e(t('payment.'.$method.'_help')) ?></span>
                </span>
            </label>
            <?php if ($method === 'online' && count($gateways) > 1) { ?>
                <div class="gateways" data-gateways<?= $chosen === 'online' ? '' : ' hidden' ?>>
                    <?php $chosenGateway = old('gateway', (string) $gateways[0]['name']); ?>
                    <?php foreach ($gateways as $gateway) { ?>
                        <label class="choice">
                            <input type="radio" name="gateway" value="<?= e($gateway['name']) ?>" <?= $chosenGateway === $gateway['name'] ? 'checked' : '' ?>>
                            <span><?= View::partial('gateway', ['gateway' => $gateway]) ?></span>
                        </label>
                    <?php } ?>
                </div>
            <?php } ?>
        <?php } ?>
        <?= $error('payment_method') ?>
    </fieldset>

    <div class="actions">
        <a class="btn btn-quiet" href="/cart"><?= e(t('checkout.back')) ?></a>
        <button class="btn btn-accent" type="submit" <?= $methods === [] ? 'disabled' : '' ?>><?= e(t('checkout.review')) ?></button>
    </div>
</form>
