<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Cases\Models\CaseEvidence;
use App\Domain\Cases\Queries\AdminCasePayload;
use App\Domain\Damage\Actions\ReceiveDamageParcel;
use App\Domain\Damage\Actions\ReportDamage;
use App\Domain\Damage\Data\DamageReport;
use App\Domain\Damage\Enums\DamageCondition;
use App\Domain\Damage\Enums\DamageStatus;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Order\Enums\ReturnCaseType;
use App\Domain\Order\Enums\ReturnDisposition;
use App\Domain\Order\Enums\ReturnParty;
use App\Domain\Order\Enums\ReturnStatus;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The staff workspace for returns, exchanges and damages (D35): one queue, one
 * case screen, only the steps a person may take, and every step enforced by the
 * action it runs and not by a button.
 */
beforeEach(function () {
    caseTestSetUp();
});

describe('the queue', function () {
    it('is closed to staff who may see no kind of case', function () {
        $courier = testPlatformStaff(PlatformRole::CourierManager);

        $this->actingAs($courier)->get(route('admin.cases.index'))->assertForbidden();
    });

    it('lists returns, exchanges and damages together, and counts only what the viewer may see', function () {
        $order = caseTestDeliveredOrder();
        $return = caseTestOpenReturn($order, 1);
        $exchange = caseTestOpenReturn($order, 1, ReturnCaseType::Exchange);
        $damage = caseTestDamageUnderReview(caseTestDeliveredOrder(), 1);

        $this->actingAs($this->viewer)->get(route('admin.cases.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/cases/index')
                ->where('counts.all.total', 3)
                ->where('counts.return.total', 1)
                ->where('counts.exchange.total', 1)
                ->where('counts.damage.total', 1)
                ->has('cases.data', 3)
                ->where('options.kinds', ['return', 'exchange', 'damage']));

        // Someone who may see damages only has neither the other kinds nor their counts.
        $damages = caseTestStaffWith(['damage.view']);

        $this->actingAs($damages)->get(route('admin.cases.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.all.total', 1)
                ->missing('counts.return')
                ->missing('counts.exchange')
                ->has('cases.data', 1)
                ->where('cases.data.0.reference', $damage->reference)
                ->where('options.kinds', ['damage']));

        expect($return->reference)->not->toBe($exchange->reference);
    });

    it('filters by type, status, partner, store, responsibility and date, and searches every reference', function () {
        $order = caseTestDeliveredOrder();
        $return = caseTestOpenReturn($order, 1);
        $exchange = caseTestOpenReturn($order, 1, ReturnCaseType::Exchange);
        $damage = caseTestDamageUnderReview(caseTestDeliveredOrder(), 1);

        $find = fn (array $query) => $this->actingAs($this->viewer)->get(route('admin.cases.index', $query));

        $find(['type' => 'damage'])->assertInertia(fn (Assert $page) => $page->has('cases.data', 1)->where('cases.data.0.kind', 'damage'));
        $find(['type' => 'exchange'])->assertInertia(fn (Assert $page) => $page->has('cases.data', 1)->where('cases.data.0.kind', 'exchange'));
        $find(['status' => 'liability_review'])->assertInertia(fn (Assert $page) => $page->has('cases.data', 1)->where('cases.data.0.kind', 'damage'));
        $find(['partner' => $this->account->public_id])->assertInertia(fn (Assert $page) => $page->has('cases.data', 3));
        $find(['partner' => 'someone-else'])->assertInertia(fn (Assert $page) => $page->has('cases.data', 0));
        $find(['store' => $this->website->public_id])->assertInertia(fn (Assert $page) => $page->has('cases.data', 3));
        $find(['responsibility' => 'banij'])->assertInertia(fn (Assert $page) => $page->has('cases.data', 0));
        $find(['from' => now()->addDay()->toDateString()])->assertInertia(fn (Assert $page) => $page->has('cases.data', 0));
        $find(['to' => now()->toDateString(), 'from' => now()->toDateString()])->assertInertia(fn (Assert $page) => $page->has('cases.data', 3));

        // Original order number, exchange order number, return reference, damage reference.
        $find(['search' => $order->reference])->assertInertia(fn (Assert $page) => $page->has('cases.data', 2));
        $find(['search' => $exchange->exchange_number])->assertInertia(fn (Assert $page) => $page->has('cases.data', 1)->where('cases.data.0.kind', 'exchange'));
        $find(['search' => $return->reference])->assertInertia(fn (Assert $page) => $page->has('cases.data', 1)->where('cases.data.0.reference', $return->reference));
        $find(['search' => $damage->reference])->assertInertia(fn (Assert $page) => $page->has('cases.data', 1)->where('cases.data.0.kind', 'damage'));
        $find(['search' => '%'])->assertInertia(fn (Assert $page) => $page->has('cases.data', 0));
    });

    it('puts what is waiting for somebody first, and names the step only to whoever may take it', function () {
        $done = caseTestAdvance(caseTestOpenReturn(caseTestDeliveredOrder(), 1), ReturnStatus::InspectionApproved);
        $waiting = caseTestOpenReturn(caseTestDeliveredOrder(), 1);

        $page = fn (User $who) => $this->actingAs($who)->get(route('admin.cases.index'));

        // The order manager reviews requests; the finance manager cannot, but is told what is waiting.
        $page($this->orderManager)->assertInertia(fn (Assert $page) => $page
            ->where('cases.data.0.reference', $waiting->reference)
            ->where('cases.data.0.next_action.key', 'review'));

        $page($this->viewer)->assertInertia(fn (Assert $page) => $page
            ->where('cases.data.0.next_action', null)
            ->where('cases.data.0.waiting_for', __('cases.next.review')));

        expect($done->status)->toBe(ReturnStatus::InspectionApproved);
    });

    it('carries nothing about the customer in a row', function () {
        caseTestOpenReturn(caseTestDeliveredOrder(), 1);

        $json = json_encode($this->actingAs($this->viewer)->get(route('admin.cases.index'))->viewData('page')['props']['cases'] ?? []);

        foreach (['Ayesha Rahman', '+88017', 'House 12', 'customer'] as $leak) {
            expect($json)->not->toContain($leak);
        }
    });
});

describe('a return case', function () {
    it('shows its sections and only the steps this person may take', function () {
        $case = caseTestOpenReturn(caseTestDeliveredOrder(), 2);

        $this->actingAs($this->orderManager)->get(route('admin.cases.returns.show', $case->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/cases/show')
                ->where('case.kind', 'return')
                ->where('case.reference', $case->reference)
                ->where('case.status', 'requested')
                ->where('case.responsibility', 'partner')
                ->where('case.next_action.key', 'review')
                ->has('items', 1)
                ->has('financials.summary')
                ->has('timeline')
                ->where('actions.0.key', 'approve')
                ->where('actions.1.key', 'reject')
                ->where('actions.0.confirmation.reason_required', true)
                ->where('actions.0.confirmation.items.0.quantity', 2));

        // A viewer with no right to review is offered nothing — and told so by the server, not by a hidden button.
        $this->actingAs($this->viewer)->get(route('admin.cases.returns.show', $case->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('actions', [])->where('case.next_action', null));
    });

    it('offers each stage\'s own next step and never a status to pick', function () {
        $case = caseTestOpenReturn(caseTestDeliveredOrder(), 2);

        $stages = [
            [ReturnStatus::WaitingForParcel, ['receive_parcel']],
            [ReturnStatus::ParcelReceived, ['start_inspection']],
            [ReturnStatus::UnderInspection, ['approve_inspection', 'reject_inspection']],
        ];

        foreach ($stages as [$status, $keys]) {
            caseTestAdvance($case, $status);

            $this->actingAs($this->orderManager)->get(route('admin.cases.returns.show', $case->public_id))
                ->assertInertia(fn (Assert $page) => $page
                    // Putting right who bears the case is always alongside, and is its own decision.
                    ->where('actions', fn ($actions) => collect($actions)->pluck('key')->all() === [...$keys, 'change_responsibility'])
                    ->missing('statuses'));
        }
    });

    it('shows the settlement the server will make before it is confirmed', function () {
        $case = caseTestAdvance(caseTestOpenReturn(caseTestDeliveredOrder(), 2), ReturnStatus::InspectionApproved);

        $this->actingAs($this->viewer)->get(route('admin.cases.returns.show', $case->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('actions.0.key', 'settle')
                ->where('actions.0.confirmation.amounts.0.key', 'return_charge')
                ->where('actions.0.confirmation.amounts.0.amount.amount', '40.00')
                ->where('actions.0.confirmation.amounts.1.key', 'product_refund')
                ->where('actions.0.confirmation.responsible', 'partner'));
    });

    it('runs each step through its route, and the action refuses what the person may not do', function () {
        $case = caseTestOpenReturn(caseTestDeliveredOrder(), 2);
        $url = fn (string $route) => route('admin.cases.returns.'.$route, $case->public_id);

        // Nobody may approve without the right, and a reason is needed.
        $this->actingAs($this->viewer)->post($url('approval'), ['reason' => 'The goods are as described.'])->assertForbidden();
        $this->actingAs($this->orderManager)->post($url('approval'), ['reason' => 'no'])->assertSessionHasErrors('reason');

        $this->actingAs($this->orderManager)->post($url('approval'), ['reason' => 'The goods are as described.'])->assertSessionHasNoErrors();
        expect($case->refresh()->status)->toBe(ReturnStatus::WaitingForParcel);

        // The parcel, then the inspection: out of order is refused, never half done.
        $this->actingAs($this->orderManager)->post($url('inspection-start'))->assertSessionHasErrors('case');

        $this->actingAs($this->orderManager)->post($url('parcel'))->assertSessionHasNoErrors();
        $this->actingAs($this->orderManager)->post($url('inspection-start'))->assertSessionHasNoErrors();

        $line = $case->items()->sole();
        $available = $this->stock->refresh()->available;

        // Counting more than was approved is refused, and nothing moves.
        $this->actingAs($this->orderManager)->post($url('inspection-approval'), [
            'warehouse' => $this->warehouse->public_id,
            'lines' => [$line->public_id => ['quantity' => 3, 'disposition' => 'restock']],
        ])->assertSessionHasErrors('lines');
        expect($this->stock->refresh()->available)->toBe($available);

        $this->actingAs($this->orderManager)->post($url('inspection-approval'), [
            'warehouse' => $this->warehouse->public_id,
            'lines' => [$line->public_id => ['quantity' => 2, 'disposition' => 'quarantine', 'note' => 'Seam looks torn.']],
        ])->assertSessionHasNoErrors();

        expect($case->refresh()->status)->toBe(ReturnStatus::InspectionApproved)
            ->and($this->stock->refresh()->returned)->toBe(2)
            ->and($line->refresh()->inspection_note)->toBe('Seam looks torn.');
    });

    it('settles once through the route and shows the charge and the responsibility change in the money tab', function () {
        websiteTestWallet($this->account, '1000.00');
        $case = caseTestAdvance(caseTestOpenReturn(caseTestDeliveredOrder(), 2), ReturnStatus::InspectionApproved);

        $this->actingAs($this->viewer)->post(route('admin.cases.returns.settlement', $case->public_id))->assertSessionHasNoErrors();
        $this->actingAs($this->viewer)->post(route('admin.cases.returns.settlement', $case->public_id))->assertSessionHasNoErrors();

        $this->actingAs($this->viewer)->get(route('admin.cases.returns.show', $case->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('financials.totals.deducted.amount', '40.00')
                ->where('financials.ledger.0.kind', 'return_charge')
                ->where('financials.ledger.0.status', 'posted')
                ->where('financials.ledger.0.ledger_url', fn ($url) => str_contains($url, '/admin/wallets/'))
                ->where('financials.summary.1.amount.amount', '40.00'));

        $this->actingAs($this->viewer)->post(route('admin.cases.returns.responsibility', $case->public_id), [
            'party' => 'banij', 'reason' => 'Packed badly at the warehouse: the fault is ours.',
        ])->assertForbidden();

        $this->actingAs($this->orderManager)->post(route('admin.cases.returns.responsibility', $case->public_id), [
            'party' => 'banij', 'reason' => 'Packed badly at the warehouse: the fault is ours.',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->viewer)->get(route('admin.cases.returns.show', $case->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('case.responsibility', 'banij')
                ->where('financials.totals.reversed.amount', '40.00')
                ->where('financials.totals.banij_cost.amount', '40.00')
                ->where('financials.totals.net_partner.amount', '0.00'));
    });

    it('sends a return placed before the case workflow to the page it always had', function () {
        $legacy = legacyReturnFlow(caseTestOpenReturn(caseTestDeliveredOrder(), 1));

        $this->actingAs($this->orderManager)->get(route('admin.cases.returns.show', $legacy->public_id))
            ->assertRedirect(route('admin.returns.show', $legacy->public_id));

        $this->actingAs($this->orderManager)->get(route('admin.returns.show', caseTestOpenReturn(caseTestDeliveredOrder(), 1)->public_id))
            ->assertRedirect();

        $this->actingAs($this->orderManager)->get(route('admin.returns.index'))->assertRedirect('/admin/cases?type=return');

        // It is still listed, marked, so nobody loses sight of it.
        $this->actingAs($this->viewer)->get(route('admin.cases.index'))
            ->assertInertia(fn (Assert $page) => $page->where('cases.data', fn ($rows) => collect($rows)->firstWhere('reference', $legacy->reference)['legacy'] === true
                && collect($rows)->firstWhere('reference', $legacy->reference)['next_action'] === null));
    });

    it('opens a damage from a returned line that is held apart, and takes the person to it', function () {
        $case = caseTestAdvance(caseTestOpenReturn(caseTestDeliveredOrder(), 2), ReturnStatus::InspectionApproved, ReturnDisposition::Quarantine);
        $line = $case->items()->sole();

        $this->actingAs($this->orderManager)->post(route('admin.cases.returns.damages.store', $case->public_id), [
            'line' => $line->public_id, 'quantity' => 2, 'condition' => 'torn', 'description' => 'Torn along the seam.',
        ])->assertRedirect();

        $damage = OrderDamage::query()->sole();

        expect($damage->order_return_item_id)->toBe($line->id)
            ->and($damage->status)->toBe(DamageStatus::Received);

        $this->actingAs($this->viewer)->get(route('admin.cases.returns.show', $case->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('linked.damages.0.reference', $damage->reference));
    });
});

describe('a damage case', function () {
    it('walks the strict workflow through its routes, with the server holding the loss to the maximum', function () {
        $wallet = websiteTestWallet($this->account, '10000.00');
        $order = caseTestDeliveredOrder();
        $damage = app(ReportDamage::class)->fromDelivered(
            $this->partner, $order, $order->items()->sole()->public_id,
            new DamageReport(2, DamageCondition::Torn, 'The fabric tore along the seam on arrival.', parcelExpected: true),
        );

        $url = fn (string $route, array $extra = []) => route('admin.cases.damages.'.$route, [$damage->public_id, ...$extra]);

        // Waiting for the parcel: the inspector's step is named, and inspecting is refused.
        $this->actingAs($this->inspector)->get($url('show'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('actions.0.key', 'receive_parcel'));
        $this->actingAs($this->inspector)->post($url('inspection-start'))->assertSessionHasErrors('case');

        $this->actingAs($this->inspector)->post($url('parcel'), ['warehouse' => $this->warehouse->public_id])->assertSessionHasNoErrors();
        $this->actingAs($this->inspector)->post($url('inspection-start'))->assertSessionHasNoErrors();

        // Every unit needs a disposition.
        $this->actingAs($this->inspector)->post($url('inspection'), [
            'dispositions' => [['disposition' => 'damaged', 'quantity' => 1]], 'cause' => 'packing',
        ])->assertSessionHasErrors('dispositions');

        $this->actingAs($this->inspector)->post($url('inspection'), [
            'dispositions' => [['disposition' => 'damaged', 'quantity' => 2], ['disposition' => 'restock', 'quantity' => 0]],
            'cause' => 'partner_handling', 'note' => 'Seam torn through.',
        ])->assertSessionHasNoErrors();

        // Deciding who bears it is another job: the inspector may not, and the loss is capped.
        $this->actingAs($this->inspector)->post($url('liability'), ['party' => 'partner', 'loss' => '100.00', 'reason' => 'Decided on the evidence.'])->assertForbidden();
        $this->actingAs($this->viewer)->post($url('liability'), ['party' => 'partner', 'loss' => '5000.01', 'reason' => 'Decided on the evidence.'])->assertSessionHasErrors('loss');
        $this->actingAs($this->viewer)->post($url('liability'), ['party' => 'partner', 'loss' => '5000.00', 'reason' => 'Decided on the evidence.'])->assertSessionHasNoErrors();

        // Posting it is a third: the person who decided may post it only if they hold that right too.
        $this->actingAs($this->orderManager)->post($url('finalization'))->assertForbidden();
        $this->actingAs($this->viewer)->post($url('finalization'))->assertSessionHasNoErrors();

        expect($damage->refresh()->status)->toBe(DamageStatus::Finalized)
            ->and($wallet->refresh()->total->toDecimal())->toBe('5000.00');

        $this->actingAs($this->viewer)->get($url('show'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('case.kind', 'damage')
                ->where('case.status', 'finalized')
                ->where('financials.totals.deducted.amount', '5000.00')
                ->where('storage_state', 'held_by_banij')
                ->where('actions.0.key', 'change_responsibility'));
    });

    it('does not offer receipt or delivery for a product that stays with the customer', function () {
        $order = caseTestDeliveredOrder();
        $damage = app(ReportDamage::class)->fromDelivered(
            $this->partner, $order, $order->items()->sole()->public_id,
            new DamageReport(1, DamageCondition::Torn, 'The customer says it is torn on arrival.', parcelExpected: false),
        );

        $this->actingAs($this->inspector)->get(route('admin.cases.damages.show', $damage->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('case.remains_with_customer', true)
                ->where('inspection.dispositions', [['value' => 'damaged', 'label' => ReturnDisposition::Damaged->label()]])
                ->where('actions', fn ($actions) => ! collect($actions)->pluck('key')->contains('receive_parcel')));
    });

    it('offers only the dispositions the backend can carry out', function () {
        $order = caseTestDeliveredOrder();
        $damage = app(ReportDamage::class)->fromDelivered(
            $this->partner, $order, $order->items()->sole()->public_id,
            new DamageReport(1, DamageCondition::Torn, 'The fabric tore along the seam on arrival.', parcelExpected: true),
        );

        // Until the parcel is in the warehouse there is nothing to put back on a shelf.
        $this->actingAs($this->inspector)->get(route('admin.cases.damages.show', $damage->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('inspection.dispositions', fn ($options) => collect($options)->pluck('value')->all() === ['damaged']));

        app(ReceiveDamageParcel::class)->handle($this->inspector, $damage, $this->warehouse);

        $this->actingAs($this->inspector)->get(route('admin.cases.damages.show', $damage->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('inspection.dispositions', fn ($options) => collect($options)->pluck('value')->all() === ['restock', 'damaged', 'quarantine']));
    });
});

describe('evidence', function () {
    it('is added by staff for the step they hold, kept private and opened only through a signed link', function () {
        $case = caseTestAdvance(caseTestOpenReturn(caseTestDeliveredOrder(), 2), ReturnStatus::ParcelReceived);

        // The viewer holds none of the evidence rights; the order manager may add at receipt and inspection.
        $this->actingAs($this->viewer)->post(route('admin.cases.returns.evidence.store', $case->public_id), [
            'purpose' => 'receipt', 'file' => caseTestPng(),
        ])->assertSessionHasErrors('file');

        $this->actingAs($this->orderManager)->post(route('admin.cases.returns.evidence.store', $case->public_id), [
            'purpose' => 'receipt', 'file' => caseTestPng('parcel photo.png'), 'note' => 'Box was crushed.',
        ])->assertSessionHasNoErrors();

        $evidence = CaseEvidence::query()->sole();

        expect($evidence->status)->toBe('submitted')
            ->and($evidence->uploader_type)->toBe('staff')
            ->and($evidence->purpose->value)->toBe('receipt')
            ->and($evidence->file->visibility->value)->toBe('private')
            ->and($evidence->order_return_id)->toBe($case->id);

        $page = $this->actingAs($this->orderManager)->get(route('admin.cases.returns.show', $case->public_id));
        $file = $page->viewData('page')['props']['evidence'][0];

        // No storage path or credential anywhere in what the screen is given.
        expect(json_encode($page->viewData('page')['props']))->not->toContain($evidence->file->path)
            ->and($file['preview_url'])->toContain('signature=');

        $this->actingAs($this->orderManager)->get($file['preview_url'])
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($this->orderManager)->get($file['download_url'])->assertOk()->assertDownload('parcel photo.png');

        // An unsigned address opens nothing.
        $this->actingAs($this->orderManager)->get(route('cases.evidence.show', $evidence->public_id))->assertForbidden();
    });

    it('refuses what is not a JPG, PNG, WebP or PDF, and what is too large', function () {
        $case = caseTestAdvance(caseTestOpenReturn(caseTestDeliveredOrder(), 2), ReturnStatus::ParcelReceived);
        $post = fn ($file) => $this->actingAs($this->orderManager)->post(route('admin.cases.returns.evidence.store', $case->public_id), ['purpose' => 'receipt', 'file' => $file]);

        // A script wearing an image's name is judged by its bytes.
        $post(caseTestDisguisedScript())->assertSessionHasErrors('file');
        $post(UploadedFile::fake()->createWithContent('notes.txt', 'plain text'))->assertSessionHasErrors('file');

        config()->set('case_evidence.max_bytes', 10);
        $post(caseTestPng())->assertSessionHasErrors('file');

        config()->set('case_evidence.max_bytes', 8 * 1024 * 1024);
        $post(caseTestPdf())->assertSessionHasNoErrors();

        expect(CaseEvidence::query()->count())->toBe(1);
    });

    it('keeps no file name, type or size in the audit trail', function () {
        $case = caseTestAdvance(caseTestOpenReturn(caseTestDeliveredOrder(), 2), ReturnStatus::ParcelReceived);

        $this->actingAs($this->orderManager)->post(route('admin.cases.returns.evidence.store', $case->public_id), [
            'purpose' => 'receipt', 'file' => caseTestPng('customer-address-proof.png'), 'note' => 'Private words.',
        ]);

        $entry = AuditLog::query()->where('action', 'order_return.evidence_added')->sole();
        $json = json_encode([$entry->before, $entry->after, $entry->reason, $entry->note]);

        expect($json)->toContain('receipt')
            ->and($json)->not->toContain('customer-address-proof')
            ->and($json)->not->toContain('image/png')
            ->and($json)->not->toContain('Private words');
    });

    it('is never removed once it is part of a case', function () {
        $case = caseTestAdvance(caseTestOpenReturn(caseTestDeliveredOrder(), 2), ReturnStatus::ParcelReceived);

        $this->actingAs($this->orderManager)->post(route('admin.cases.returns.evidence.store', $case->public_id), ['purpose' => 'receipt', 'file' => caseTestPng()]);
        $evidence = CaseEvidence::query()->sole();

        expect(fn () => $evidence->delete())->toThrow(RuntimeException::class);

        try {
            DB::transaction(fn () => DB::table('case_evidence')->where('id', $evidence->id)->delete());
        } catch (QueryException) {
            // The database says so too.
        }

        expect(CaseEvidence::query()->count())->toBe(1);

        $this->actingAs($this->orderManager)->delete(route('cases.evidence.destroy', $evidence->public_id))->assertForbidden();
    });
});

describe('the timeline', function () {
    it('shows staff the reasons and internal notes, and never shows a Partner either', function () {
        $case = caseTestOpenReturn(caseTestDeliveredOrder(), 2);

        $this->actingAs($this->orderManager)->post(route('admin.cases.returns.approval', $case->public_id), ['reason' => 'Internal: customer has returned twice before.']);

        $staff = $this->actingAs($this->orderManager)->get(route('admin.cases.returns.show', $case->public_id))->viewData('page')['props']['timeline'];
        $partner = $this->actingAs($this->partner)->get(route('cases.show', $case->public_id))->viewData('page')['props']['timeline'];

        expect(json_encode($staff))->toContain('customer has returned twice before')
            ->and(json_encode($partner))->not->toContain('customer has returned twice before')
            ->and(json_encode($partner))->not->toContain($this->orderManager->name);
    });

    it('is read in English and in Bangla', function () {
        $case = caseTestOpenReturn(caseTestDeliveredOrder(), 2);
        $payload = app(AdminCasePayload::class);

        app()->setLocale('en');
        $english = $payload->forReturn($this->orderManager, $case)['case']['status_label'];

        app()->setLocale('bn');
        $bangla = $payload->forReturn($this->orderManager, $case)['case']['status_label'];
        $nextBangla = __('cases.next.review');

        app()->setLocale('en');

        expect($english)->toBe('Requested')
            ->and($bangla)->not->toBe($english)
            ->and($nextBangla)->not->toBe(__('cases.next.review'));
    });
});

describe('navigation', function () {
    it('shows the one entry to whoever may see any kind of case, and shares the permissions that gate it', function () {
        $this->actingAs($this->viewer)->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions', fn ($permissions) => collect($permissions)->get('return.view') === true && collect($permissions)->get('damage.view') === true));

        $courier = testPlatformStaff(PlatformRole::CourierManager);

        $this->actingAs($courier)->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('permissions', fn ($permissions) => collect($permissions)->get('return.view') === false));
    });
});

describe('responsibility', function () {
    it('is the same two choices on a damage and a return, and a Partner cannot make either', function () {
        $case = caseTestAdvance(caseTestOpenReturn(caseTestDeliveredOrder(), 2), ReturnStatus::InspectionApproved);

        $this->actingAs($this->partner)->post(route('admin.cases.returns.responsibility', $case->public_id), [
            'party' => ReturnParty::Banij->value, 'reason' => 'Please make this one ours.',
        ])->assertForbidden();

        expect($case->refresh()->responsible_party)->toBe(ReturnParty::Partner);
    });
});
