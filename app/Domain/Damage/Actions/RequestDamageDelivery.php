<?php

namespace App\Domain\Damage\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Enums\AddressStatus;
use App\Domain\Address\Models\SharedAddress;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\CalculateDeliveryCharge;
use App\Domain\Damage\Actions\Concerns\GuardsDamageActions;
use App\Domain\Damage\Enums\DamageDispatchStatus;
use App\Domain\Damage\Enums\DamageStatus;
use App\Domain\Damage\Exceptions\DamageRefused;
use App\Domain\Damage\Models\DamageDispatch;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnChargeKind;
use App\Domain\Order\Enums\ReturnParty;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A Partner asks for their damaged item to be delivered to them (D35).
 *
 * Only the Partner who bears the loss, only once the loss is posted, and only for
 * the units inspection held as damaged. They choose one of **their own saved
 * addresses**, which is copied as it stood.
 *
 * **The Partner pays, and the server says how much.** The delivery charge is
 * worked out by the existing delivery-charge service from the item's own
 * logistics figures and the damaged quantity; no amount arrives from a request.
 * It is debited from the wallet — platform credit first, withdrawable after —
 * in the same transaction that creates the dispatch, and a wallet that cannot
 * cover it refuses the request: a Partner cannot run up a debt by asking for
 * their own goods. The charge is posted once, under a key of the case, so a
 * retry or a racing duplicate pays nothing twice.
 *
 * **One dispatch per damage**, by a unique index, so the same units can never be
 * sent twice. The item stays non-sellable throughout: nothing here moves stock
 * into a sellable bucket.
 */
class RequestDamageDelivery
{
    use GuardsDamageActions;

    public function __construct(
        protected CalculateDeliveryCharge $delivery,
        protected PostDamageCharge $charges,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws DamageRefused
     * @throws AuthorizationException
     */
    public function handle(User $actor, OrderDamage $damage, string $addressPublicId): DamageDispatch
    {
        $this->authorizeOwner($actor, $damage, true, 'ask for a damaged item to be delivered');

        $key = 'damage-delivery:'.$damage->public_id;
        $created = false;

        try {
            $dispatch = $this->database->transaction(function () use ($actor, $damage, $addressPublicId, $key, &$created) {
                $locked = $this->locked($damage);

                // Already asked for: the first request's answer stands.
                if ($existing = $locked->dispatch()->first()) {
                    return $existing;
                }

                $this->requireDeliverable($locked);

                $address = $this->ownAddress($locked, $addressPublicId);
                $quantity = $locked->damagedQuantity();
                $currency = Currency::from($locked->currency_code);
                $item = $locked->orderItem;

                // What is being sent is measured from the product it was; one that has
                // since been removed cannot be measured, and so cannot be priced.
                $sent = $item->variant ?? $item->product ?? throw DamageRefused::notInThatState();

                $calculation = $this->delivery->handle(
                    [['logistics' => $sent->logistics(), 'quantity' => $quantity]],
                    $currency,
                );

                // Paid now, or refused: never a debt for asking.
                $this->charges->handle(
                    damage: $locked,
                    kind: ReturnChargeKind::DamageDelivery,
                    amount: $calculation->finalCharge,
                    bearer: ReturnParty::Partner,
                    reason: 'Delivery of the damaged item on '.$locked->reference.'.',
                    actor: $actor,
                    allowOutstanding: false,
                );

                $dispatch = DamageDispatch::create([
                    'order_damage_id' => $locked->id,
                    'business_account_id' => $locked->business_account_id,
                    'quantity' => $quantity,
                    'shared_address_id' => $address->id,
                    'address_snapshot' => get_object_vars($address->toSnapshot()),
                    'currency_code' => $currency->value,
                    'delivery_charge' => $calculation->finalCharge,
                    'delivery_calculation' => $calculation->toArray(),
                    'status' => DamageDispatchStatus::Requested,
                    'requested_by' => $actor->id,
                    'requested_at' => CarbonImmutable::now(),
                    'idempotency_key' => $key,
                ]);

                $locked->moveTo(
                    DamageStatus::DispatchRequested,
                    new StatusChange(
                        actorId: $actor->id,
                        reason: 'The Partner asked for the item to be delivered.',
                        publicNote: 'damages.notes.delivery_requested',
                    ),
                    OrderStatusChangeSource::Account,
                );

                $created = true;

                return $dispatch;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = DamageDispatch::query()->where('order_damage_id', $damage->id)->first();

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }

        if ($created) {
            $this->audit->handle(new AuditEntry(
                action: 'damage.delivery_requested',
                actorId: $actor->id,
                auditableType: OrderDamage::class,
                auditableId: $damage->id,
                after: [
                    'reference' => $damage->reference,
                    'quantity' => $dispatch->quantity,
                    'delivery_charge' => $dispatch->delivery_charge->toDecimal(),
                    'currency' => $dispatch->currency_code,
                    'dispatch' => $dispatch->public_id,
                ],
                accountId: $damage->business_account_id,
                module: PermissionModule::Damage->value,
            ));
        }

        $damage->refresh();

        return $dispatch;
    }

    /**
     * @throws DamageRefused
     */
    protected function requireDeliverable(OrderDamage $damage): void
    {
        if ($damage->status !== DamageStatus::Finalized) {
            throw $damage->status->hasDispatchStarted() ? DamageRefused::alreadyRequested() : DamageRefused::notInThatState();
        }

        if ($damage->responsible_party !== ReturnParty::Partner) {
            throw DamageRefused::notPartnerLiable();
        }

        if (! $damage->in_custody || $damage->damagedQuantity() < 1) {
            throw DamageRefused::noDamagedUnits();
        }
    }

    /**
     * One of the Partner's own, still-active addresses — never anybody else's.
     *
     * @throws DamageRefused
     */
    protected function ownAddress(OrderDamage $damage, string $addressPublicId): SharedAddress
    {
        /** @var SharedAddress|null $address */
        $address = SharedAddress::query()
            ->where('public_id', $addressPublicId)
            ->where('owner_type', AddressOwnerType::BusinessAccount->value)
            ->where('owner_id', $damage->business_account_id)
            ->where('status', AddressStatus::Active->value)
            ->first();

        if ($address === null) {
            throw DamageRefused::addressNotAllowed();
        }

        return $address;
    }
}
