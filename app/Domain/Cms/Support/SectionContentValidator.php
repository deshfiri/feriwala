<?php

namespace App\Domain\Cms\Support;

use App\Domain\Cms\Enums\SectionKind;
use App\Domain\Cms\Rules\SafeCtaHref;
use App\Domain\Cms\Rules\SafeMenuUrl;
use Illuminate\Support\Facades\Validator;
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

        return match ($kind) {
            SectionKind::HeaderNav => [],

            SectionKind::Hero => [
                ...$localized('heading'),
                ...$localized('subheading', false),
                ...$localized('body', false),
                ...$cta('primary_cta'),
                ...$cta('secondary_cta', false),
            ],

            SectionKind::PlatformIntroduction, SectionKind::Dropshipping,
            SectionKind::Wholesale, SectionKind::PartnerWebsites,
            SectionKind::SupplierOpportunity => [
                ...$localized('heading'),
                ...$localized('body', false),
                'bullets' => ['nullable', 'array', 'max:8'],
                ...$localized('bullets.*', false),
                ...$cta('cta', false),
            ],

            SectionKind::Benefits => [
                ...$localized('heading'),
                'items' => ['required', 'array', 'min:1', 'max:8'],
                'items.*.icon' => ['required', 'string', 'in:'.implode(',', self::ALLOWED_ICONS)],
                ...$localized('items.*.heading'),
                ...$localized('items.*.body', false),
            ],

            SectionKind::HowItWorks => [
                ...$localized('heading'),
                'steps' => ['required', 'array', 'min:2', 'max:8'],
                'steps.*.step_number' => ['required', 'integer', 'min:1', 'max:8'],
                ...$localized('steps.*.heading'),
                ...$localized('steps.*.body', false),
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
            SectionKind::Hero, SectionKind::PlatformIntroduction,
            SectionKind::Dropshipping, SectionKind::Wholesale,
            SectionKind::PartnerWebsites, SectionKind::SupplierOpportunity,
            SectionKind::PackagePreview, SectionKind::Cta => ['body'],
            SectionKind::Faq => ['items.*.answer'],
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
