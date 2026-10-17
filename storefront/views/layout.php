<?php
/** @var string $content */
/** @var array<string, mixed> $site */
/** @var list<array<string, mixed>> $categories */
/** @var list<array{type: string, message: string}> $flash */
$brand = $site['branding'] ?? [];
$shopName = (string) ($site['name'] ?? app_name());
$pageTitle = isset($title) && $title !== '' && $title !== $shopName ? $title.' · '.$shopName : $shopName;
$logo = safe_url($brand['logo_url'] ?? null);
$primary = (string) ($brand['primary_color'] ?? '#1f2937');
$secondary = (string) ($brand['secondary_color'] ?? '#c2410c');
$initial = mb_strtoupper(mb_substr(trim($shopName) === '' ? '?' : trim($shopName), 0, 1));
$other = ($locale ?? 'en') === 'bn' ? 'en' : 'bn';
?>
<!doctype html>
<html lang="<?= e($locale ?? 'en') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <?php if (! empty($description)) { ?><meta name="description" content="<?= e($description) ?>"><?php } ?>
    <?php if (! empty($noindex)) { ?><meta name="robots" content="noindex, nofollow"><?php } ?>
    <meta name="theme-color" content="<?= e($primary) ?>">
    <?php if (! empty($image)) { ?>
        <meta property="og:image" content="<?= e(safe_url($image)) ?>">
        <meta property="og:title" content="<?= e($title ?? '') ?>">
    <?php } ?>
    <style>:root{--brand:<?= e($primary) ?>;--on-brand:<?= e(on_colour($primary)) ?>;--accent:<?= e($secondary) ?>;--on-accent:<?= e(on_colour($secondary)) ?>}</style>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<a class="skip" href="#main"><?= e(t('nav.skip')) ?></a>

<header class="site-header">
    <div class="container header-row">
        <a class="brand" href="/" aria-label="<?= e($shopName) ?>">
            <?php if ($logo !== '') { ?>
                <img class="brand-logo" src="<?= e($logo) ?>" alt="" width="160" height="40" data-fallback="brand-mark">
            <?php } ?>
            <span class="brand-mark" aria-hidden="true"<?= $logo !== '' ? ' hidden' : '' ?>><?= e($initial) ?></span>
            <span class="brand-name"><?= e($shopName) ?></span>
        </a>

        <form class="search" action="/search" method="get" role="search">
            <label class="sr-only" for="q"><?= e(t('nav.search')) ?></label>
            <input id="q" name="q" type="search" maxlength="100" value="<?= e($searchTerm ?? '') ?>" placeholder="<?= e(t('nav.search_placeholder')) ?>">
            <button type="submit"><?= e(t('nav.search')) ?></button>
        </form>

        <nav class="header-actions" aria-label="<?= e(t('nav.account')) ?>">
            <a class="hide-small" href="/track"><?= e(t('nav.track')) ?></a>
            <form action="/locale" method="post" class="locale hide-small">
                <?= csrf_field() ?>
                <input type="hidden" name="locale" value="<?= e($other) ?>">
                <button type="submit" class="link" lang="<?= e($other) ?>"><?= e($other === 'en' ? 'English' : 'বাংলা') ?></button>
            </form>
            <a class="account-link hide-small" href="/track" aria-label="<?= e(t('nav.track')) ?>" title="<?= e(t('nav.track')) ?>">
                <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><circle cx="12" cy="8" r="3.6" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M4.5 20c.9-3.6 3.8-5.4 7.5-5.4s6.6 1.8 7.5 5.4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </a>
            <a class="cart-link" href="/cart">
                <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M3 4h2l2.4 11.2a1 1 0 0 0 1 .8h8.9a1 1 0 0 0 1-.76L20 8H6.2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9.5" cy="20" r="1.4" fill="currentColor"/><circle cx="17" cy="20" r="1.4" fill="currentColor"/></svg>
                <span class="cart-text"><?= e(t('nav.cart')) ?></span>
                <?php if (($cartCount ?? 0) > 0) { ?><span class="badge" aria-label="<?= e(t('nav.cart_items', ['n' => (string) $cartCount])) ?>"><?= e(num($cartCount)) ?></span><?php } ?>
            </a>
            <details class="menu">
                <summary aria-label="<?= e(t('nav.menu_open')) ?>">
                    <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M4 7h16M4 12h16M4 17h16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <span class="sr-only"><?= e(t('nav.menu')) ?></span>
                </summary>
                <div class="menu-panel">
                    <a href="/track"><?= e(t('nav.track')) ?></a>
                    <a href="/about"><?= e(t('page.about')) ?></a>
                    <a href="/contact"><?= e(t('page.contact')) ?></a>
                    <form action="/locale" method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="locale" value="<?= e($other) ?>">
                        <button type="submit" class="link" lang="<?= e($other) ?>"><?= e($other === 'en' ? 'English' : 'বাংলা') ?></button>
                    </form>
                </div>
            </details>
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
            <strong><?= e($shopName) ?></strong>
            <?php if (! empty($brand['tagline'])) { ?><p class="muted"><?= e($brand['tagline']) ?></p><?php } ?>
        </div>
        <div>
            <strong><?= e(t('footer.shop')) ?></strong>
            <ul>
                <li><a href="/products"><?= e(t('nav.all')) ?></a></li>
                <li><a href="/cart"><?= e(t('nav.cart')) ?></a></li>
                <li><a href="/track"><?= e(t('nav.track')) ?></a></li>
            </ul>
        </div>
        <div>
            <strong><?= e(t('footer.help')) ?></strong>
            <ul>
                <li><a href="/about"><?= e(t('page.about')) ?></a></li>
                <li><a href="/contact"><?= e(t('page.contact')) ?></a></li>
                <?php if (! empty($site['contact']['phone'])) { ?><li><?= e($site['contact']['phone']) ?></li><?php } ?>
                <?php if (! empty($site['contact']['email'])) { ?><li><?= e($site['contact']['email']) ?></li><?php } ?>
                <?php if (! empty($site['contact']['address'])) { ?><li><?= e($site['contact']['address']) ?></li><?php } ?>
            </ul>
        </div>
    </div>
    <div class="container fine footer-bottom">
        <span><?= e(t('footer.rights', ['year' => gmdate('Y'), 'name' => $shopName])) ?></span>
        <span><?= e(t('footer.powered')) ?></span>
        <a class="to-top" href="#main"><?= e(t('footer.top')) ?> ↑</a>
    </div>
</footer>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
