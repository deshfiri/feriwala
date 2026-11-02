<?php

namespace App\Http\Requests\Mobile\Websites;

use App\Domain\Website\BannerLink;
use App\Domain\Website\Enums\WebsiteTheme;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWebsiteSettingsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /** The shop's name. */
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'tagline' => ['nullable', 'string', 'max:160'],
            'about' => ['nullable', 'string', 'max:2000'],
            /** A value from `themes` on the settings endpoint. */
            'theme' => ['required', Rule::enum(WebsiteTheme::class)],
            /**
             * Six lowercase hex digits with a leading `#`.
             *
             * @example #0b0b0b
             */
            'primary_color' => ['required', 'string', 'regex:/^#[0-9a-f]{6}$/'],
            /**
             * @example #ff6600
             */
            'secondary_color' => ['required', 'string', 'regex:/^#[0-9a-f]{6}$/'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'contact_address' => ['nullable', 'string', 'max:500'],
            /** Where the banner image leads: an address on this shop, or an `https` URL. */
            'banner_link_url' => ['nullable', 'string', 'max:'.BannerLink::MAX_LENGTH, function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && trim($value) !== '' && ! BannerLink::isAcceptable(trim($value))) {
                    $fail(__('website.refused.banner_link_invalid'));
                }
            }],
        ];
    }
}
