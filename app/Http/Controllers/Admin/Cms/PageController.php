<?php

namespace App\Http\Controllers\Admin\Cms;

use App\Domain\Cms\Actions\PublishPage;
use App\Domain\Cms\Actions\UnpublishPage;
use App\Domain\Cms\Actions\UpdatePageMeta;
use App\Domain\Cms\Enums\SectionKind;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Models\PageRevision;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\SavePageMetaRequest;
use App\Models\User;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The CMS page workspace (§4, §34, Stage 7). A page's own row is thin (see
 * {@see Page}), so this controller assembles the full editing picture from
 * three places: the page's live, editable sections; its append-only
 * revision history; and its draft-side SEO override.
 */
class PageController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Page::class);

        $pages = Page::query()->orderBy('slug')->get();

        return Inertia::render('admin/cms/pages/index', [
            'pages' => $pages->map(fn (Page $page) => [
                'id' => $page->public_id,
                'slug' => $page->slug,
                'page_type' => $page->page_type,
                'publication_state' => [
                    'value' => $page->publication_state->value,
                    'label' => $page->publication_state->label(),
                    'tone' => $page->publication_state->tone(),
                ],
                'scheduled_publish_at' => $page->scheduled_publish_at?->toIso8601String(),
            ]),
        ]);
    }

    public function edit(Page $page): Response
    {
        Gate::authorize('view', $page);

        $page->load(['sections', 'revisions.creator', 'revisions.restoredFrom']);

        $overrides = $page->seo_overrides?->getArrayCopy() ?? [];

        return Inertia::render('admin/cms/pages/edit', [
            'page' => [
                'id' => $page->public_id,
                'slug' => $page->slug,
                'page_type' => $page->page_type,
                'publication_state' => [
                    'value' => $page->publication_state->value,
                    'label' => $page->publication_state->label(),
                    'tone' => $page->publication_state->tone(),
                ],
                'scheduled_publish_at' => $page->scheduled_publish_at?->toIso8601String(),
                'seo_overrides' => [
                    'title' => $overrides['title'] ?? ['en' => null, 'bn' => null],
                    'description' => $overrides['description'] ?? ['en' => null, 'bn' => null],
                    'canonical_url' => $overrides['canonical_url'] ?? null,
                    'og_image_url' => $overrides['og_image_url'] ?? null,
                    'robots' => $overrides['robots'] ?? null,
                ],
            ],

            'sections' => $page->sections->map(fn ($section) => [
                'id' => $section->public_id,
                'section_key' => $section->section_key,
                'kind' => $section->kind->value,
                'sort_order' => $section->sort_order,
                'is_enabled' => $section->is_enabled,
                'visible_on_desktop' => $section->visible_on_desktop,
                'visible_on_mobile' => $section->visible_on_mobile,
                'variant' => $section->variant,
                'content' => $section->content->getArrayCopy(),
            ]),

            // Every revision, not paginated — a page is published occasionally,
            // not continuously, so this list stays short in practice.
            'revisions' => $page->revisions->map(fn (PageRevision $revision) => [
                'id' => $revision->public_id,
                'version' => $revision->version,
                'publication_state' => [
                    'value' => $revision->publication_state->value,
                    'label' => $revision->publication_state->label(),
                    'tone' => $revision->publication_state->tone(),
                ],
                'published_at' => $revision->published_at?->toIso8601String(),
                'created_by' => $revision->creator?->name,
                'reason' => $revision->reason,
                'restored_from_version' => $revision->restoredFrom?->version,
                'content' => $revision->content->getArrayCopy(),
            ]),

            'section_kinds' => array_map(fn (SectionKind $kind) => [
                'value' => $kind->value,
                'label' => $kind->label(),
            ], SectionKind::implemented()),

            'can' => [
                'edit' => Gate::allows('update', $page),
                'publish' => Gate::allows('publish', $page),
                'unpublish' => Gate::allows('unpublish', $page),
                'delete' => Gate::allows('delete', $page),
            ],
        ]);
    }

    public function updateMeta(SavePageMetaRequest $request, Page $page, UpdatePageMeta $update): RedirectResponse
    {
        Gate::authorize('update', $page);

        $update->handle($page, $request->validated(), $this->actor($request));

        return back()->with('success', __('SEO settings saved as a draft. Publish to make them live.'));
    }

    public function publish(Request $request, Page $page, PublishPage $publish): RedirectResponse
    {
        Gate::authorize('publish', $page);

        $validated = $request->validate([
            'publish_at' => ['nullable', 'date', 'after_or_equal:now'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $publishAt = filled($validated['publish_at'] ?? null)
            ? CarbonImmutable::parse($validated['publish_at'])
            : null;

        $publish->handle($page, $this->actor($request), $validated['reason'] ?? null, $publishAt);

        return back()->with('success', $publishAt !== null
            ? __('Publish scheduled.')
            : __('Page published.'));
    }

    public function unpublish(Request $request, Page $page, UnpublishPage $unpublish): RedirectResponse
    {
        Gate::authorize('unpublish', $page);

        try {
            $unpublish->handle($page, $this->actor($request));
        } catch (IllegalStateTransition) {
            throw ValidationException::withMessages(['page' => __('This page is not currently published.')]);
        }

        return back()->with('success', __('Page unpublished.'));
    }

    protected function actor(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
