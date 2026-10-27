<?php

namespace App\Domain\Order\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Models\OrderReturn;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

/**
 * Record that the extra amount a dearer replacement cost has been collected
 * (D35).
 *
 * The customer's extra payment is a commercial matter between the Partner and
 * their customer — collected on delivery of the replacement, or however the shop
 * arranges it — so this system cannot take it and does not pretend to: it
 * records, with who said so and how, that it was. Nothing here posts to a wallet
 * or the ledger. A cheaper replacement is not collected but refunded, through
 * the refund the settlement opens.
 *
 * Once: a collection already recorded is returned as it stands.
 */
class RecordExchangeCollection
{
    public const MINIMUM_NOTE = 10;

    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws ReturnRefused
     * @throws AuthorizationException
     */
    public function handle(User $actor, OrderReturn $return, string $how): OrderReturn
    {
        if (! $actor->can(PermissionCatalogue::name(PermissionModule::ReturnCase, PermissionAction::Refund))) {
            throw new AuthorizationException('This account may not record a collection.');
        }

        $how = trim($how);

        if (mb_strlen($how) < self::MINIMUM_NOTE) {
            throw ReturnRefused::decisionNeedsAReason();
        }

        $recorded = false;

        $case = $this->database->transaction(function () use ($return, &$recorded) {
            /** @var OrderReturn $locked */
            $locked = OrderReturn::query()->lockForUpdate()->findOrFail($return->id);

            if ($locked->exchange_difference_state === 'collected') {
                return $locked;
            }

            if ($locked->exchange_difference_state !== 'collection_pending') {
                throw ReturnRefused::nothingToCollect();
            }

            $locked->forceFill(['exchange_difference_state' => 'collected'])->save();
            $recorded = true;

            return $locked;
        });

        $return->setRawAttributes($case->getAttributes(), sync: true);

        if ($recorded) {
            $this->audit->handle(new AuditEntry(
                action: 'order_return.exchange_difference_collected',
                actorId: $actor->id,
                auditableType: OrderReturn::class,
                auditableId: $case->id,
                after: [
                    'reference' => $case->reference,
                    'exchange_number' => $case->exchange_number,
                    'amount' => $case->exchange_additional_due?->toDecimal(),
                ],
                note: $how,
                accountId: $case->business_account_id,
                module: PermissionModule::ReturnCase->value,
            ));
        }

        return $case;
    }
}
