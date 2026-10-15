<?php
use Storefront\Http\View;
use Storefront\Support\Money;

/** @var list<array<string, mixed>> $products */
/** @var array<string, mixed> $facets */
/** @var array<string, mixed> $filters */
/** @var array<string, mixed>|null $category */
/** @var string|null $nextCursor */
/** @var bool $hasMore */
/** @var string|null $error */
/** @var bool $hasCursor */
/** @var string|null $categorySlug */

// The current query, minus paging, so "load more" and the sort keep every filter.
$keep = static function (array $replace = []) use ($filters): string {
    $query = array_filter([
        'q' => $filters['q'] ?? '',
        'sort' => $filters['sort'] ?? '',
        'price_min' => $filters['price_min'] ?? '',
        'price_max' => $filters['price_max'] ?? '',
        'brand' => $filters['brand'] ?? '',
        'in_stock' => ($filters['in_stock'] ?? '') === 'true' ? '1' : '',
        'category' => ($filters['category'] ?? '') !== '' ? $filters['category'] : '',
        'attributes' => $filters['attributes'] ?? [],
    ], static fn ($v) => $v !== '' && $v !== []);

    foreach ($replace as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    return http_build_query($query);
};

$base = $categorySlug !== null ? '/category/'.rawurlencode($categorySlug) : (($filters['q'] ?? '') !== '' ? '/search' : '/products');
if ($categorySlug !== null) {
    $keepWithoutCategory = static fn (array $r = []) => $keep($r + ['category' => null]);
} else {
    $keepWithoutCategory = $keep;
}
$sorts = ['' => 'catalog.sort.default', 'newest' => 'catalog.sort.newest', 'price_asc' => 'catalog.sort.price_asc', 'price_desc' => 'catalog.sort.price_desc', 'name' => 'catalog.sort.name', 'best_selling' => 'catalog.sort.best_selling'];
$range = is_array($facets['price_range'] ?? null) ? $facets['price_range'] : null;
?>
<div class="page-head">
    <h1><?= e($title) ?></h1>
</div>

<div class="layout-catalog">
    <aside class="filters" aria-label="<?= e(t('catalog.filters')) ?>">
        <details open>
            <summary><?= e(t('catalog.filters')) ?></summary>
            <form method="get" action="<?= e($base) ?>">
                <?php if (($filters['q'] ?? '') !== '') { ?><input type="hidden" name="q" value="<?= e($filters['q']) ?>"><?php } ?>
                <?php if ($categorySlug === null && ($filters['category'] ?? '') !== '') { ?><input type="hidden" name="category" value="<?= e($filters['category']) ?>"><?php } ?>

                <label for="sort"><?= e(t('catalog.sort')) ?></label>
                <select id="sort" name="sort">
                    <?php foreach ($sorts as $value => $label) { ?>
                        <option value="<?= e($value) ?>" <?= ($filters['sort'] ?? '') === $value ? 'selected' : '' ?>><?= e(t($label)) ?></option>
                    <?php } ?>
                </select>

                <fieldset>
                    <legend><?= e(t('catalog.price')) ?></legend>
                    <div class="two">
                        <input name="price_min" inputmode="decimal" aria-label="<?= e(t('catalog.price_min')) ?>" placeholder="<?= e($range !== null ? Money::group((string) ($range['minimum']['amount'] ?? '')) : t('catalog.price_min')) ?>" value="<?= e($filters['price_min'] ?? '') ?>">
                        <input name="price_max" inputmode="decimal" aria-label="<?= e(t('catalog.price_max')) ?>" placeholder="<?= e($range !== null ? Money::group((string) ($range['maximum']['amount'] ?? '')) : t('catalog.price_max')) ?>" value="<?= e($filters['price_max'] ?? '') ?>">
                    </div>
                </fieldset>

                <?php if (! empty($facets['brands'])) { ?>
                    <label for="brand"><?= e(t('catalog.brand')) ?></label>
                    <select id="brand" name="brand">
                        <option value=""><?= e(t('catalog.any')) ?></option>
                        <?php foreach ($facets['brands'] as $brand) { ?>
                            <option value="<?= e($brand['slug'] ?? '') ?>" <?= ($filters['brand'] ?? '') === ($brand['slug'] ?? null) ? 'selected' : '' ?>>
                                <?= e($brand['name'] ?? '') ?><?= isset($brand['count']) ? ' ('.e(num((string) $brand['count'])).')' : '' ?>
                            </option>
                        <?php } ?>
                    </select>
                <?php } ?>

                <?php foreach ((array) ($facets['attributes'] ?? []) as $attribute) { ?>
                    <?php $slug = (string) ($attribute['slug'] ?? '');
                    if ($slug === '') {
                        continue;
                    } ?>
                    <label for="attr-<?= e($slug) ?>"><?= e($attribute['name'] ?? $slug) ?></label>
                    <select id="attr-<?= e($slug) ?>" name="attributes[<?= e($slug) ?>]">
                        <option value=""><?= e(t('catalog.any')) ?></option>
                        <?php foreach ((array) ($attribute['values'] ?? []) as $value) { ?>
                            <?php $label = (string) (is_array($value) ? ($value['value'] ?? $value['name'] ?? '') : $value); ?>
                            <option value="<?= e($label) ?>" <?= (($filters['attributes'][$slug] ?? '') === $label) ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php } ?>
                    </select>
                <?php } ?>

                <label class="check"><input type="checkbox" name="in_stock" value="1" <?= ($filters['in_stock'] ?? '') === 'true' ? 'checked' : '' ?>> <?= e(t('catalog.in_stock_only')) ?></label>

                <div class="actions">
                    <button class="btn" type="submit"><?= e(t('catalog.apply')) ?></button>
                    <a class="btn btn-quiet" href="<?= e($base === '/search' ? '/search?q='.rawurlencode((string) $filters['q']) : $base) ?>"><?= e(t('catalog.reset')) ?></a>
                </div>
            </form>
        </details>
    </aside>

    <section aria-live="polite">
        <?php if ($error !== null) { ?>
            <p class="flash flash-error" role="alert"><?= e(t($error)) ?></p>
        <?php } elseif ($products === []) { ?>
            <div class="empty">
                <p><?= e(t('catalog.no_results')) ?></p>
                <a class="btn" href="/products"><?= e(t('catalog.browse_all')) ?></a>
            </div>
        <?php } else { ?>
            <div class="grid">
                <?php foreach ($products as $product) { ?><?= View::partial('product_card', ['product' => $product]) ?><?php } ?>
            </div>

            <nav class="pager" aria-label="<?= e(t('catalog.pagination')) ?>">
                <?php if ($hasCursor) { ?>
                    <a class="btn btn-quiet" href="<?= e($base.($keepWithoutCategory() !== '' ? '?'.$keepWithoutCategory() : '')) ?>"><?= e(t('catalog.first_page')) ?></a>
                <?php } ?>
                <?php if ($hasMore && $nextCursor !== null) { ?>
                    <a class="btn" rel="next" href="<?= e($base.'?'.$keepWithoutCategory(['cursor' => $nextCursor])) ?>"><?= e(t('catalog.next')) ?></a>
                <?php } ?>
            </nav>
        <?php } ?>
    </section>
</div>
