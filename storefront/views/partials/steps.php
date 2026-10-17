<?php
/** @var int $current 1 = cart, 2 = your details, 3 = review */
$steps = [1 => 'steps.cart', 2 => 'steps.details', 3 => 'steps.review'];
?>
<ol class="steps" aria-label="<?= e(t('steps.label')) ?>">
    <?php foreach ($steps as $number => $label) { ?>
        <li<?= $number === $current ? ' aria-current="step"' : '' ?> class="<?= $number < $current ? 'done' : ($number === $current ? 'now' : '') ?>">
            <span class="step-dot" aria-hidden="true"><?= e(num((string) $number)) ?></span>
            <span><?= e(t($label)) ?></span>
        </li>
    <?php } ?>
</ol>
