<?php

namespace App\Http\Controllers\Admin\Cms;

use App\Domain\Cms\Actions\SaveSeoSetting;
use App\Domain\Cms\Models\Media;
use App\Domain\Cms\Models\SeoSetting;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\SaveSeoSettingRequest;
use App\Support\Localization\Locale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The global, per-locale SEO defaults every page's own override falls back
 * to (§34). One row per supported locale, edited in place rather than as a
 * list — there is never a choice of which row to open.
 */
class SeoSettingController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', SeoSetting::class);

        $settings = SeoSetting::query()->get()->keyBy('locale');

        return Inertia::render('admin/cms/seo/index', [
            'settings' => collect(Locale::cases())->map(function (Locale $locale) use ($settings) {
                $setting = $settings->get($locale->value);

                return [
                    'locale' => $locale->value,
                    'default_title' => $setting?->default_title,
                    'default_description' => $setting?->default_description,
                    'default_og_image_id' => $setting?->default_og_image_id,
                    'organization_name' => $setting?->organization_name,
                    'organization_logo_id' => $setting?->organization_logo_id,
                    'organization_url' => $setting?->organization_url,
                    'robots_default' => $setting === null ? 'index, follow' : $setting->robots_default,
                    'twitter_handle' => $setting?->twitter_handle,
                ];
            })->values(),

            'media' => Gate::allows('viewAny', Media::class)
                ? Media::query()->orderByDesc('id')->get()->map(fn (Media $item) => [
                    'id' => $item->public_id,
                    'url' => $item->url(),
                    'original_filename' => $item->original_filename,
                    'mime_type' => $item->mime_type,
                    'width' => $item->width,
                    'height' => $item->height,
                    'alt_text_en' => $item->alt_text_en,
                    'alt_text_bn' => $item->alt_text_bn,
                ])
                : [],

            'can' => [
                'update' => Gate::allows('update', SeoSetting::class),
                'view_media' => Gate::allows('viewAny', Media::class),
                'manage_media' => Gate::allows('create', Media::class),
            ],
        ]);
    }

    public function update(SaveSeoSettingRequest $request, string $locale, SaveSeoSetting $save): RedirectResponse
    {
        Gate::authorize('update', SeoSetting::class);

        if (! in_array($locale, array_map(fn (Locale $case) => $case->value, Locale::cases()), true)) {
            throw ValidationException::withMessages(['locale' => __('Unsupported locale.')]);
        }

        $save->handle($locale, $request->validated());

        return back()->with('success', __('SEO defaults saved.'));
    }
}
