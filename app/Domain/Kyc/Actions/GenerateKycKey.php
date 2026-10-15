<?php

namespace App\Domain\Kyc\Actions;

use App\Domain\Kyc\Models\KycBatch;
use App\Domain\Kyc\Models\KycBatchGroup;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycRequirementSlot;
use Illuminate\Support\Str;

/**
 * Stable handles for things an administrator names (D30).
 *
 * A key is what rounds, evidence and verdicts address a requirement, group or slot
 * by, so it is made once from the name and never changed when the name is edited —
 * renaming "National ID" to "NID card" must not orphan what applicants already
 * gave. Each is unique within its parent (requirements across the whole catalogue,
 * because the catalogue's key is globally unique).
 */
class GenerateKycKey
{
    public function forBatch(string $name): string
    {
        return $this->unique($this->slug($name, 'batch', 64), fn (string $key) => KycBatch::query()->where('key', $key)->exists(), 64);
    }

    public function forGroup(KycBatch $batch, string $name): string
    {
        return $this->unique(
            $this->slug($name, 'group', 48),
            fn (string $key) => KycBatchGroup::query()->where('kyc_batch_id', $batch->id)->where('key', $key)->exists(),
            48,
        );
    }

    public function forRequirement(KycBatch $batch, string $name): string
    {
        return $this->unique(
            $this->slug($batch->key.'_'.$this->slug($name, 'requirement', 40), 'requirement', 64),
            fn (string $key) => KycDocumentType::query()->where('key', $key)->exists(),
            64,
        );
    }

    public function forSlot(KycDocumentType $requirement, string $label): string
    {
        return $this->unique(
            $this->slug($label, 'item', 48),
            fn (string $key) => KycRequirementSlot::query()->where('kyc_document_type_id', $requirement->id)->where('key', $key)->exists(),
            48,
        );
    }

    /**
     * Lower-case letters, digits and underscores, so it is safe in a URL, a
     * column and a form field. A name with no Latin letters (Bangla) falls back to
     * the given word.
     */
    private function slug(string $name, string $fallback, int $limit): string
    {
        $slug = Str::of(Str::ascii($name))->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit($limit - 5, '')->toString();

        return $slug === '' ? $fallback : $slug;
    }

    /**
     * @param  callable(string): bool  $taken
     */
    private function unique(string $key, callable $taken, int $limit): string
    {
        $candidate = $key;

        for ($suffix = 2; $taken($candidate); $suffix++) {
            $candidate = Str::limit($key, $limit - strlen((string) $suffix) - 1, '').'_'.$suffix;
        }

        return $candidate;
    }
}
