<?php

namespace App\Http\Controllers\Admin\Cms;

use App\Domain\Cms\Actions\DeleteCmsMedia;
use App\Domain\Cms\Actions\UpdateCmsMedia;
use App\Domain\Cms\Actions\UploadCmsMedia;
use App\Domain\Cms\Exceptions\CmsMediaRefused;
use App\Domain\Cms\Models\Media;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\UpdateCmsMediaRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The CMS media library (§4, §34's media-safety requirements). Real
 * byte-sniffed MIME/size checking and required alt text are
 * {@see UploadCmsMedia}'s and {@see Media::hasRequiredAltText()}'s job —
 * this controller only surfaces the refusal.
 */
class MediaController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Media::class);

        $media = Media::query()->with('uploader')->orderByDesc('id')->get();

        return Inertia::render('admin/cms/media/index', [
            'media' => $media->map(fn (Media $item) => [
                'id' => $item->public_id,
                'url' => $item->url(),
                'original_filename' => $item->original_filename,
                'mime_type' => $item->mime_type,
                'size_bytes' => $item->size_bytes,
                'width' => $item->width,
                'height' => $item->height,
                'alt_text_en' => $item->alt_text_en,
                'alt_text_bn' => $item->alt_text_bn,
                'attribution' => $item->attribution,
                'has_required_alt_text' => $item->hasRequiredAltText(),
                'uploaded_by' => $item->uploader?->name,
            ]),

            'can' => [
                'create' => Gate::allows('create', Media::class),
            ],
        ]);
    }

    public function store(Request $request, UploadCmsMedia $upload): RedirectResponse
    {
        Gate::authorize('create', Media::class);

        $validated = $request->validate(['file' => ['required', 'file']]);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $upload->handle($validated['file'], $actor);
        } catch (CmsMediaRefused $refused) {
            throw ValidationException::withMessages(['file' => $refused->getMessage()]);
        }

        return back()->with('success', __('Image uploaded.'));
    }

    public function update(UpdateCmsMediaRequest $request, string $media, UpdateCmsMedia $update): RedirectResponse
    {
        Gate::authorize('update', Media::class);

        $validated = $request->validated();

        $update->handle(
            $this->mediaFor($media),
            $validated['alt_text_en'] ?? null,
            $validated['alt_text_bn'] ?? null,
            $validated['attribution'] ?? null,
        );

        return back()->with('success', __('Image details saved.'));
    }

    public function destroy(string $media, DeleteCmsMedia $delete): RedirectResponse
    {
        Gate::authorize('delete', Media::class);

        $delete->handle($this->mediaFor($media));

        return back()->with('success', __('Image removed.'));
    }

    protected function mediaFor(string $media): Media
    {
        return Media::query()->wherePublicId($media)->firstOrFail();
    }
}
