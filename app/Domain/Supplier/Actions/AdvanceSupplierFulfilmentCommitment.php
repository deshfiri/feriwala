<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Supplier\Enums\FulfilmentCommitmentStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Models\SupplierFulfilmentCommitment;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use InvalidArgumentException;

/**
 * Staff transitions on a Supplier's fulfilment commitment (Supplier Bulk
 * Product Listing batch, correction 7).
 *
 * Reaching {@see FulfilmentCommitmentStatus::Ready}, {@see
 * FulfilmentCommitmentStatus::Failed} or {@see
 * FulfilmentCommitmentStatus::Cancelled} is what frees the capacity this
 * commitment held -- {@see RecordSupplierFulfilmentCommitment} only ever
 * counts the non-terminal states, so no separate "release capacity" step
 * exists beyond the status transition itself.
 */
class AdvanceSupplierFulfilmentCommitment
{
    public function confirm(SupplierFulfilmentCommitment $commitment, User $actor): SupplierFulfilmentCommitment
    {
        // The database's own "confirmation is recorded" CHECK is evaluated
        // against whatever transitionWithHistory()'s single UPDATE actually
        // writes -- forceFill() before it, never after, so confirmed_at
        // lands in the same statement as the status change, not a second one
        // the CHECK cannot yet see.
        $commitment->forceFill(['confirmed_by' => $actor->id, 'confirmed_at' => now()]);
        $commitment->transitionWithHistory(
            FulfilmentCommitmentStatus::Confirmed,
            new StatusChange(actorId: $actor->id, reason: 'Confirmed by staff.'),
            ['source' => SupplierStatusChangeSource::Staff],
        );

        return $commitment->refresh();
    }

    public function startPreparing(SupplierFulfilmentCommitment $commitment, User $actor): SupplierFulfilmentCommitment
    {
        $commitment->transitionWithHistory(
            FulfilmentCommitmentStatus::Preparing,
            new StatusChange(actorId: $actor->id, reason: 'Preparation started.'),
            ['source' => SupplierStatusChangeSource::Staff],
        );

        return $commitment->refresh();
    }

    public function markReady(SupplierFulfilmentCommitment $commitment, User $actor): SupplierFulfilmentCommitment
    {
        $commitment->transitionWithHistory(
            FulfilmentCommitmentStatus::Ready,
            new StatusChange(actorId: $actor->id, reason: 'Ready for dispatch.'),
            ['source' => SupplierStatusChangeSource::Staff],
        );

        return $commitment->refresh();
    }

    public function fail(SupplierFulfilmentCommitment $commitment, User $actor, string $reason): SupplierFulfilmentCommitment
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required and is recorded against this failure.');
        }

        $commitment->forceFill(['failed_reason' => $reason, 'failed_at' => now()]);
        $commitment->transitionWithHistory(
            FulfilmentCommitmentStatus::Failed,
            new StatusChange(actorId: $actor->id, reason: $reason),
            ['source' => SupplierStatusChangeSource::Staff],
        );

        return $commitment->refresh();
    }

    public function cancel(SupplierFulfilmentCommitment $commitment, User $actor, string $reason): SupplierFulfilmentCommitment
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required and is recorded against this cancellation.');
        }

        $commitment->forceFill(['cancelled_by' => $actor->id, 'cancelled_reason' => $reason, 'cancelled_at' => now()]);
        $commitment->transitionWithHistory(
            FulfilmentCommitmentStatus::Cancelled,
            new StatusChange(actorId: $actor->id, reason: $reason),
            ['source' => SupplierStatusChangeSource::Staff],
        );

        return $commitment->refresh();
    }
}
