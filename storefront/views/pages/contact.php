<?php /** @var array<string, mixed> $site */
$contact = $site['contact'] ?? [];
?>
<div class="page-head"><h1><?= e(t('page.contact')) ?></h1></div>
<article class="prose">
    <?php if (empty($contact['phone']) && empty($contact['email']) && empty($contact['address'])) { ?>
        <p><?= e(t('page.contact_empty')) ?></p>
    <?php } else { ?>
        <dl class="contact">
            <?php if (! empty($contact['phone'])) { ?><dt><?= e(t('contact.phone')) ?></dt><dd><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $contact['phone'])) ?>"><?= e($contact['phone']) ?></a></dd><?php } ?>
            <?php if (! empty($contact['email'])) { ?><dt><?= e(t('contact.email')) ?></dt><dd><a href="mailto:<?= e($contact['email']) ?>"><?= e($contact['email']) ?></a></dd><?php } ?>
            <?php if (! empty($contact['address'])) { ?><dt><?= e(t('contact.address')) ?></dt><dd><?= e($contact['address']) ?></dd><?php } ?>
        </dl>
    <?php } ?>
</article>
