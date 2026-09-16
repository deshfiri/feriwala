<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Website\Actions\ManageWebsiteBranding;
use App\Domain\Website\Enums\WebsiteTheme;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Queries\WebsiteOverview;
use App\Domain\Website\WebsiteImageStore;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Managing a storefront from the ERP (§16.3, P5-12, P5-14).
 *
 * The list §16.3 gives and nothing more: website information, logo, banner,
 * contact details, colours, basic branding and the theme. **There is no route
 * here that creates a product**, and that is the point rather than an
 * omission — §16.3 says a partner cannot create products through website
 * management, so the surface does not exist to be permitted (P5-14). Products
 * are selected from the central catalogue on their own screens.
 *
 * Self-scoped like every other website route: the website is found among this
 * account's or not at all.
 */
class WebsiteSettingsController extends Controller
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected WebsiteOverview $overview,
        protected WebsiteImageStore $images,
    ) {}

    public function edit(Request $request, string $website): Response
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        return Inertia::render('websites/settings', [
            'website' => $this->overview->detail($record),
            'themes' => array_map(fn (WebsiteTheme $theme) => [
                'value' => $theme->value,
                'label' => __('website.themes.'.$theme->value),
            ], WebsiteTheme::cases()),
            'image_limits' => [
                'max_bytes' => WebsiteImageStore::MAX_BYTES,
                'accepted' => WebsiteImageStore::ACCEPTED_MIME_TYPES,
            ],
        ]);
    }

    public function update(Request $request, string $website, ManageWebsiteBranding $branding): RedirectResponse
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'tagline' => ['nullable', 'string', 'max:160'],
            'about' => ['nullable', 'string', 'max:2000'],
            'theme' => ['required', Rule::enum(WebsiteTheme::class)],

            // Six hex digits, lowercase. The database holds the column to the
            // same shape, so a colour that reaches it is one a browser renders.
            'primary_color' => ['required', 'string', 'regex:/^#[0-9a-f]{6}$/'],
            'secondary_color' => ['required', 'string', 'regex:/^#[0-9a-f]{6}$/'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'contact_address' => ['nullable', 'string', 'max:500'],
        ]);

        $this->attempt(fn () => $branding->update($record, $this->person($request), [
            ...$validated,
            'theme' => WebsiteTheme::from($validated['theme']),
        ]));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.settings_saved')]);

        return to_route('websites.settings.edit', $record->public_id);
    }

    /**
     * Upload a logo or a banner.
     *
     * POST rather than PUT because a browser cannot send a file with PUT.
     */
    public function updateImage(
        Request $request,
        string $website,
        string $asset,
        ManageWebsiteBranding $branding,
    ): RedirectResponse {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $request->validate([
            'image' => [
                'required', 'file',
                'mimetypes:'.implode(',', WebsiteImageStore::ACCEPTED_MIME_TYPES),
                'max:'.(int) (WebsiteImageStore::MAX_BYTES / 1024),
            ],
        ]);

        $file = $request->file('image');

        abort_if(! $file instanceof UploadedFile, 422);

        $this->attempt(fn () => $branding->putImage($record, $this->person($request), $asset, $file));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.image_saved')]);

        return to_route('websites.settings.edit', $record->public_id);
    }

    public function destroyImage(
        Request $request,
        string $website,
        string $asset,
        ManageWebsiteBranding $branding,
    ): RedirectResponse {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $this->attempt(fn () => $branding->removeImage($record, $this->person($request), $asset));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.image_removed')]);

        return to_route('websites.settings.edit', $record->public_id);
    }

    /**
     * @param  callable(): mixed  $step
     */
    protected function attempt(callable $step): void
    {
        try {
            $step();
        } catch (WebsiteRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }
    }

    protected function websiteFor(Request $request, string $website): Website
    {
        $record = $this->overview->findForAccount($this->businessAccountFor($request), $website);

        abort_if($record === null, 404);

        return $record;
    }

    protected function person(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
