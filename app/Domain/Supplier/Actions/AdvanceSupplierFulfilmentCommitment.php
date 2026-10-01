<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Supplier\Enums\FulfilmentCommitmentStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Models\SupplierFulfilmentCommitment;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Transitions on a Supplier's fulfilment commitment (Supplier Bulk Product
 * Listing batch, correction 7; Advanced Order Management batch, Commit 2).
 *
 * Every method takes the acting {@see SupplierStatusChangeSource}, defaulting
 * to `Staff` so every existing call (the admin Order Detail screen) keeps
 * moving exactly as before. `$actor` is nullable because a Supplier is its
 * own, separate `Authenticatable` (D25) with no `users` row at all —
 * `confirmed_by`/`cancelled_by`/`changed_by` stay staff-only columns and are
 * left null for a Supplier's own action, which `source` already identifies
 * precisely (there being exactly one Supplier behind any one commitment).
 * The Supplier's own self-service workspace passes
 * `SupplierStatusChangeSource::Supplier` and `actor: null`.
 *
 * Reaching {@see FulfilmentCommitmentStatus::Ready}, {@see
 * FulfilmentCommitmentStatus::Failed} or {@see
 * FulfilmentCommitmentStatus::Cancelled} is what frees the capacity this
 * commitment held -- {@see RecordSupplierFulfilmentCommitment} only ever
 * counts the non-terminal states, so no separate "release capacity" step
 * exists beyond the status transition itself. A Supplier "declining" an
 * assignment is this same `cancel()`, recorded with their own source — there
 * is no separate Declined status.
 */
class AdvanceSupplierFulfilmentCommitment
{
    public function confirm(
        SupplierFulfilmentCommitment $commitment,
        ?User $actor,
        SupplierStatusChangeSource $source = SupplierStatusChangeSource::Staff,
        ?CarbonImmutable $expectedReadyAt = null,
    ): SupplierFulfilmentCommitment {
        // The database's own "confirmation is recorded" CHECK is evaluated
        // against whatever transitionWithHistory()'s single UPDATE actually
        // writes -- forceFill() before it, never after, so confirmed_at
        // lands in the same statement as the status change, not a second one
        // the CHECK cannot yet see.
        $commitment->forceFill([
            'confirmed_by' => $actor?->id,
            'confirmed_at' => now(),
            'expected_ready_at' => $expectedReadyAt,
        ]);
        $commitment->transitionWithHistory(
            FulfilmentCommitmentStatus::Confirmed,
            new StatusChange(actorId: $actor?->id, reason: 'Confirmed by '.$source->label().'.'),
            ['source' => $source],
        );

        return $commitment->refresh();
    }

    public function startPreparing(
        SupplierFulfilmentCommitment $commitment,
        ?User $actor,
        SupplierStatusChangeSource $source = SupplierStatusChangeSource::Staff,
    ): SupplierFulfilmentCommitment {
        $commitment->transitionWithHistory(
            FulfilmentCommitmentStatus::Preparing,
            new StatusChange(actorId: $actor?->id, reason: 'Preparation started.'),
            ['source' => $source],
        );

        return $commitment->refresh();
    }

    public function markReady(
        SupplierFulfilmentCommitment $commitment,
        ?User $actor,
        SupplierStatusChangeSource $source = SupplierStatusChangeSource::Staff,
    ): SupplierFulfilmentCommitment {
        $commitment->transitionWithHistory(
            FulfilmentCommitmentStatus::Ready,
            new StatusChange(actorId: $actor?->id, reason: 'Ready for dispatch.'),
            ['source' => $source],
        );

        return $commitment->refresh();
    }

    public function fail(
        SupplierFulfilmentCommitment $commitment,
        ?User $actor,
        string $reason,
        SupplierStatusChangeSource $source = SupplierStatusChangeSource::Staff,
    ): SupplierFulfilmentCommitment {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required and is recorded against this failure.');
        }

        $commitment->forceFill(['failed_reason' => $reason, 'failed_at' => now()]);
        $commitment->transitionWithHistory(
            FulfilmentCommitmentStatus::Failed,
            new StatusChange(actorId: $actor?->id, reason: $reason),
            ['source' => $source],
        );

        return $commitment->refresh();
    }

    public function cancel(
        SupplierFulfilmentCommitment $commitment,
        ?User $actor,
        string $reason,
        SupplierStatusChangeSource $source = SupplierStatusChangeSource::Staff,
    ): SupplierFulfilmentCommitment {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required and is recorded against this cancellation.');
        }

        $commitment->forceFill(['cancelled_by' => $actor?->id, 'cancelled_reason' => $reason, 'cancelled_at' => now()]);
        $commitment->transitionWithHistory(
            FulfilmentCommitmentStatus::Cancelled,
            new StatusChange(actorId: $actor?->id, reason: $reason),
            ['source' => $source],
        );

        return $commitment->refresh();
    }
}
