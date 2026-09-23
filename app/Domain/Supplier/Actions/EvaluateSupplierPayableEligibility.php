<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Enums\PayableChangeSource;
use App\Domain\Supplier\Enums\PayableStatus;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * A Supplier payable becomes eligible for settlement only when both are true:
 * the allocated quantity has been delivered, and the order's payment has
 * settled (D25, P13-22). Neither fact alone moves it — the two entry points
 * below record their own fact, idempotently, and only the second one to
 * arrive actually transitions the payable.
 *
 * Called from {@see RecordSupplierPayableDelivery} (the explicit delivery
 * event this batch stands in for fulfilment with) and
 * {@see QualifySupplierPayablePayment} (from {@see
 * \App\Domain\Order\Actions\ConfirmOrderPayment}). Neither call ever marks an
 * ordinary order delivered by itself.
 */
class EvaluateSupplierPayableEligibility
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function markDelivered(SupplierPayable $payable, ?CarbonImmutable $at = null): SupplierPayable
    {
        return $this->apply($payable, deliveredAt: $at ?? CarbonImmutable::now());
    }

    public function markPaymentSettled(SupplierPayable $payable, ?CarbonImmutable $at = null): SupplierPayable
    {
        return $this->apply($payable, paymentSettledAt: $at ?? CarbonImmutable::now());
    }

    protected function apply(SupplierPayable $payable, ?CarbonImmutable $deliveredAt = null, ?CarbonImmutable $paymentSettledAt = null): SupplierPayable
    {
        return $this->database->transaction(function () use ($payable, $deliveredAt, $paymentSettledAt) {
            /** @var SupplierPayable $locked */
            $locked = SupplierPayable::query()->lockForUpdate()->findOrFail($payable->id);

            // Only a payable still on its way to being earned takes a fact —
            // one already reversed, cancelled or on hold is not moved by this.
            if ($locked->status !== PayableStatus::Pending) {
                return $locked;
            }

            if ($deliveredAt !== null && $locked->delivered_at === null) {
                $locked->forceFill(['delivered_at' => $deliveredAt]);
            }

            if ($paymentSettledAt !== null && $locked->payment_settled_at === null) {
                $locked->forceFill(['payment_settled_at' => $paymentSettledAt]);
            }

            if ($locked->delivered_at !== null && $locked->payment_settled_at !== null) {
                $locked->forceFill(['eligible_at' => CarbonImmutable::now()]);

                $locked->transitionWithHistory(
                    PayableStatus::Eligible,
                    StatusChange::bySystem(reason: 'Delivered and payment settled.'),
                    ['source' => PayableChangeSource::System],
                );

                $this->audit->handle(new AuditEntry(
                    action: 'supplier_payable.eligible',
                    auditableType: SupplierPayable::class,
                    auditableId: $locked->id,
                    accountId: $locked->supplier_id,
                    module: PermissionModule::SupplierPayable->value,
                ));
            } elseif ($locked->isDirty()) {
                $locked->save();
            }

            $payable->setRawAttributes($locked->getAttributes(), sync: true);

            return $locked;
        });
    }
}
