<?php

namespace App\Domain\Kyc\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Creating and maintaining the KYC requirement catalogue (§7.2).
 *
 * The rule that shapes all of it: **a type a submission has ever referenced is
 * never deleted**. Removing it would leave a reviewed round describing a
 * requirement nobody can name any more, and a decision that cannot be read is a
 * decision that cannot be defended (§7.3, §36.2). Such a type is archived.
 *
 * Archiving and deactivating are different acts and both exist. Deactivating
 * pauses a requirement that may come back — a document withdrawn while a
 * regulator consults on it. Archiving retires one for good. Collapsing them
 * would mean an administrator turning something off temporarily could not tell
 * it apart from one that has been retired.
 */
class ManageKycDocumentTypes
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, User $by): KycDocumentType
    {
        return $this->database->transaction(function () use ($attributes, $by) {
            $type = KycDocumentType::create([
                ...$attributes,
                'sort_order' => $attributes['sort_order'] ?? $this->nextSortOrder(),
            ]);

            $this->record('kyc.document_type_created', $type, $by, after: $type->getAttributes());

            return $type;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(KycDocumentType $type, array $attributes, User $by): KycDocumentType
    {
        if ($type->isArchived()) {
            // An archived type is part of a historical record. Editing it would
            // change what past rounds appear to have asked for — which is the
            // whole reason archiving exists rather than deleting.
            throw new InvalidArgumentException(
                'An archived document type cannot be edited. Create a new one instead.'
            );
        }

        return $this->database->transaction(function () use ($type, $attributes, $by) {
            $before = $type->getOriginal();

            $type->fill($attributes)->save();

            $this->record(
                'kyc.document_type_updated',
                $type,
                $by,
                before: array_intersect_key($before, $type->getChanges()),
                after: $type->getChanges(),
            );

            return $type;
        });
    }

    /**
     * Pause or resume a requirement.
     */
    public function setActive(KycDocumentType $type, bool $isActive, User $by): KycDocumentType
    {
        if ($type->isArchived() && $isActive) {
            throw new InvalidArgumentException(
                'An archived document type cannot be reactivated.'
            );
        }

        return $this->update($type, ['is_active' => $isActive], $by);
    }

    /**
     * Retire a type for good.
     */
    public function archive(KycDocumentType $type, User $by): KycDocumentType
    {
        if ($type->isArchived()) {
            return $type;
        }

        return $this->database->transaction(function () use ($type, $by) {
            $type->forceFill([
                'archived_at' => now(),
                // Archiving implies deactivation. A type left "active but
                // archived" would be a contradiction the form would have to
                // resolve on its own, and it would resolve it differently in
                // each place that asked.
                'is_active' => false,
            ])->save();

            $this->record('kyc.document_type_archived', $type, $by, after: ['archived_at' => $type->archived_at?->toIso8601String()]);

            return $type;
        });
    }

    /**
     * Remove a type entirely — only ever one nothing refers to.
     *
     * @throws InvalidArgumentException when a submission has referenced it
     */
    public function delete(KycDocumentType $type, User $by): void
    {
        if ($type->isReferenced()) {
            throw new InvalidArgumentException(
                'This document type has been used on a submission. Archive it instead of deleting it.'
            );
        }

        $this->database->transaction(function () use ($type, $by) {
            $this->record('kyc.document_type_deleted', $type, $by, before: $type->getAttributes());

            $type->scopes()->delete();
            $type->delete();
        });
    }

    /**
     * Set the order requirements appear in on the form.
     *
     * Written in one transaction, because a half-applied reorder leaves two
     * types claiming the same position and the form ordering silently falling
     * back to insertion order.
     *
     * @param  array<int, string>  $publicIdsInOrder
     */
    public function reorder(array $publicIdsInOrder, User $by): void
    {
        $this->database->transaction(function () use ($publicIdsInOrder, $by) {
            foreach (array_values($publicIdsInOrder) as $position => $publicId) {
                KycDocumentType::query()
                    ->where('public_id', $publicId)
                    ->update(['sort_order' => $position]);
            }

            $this->audit->handle(new AuditEntry(
                action: 'kyc.document_types_reordered',
                actorId: $by->id,
                after: ['order' => array_values($publicIdsInOrder)],
                module: 'kyc',
            ));
        });
    }

    protected function nextSortOrder(): int
    {
        return (int) KycDocumentType::query()->max('sort_order') + 1;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    protected function record(
        string $action,
        KycDocumentType $type,
        User $by,
        ?array $before = null,
        ?array $after = null,
    ): void {
        $this->audit->handle(new AuditEntry(
            action: $action,
            actorId: $by->id,
            auditableType: KycDocumentType::class,
            auditableId: $type->id,
            before: $before,
            after: $after,
            module: 'kyc',
        ));
    }
}
