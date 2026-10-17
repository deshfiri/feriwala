<?php
/** @var string $content */
/** @var array<string, mixed> $site */
/** @var list<array<string, mixed>> $categories */
/** @var list<array{type: string, message: string}> $flash */
$brand = $site['branding'] ?? [];
$shopName = (string) ($site['name'] ?? app_name());
$pageTitle = isset($title) && $title !== '' && $title !== $shopName ? $title.' · '.$shopName : $shopName;
$logo = safe_url($brand['logo_url'] ?? null);
?>
<!doctype html>
<html lang="<?= e($locale ?? 'en') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <?php if (! empty($description)) { ?><meta name="description" content="<?= e($description) ?>"><?php } ?>
    <?php if (! empty($noindex)) { ?><meta name="robots" content="noindex, nofollow"><?php } ?>
    <meta name="theme-color" content="<?= e($brand['primary_color'] ?? '#1f2937') ?>">
    <?php if (! empty($image)) { ?>
        <meta property="og:image" content="<?= e(safe_url($image)) ?>">
        <meta property="og:title" content="<?= e($title ?? '') ?>">
    <?php } ?>
    <style>:root{--brand:<?= e($brand['primary_color'] ?? '#1f2937') ?>;--accent:<?= e($brand['secondary_color'] ?? '#c2410c') ?>}</style>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<a class="skip" href="#main"><?= e(t('nav.skip')) ?></a>

<header class="site-header">
    <div class="container header-row">
        <a class="brand" href="/" aria-label="<?= e($site['name'] ?? app_name()) ?>">
            <?php if ($logo !== '') { ?>
                <img src="<?= e($logo) ?>" alt="" height="36">
            <?php } ?>
            <span><?= e($site['name'] ?? app_name()) ?></span>
        </a>

        <form class="search" action="/search" method="get" role="search">
            <label class="sr-only" for="q"><?= e(t('nav.search')) ?></label>
            <input id="q" name="q" type="search" maxlength="100" value="<?= e($searchTerm ?? '') ?>" placeholder="<?= e(t('nav.search_placeholder')) ?>">
            <button type="submit"><?= e(t('nav.search')) ?></button>
        </form>

        <nav class="header-actions" aria-label="<?= e(t('nav.account')) ?>">
            <a href="/track"><?= e(t('nav.track')) ?></a>
            <form action="/locale" method="post" class="locale">
                <?= csrf_field() ?>
                <input type="hidden" name="locale" value="<?= ($locale ?? 'en') === 'bn' ? 'en' : 'bn' ?>">
                <button type="submit" class="link" lang="<?= ($locale ?? 'en') === 'bn' ? 'en' : 'bn' ?>"><?= e(($locale ?? 'en') === 'bn' ? 'English' : 'বাংলা') ?></button>
            </form>
            <a class="cart-link" href="/cart">
                <?= e(t('nav.cart')) ?>
                <?php if (($cartCount ?? 0) > 0) { ?><span class="badge" aria-label="<?= e(t('nav.cart_items', ['n' => (string) $cartCount])) ?>"><?= e(num($cartCount)) ?></span><?php } ?>
            </a>
        </nav>
    </div>

    <?php if (! empty($categories)) { ?>
        <nav class="categories" aria-label="<?= e(t('nav.categories')) ?>">
            <div class="container">
                <a href="/products"><?= e(t('nav.all')) ?></a>
                <?php foreach ($categories as $category) { ?>
                    <a href="/category/<?= e(rawurlencode((string) ($category['slug'] ?? ''))) ?>"><?= e($category['name'] ?? '') ?></a>
                <?php } ?>
            </div>
        </nav>
    <?php } ?>
</header>

<?php if (! ($site['accepting_orders'] ?? true)) { ?>
    <div class="notice notice-warning" role="status"><div class="container"><?= e(t('site.not_accepting')) ?></div></div>
<?php } ?>

<main id="main" class="container">
    <?php foreach ($flash ?? [] as $message) { ?>
        <div class="flash flash-<?= e($message['type']) ?>" role="<?= $message['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($message['message']) ?></div>
    <?php } ?>

    <?= $content ?>
</main>

<footer class="site-footer">
    <div class="container footer-grid">
        <div>
            <strong><?= e($site['name'] ?? app_name()) ?></strong>
            <?php if (! empty($brand['tagline'])) { ?><p><?= e($brand['tagline']) ?></p><?php } ?>
        </div>
        <div>
            <strong><?= e(t('footer.help')) ?></strong>
            <ul>
                <li><a href="/track"><?= e(t('nav.track')) ?></a></li>
                <li><a href="/about"><?= e(t('page.about')) ?></a></li>
                <li><a href="/contact"><?= e(t('page.contact')) ?></a></li>
            </ul>
        </div>
        <div>
            <strong><?= e(t('page.contact')) ?></strong>
            <ul>
                <?php if (! empty($site['contact']['phone'])) { ?><li><?= e($site['contact']['phone']) ?></li><?php } ?>
                <?php if (! empty($site['contact']['email'])) { ?><li><?= e($site['contact']['email']) ?></li><?php } ?>
                <?php if (! empty($site['contact']['address'])) { ?><li><?= e($site['contact']['address']) ?></li><?php } ?>
            </ul>
        </div>
    </div>
    <div class="container fine"><?= e(t('footer.rights', ['year' => gmdate('Y'), 'name' => (string) ($site['name'] ?? app_name())])) ?></div>
</footer>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
