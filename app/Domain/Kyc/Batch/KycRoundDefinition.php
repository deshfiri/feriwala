<?php

namespace App\Domain\Kyc\Batch;

use App\Domain\Kyc\Enums\KycSelfieMode;
use App\Domain\Kyc\Models\KycBatchVersion;

/**
 * What a round asks for: the structure of the batch version it was opened
 * against, and nothing else (D30).
 *
 * Read-only by construction. A round never consults the live batch, the live
 * catalogue or today's settings — only this — which is what makes "editing a
 * batch does not change a round already open" a property of the code rather than
 * a promise.
 *
 * The selfie is not a requirement an administrator creates; it is a setting of
 * the batch. It appears here under a fixed requirement and slot key so evidence,
 * review and completion treat it like everything else.
 */
final class KycRoundDefinition
{
    public const SELFIE_REQUIREMENT = '__selfie';

    public const SELFIE_SLOT = 'capture';

    /** What a captured selfie may be: a still image from the camera, no PDF. */
    public const SELFIE_MIMES = ['image/jpeg', 'image/png'];

    public const SELFIE_MAX_KB = 3072;

    /**
     * @param  array<string, mixed>  $data  the published definition
     */
    public function __construct(
        public readonly array $data,
        public readonly int $batchId,
        public readonly int $version,
    ) {}

    public static function fromVersion(KycBatchVersion $version): self
    {
        return new self($version->definition, $version->kyc_batch_id, $version->version);
    }

    public function batchKey(): string
    {
        return $this->data['batch']['key'];
    }

    public function batchName(?string $locale = null): string
    {
        return self::localized($this->data['batch'], 'name', $locale);
    }

    public function batchDescription(?string $locale = null): ?string
    {
        $text = self::localized($this->data['batch'], 'description', $locale);

        return $text === '' ? null : $text;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function groups(): array
    {
        return $this->data['groups'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function group(string $key): ?array
    {
        foreach ($this->groups() as $group) {
            if ($group['key'] === $key) {
                return $group;
            }
        }

        return null;
    }

    /**
     * The group an option sits in.
     *
     * @return array<string, mixed>|null
     */
    public function groupOf(string $requirementKey): ?array
    {
        foreach ($this->groups() as $group) {
            foreach ($group['requirements'] as $requirement) {
                if ($requirement['key'] === $requirementKey) {
                    return $group;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function requirement(string $key): ?array
    {
        foreach ($this->groups() as $group) {
            foreach ($group['requirements'] as $requirement) {
                if ($requirement['key'] === $key) {
                    return $requirement;
                }
            }
        }

        return null;
    }

    /**
     * One slot of a requirement. The selfie answers to its own fixed keys.
     *
     * @return array<string, mixed>|null
     */
    public function slot(string $requirementKey, string $slotKey): ?array
    {
        if ($requirementKey === self::SELFIE_REQUIREMENT) {
            return $slotKey === self::SELFIE_SLOT && $this->selfieMode()->isAsked() ? $this->selfieSlot() : null;
        }

        foreach ($this->requirement($requirementKey)['slots'] ?? [] as $slot) {
            if ($slot['key'] === $slotKey) {
                return $slot;
            }
        }

        return null;
    }

    public function selfieMode(): KycSelfieMode
    {
        return KycSelfieMode::from($this->data['batch']['selfie']['mode']);
    }

    public function selfieAllowsUpload(): bool
    {
        return (bool) $this->data['batch']['selfie']['allows_upload'];
    }

    /**
     * The selfie, shaped as a slot.
     *
     * @return array<string, mixed>
     */
    public function selfieSlot(): array
    {
        return [
            'key' => self::SELFIE_SLOT,
            'label' => __('kyc.batch.selfie_label'),
            'label_bn' => null,
            'instructions' => __('kyc.batch.selfie_instructions'),
            'instructions_bn' => null,
            'required' => $this->selfieMode() === KycSelfieMode::Required,
            'evidence' => 'image',
            'mimes' => self::SELFIE_MIMES,
            'max_kb' => self::SELFIE_MAX_KB,
            'value_label' => null,
            'value_label_bn' => null,
            'sensitive' => false,
            'sort' => 0,
        ];
    }

    /**
     * An item's text in the reader's language, falling back to English when the
     * Bangla is empty rather than showing a blank.
     *
     * @param  array<string, mixed>  $item
     */
    public static function localized(array $item, string $field, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        if (str_starts_with($locale, 'bn')) {
            $translated = $item[$field.'_bn'] ?? null;

            if (is_string($translated) && $translated !== '') {
                return $translated;
            }
        }

        return (string) ($item[$field] ?? '');
    }
}
