<?php

namespace App\Domain\Cms\Support;

use App\Domain\Cms\Enums\MenuLocation;
use App\Domain\Cms\Models\Menu;
use App\Domain\Cms\Models\MenuItem;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Models\PageRevision;
use App\Domain\Cms\Models\SeoSetting;
use App\Domain\Package\Models\Package;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The one server-side query that turns a page's frozen revision into what a
 * visitor actually sees (§4, §34's "published-page reader" requirement).
 *
 * Never reads `cms_page_sections` (the live, editable draft) — only
 * `Page::currentPublishedRevision`, so a draft-in-progress edit is invisible
 * to this class by construction, not by a check it could forget to run.
 *
 * Whether a revision is live right now is a **timestamp comparison, run
 * fresh on every call** — never cached — because a scheduled start or end
 * time takes effect the instant it passes, not the instant a cache entry
 * expires. Only the expensive part (the resolved, locale-picked section
 * content) is cached, keyed by the revision's own immutable id: a new
 * publish always produces a new revision id and therefore a new cache key,
 * so there is nothing to invalidate by hand and no way for a stale publish
 * to survive a cache hit.
 */
class PublishedPageReader
{
    public function __construct(protected LocalizedContentResolver $resolver) {}

    /**
     * @return array{seo: array<string, mixed>, sections: array<int, array<string, mixed>>, structured_data: array<int, array<string, mixed>>, menus: array<string, array<int, array<string, mixed>>>}|null
     *                                                                                                                                                                                                      null when nothing is live to show for this slug right now.
     */
    public function render(string $slug, string $locale): ?array
    {
        $page = Page::query()
            ->where('slug', $slug)
            ->with('currentPublishedRevision')
            ->first();

        if ($page === null) {
            return null;
        }

        $revision = $page->currentPublishedRevision;

        if ($revision === null || ! $this->isLiveNow($page, $revision)) {
            return null;
        }

        $resolved = Cache::remember(
            "cms:page:{$revision->id}:{$locale}",
            now()->addMinutes(30),
            fn () => $this->resolveRevision($revision, $locale),
        );

        return [
            ...$resolved,
            'menus' => $this->menus($locale),
        ];
    }

    protected function isLiveNow(Page $page, PageRevision $revision): bool
    {
        if ($page->publication_state->value === 'unpublished') {
            return false;
        }

        if ($revision->published_at === null || $revision->published_at->isFuture()) {
            return false;
        }

        $unpublishAt = $page->scheduled_unpublish_at;

        return ! ($unpublishAt !== null && $unpublishAt->isPast());
    }

    /**
     * @return array{seo: array<string, mixed>, sections: array<int, array<string, mixed>>, structured_data: array<int, array<string, mixed>>}
     */
    protected function resolveRevision(PageRevision $revision, string $locale): array
    {
        $snapshot = $revision->content->getArrayCopy();

        /** @var array<int, array<string, mixed>> $rawSections */
        $rawSections = $snapshot['sections'] ?? [];

        $enabled = array_values(array_filter(
            $rawSections,
            fn (array $section) => $section['is_enabled'] ?? true,
        ));

        usort($enabled, fn (array $a, array $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));

        $sections = array_map(fn (array $section) => [
            'key' => $section['section_key'],
            'kind' => $section['kind'],
            'variant' => $section['variant'] ?? null,
            'visible_on_desktop' => $section['visible_on_desktop'] ?? true,
            'visible_on_mobile' => $section['visible_on_mobile'] ?? true,
            'content' => $this->resolver->resolve($section['content'] ?? [], $locale),
        ], $enabled);

        $seo = $this->seo($snapshot['seo'] ?? [], $locale);

        return [
            'seo' => $seo,
            'sections' => $sections,
            'structured_data' => $this->structuredData($seo, $sections),
        ];
    }

    /**
     * schema.org JSON-LD for the page (§34, Stage 8 completion): a WebSite
     * entry always, an Organization entry whenever the global SEO defaults
     * name one, and a FAQPage entry whenever an enabled `faq` section is
     * actually present in `$sections` — which, by the time this runs, is
     * already filtered to enabled sections from a published revision only
     * (see `resolveRevision()` above), so a disabled or draft FAQ can never
     * reach this.
     *
     * @param  array<string, mixed>  $seo
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<string, mixed>>
     */
    protected function structuredData(array $seo, array $sections): array
    {
        $schemas = [
            [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => $seo['organization_name'] ?? $seo['title'],
                'url' => url('/'),
            ],
        ];

        if ($seo['organization_name'] !== null) {
            $organization = [
                '@context' => 'https://schema.org',
                '@type' => 'Organization',
                'name' => $seo['organization_name'],
                'url' => $seo['organization_url'] ?? url('/'),
            ];

            if ($seo['organization_logo_url'] !== null) {
                $organization['logo'] = $seo['organization_logo_url'];
            }

            $schemas[] = $organization;
        }

        $faqItems = collect($sections)->firstWhere('kind', 'faq')['content']['items'] ?? [];

        if ($faqItems !== []) {
            $schemas[] = [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => array_map(fn (array $item) => [
                    '@type' => 'Question',
                    'name' => $item['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $item['answer'],
                    ],
                ], $faqItems),
            ];
        }

        return $schemas;
    }

    /**
     * @param  array<string, mixed>  $pageSeo
     * @return array<string, mixed>
     */
    protected function seo(array $pageSeo, string $locale): array
    {
        $defaults = SeoSetting::query()->where('locale', $locale)->first();

        $defaultTitle = $defaults instanceof SeoSetting ? $defaults->default_title : config('app.name');
        $defaultDescription = $defaults instanceof SeoSetting ? $defaults->default_description : null;
        $defaultOgImage = $defaults instanceof SeoSetting ? $defaults->ogImageUrl() : null;
        $defaultRobots = $defaults instanceof SeoSetting ? $defaults->robots_default : 'index, follow';
        $organizationName = $defaults instanceof SeoSetting ? $defaults->organization_name : null;
        $organizationUrl = $defaults instanceof SeoSetting ? $defaults->organization_url : null;
        $organizationLogo = $defaults instanceof SeoSetting ? $defaults->organizationLogoUrl() : null;
        $twitterHandle = $defaults instanceof SeoSetting ? $defaults->twitter_handle : null;

        return [
            'title' => $pageSeo['title'][$locale] ?? $pageSeo['title']['en'] ?? $defaultTitle,
            'description' => $pageSeo['description'][$locale] ?? $pageSeo['description']['en'] ?? $defaultDescription,
            'canonical_url' => $pageSeo['canonical_url'] ?? null,
            // Already a resolved public URL, frozen at publish time by
            // PublishPage::snapshot() -- this reader never queries the
            // media table for a page-level override (Stage 7 addendum).
            'og_image_url' => $pageSeo['og_image_url'] ?? $defaultOgImage,
            'robots' => $pageSeo['robots'] ?? $defaultRobots,
            'organization_name' => $organizationName,
            'organization_url' => $organizationUrl,
            'organization_logo_url' => $organizationLogo,
            'twitter_handle' => $twitterHandle,
        ];
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function menus(string $locale): array
    {
        $result = [];

        foreach (MenuLocation::cases() as $location) {
            $menu = Menu::query()->where('location', $location->value)->with('items.children')->first();

            $result[$location->value] = $menu === null ? [] : $this->menuItems($menu->items, $locale);
        }

        return $result;
    }

    /**
     * @param  Collection<int, MenuItem>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function menuItems($items, string $locale): array
    {
        return $items
            ->filter(fn ($item) => $item->is_enabled)
            ->map(fn ($item) => [
                'key' => $item->public_id,
                'label' => $item->label($locale),
                'href' => $item->href(),
                'target' => $item->link_target,
                'children' => $this->menuItems($item->children, $locale),
            ])
            ->filter(fn (array $item) => $item['href'] !== null)
            ->values()
            ->all();
    }

    /**
     * Real, current package data for the `package_preview` section — never
     * duplicated into a section's own JSON, so a price change is reflected
     * the moment it happens rather than waiting for someone to re-publish
     * the landing page (§34's "do not duplicate package prices" rule).
     *
     * @return array<int, array<string, mixed>>
     */
    public function publicPackagePreviews(): array
    {
        return Package::query()
            ->publiclyListed()
            ->get()
            ->map(fn (Package $package) => [
                'key' => $package->public_id,
                'name' => $package->name,
                'short_description' => $package->short_description,
                'fee' => $package->fee->jsonSerialize(),
                'validity_days' => $package->validity_days,
            ])
            ->all();
    }
}
