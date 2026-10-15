<?php /** @var array<string, mixed> $site */ ?>
<div class="page-head"><h1><?= e(t('page.about')) ?></h1></div>
<article class="prose">
    <?php $about = paragraphs((string) ($site['branding']['about'] ?? '')); ?>
    <?php if ($about === []) { ?>
        <p><?= e(t('page.about_empty', ['name' => (string) $site['name']])) ?></p>
    <?php } else { ?>
        <?php foreach ($about as $paragraph) { ?><p><?= e($paragraph) ?></p><?php } ?>
    <?php } ?>
</article>
