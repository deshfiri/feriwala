<div class="page-head"><h1><?= e(t('track.title')) ?></h1></div>

<form class="checkout narrow" action="/track" method="post">
    <?= csrf_field() ?>
    <p class="muted"><?= e(t('track.help')) ?></p>

    <label for="reference"><?= e(t('track.reference')) ?></label>
    <input id="reference" name="reference" required maxlength="64" autocomplete="off" placeholder="ORD-… / SF-…">

    <label for="mobile"><?= e(t('track.mobile')) ?></label>
    <input id="mobile" name="mobile" type="tel" inputmode="tel" required maxlength="32" autocomplete="tel">

    <button class="btn btn-accent" type="submit"><?= e(t('track.find')) ?></button>
</form>
