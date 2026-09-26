<?php

namespace App\Domain\Cms\Support;

use App\Domain\Cms\Enums\SectionKind;
use App\Domain\Cms\Models\Media;
use App\Domain\Cms\Rules\MediaHasRequiredAltText;
use App\Domain\Cms\Rules\SafeCtaHref;
use App\Domain\Cms\Rules\SafeMenuUrl;
use App\Domain\Cms\Rules\SafeVideoUrl;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The one place a section's `content` shape is defined and checked (§34).
 *
 * A localized text field is always `{en: string, bn: string|null}` — `bn`
 * may be blank at draft time, but {@see LocalizedText}
 * falls back to `en` at render time rather than showing a blank string, the
 * same fallback philosophy `HandleInertiaRequests::loadTranslations()` already
 * applies to UI copy.
 *
 * A CTA's `href` accepts only a route name already registered or a
 * site-relative path — never an arbitrary external URL, so a page section
 * cannot be used to send a visitor somewhere Feriwala does not control
 * (menus, which are allowed a safe external URL, go through
 * {@see SafeMenuUrl} instead).
 *
 * A media field (Stage 7 addendum) is always the CMS media library's own
 * `public_id` — never a storage path or an unrestricted URL — validated
 * against the live `cms_media` table so a stale or fabricated id is refused
 * at save time, not discovered when the page fails to render. Fields follow
 * one naming convention throughout, which is what lets
 * {@see MediaReferenceWalker} and {@see MediaSnapshotResolver} work without
 * a per-kind registry: `media_id` for the primary image/asset, and
 * `<name>_media_id` for anything else (`mobile_media_id`,
 * `poster_media_id`).
 */
class SectionContentValidator
{
    public function __construct(protected HtmlSanitizer $sanitizer) {}

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed> the validated, sanitized content
     */
    public function validate(SectionKind $kind, array $content): array
    {
        if (! $kind->hasRenderer()) {
            throw ValidationException::withMessages([
                'kind' => "The \"{$kind->value}\" section kind has no content schema yet.",
            ]);
        }

        $validated = Validator::make($content, $this->rulesFor($kind))->validate();

        return $this->sanitizeRichFields($kind, $validated);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rulesFor(SectionKind $kind): array
    {
        $localized = fn (string $prefix, bool $required = true) => [
            "{$prefix}.en" => [$required ? 'required' : 'nullable', 'string', 'max:2000'],
            "{$prefix}.bn" => ['nullable', 'string', 'max:2000'],
        ];

        $cta = fn (string $prefix, bool $required = true) => [
            ...$localized("{$prefix}.label", $required),
            "{$prefix}.href" => [$required ? 'required' : 'nullable', 'string', 'max:255', new SafeCtaHref],
        ];

        $media = fn (string $field = 'media_id', bool $required = false) => [
            $field => [
                $required ? 'required' : 'nullable', 'string',
                Rule::exists(Media::class, 'public_id'),
                new MediaHasRequiredAltText,
            ],
        ];

        $mediaPosition = fn (array $allowed = ['left', 'right', 'background']) => [
            'media_position' => ['nullable', 'string', Rule::in($allowed)],
        ];

        $mediaFit = [
            'media_fit' => ['nullable', 'string', Rule::in(['cover', 'contain'])],
        ];

        return match ($kind) {
            SectionKind::HeaderNav => [],

            SectionKind::Hero => [
                ...$localized('heading'),
                ...$localized('subheading', false),
                ...$localized('body', false),
                ...$cta('primary_cta'),
                ...$cta('secondary_cta', false),
                ...$media(),
                ...$media('mobile_media_id'),
                ...$mediaPosition(),
                ...$mediaFit,
                ...$localized('media_alt_override', false),
            ],

            SectionKind::About => [
                ...$localized('heading'),
                ...$localized('body'),
                ...$media(),
                ...$mediaPosition(['left', 'right']),
                ...$mediaFit,
            ],

            SectionKind::PlatformIntroduction, SectionKind::Dropshipping,
            SectionKind::Wholesale, SectionKind::PartnerWebsites,
            SectionKind::SupplierOpportunity => [
                ...$localized('heading'),
                ...$localized('body', false),
                'bullets' => ['nullable', 'array', 'max:8'],
                ...$localized('bullets.*', false),
                ...$cta('cta', false),
                ...$media(),
                ...$mediaPosition(['left', 'right']),
                ...$mediaFit,
            ],

            SectionKind::Benefits => [
                ...$localized('heading'),
                'items' => ['required', 'array', 'min:1', 'max:8'],
                'items.*.icon' => ['required', 'string', 'in:'.implode(',', self::ALLOWED_ICONS)],
                ...$localized('items.*.heading'),
                ...$localized('items.*.body', false),
                ...$media('items.*.media_id'),
            ],

            SectionKind::HowItWorks => [
                ...$localized('heading'),
                'steps' => ['required', 'array', 'min:2', 'max:8'],
                'steps.*.step_number' => ['required', 'integer', 'min:1', 'max:8'],
                ...$localized('steps.*.heading'),
                ...$localized('steps.*.body', false),
                ...$media('steps.*.media_id'),
            ],

            SectionKind::PackagePreview => [
                // Deliberately no package/price fields: real, current package
                // data is fetched live at render time
                // (App\Domain\Cms\Support\PublishedPageReader), never
                // duplicated into this JSON where it could go stale.
                ...$localized('heading'),
                ...$localized('body', false),
                ...$cta('cta', false),
            ],

            SectionKind::Video => [
                ...$localized('heading', false),
                ...$localized('body', false),
                'video_url' => ['required', 'string', 'max:500', new SafeVideoUrl],
                ...$media('poster_media_id', required: true),
            ],

            SectionKind::Testimonials => [
                ...$localized('heading', false),
                'items' => ['required', 'array', 'min:1', 'max:12'],
                ...$localized('items.*.quote'),
                'items.*.author_name' => ['required', 'string', 'max:120'],
                ...$localized('items.*.author_role', false),
                ...$media('items.*.media_id'),
            ],

            SectionKind::ClientsPartners => [
                ...$localized('heading', false),
                'items' => ['required', 'array', 'min:1', 'max:24'],
                'items.*.name' => ['required', 'string', 'max:120'],
                ...$media('items.*.media_id', required: true),
            ],

            SectionKind::Faq => [
                ...$localized('heading'),
                'items' => ['required', 'array', 'min:1', 'max:20'],
                ...$localized('items.*.question'),
                ...$localized('items.*.answer'),
            ],

            SectionKind::Cta => [
                ...$localized('heading'),
                ...$localized('body', false),
                ...$cta('primary_cta'),
                ...$cta('secondary_cta', false),
                ...$media(),
                ...$mediaPosition(['left', 'right', 'background']),
                ...$mediaFit,
            ],

            SectionKind::Footer => [
                ...$localized('tagline', false),
                ...$localized('copyright_text'),
            ],

            default => throw ValidationException::withMessages([
                'kind' => "The \"{$kind->value}\" section kind has no content schema yet.",
            ]),
        };
    }

    /**
     * Icon names a section may reference — a closed set, never an arbitrary
     * component or class name a section's JSON could smuggle in.
     */
    protected const ALLOWED_ICONS = [
        'shield-check', 'wallet', 'truck', 'store', 'users', 'package',
        'trending-up', 'globe', 'lock', 'clock', 'layers', 'banknote',
    ];

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function sanitizeRichFields(SectionKind $kind, array $validated): array
    {
        $bodyPaths = match ($kind) {
            SectionKind::Hero, SectionKind::About,
            SectionKind::PlatformIntroduction,
            SectionKind::Dropshipping, SectionKind::Wholesale,
            SectionKind::PartnerWebsites, SectionKind::SupplierOpportunity,
            SectionKind::PackagePreview, SectionKind::Video, SectionKind::Cta => ['body'],
            SectionKind::Faq => ['items.*.answer'],
            SectionKind::Testimonials => ['items.*.quote'],
            default => [],
        };

        foreach ($bodyPaths as $path) {
            $validated = $this->sanitizePath($validated, explode('.', $path));
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $segments
     * @return array<string, mixed>
     */
    protected function sanitizePath(array $data, array $segments): array
    {
        [$segment, $rest] = [$segments[0], array_slice($segments, 1)];

        if ($segment === '*') {
            foreach ($data as $key => $value) {
                $data[$key] = $rest === [] ? $this->sanitizeLocalized($value) : $this->sanitizePath($value, $rest);
            }

            return $data;
        }

        if (! array_key_exists($segment, $data)) {
            return $data;
        }

        $data[$segment] = $rest === []
            ? $this->sanitizeLocalized($data[$segment])
            : $this->sanitizePath($data[$segment], $rest);

        return $data;
    }

    /**
     * @param  array<string, mixed>|null  $localized
     * @return array<string, mixed>|null
     */
    protected function sanitizeLocalized(?array $localized): ?array
    {
        if ($localized === null) {
            return null;
        }

        foreach (['en', 'bn'] as $locale) {
            if (isset($localized[$locale]) && is_string($localized[$locale])) {
                $localized[$locale] = $this->sanitizer->sanitize($localized[$locale]);
            }
        }

        return $localized;
    }
}
