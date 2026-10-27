<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Cases\Actions\StoreCaseEvidence;
use App\Domain\Cases\Enums\EvidencePurpose;
use App\Domain\Courier\Models\CourierProvider;
use App\Domain\Damage\Actions\ApproveDamageLiability;
use App\Domain\Damage\Actions\ChangeDamageResponsibility;
use App\Domain\Damage\Actions\ConfirmDamageReceivedByPartner;
use App\Domain\Damage\Actions\DispatchDamage;
use App\Domain\Damage\Actions\FinalizeDamage;
use App\Domain\Damage\Actions\ReceiveDamageParcel;
use App\Domain\Damage\Actions\RecordDamageInspection;
use App\Domain\Damage\Actions\RejectDamage;
use App\Domain\Damage\Actions\SettleOutstandingDamageCharge;
use App\Domain\Damage\Actions\StartDamageInspection;
use App\Domain\Damage\Data\DamageInspection;
use App\Domain\Damage\Enums\DamageCause;
use App\Domain\Damage\Enums\DamageCondition;
use App\Domain\Damage\Models\DamageCharge;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Enums\ReturnDisposition;
use App\Domain\Order\Enums\ReturnParty;
use App\Http\Controllers\Concerns\RunsCaseActions;
use App\Http\Controllers\Controller;
use App\Support\Money\Currency;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Rules\DecimalAmountRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The steps of a damage case, one route each (D35).
 *
 * Every route runs exactly one existing action, which checks the permission for
 * its step and the stage the case is at, and does it once. Money is never taken
 * from the browser except the approved loss itself, which the action holds to the
 * most the item was worth when it was sold; every other figure is the server's.
 */
class DamageCaseActionController extends Controller
{
    use RunsCaseActions;

    public function __construct(
        protected ReceiveDamageParcel $receipts,
        protected StartDamageInspection $inspectionStarts,
        protected RecordDamageInspection $inspections,
        protected RejectDamage $rejections,
        protected ApproveDamageLiability $liabilities,
        protected FinalizeDamage $finalizations,
        protected ChangeDamageResponsibility $responsibilities,
        protected DispatchDamage $dispatches,
        protected ConfirmDamageReceivedByPartner $partnerReceipts,
        protected SettleOutstandingDamageCharge $outstanding,
        protected StoreCaseEvidence $evidence,
    ) {}

    public function receiveParcel(Request $request, string $damage): RedirectResponse
    {
        $actor = $this->actor($request);
        $record = $this->find($damage);

        $validated = $request->validate(['warehouse' => ['nullable', 'string', 'exists:warehouses,public_id']]);

        $warehouse = isset($validated['warehouse'])
            ? Warehouse::query()->where('public_id', $validated['warehouse'])->firstOrFail()
            : null;

        return $this->attempt(fn () => $this->receipts->handle($actor, $record, $warehouse), 'cases.flash.parcel_received', 'warehouse');
    }

    public function startInspection(Request $request, string $damage): RedirectResponse
    {
        $actor = $this->actor($request);
        $record = $this->find($damage);

        return $this->attempt(fn () => $this->inspectionStarts->handle($actor, $record), 'cases.flash.inspection_started');
    }

    public function recordInspection(Request $request, string $damage): RedirectResponse
    {
        $actor = $this->actor($request);
        $record = $this->find($damage);

        $validated = $request->validate([
            'dispositions' => ['required', 'array', 'min:1'],
            'dispositions.*.disposition' => ['required', Rule::enum(ReturnDisposition::class)],
            'dispositions.*.quantity' => ['required', 'integer', 'min:0'],
            'cause' => ['required', Rule::enum(DamageCause::class)],
            'condition' => ['nullable', Rule::enum(DamageCondition::class)],
            'note' => ['nullable', 'string', 'max:1000'],
            'warehouse' => ['nullable', 'string', 'exists:warehouses,public_id'],
        ]);

        // Only the dispositions that were given units to: a row of nothing is not a decision.
        $dispositions = [];

        foreach ($validated['dispositions'] as $row) {
            if ((int) $row['quantity'] > 0) {
                $dispositions[] = ['disposition' => ReturnDisposition::from($row['disposition']), 'quantity' => (int) $row['quantity']];
            }
        }

        $warehouse = isset($validated['warehouse'])
            ? Warehouse::query()->where('public_id', $validated['warehouse'])->firstOrFail()
            : null;

        return $this->attempt(fn () => $this->inspections->handle($actor, $record, new DamageInspection(
            dispositions: $dispositions,
            cause: DamageCause::from($validated['cause']),
            note: $validated['note'] ?? null,
            condition: isset($validated['condition']) ? DamageCondition::from($validated['condition']) : null,
            warehouse: $warehouse,
        )), 'cases.flash.inspection_recorded', 'dispositions');
    }

    public function reject(Request $request, string $damage): RedirectResponse
    {
        $actor = $this->actor($request);
        $record = $this->find($damage);

        $validated = $request->validate(['reason' => ['required', 'string', 'min:'.ApproveDamageLiability::MINIMUM_REASON, 'max:1000']]);

        return $this->attempt(fn () => $this->rejections->handle($actor, $record, $validated['reason']), 'cases.flash.rejected', 'reason');
    }

    public function assignLiability(Request $request, string $damage): RedirectResponse
    {
        $actor = $this->actor($request);
        $record = $this->find($damage);

        $validated = $request->validate([
            'party' => ['required', Rule::enum(ReturnParty::class)],
            'loss' => ['required', new DecimalAmountRule],
            'reason' => ['required', 'string', 'min:'.ApproveDamageLiability::MINIMUM_REASON, 'max:1000'],
            'write_off_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->attempt(fn () => $this->liabilities->handle(
            $actor,
            $record,
            ReturnParty::from($validated['party']),
            DecimalAmount::parse($validated['loss'], Currency::from($record->currency_code)),
            $validated['reason'],
            $validated['write_off_reason'] ?? null,
        ), 'cases.flash.liability_approved', 'loss');
    }

    public function finalize(Request $request, string $damage): RedirectResponse
    {
        $actor = $this->actor($request);
        $record = $this->find($damage);

        return $this->attempt(fn () => $this->finalizations->handle($actor, $record), 'cases.flash.finalized');
    }

    public function changeResponsibility(Request $request, string $damage): RedirectResponse
    {
        $actor = $this->actor($request);
        $record = $this->find($damage);

        $validated = $request->validate([
            'party' => ['required', Rule::enum(ReturnParty::class)],
            'reason' => ['required', 'string', 'min:'.ApproveDamageLiability::MINIMUM_REASON, 'max:1000'],
        ]);

        return $this->attempt(
            fn () => $this->responsibilities->handle($actor, $record, ReturnParty::from($validated['party']), $validated['reason']),
            'cases.flash.responsibility_changed',
            'reason',
        );
    }

    public function dispatch(Request $request, string $damage): RedirectResponse
    {
        $actor = $this->actor($request);
        $record = $this->find($damage);

        $validated = $request->validate([
            'tracking_number' => ['required', 'string', 'max:120'],
            'courier' => ['nullable', 'string', 'exists:courier_providers,code'],
        ]);

        $courier = isset($validated['courier'])
            ? CourierProvider::query()->where('code', $validated['courier'])->firstOrFail()
            : null;

        return $this->attempt(fn () => $this->dispatches->handle($actor, $record, $validated['tracking_number'], $courier), 'cases.flash.dispatched', 'tracking_number');
    }

    public function confirmReceipt(Request $request, string $damage): RedirectResponse
    {
        $actor = $this->actor($request);
        $record = $this->find($damage);

        return $this->attempt(fn () => $this->partnerReceipts->handle($actor, $record), 'cases.flash.partner_received');
    }

    public function settleOutstanding(Request $request, string $damage, string $charge): RedirectResponse
    {
        $actor = $this->actor($request);
        $record = $this->find($damage);

        /** @var DamageCharge $liability */
        $liability = $record->charges()->where('public_id', $charge)->firstOrFail();

        // Collecting what is owed is posting money: the same right as posting the loss.
        abort_unless($actor->can(PermissionCatalogue::name(PermissionModule::Damage, PermissionAction::Finalize)), 403);

        return $this->attempt(function () use ($liability, $actor) {
            if (! $this->outstanding->handle($liability, $actor)) {
                throw ValidationException::withMessages(['case' => __('cases.errors.wallet_cannot_cover')]);
            }
        }, 'cases.flash.outstanding_settled');
    }

    public function addEvidence(Request $request, string $damage): RedirectResponse
    {
        $actor = $this->actor($request);
        $record = $this->find($damage);

        $validated = $request->validate([
            'purpose' => ['required', Rule::enum(EvidencePurpose::class)],
            'file' => ['required', 'file'],
            'note' => ['nullable', 'string', 'max:'.StoreCaseEvidence::MAX_NOTE],
        ]);

        return $this->attempt(
            fn () => $this->evidence->onCase($actor, $record, EvidencePurpose::from($validated['purpose']), $request->file('file'), $validated['note'] ?? null),
            'cases.flash.evidence_added',
            'file',
        );
    }

    protected function find(string $publicId): OrderDamage
    {
        /** @var OrderDamage $damage */
        $damage = OrderDamage::query()->where('public_id', $publicId)->firstOrFail();

        return $damage;
    }
}
