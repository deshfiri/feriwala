<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Cases\Actions\StoreCaseEvidence;
use App\Domain\Cases\Enums\EvidencePurpose;
use App\Domain\Damage\Actions\ReportDamage;
use App\Domain\Damage\Data\DamageReport;
use App\Domain\Damage\Enums\DamageCondition;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Actions\ChangeReturnResponsibility;
use App\Domain\Order\Actions\DecideOrderReturn;
use App\Domain\Order\Actions\FinalizeReturnInspection;
use App\Domain\Order\Actions\ReceiveReturnParcel;
use App\Domain\Order\Actions\RecordExchangeCollection;
use App\Domain\Order\Actions\StartReturnInspection;
use App\Domain\Order\Actions\StartReturnSettlement;
use App\Domain\Order\Data\ReturnedLine;
use App\Domain\Order\Enums\ReturnDisposition;
use App\Domain\Order\Enums\ReturnParty;
use App\Domain\Order\Models\OrderReturn;
use App\Http\Controllers\Concerns\RunsCaseActions;
use App\Http\Controllers\Controller;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Rules\DecimalAmountRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The steps of a return or exchange case, one route each (D35).
 *
 * Every route runs exactly one existing action, which checks the permission for
 * its step and the stage the case is at, and does it once. There is no route that
 * sets a status: the case moves because a step was taken, with its reason, and the
 * history records who took it. A refusal comes back as a message on the form.
 */
class ReturnCaseActionController extends Controller
{
    use RunsCaseActions;

    public function __construct(
        protected DecideOrderReturn $decisions,
        protected ReceiveReturnParcel $parcels,
        protected StartReturnInspection $inspectionStarts,
        protected FinalizeReturnInspection $inspections,
        protected ChangeReturnResponsibility $responsibilities,
        protected StartReturnSettlement $settlements,
        protected RecordExchangeCollection $collections,
        protected ReportDamage $damages,
        protected StoreCaseEvidence $evidence,
    ) {}

    public function approve(Request $request, string $return): RedirectResponse
    {
        $actor = $this->actor($request);
        $case = $this->find($return);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:'.DecideOrderReturn::MINIMUM_REASON, 'max:1000'],
            'quantities' => ['sometimes', 'array'],
            'quantities.*' => ['integer', 'min:0'],
        ]);

        return $this->attempt(
            fn () => $this->decisions->approve($actor, $case, array_map('intval', $validated['quantities'] ?? []), $validated['reason']),
            'cases.flash.approved',
            'reason',
        );
    }

    public function reject(Request $request, string $return): RedirectResponse
    {
        $actor = $this->actor($request);
        $case = $this->find($return);

        $validated = $request->validate(['reason' => ['required', 'string', 'min:'.DecideOrderReturn::MINIMUM_REASON, 'max:1000']]);

        return $this->attempt(fn () => $this->decisions->reject($actor, $case, $validated['reason']), 'cases.flash.rejected', 'reason');
    }

    public function receiveParcel(Request $request, string $return): RedirectResponse
    {
        $actor = $this->actor($request);
        $case = $this->find($return);

        $validated = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->parcels->handle($actor, $case, $validated['note'] ?? null), 'cases.flash.parcel_received');
    }

    public function startInspection(Request $request, string $return): RedirectResponse
    {
        $actor = $this->actor($request);
        $case = $this->find($return);

        return $this->attempt(fn () => $this->inspectionStarts->handle($actor, $case), 'cases.flash.inspection_started');
    }

    public function approveInspection(Request $request, string $return): RedirectResponse
    {
        $actor = $this->actor($request);
        $case = $this->find($return);

        $validated = $request->validate([
            'warehouse' => ['required', 'string', 'exists:warehouses,public_id'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.quantity' => ['required', 'integer', 'min:0'],
            'lines.*.disposition' => ['required', Rule::enum(ReturnDisposition::class)],
            'lines.*.note' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var Warehouse $warehouse */
        $warehouse = Warehouse::query()->where('public_id', $validated['warehouse'])->firstOrFail();

        $lines = [];

        foreach ($validated['lines'] as $lineId => $line) {
            $lines[] = new ReturnedLine((string) $lineId, (int) $line['quantity'], ReturnDisposition::from($line['disposition']), $line['note'] ?? null);
        }

        return $this->attempt(
            fn () => $this->inspections->approve($actor, $case, $lines, $warehouse, $validated['note'] ?? null),
            'cases.flash.inspection_approved',
            'lines',
        );
    }

    public function rejectInspection(Request $request, string $return): RedirectResponse
    {
        $actor = $this->actor($request);
        $case = $this->find($return);

        $validated = $request->validate(['reason' => ['required', 'string', 'min:'.FinalizeReturnInspection::MINIMUM_NOTE, 'max:1000']]);

        return $this->attempt(fn () => $this->inspections->reject($actor, $case, $validated['reason']), 'cases.flash.inspection_rejected', 'reason');
    }

    public function changeResponsibility(Request $request, string $return): RedirectResponse
    {
        $actor = $this->actor($request);
        $case = $this->find($return);

        $validated = $request->validate([
            'party' => ['required', Rule::enum(ReturnParty::class)],
            'reason' => ['required', 'string', 'min:'.ChangeReturnResponsibility::MINIMUM_REASON, 'max:1000'],
        ]);

        return $this->attempt(
            fn () => $this->responsibilities->handle($actor, $case, ReturnParty::from($validated['party']), $validated['reason']),
            'cases.flash.responsibility_changed',
            'reason',
        );
    }

    public function settle(Request $request, string $return): RedirectResponse
    {
        $actor = $this->actor($request);
        $case = $this->find($return);

        $validated = $request->validate([
            'adjustment' => ['nullable', new DecimalAmountRule],
            'adjustment_note' => ['nullable', 'required_with:adjustment', 'string', 'max:1000'],
        ]);

        $adjustment = DecimalAmount::parseOrNull($validated['adjustment'] ?? null);

        return $this->attempt(
            fn () => $this->settlements->handle($actor, $case, $adjustment, $validated['adjustment_note'] ?? null),
            'cases.flash.settled',
            'adjustment',
        );
    }

    public function recordCollection(Request $request, string $return): RedirectResponse
    {
        $actor = $this->actor($request);
        $case = $this->find($return);

        $validated = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);

        return $this->attempt(fn () => $this->collections->handle($actor, $case, $validated['reason']), 'cases.flash.collection_recorded', 'reason');
    }

    /**
     * Open a damage case from a returned line that is held apart.
     */
    public function openDamage(Request $request, string $return): RedirectResponse
    {
        $actor = $this->actor($request);
        $case = $this->find($return);

        $validated = $request->validate([
            'line' => ['required', 'string'],
            'quantity' => ['required', 'integer', 'min:1'],
            'condition' => ['required', Rule::enum(DamageCondition::class)],
            'description' => ['required', 'string', 'min:'.ReportDamage::MINIMUM_DESCRIPTION, 'max:2000'],
        ]);

        $line = $case->items()->where('public_id', $validated['line'])->firstOrFail();

        $damage = null;

        $response = $this->attempt(function () use ($actor, $case, $line, $validated, &$damage) {
            $damage = $this->damages->fromReturn($actor, $case, $line, new DamageReport(
                quantity: (int) $validated['quantity'],
                condition: DamageCondition::from($validated['condition']),
                description: $validated['description'],
                parcelExpected: true,
            ));
        }, 'cases.flash.damage_opened', 'description');

        return $damage === null ? $response : redirect()->route('admin.cases.damages.show', $damage->public_id);
    }

    public function addEvidence(Request $request, string $return): RedirectResponse
    {
        $actor = $this->actor($request);
        $case = $this->find($return);

        $validated = $request->validate([
            'purpose' => ['required', Rule::enum(EvidencePurpose::class)],
            'file' => ['required', 'file'],
            'note' => ['nullable', 'string', 'max:'.StoreCaseEvidence::MAX_NOTE],
        ]);

        return $this->attempt(
            fn () => $this->evidence->onCase($actor, $case, EvidencePurpose::from($validated['purpose']), $request->file('file'), $validated['note'] ?? null),
            'cases.flash.evidence_added',
            'file',
        );
    }

    protected function find(string $publicId): OrderReturn
    {
        /** @var OrderReturn $case */
        $case = OrderReturn::query()->where('public_id', $publicId)->firstOrFail();

        return $case;
    }
}
