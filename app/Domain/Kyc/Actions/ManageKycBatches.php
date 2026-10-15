<?php

namespace App\Domain\Kyc\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Kyc\Exceptions\InvalidKycBatch;
use App\Domain\Kyc\Models\KycBatch;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Create and keep verification batches (§7.2).
 *
 * Every change is audited with the batch's own fields only — never a document,
 * a path or anything an applicant supplied.
 *
 * Changing a batch's audience carries its criteria with it: a criterion's
 * audience is always its batch's, so the two can never disagree about who is
 * asked.
 */
class ManageKycBatches
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
        protected PublishKycBatchVersion $publisher,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array{package?: string|null, country?: string|null}>  $rules
     */
    public function create(array $attributes, array $rules, User $by): KycBatch
    {
        return $this->database->transaction(function () use ($attributes, $rules, $by) {
            $batch = KycBatch::create([
                ...$attributes,
                'sort_order' => $attributes['sort_order'] ?? $this->nextSortOrder(),
            ]);

            $this->syncRules($batch, $rules);

            $this->record('kyc.batch_created', $batch, $by, after: $batch->getAttributes());

            return $batch;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array{package?: string|null, country?: string|null}>  $rules
     */
    public function update(KycBatch $batch, array $attributes, array $rules, User $by): KycBatch
    {
        if ($batch->isArchived()) {
            // An archived batch is part of a historical record. Editing it would
            // change what past submissions appear to have asked for.
            throw new InvalidArgumentException('An archived batch cannot be edited. Create a new one instead.');
        }

        return $this->database->transaction(function () use ($batch, $attributes, $rules, $by) {
            $before = $batch->getOriginal();

            $batch->fill($attributes)->save();
            $changes = $batch->getChanges();

            // A criterion's audience is its batch's — and the default is per
            // audience, so a batch that moves to another gives its place up.
            if (array_key_exists('audience', $changes)) {
                $batch->types()->update(['audience' => $batch->audience->value]);
                $batch->forceFill(['is_default' => false])->save();
            }

            $this->syncRules($batch, $rules);

            $this->record(
                'kyc.batch_updated',
                $batch,
                $by,
                before: array_intersect_key($before, $changes),
                after: $changes,
            );

            return $batch;
        });
    }

    public function setActive(KycBatch $batch, bool $isActive, User $by): KycBatch
    {
        if ($batch->isArchived() && $isActive) {
            throw new InvalidArgumentException('An archived batch cannot be reactivated.');
        }

        // A switched-off batch cannot be the default: new rounds would be pinned
        // to something applicants are not meant to see.
        $batch->forceFill(['is_active' => $isActive, ...($isActive ? [] : ['is_default' => false])])->save();

        $this->record('kyc.batch_updated', $batch, $by, after: ['is_active' => $isActive]);

        return $batch;
    }

    /**
     * Switch a batch on for the first time or again: its current structure is
     * published as a version (so what goes live is exactly what was previewed) and
     * only then does it become available to applicants.
     *
     * @throws InvalidKycBatch when an applicant could never complete it
     */
    public function activate(KycBatch $batch, User $by): KycBatch
    {
        if ($batch->isArchived()) {
            throw new InvalidArgumentException(__('kyc.batch_builder.refused.archived'));
        }

        $this->publisher->handle($batch, $by);

        return $this->setActive($batch->refresh(), true, $by);
    }

    /**
     * Make a batch the one a new round for its audience opens with when staff
     * choose none. One per audience: the previous default is released in the same
     * step, and the database holds the same line.
     */
    public function setDefault(KycBatch $batch, bool $isDefault, User $by): KycBatch
    {
        if ($isDefault && ($batch->isArchived() || ! $batch->is_active || $batch->published_version < 1)) {
            // A default nobody could be given — switched off, retired or never
            // published — would quietly leave new rounds on the older flow.
            throw new InvalidArgumentException(__('kyc.batch_builder.refused.default_unavailable'));
        }

        return $this->database->transaction(function () use ($batch, $isDefault, $by) {
            if ($isDefault) {
                KycBatch::query()
                    ->where('audience', $batch->audience->value)
                    ->where('is_default', true)
                    ->whereKeyNot($batch->id)
                    ->update(['is_default' => false]);
            }

            $batch->forceFill(['is_default' => $isDefault])->save();

            $this->record('kyc.batch_default_changed', $batch, $by, after: ['audience' => $batch->audience->value, 'is_default' => $isDefault]);

            return $batch;
        });
    }

    public function archive(KycBatch $batch, User $by): KycBatch
    {
        if ($batch->isArchived()) {
            return $batch;
        }

        return $this->database->transaction(function () use ($batch, $by) {
            // Archiving implies deactivation, and takes the criteria out with it:
            // an active criterion in an archived batch would be one the form
            // could never show.
            $batch->forceFill(['archived_at' => now(), 'is_active' => false])->save();
            $batch->types()->whereNull('archived_at')->update(['archived_at' => now(), 'is_active' => false]);

            $this->record('kyc.batch_archived', $batch, $by, after: ['archived_at' => $batch->archived_at?->toIso8601String()]);

            return $batch;
        });
    }

    public function delete(KycBatch $batch, User $by): void
    {
        if ($batch->isReferenced()) {
            throw new InvalidArgumentException(
                'This batch has criteria or has been used on a submission. Archive it instead of deleting it.'
            );
        }

        $this->database->transaction(function () use ($batch, $by) {
            $this->record('kyc.batch_deleted', $batch, $by, before: $batch->getAttributes());

            $batch->scopes()->delete();
            $batch->delete();
        });
    }

    /**
     * @param  array<int, string>  $publicIdsInOrder
     */
    public function reorder(array $publicIdsInOrder, User $by): void
    {
        $this->database->transaction(function () use ($publicIdsInOrder, $by) {
            foreach (array_values($publicIdsInOrder) as $position => $publicId) {
                KycBatch::query()->where('public_id', $publicId)->update(['sort_order' => $position]);
            }

            $this->audit->handle(new AuditEntry(
                action: 'kyc.batches_reordered',
                actorId: $by->id,
                after: ['order' => array_values($publicIdsInOrder)],
                module: 'kyc',
            ));
        });
    }

    /**
     * Replace the batch's rules. A rule naming neither a package nor a country is
     * not a rule, so it is dropped.
     *
     * @param  array<int, array{package?: string|null, country?: string|null}>  $rules
     */
    protected function syncRules(KycBatch $batch, array $rules): void
    {
        $batch->scopes()->delete();

        foreach ($rules as $rule) {
            $package = filled($rule['package'] ?? null) ? $rule['package'] : null;
            $country = filled($rule['country'] ?? null) ? mb_strtoupper((string) $rule['country']) : null;

            if ($package === null && $country === null) {
                continue;
            }

            $batch->scopes()->create(['package_public_id' => $package, 'country_code' => $country]);
        }
    }

    protected function nextSortOrder(): int
    {
        return (int) KycBatch::query()->max('sort_order') + 1;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    protected function record(string $action, KycBatch $batch, User $by, ?array $before = null, ?array $after = null): void
    {
        $this->audit->handle(new AuditEntry(
            action: $action,
            actorId: $by->id,
            auditableType: KycBatch::class,
            auditableId: $batch->id,
            before: $before,
            after: $after,
            module: 'kyc',
        ));
    }
}
