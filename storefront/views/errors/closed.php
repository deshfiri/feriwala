<?php
/** @var string $closedStatus */
/** @var string|null $message */
$key = match ($closedStatus) {
    'maintenance' => 'closed.maintenance',
    'preparing' => 'closed.preparing',
    'unreachable' => 'closed.unreachable',
    default => 'closed.unavailable',
};
?>
<div class="empty center">
    <h1><?= e(t($key.'_title')) ?></h1>
    <p><?= e($message !== null && $message !== '' ? $message : t($key.'_body')) ?></p>
    <?php if ($closedStatus === 'unreachable') { ?><a class="btn" href="javascript:location.reload()"><?= e(t('error.retry')) ?></a><?php } ?>
</div>
