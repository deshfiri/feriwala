<?php

/** @var array{name: string, label: string} $gateway */
$logo = '/assets/img/gateways/'.$gateway['name'].'.png';
$hasLogo = is_file(dirname(__DIR__, 2).'/public'.$logo);
?>
<?php if ($hasLogo) { ?>
    <img class="gateway-logo" src="<?= e($logo) ?>" alt="<?= e($gateway['label']) ?>" height="28">
<?php } else { ?>
    <strong><?= e($gateway['label']) ?></strong>
<?php } ?>
