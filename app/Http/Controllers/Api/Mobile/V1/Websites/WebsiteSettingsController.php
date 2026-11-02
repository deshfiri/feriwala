<?php

namespace App\Http\Controllers\Api\Mobile\V1\Websites;

use App\Domain\Website\Actions\ManageWebsiteBranding;
use App\Domain\Website\Actions\SetWebsiteSmsVerification;
use App\Domain\Website\BannerLink;
use App\Domain\Website\CheckoutVerification;
use App\Domain\Website\Enums\WebsiteTheme;
use App\Domain\Website\Queries\WebsiteOverview;
use App\Domain\Website\WebsiteImageStore;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Controllers\Api\Mobile\V1\Websites\Concerns\ResolvesOwnWebsite;
use App\Http\Requests\Mobile\Websites\UpdateSmsVerificationRequest;
use App\Http\Requests\Mobile\Websites\UpdateWebsiteSettingsRequest;
use App\Http\Requests\Mobile\Websites\UploadWebsiteImageRequest;
use App\Http\Resources\Mobile\Websites\WebsiteDetailResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * Managing a storefront from the app (§16.3).
 *
 * The list §16.3 gives and nothing more: website information, logo, banner,
 * contact details, colours and the theme. There is no endpoint here that creates
 * a product — §16.3 forbids it and §12 makes catalogue authorship a platform
 * privilege. Self-scoped like every website endpoint.
 */
class WebsiteSettingsController extends MobileController
{
    use ResolvesOwnWebsite;

    public function __construct(
        protected WebsiteOverview $overview,
        protected CheckoutVerification $verification,
    ) {}

    /**
     * Storefront settings
     *
     * The website with the choices its settings form needs: the themes, the limits for
     * a logo or banner upload, and the checkout SMS-verification setting (`enabled` is
     * the shop's own choice, `locked` says the platform's policy overrides it,
     * `effective` is what applies). `403` without the right to manage the website.
     *
     * @response array{data: WebsiteDetailResource, themes: list<array{value: string, label: string}>, image_limits: array{max_bytes: int, accepted: list<string>}, sms_verification: array{enabled: bool, locked: bool, effective: bool}}
     */
    public function show(Request $request, string $website): JsonResponse
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        return new JsonResponse([
            'data' => (new WebsiteDetailResource($this->overview->detail($record)))->resolve($request),
            'themes' => array_map(fn (WebsiteTheme $theme) => [
                'value' => $theme->value,
                'label' => __('website.themes.'.$theme->value),
            ], WebsiteTheme::cases()),
            'image_limits' => [
                'max_bytes' => WebsiteImageStore::MAX_BYTES,
                'accepted' => WebsiteImageStore::ACCEPTED_MIME_TYPES,
            ],
            'sms_verification' => [
                'enabled' => $record->sms_verification_enabled,
                'locked' => $this->verification->lockedFor($record),
                'effective' => $this->verification->requiredFor($record),
            ],
        ]);
    }

    /**
     * Save storefront settings
     *
     * Saves the shop's information, branding, contact details and theme in one write and
     * returns the website. `403` without the right to manage it; `422` for an invalid
     * colour, theme or banner link.
     */
    public function update(UpdateWebsiteSettingsRequest $request, string $website, ManageWebsiteBranding $branding): WebsiteDetailResource
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $validated = $request->validated();
        $validated['banner_link_url'] = BannerLink::normalise($validated['banner_link_url'] ?? null);

        $this->attempt(fn () => $branding->update($record, $this->person($request), [
            ...$validated,
            'theme' => WebsiteTheme::from($validated['theme']),
        ]));

        return new WebsiteDetailResource($this->overview->detail($record->refresh()));
    }

    /**
     * Set checkout SMS verification
     *
     * Whether this storefront's cash-on-delivery customers confirm with an SMS code at
     * checkout, and returns the website. The platform's own policy may override the
     * shop's choice (see `sms_verification.locked` on the settings endpoint). `403`
     * without the right to manage the website.
     */
    public function updateSmsVerification(UpdateSmsVerificationRequest $request, string $website, SetWebsiteSmsVerification $verification): WebsiteDetailResource
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $this->attempt(fn () => $verification->handle($record, $this->person($request), (bool) $request->validated('enabled')));

        return new WebsiteDetailResource($this->overview->detail($record->refresh()));
    }

    /**
     * Upload a logo or banner
     *
     * Sends the picture as `multipart/form-data` (`image`: JPEG, PNG or WebP, 2 MB at
     * most) and returns the website with the address it is now served from. `asset` is
     * `logo` or `banner`; a new picture replaces the old one. `403` without the right to
     * manage the website; `422` for a file that is not an accepted picture.
     */
    public function updateImage(UploadWebsiteImageRequest $request, string $website, string $asset, ManageWebsiteBranding $branding): WebsiteDetailResource
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $file = $request->file('image');

        abort_if(! $file instanceof UploadedFile, 422);

        $this->attempt(fn () => $branding->putImage($record, $this->person($request), $asset, $file));

        return new WebsiteDetailResource($this->overview->detail($record->refresh()));
    }

    /**
     * Remove a logo or banner
     *
     * Removes the stored picture and returns the website. `asset` is `logo` or `banner`.
     * `403` without the right to manage the website.
     */
    public function destroyImage(Request $request, string $website, string $asset, ManageWebsiteBranding $branding): WebsiteDetailResource
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $this->attempt(fn () => $branding->removeImage($record, $this->person($request), $asset));

        return new WebsiteDetailResource($this->overview->detail($record->refresh()));
    }
}
