<?php

namespace App\Domain\Kyc\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Kyc\Models\KycBatch;
use App\Models\User;

/**
 * Who may do what with a KYC batch template (D30).
 *
 * One permission per kind of act, so the person who builds a form need not be the
 * person who switches it on for every applicant, nor the person who grades what
 * applicants send. A template is platform configuration and holds no applicant
 * data, so nothing here is account-scoped.
 *
 * An archived batch is part of the record of what past rounds were asked, so it
 * cannot be edited or switched on; the policy says so as well as the action, so
 * the control is not offered.
 */
class KycBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->can($user, PermissionAction::View);
    }

    public function view(User $user, KycBatch $batch): bool
    {
        return $this->can($user, PermissionAction::View);
    }

    /** Making a new batch, or a copy of one. */
    public function create(User $user): bool
    {
        return $this->can($user, PermissionAction::Create);
    }

    public function update(User $user, KycBatch $batch): bool
    {
        return ! $batch->isArchived() && $this->can($user, PermissionAction::Edit);
    }

    /** Publishing, switching on or off, and choosing the default. */
    public function activate(User $user, KycBatch $batch): bool
    {
        return ! $batch->isArchived() && $this->can($user, PermissionAction::Activate);
    }

    /** Choosing a batch when opening a Partner or Supplier round. */
    public function assign(User $user): bool
    {
        return $this->can($user, PermissionAction::Assign);
    }

    /** Accepting or sending back evidence on a round on a batch. */
    public function review(User $user): bool
    {
        return $this->can($user, PermissionAction::Review);
    }

    /** Revealing a typed value (a TIN, a NID number) in full. */
    public function viewSensitive(User $user): bool
    {
        return $this->can($user, PermissionAction::ViewSensitiveData);
    }

    protected function can(User $user, PermissionAction $action): bool
    {
        return $user->can(PermissionCatalogue::name(PermissionModule::KycBatch, $action));
    }
}
