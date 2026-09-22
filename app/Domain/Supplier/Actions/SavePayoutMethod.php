<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Enums\SupplierPayoutMethodStatus;
use App\Domain\Supplier\Enums\SupplierPayoutMethodType;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierPayoutMethod;
use App\Domain\Supplier\Models\SupplierWithdrawal;
use Illuminate\Database\DatabaseManager;

/**
 * Create, update or archive a Supplier's payout method (D25, P13-24).
 *
 * Ownership belongs directly to the Supplier — never a Business Account, and
 * never shared. `details` is encrypted at rest by the model's own cast; this
 * action never returns it, only `last_four`. A method is never deleted, only
 * archived: {@see SupplierWithdrawal} snapshots a
 * method's details onto itself at request time, so archiving or editing one
 * afterwards changes nothing about a withdrawal already made against it.
 *
 * Password confirmation and any two-factor requirement are the controller's
 * job, checked before this ever runs — the same boundary the rest of the
 * application draws between "is this request authorised" and "what does the
 * authorised request do".
 */
class SavePayoutMethod
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public function handle(
        Supplier $supplier,
        SupplierPayoutMethodType $type,
        string $label,
        array $details,
        bool $makeDefault = false,
        ?SupplierPayoutMethod $existing = null,
    ): SupplierPayoutMethod {
        return $this->database->transaction(function () use ($supplier, $type, $label, $details, $makeDefault, $existing) {
            $lastFour = mb_substr((string) ($details[$type->numberField()] ?? ''), -4);

            if ($existing !== null) {
                /** @var SupplierPayoutMethod $method */
                $method = SupplierPayoutMethod::query()
                    ->where('supplier_id', $supplier->id)
                    ->lockForUpdate()
                    ->findOrFail($existing->id);

                $method->forceFill([
                    'type' => $type,
                    'label' => $label,
                    'details' => $details,
                    'last_four' => $lastFour,
                    // A changed number is not the number that was verified.
                    'verified_at' => null,
                    'verified_by' => null,
                ])->save();

                $action = 'supplier_payout_method.updated';
            } else {
                $method = SupplierPayoutMethod::create([
                    'supplier_id' => $supplier->id,
                    'type' => $type,
                    'label' => $label,
                    'details' => $details,
                    'last_four' => $lastFour,
                    'is_default' => false,
                    'status' => SupplierPayoutMethodStatus::Active,
                ]);

                $action = 'supplier_payout_method.created';
            }

            if ($makeDefault) {
                $this->makeDefault($supplier, $method);
            }

            $this->audit->handle(new AuditEntry(
                action: $action,
                auditableType: SupplierPayoutMethod::class,
                auditableId: $method->id,
                after: ['type' => $type->value, 'last_four' => $lastFour],
                accountId: $supplier->id,
                isSensitive: true,
            ));

            return $method->refresh();
        });
    }

    public function archive(SupplierPayoutMethod $method): SupplierPayoutMethod
    {
        return $this->database->transaction(function () use ($method) {
            /** @var SupplierPayoutMethod $locked */
            $locked = SupplierPayoutMethod::query()->lockForUpdate()->findOrFail($method->id);

            $locked->forceFill([
                'status' => SupplierPayoutMethodStatus::Archived,
                'is_default' => false,
            ])->save();

            $this->audit->handle(new AuditEntry(
                action: 'supplier_payout_method.archived',
                auditableType: SupplierPayoutMethod::class,
                auditableId: $locked->id,
                accountId: $locked->supplier_id,
                isSensitive: true,
            ));

            return $locked;
        });
    }

    protected function makeDefault(Supplier $supplier, SupplierPayoutMethod $method): void
    {
        SupplierPayoutMethod::query()
            ->where('supplier_id', $supplier->id)
            ->where('id', '!=', $method->id)
            ->update(['is_default' => false]);

        $method->forceFill(['is_default' => true])->save();
    }
}
