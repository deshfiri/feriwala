<?php

namespace App\Domain\Kyc\Batch;

use Illuminate\Http\UploadedFile;

/**
 * A KYC round, seen through the one set of operations the batch engine needs
 * (D30).
 *
 * Two implementations, because D25 keeps Partner and Supplier data in separate
 * tables behind separate guards: {@see PartnerKycRound} and {@see SupplierKycRound}.
 * Everything that decides anything — which groups are satisfied, whether a file
 * is acceptable, whether an option may be changed — lives in the engine and runs
 * against this interface, so the rules cannot drift between the two audiences.
 */
interface KycRound
{
    /**
     * What this round asks for, or null for a round opened before batches were
     * versioned (which keeps its own flow).
     */
    public function definition(): ?KycRoundDefinition;

    /**
     * 'account' or 'supplier'.
     */
    public function audience(): string;

    /**
     * Whether the applicant may still change it.
     */
    public function isEditable(): bool;

    /**
     * Whether it has ever been submitted. A file replaced after that is kept,
     * marked superseded; before it, a replaced draft file is simply removed.
     */
    public function hasBeenSubmitted(): bool;

    /**
     * The option chosen in each "any one" / "minimum" group: group key to the
     * requirement keys chosen.
     *
     * @return array<string, array<int, string>>
     */
    public function selections(): array;

    /**
     * @param  array<string, array<int, string>>  $selections
     */
    public function saveSelections(array $selections): void;

    /**
     * Every piece of current evidence, keyed by {@see KycEvidenceItem::key()}.
     * Superseded evidence is not in it.
     *
     * @return array<string, KycEvidenceItem>
     */
    public function evidence(): array;

    /**
     * Store a file against a slot, replacing what the slot held.
     *
     * @param  array<string, mixed>|null  $captureMeta  safe technical facts about a live capture
     */
    public function attachFile(
        string $requirement,
        string $slot,
        UploadedFile $file,
        bool $capturedLive = false,
        ?array $captureMeta = null,
    ): void;

    /**
     * Record a typed value or a declaration against a slot, replacing what it
     * held.
     */
    public function saveValue(string $requirement, string $slot, string $value): void;

    /**
     * Take a slot's evidence off the round: removed outright while the round has
     * never been submitted, superseded (and kept) once it has.
     */
    public function discard(string $requirement, string $slot): void;
}
