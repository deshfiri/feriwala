<?php

namespace App\Domain\Kyc\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Models\User;

/**
 * Who may shape what applicants are asked for (§7.2, §32).
 *
 * All of it sits behind `kyc.manage_settings` rather than `kyc.approve`.
 * Changing the requirement catalogue decides what every future applicant must
 * provide; reviewing decides one case. A reviewer working the queue should not
 * be able to quietly add a requirement to the platform, and the person who
 * configures requirements need not be able to approve anybody.
 *
 * The catalogue is platform configuration and is never account-scoped — there
 * is deliberately no per-account view of it, so one account can never learn
 * what another is asked for.
 */
class KycDocumentTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can($this->permission());
    }

    public function view(User $user, KycDocumentType $type): bool
    {
        return $user->can($this->permission());
    }

    public function create(User $user): bool
    {
        return $user->can($this->permission());
    }

    public function update(User $user, KycDocumentType $type): bool
    {
        // An archived type is a historical record, not configuration. Refused
        // here as well as in the action, so the control is not offered either.
        return ! $type->isArchived() && $user->can($this->permission());
    }

    public function archive(User $user, KycDocumentType $type): bool
    {
        return $user->can($this->permission());
    }

    /**
     * Deletion is offered only for a type nothing has ever referenced.
     *
     * The alternative — offering it and refusing on submit — teaches an
     * administrator that the button sometimes lies.
     */
    public function delete(User $user, KycDocumentType $type): bool
    {
        return ! $type->isReferenced() && $user->can($this->permission());
    }

    protected function permission(): string
    {
        return PermissionCatalogue::name(
            PermissionModule::Kyc,
            PermissionAction::ManageSettings,
        );
    }
}
