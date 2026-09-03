<?php

namespace App\Domain\Kyc\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Kyc\Models\KycSubmission;
use App\Models\User;

/**
 * Who may work the KYC queue (§7.3, §32).
 *
 * Seeing the queue and opening the documents in it are separate permissions —
 * see {@see KycDocumentPolicy}. This governs the queue.
 */
class KycSubmissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can($this->permission(PermissionAction::View));
    }

    public function view(User $user, KycSubmission $submission): bool
    {
        // An applicant may look at their own submission.
        if ($submission->user_id === $user->id) {
            return true;
        }

        return $user->can($this->permission(PermissionAction::View));
    }

    /**
     * Deciding is separate from viewing: a support agent may need to see where
     * an application has got to without being able to approve it.
     */
    public function review(User $user, KycSubmission $submission): bool
    {
        if ($submission->user_id === $user->id) {
            // Nobody reviews their own KYC, whatever else they hold.
            return false;
        }

        return $user->can($this->permission(PermissionAction::Approve));
    }

    protected function permission(PermissionAction $action): string
    {
        return PermissionCatalogue::name(PermissionModule::Kyc, $action);
    }
}
