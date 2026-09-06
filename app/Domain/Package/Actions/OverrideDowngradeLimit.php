<?php

namespace App\Domain\Package\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Package\Data\DowngradeAssessment;
use App\Domain\Package\Data\FeatureExcess;
use App\Domain\Package\Models\Package;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * Lets an authorised administrator push a blocked downgrade through (D16).
 *
 * Two conditions, both required: the permission, and a **recorded reason**.
 * D16 allows the override precisely because there are legitimate cases — a
 * negotiated plan change, a correction to a mis-sold package — and requires the
 * reason because those cases have to be distinguishable afterwards from someone
 * clicking past a warning.
 *
 * The audit entry names what was over the limit and by how much. "Override
 * granted" tells a reviewer nothing; "allowed 63 published products onto a
 * 50-product package" tells them what was actually waved through.
 *
 * Overriding does **not** remove anything. The account keeps what it has and
 * exceeds the new limit; publishing more is what the limit then refuses. D16
 * forbids auto-removal, and an override that deleted the excess to make the
 * numbers agree would be exactly that.
 */
class OverrideDowngradeLimit
{
    public function __construct(
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @throws AuthorizationException when the actor may not override
     * @throws InvalidArgumentException when there is nothing to override, or no reason given
     */
    public function handle(
        BusinessAccount $account,
        Package $target,
        DowngradeAssessment $assessment,
        User $by,
        string $reason,
    ): void {
        if (! $by->can($this->permission())) {
            throw new AuthorizationException(
                'You do not have permission to override a package limit.'
            );
        }

        if ($assessment->isAllowed) {
            // Nothing is being waved through. An audit entry claiming an
            // override happened when the downgrade was legal anyway would put
            // noise into the record a reviewer has to read.
            throw new InvalidArgumentException(
                'This downgrade is not blocked, so there is nothing to override.'
            );
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('An override needs a recorded reason.');
        }

        $this->audit->handle(new AuditEntry(
            action: 'package.downgrade_limit_overridden',
            actorId: $by->id,
            auditableType: BusinessAccount::class,
            auditableId: $account->id,
            after: [
                'target_package' => $target->slug,
                'excess' => array_map(
                    fn (FeatureExcess $item) => $item->toArray(),
                    $assessment->excess,
                ),
            ],
            reason: trim($reason),
            accountId: $account->id,
            module: 'package',
            // A limit waved through is what a reviewer looks for after the
            // fact, so it is flagged rather than left in the general stream.
            isSensitive: true,
        ));
    }

    /**
     * The permission an override needs.
     *
     * `package.approve` rather than a verb of its own: overriding a limit is
     * approving a package change that would otherwise be refused, and the verb
     * catalogue is deliberately small (a permission list nobody can read is one
     * nobody audits).
     */
    protected function permission(): string
    {
        return PermissionCatalogue::name(PermissionModule::Package, PermissionAction::Approve);
    }
}
