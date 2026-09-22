<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Order\Actions\ReceiveReturnedItems;
use App\Domain\Order\Models\OrderReturnItem;
use App\Domain\Supplier\Enums\PayableChangeSource;
use App\Domain\Supplier\Enums\PayableStatus;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Domain\Supplier\Models\SupplierPayableReversal;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The compensating record for goods that came back against a Supplier's
 * payable (D25, P13-22).
 *
 * **Never edits the payable.** A return or refund appends one
 * {@see SupplierPayableReversal} row, exactly `$quantity` at the payable's own
 * Supplier Rate — the database refuses anything else, and refuses reversals
 * that together would exceed the original payable, so several partial returns
 * can never over-reverse it. Called from
 * {@see ReceiveReturnedItems} once a Supplier-backed
 * line's goods are taken back into Supplier stock; a line with no Supplier
 * payable — because it was never Supplier-backed — is simply left alone.
 */
class ReverseSupplierPayable
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(OrderReturnItem $returnItem, int $quantity, string $reason): ?SupplierPayableReversal
    {
        /** @var SupplierPayable|null $payable */
        $payable = SupplierPayable::query()->where('order_item_id', $returnItem->order_item_id)->first();

        if ($payable === null || $quantity <= 0) {
            return null;
        }

        $key = 'supplier-payable-reversal:'.$returnItem->public_id;
        $existing = $this->byKey($key);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->database->transaction(function () use ($payable, $returnItem, $quantity, $reason, $key) {
                /** @var SupplierPayable $locked */
                $locked = SupplierPayable::query()->lockForUpdate()->findOrFail($payable->id);

                $amount = $locked->supplier_rate_minor->multipliedBy($quantity);

                $reversal = SupplierPayableReversal::create([
                    'supplier_payable_id' => $locked->id,
                    'order_return_item_id' => $returnItem->id,
                    'quantity' => $quantity,
                    'amount_minor' => $amount->minorUnits,
                    'currency_code' => $locked->currency_code,
                    'reason' => $reason,
                    'idempotency_key' => $key,
                    'created_at' => now(),
                ]);

                $reversedQuantity = $locked->reversedQuantity();
                $to = $reversedQuantity >= $locked->quantity ? PayableStatus::Reversed : PayableStatus::PartiallyReversed;

                if ($locked->canTransitionTo($to)) {
                    $locked->transitionWithHistory(
                        $to,
                        StatusChange::bySystem(reason: $reason),
                        ['source' => PayableChangeSource::System],
                    );
                }

                $this->audit->handle(new AuditEntry(
                    action: 'supplier_payable.reversed',
                    auditableType: SupplierPayable::class,
                    auditableId: $locked->id,
                    after: ['quantity' => $quantity, 'amount_minor' => $amount->minorUnits, 'new_status' => $to->value],
                    reason: $reason,
                    accountId: $locked->supplier_id,
                    module: PermissionModule::SupplierPayable->value,
                ));

                return $reversal;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $racedWith = $this->byKey($key);

            if ($racedWith === null) {
                throw $exception;
            }

            return $racedWith;
        }
    }

    protected function byKey(string $key): ?SupplierPayableReversal
    {
        return SupplierPayableReversal::query()->where('idempotency_key', $key)->first();
    }
}
