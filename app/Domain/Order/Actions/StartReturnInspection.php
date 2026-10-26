<?php

namespace App\Domain\Order\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Models\OrderReturn;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

/**
 * Take the parcel up for inspection (D35).
 *
 * A stage of its own, between the parcel arriving and the goods being judged,
 * because it says who has the goods in their hands: a case "under inspection"
 * is one a named person is working, not one nobody has opened. It cannot be
 * started before the parcel was received, and the judgement itself —
 * {@see FinalizeReturnInspection} — cannot be given before it is started.
 *
 * Once: starting a case already under inspection returns it as it stands.
 */
class StartReturnInspection
{
    public function __construct(
        protected AnnounceReturnStatus $announcements,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws ReturnRefused
     * @throws AuthorizationException
     */
    public function handle(User $actor, OrderReturn $return): OrderReturn
    {
        if (! $actor->can(PermissionCatalogue::name(PermissionModule::ReturnCase, PermissionAction::Inspect))) {
            throw new AuthorizationException('This account may not inspect returned goods.');
        }

        $started = false;

        $inspecting = $this->database->transaction(function () use ($actor, $return, &$started) {
            /** @var OrderReturn $locked */
            $locked = OrderReturn::query()->lockForUpdate()->findOrFail($return->id);

            if (! $locked->usesCaseWorkflow()) {
                throw ReturnRefused::wrongWorkflow();
            }

            if ($locked->status === ReturnStatus::UnderInspection) {
                return $locked;
            }

            if ($locked->status === ReturnStatus::WaitingForParcel) {
                throw ReturnRefused::parcelNotReceived();
            }

            if ($locked->status !== ReturnStatus::ParcelReceived) {
                throw ReturnRefused::notInThatState();
            }

            $locked->moveTo(
                ReturnStatus::UnderInspection,
                new StatusChange(
                    actorId: $actor->id,
                    reason: 'Inspection of the returned goods began.',
                    publicNote: 'returns.notes.under_inspection',
                ),
                OrderStatusChangeSource::Staff,
            );

            $this->announcements->handle($locked->refresh()->load('items'));
            $started = true;

            return $locked;
        });

        $return->setRawAttributes($inspecting->getAttributes(), sync: true);

        if ($started) {
            $this->audit->handle(new AuditEntry(
                action: 'order_return.inspection_started',
                actorId: $actor->id,
                auditableType: OrderReturn::class,
                auditableId: $inspecting->id,
                after: ['reference' => $inspecting->reference],
                accountId: $inspecting->business_account_id,
                module: PermissionModule::ReturnCase->value,
            ));
        }

        return $inspecting;
    }
}
