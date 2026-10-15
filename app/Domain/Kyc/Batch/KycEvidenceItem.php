<?php

namespace App\Domain\Kyc\Batch;

use Carbon\CarbonInterface;

/**
 * One piece of evidence currently on a round, in the same shape whether it
 * belongs to a Partner or a Supplier (D30).
 *
 * Carries what a screen needs to say that it is there — a name, a size, whether
 * the selfie was a live capture, a masked value — and never the stored path, the
 * checksum or a typed value in full.
 */
final class KycEvidenceItem
{
    public const FILE = 'file';

    public const VALUE = 'value';

    /**
     * @param  'file'|'value'  $kind
     * @param  string|null  $verdict  'accepted' or 'rejected': the latest verdict that still applies to this evidence
     */
    public function __construct(
        public readonly string $requirement,
        public readonly string $slot,
        public readonly string $kind,
        public readonly CarbonInterface $recordedAt,
        public readonly ?string $documentId = null,
        public readonly ?string $name = null,
        public readonly ?int $sizeBytes = null,
        public readonly ?string $mime = null,
        public readonly bool $capturedLive = false,
        public readonly ?string $maskedValue = null,
        public readonly ?string $verdict = null,
        public readonly ?string $note = null,
        public readonly ?string $ref = null,
    ) {}

    public function key(): string
    {
        return self::keyFor($this->requirement, $this->slot);
    }

    /**
     * What a verdict names to say which evidence it judged: a document's public id,
     * or a typed value's row. Never shown to anyone.
     */
    public static function refForField(int $id): string
    {
        return 'field:'.$id;
    }

    public static function keyFor(string $requirement, string $slot): string
    {
        return $requirement.'|'.$slot;
    }

    public function isRejected(): bool
    {
        return $this->verdict === 'rejected';
    }

    public function isAccepted(): bool
    {
        return $this->verdict === 'accepted';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'requirement' => $this->requirement,
            'slot' => $this->slot,
            'kind' => $this->kind,
            'document' => $this->documentId,
            'name' => $this->name,
            'size_bytes' => $this->sizeBytes,
            'live' => $this->capturedLive,
            'value_preview' => $this->maskedValue,
            'verdict' => $this->verdict,
            'note' => $this->note,
        ];
    }
}
