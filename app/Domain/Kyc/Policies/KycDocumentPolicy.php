<?php

namespace App\Domain\Kyc\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Kyc\Models\KycDocument;
use App\Models\User;

/**
 * Who may open someone's identity documents (§7.5, §32.2).
 *
 * Deliberately narrow. `kyc.view_kyc_documents` is held by the KYC manager and
 * nobody else (plus Super Admin), and viewing the *list* of submissions does not
 * grant opening the files inside them — those are two different permissions
 * because they are two different levels of intrusion.
 *
 * An applicant may open their own documents. They uploaded them, and refusing
 * would mean they cannot check what they sent before a reviewer sees it.
 */
class KycDocumentPolicy
{
    public function view(User $user, KycDocument $document): bool
    {
        if ($this->isOwner($user, $document)) {
            return true;
        }

        return $user->can(PermissionCatalogue::name(
            PermissionModule::Kyc,
            PermissionAction::ViewKycDocuments,
        ));
    }

    /**
     * Taking a copy away is separated from viewing on screen.
     *
     * An applicant may download their own; a reviewer needs the same permission
     * as viewing, but the distinction is recorded so an investigation can tell
     * "looked at 40 documents" from "downloaded 40 documents".
     */
    public function download(User $user, KycDocument $document): bool
    {
        return $this->view($user, $document);
    }

    protected function isOwner(User $user, KycDocument $document): bool
    {
        return $document->submission()->value('user_id') === $user->id;
    }
}
