<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Settings\Actions\ManageBranding;
use App\Domain\Settings\Branding;
use App\Domain\Settings\Enums\BrandingAsset;
use App\Domain\Settings\Policies\BrandingPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The platform's logo and browser icon.
 *
 * Every action asks {@see BrandingPolicy} before reading the request, so a
 * refused person meets a 403 rather than a validation message about the file
 * they sent.
 */
class BrandingController extends Controller
{
    public function __construct(
        protected Branding $branding,
        protected ManageBranding $manage,
    ) {}

    public function edit(Request $request): Response
    {
        $this->authorizeManager($request);

        return Inertia::render('admin/branding', [
            'assets' => array_map(fn (BrandingAsset $asset) => [
                'asset' => $asset->value,
                'url' => $this->branding->url($asset),
                'is_custom' => $this->branding->isCustom($asset),
                'extensions' => array_values(array_unique($asset->acceptedTypes())),
                'accept' => implode(',', array_keys($asset->acceptedTypes())),
                'max_kb' => $asset->maxKilobytes(),
            ], BrandingAsset::cases()),
        ]);
    }

    public function update(Request $request, string $asset): RedirectResponse
    {
        $actor = $this->authorizeManager($request);
        $kind = BrandingAsset::from($asset);

        $request->validate([
            'file' => [
                'required', 'file',
                // Read from the bytes: an SVG or a script renamed `.png` is refused.
                'mimetypes:'.implode(',', array_keys($kind->acceptedTypes())),
                'max:'.$kind->maxKilobytes(),
            ],
        ], [
            'file.mimetypes' => __('branding.refused_type'),
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        try {
            $this->manage->replace($actor, $kind, $file);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['file' => __('branding.refused_type')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('branding.saved', [
            'asset' => __("branding.{$kind->value}.title"),
        ])]);

        return back();
    }

    public function destroy(Request $request, string $asset): RedirectResponse
    {
        $actor = $this->authorizeManager($request);
        $kind = BrandingAsset::from($asset);

        $this->manage->restore($actor, $kind);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('branding.restored', [
            'asset' => __("branding.{$kind->value}.title"),
        ])]);

        return back();
    }

    protected function authorizeManager(Request $request): User
    {
        $actor = $request->user();

        abort_unless($actor instanceof User && BrandingPolicy::canManage($actor), 403);

        return $actor;
    }
}
