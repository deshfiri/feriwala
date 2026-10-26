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
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

/**
 * The parcel arrived: write down who received it and when (D35).
 *
 * **Only that.** Nothing is counted, judged or restocked here — that is
 * inspection, which comes next and is somebody's separate decision. A parcel
 * received is a fact about a box at a door; whether what is inside is what was
 * described, and fit to sell, is not yet known, and no stock or money may move
 * on it.
 *
 * Once: a parcel already marked received is returned as it stands, with the
 * first person's name and the first timestamp.
 */
class ReceiveReturnParcel
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
    public function handle(User $actor, OrderReturn $return, ?string $note = null): OrderReturn
    {
        if (! $actor->can(PermissionCatalogue::name(PermissionModule::ReturnCase, PermissionAction::Receive))) {
            throw new AuthorizationException('This account may not mark a parcel as received.');
        }

        $marked = false;

        $received = $this->database->transaction(function () use ($actor, $return, $note, &$marked) {
            /** @var OrderReturn $locked */
            $locked = OrderReturn::query()->lockForUpdate()->findOrFail($return->id);

            if (! $locked->usesCaseWorkflow()) {
                throw ReturnRefused::wrongWorkflow();
            }

            // Marked once already: the first receiver and time stand.
            if ($locked->status === ReturnStatus::ParcelReceived) {
                return $locked;
            }

            if ($locked->status !== ReturnStatus::WaitingForParcel) {
                throw ReturnRefused::notInThatState();
            }

            $locked->forceFill([
                'received_by' => $actor->id,
                'received_at' => CarbonImmutable::now(),
            ]);

            $locked->moveTo(
                ReturnStatus::ParcelReceived,
                new StatusChange(
                    actorId: $actor->id,
                    reason: 'The parcel arrived.',
                    internalNote: $note,
                    publicNote: 'returns.notes.parcel_received',
                ),
                OrderStatusChangeSource::Staff,
            );

            $this->announcements->handle($locked->refresh()->load('items'));
            $marked = true;

            return $locked;
        });

        $return->setRawAttributes($received->getAttributes(), sync: true);

        if ($marked) {
            $this->audit->handle(new AuditEntry(
                action: 'order_return.parcel_received',
                actorId: $actor->id,
                auditableType: OrderReturn::class,
                auditableId: $received->id,
                after: ['reference' => $received->reference, 'received_at' => $received->received_at?->toIso8601String()],
                note: $note,
                accountId: $received->business_account_id,
                module: PermissionModule::ReturnCase->value,
            ));
        }

        return $received;
    }
}
