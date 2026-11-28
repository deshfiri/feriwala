<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Exceptions\SensitiveActionRefused;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Inventory\Models\PartnerStockCostLot;
use App\Domain\Order\Actions\CorrectStorefrontProfitCost;
use App\Domain\Order\Actions\SettleStorefrontProfit;
use App\Domain\Order\Enums\StorefrontProfitStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\PartnerStorefrontProfit;
use App\Domain\Order\Models\PartnerStorefrontProfitLine;
use App\Domain\Order\Models\PartnerStorefrontProfitReversal;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Money;
use App\Support\Money\Rules\DecimalAmountRule;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Partner Storefront profits, platform-wide.
 *
 * Seen with `order.view`. Confirming a COD collection and retrying a credit that
 * failed for a technical reason need `order.edit`. Deciding an unproven cost is a
 * wallet-adjusting decision: `wallet.adjust_wallet`, a freshly confirmed password
 * (on the route) and two-factor (in the action).
 */
class StorefrontProfitController extends Controller
{
    /** @var list<string> what a person settles by hand, filterable on the list */
    public const QUEUE_REASONS = ['awaiting_collection', 'possible_duplicate', 'delivered_time_unproven', 'cost_unproven', 'negative_profit', 'collection_mismatch'];

    /**
     * Staff have checked a held profit — the earlier credit is not this one, or
     * the Delivered time is right — and release it to its facts again. A
     * wallet-affecting decision: `wallet.adjust_wallet`, a confirmed password
     * (on the route) and a reason, audited.
     */
    public function clearHold(Request $request, string $profit, SettleStorefrontProfit $settle): RedirectResponse
    {
        Gate::authorize(PermissionCatalogue::name(PermissionModule::Wallet, PermissionAction::AdjustWallet));

        $validated = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);

        try {
            $settle->clearReviewHold($this->person($request), $this->find($profit), $validated['reason']);
        } catch (InvalidArgumentException $refused) {
            throw ValidationException::withMessages(['reason' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('storefront_profit.flash.hold_cleared')]);

        return back();
    }

    public function index(Request $request): Response
    {
        Gate::authorize(PermissionCatalogue::name(PermissionModule::Order, PermissionAction::View));

        $status = in_array($request->string('status')->toString(), StorefrontProfitStatus::values(), true) ? $request->string('status')->toString() : null;
        $reason = in_array($request->string('reason')->toString(), self::QUEUE_REASONS, true) ? $request->string('reason')->toString() : null;
        $search = trim($request->string('search')->toString());

        $profits = PartnerStorefrontProfit::query()
            ->with(['order:id,public_id,reference', 'businessAccount:id,name,partner_number'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($reason !== null, fn ($query) => $query->where('status_reason', $reason))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->whereHas('order', fn ($order) => $order->where('reference', 'ilike', '%'.$search.'%'))
                ->orWhereHas('businessAccount', fn ($account) => $account->where('name', 'ilike', '%'.$search.'%')->orWhere('partner_number', 'ilike', '%'.$search.'%'))))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (PartnerStorefrontProfit $profit) => $this->row($profit));

        return Inertia::render('admin/storefront-profits/index', [
            'profits' => $profits,
            'filters' => ['status' => $status, 'reason' => $reason, 'search' => $search === '' ? null : $search],
            'statuses' => array_map(fn (StorefrontProfitStatus $case) => ['value' => $case->value, 'label' => $case->label()], StorefrontProfitStatus::cases()),
            'summary' => $this->summary(),
            // The reconciliation queue: what a person has to settle by hand.
            'reasons' => array_map(fn (string $code) => ['value' => $code, 'label' => __('storefront_profit.reasons.'.$code)], self::QUEUE_REASONS),
        ]);
    }

    public function show(Request $request, string $profit, CorrectStorefrontProfitCost $corrections): Response
    {
        Gate::authorize(PermissionCatalogue::name(PermissionModule::Order, PermissionAction::View));

        $record = $this->find($profit);
        $record->load(['order.items.stockReservation', 'businessAccount', 'lines.orderItem', 'reversals']);
        $user = $this->person($request);

        $canCorrect = $user->can(PermissionCatalogue::name(PermissionModule::Wallet, PermissionAction::AdjustWallet))
            && $record->status->isUnsettled()
            && $record->status !== StorefrontProfitStatus::ReportedOnly;

        $confirmed = time() - (int) $request->session()->get('auth.password_confirmed_at', 0) < (int) config('auth.password_timeout', 10800);

        if ($canCorrect && ! $confirmed) {
            redirect()->setIntendedUrl($request->fullUrl());
        }

        return Inertia::render('admin/storefront-profits/show', [
            'profit' => [
                ...$this->row($record),
                'collection_channel' => $record->collection_channel?->value,
                'collected_amount' => $record->collected_amount?->jsonSerialize(),
                'released_at' => $record->released_at?->toIso8601String(),
                'legacy_credited' => $record->legacy_credited_amount->jsonSerialize(),
                'attempts' => $record->attempts,
                'failure_message' => $record->failure_message,
                'lines' => $record->lines->map(fn (PartnerStorefrontProfitLine $line) => [
                    'id' => $line->orderItem->public_id,
                    'name' => $line->orderItem->product_name,
                    'sku' => $line->orderItem->sku,
                    'basis' => $line->cost_basis,
                    'quantity' => $line->quantity,
                    'returned' => $line->returned_quantity,
                    'selling' => $line->selling_amount->jsonSerialize(),
                    'cost' => $line->cost_amount?->jsonSerialize(),
                    'profit' => $line->profit_amount?->jsonSerialize(),
                    'unproven_reason' => $line->unproven_reason,
                    'lots' => $canCorrect && $line->unproven_reason !== null ? $this->candidateLots($line->orderItem) : [],
                ])->values(),
                'reversals' => $record->reversals->map(fn (PartnerStorefrontProfitReversal $reversal) => [
                    'id' => $reversal->public_id,
                    'amount' => $reversal->amount->jsonSerialize(),
                    'status' => $reversal->status,
                    'cause' => $reversal->cause,
                    'posted_at' => $reversal->posted_at?->toIso8601String(),
                    'created_at' => $reversal->created_at->toIso8601String(),
                ])->values(),
                'corrections' => DB::table('partner_storefront_profit_corrections')
                    ->join('users', 'users.id', '=', 'partner_storefront_profit_corrections.actor_id')
                    ->where('partner_storefront_profit_id', $record->id)
                    ->orderByDesc('partner_storefront_profit_corrections.id')
                    ->get(['partner_storefront_profit_corrections.public_id', 'cost_amount', 'reason', 'reference', 'users.name as actor', 'partner_storefront_profit_corrections.created_at'])
                    ->map(fn (object $row) => [
                        'id' => $row->public_id,
                        'cost' => Money::fromDecimal((string) $row->cost_amount, Currency::from($record->currency_code))->jsonSerialize(),
                        'reason' => $row->reason,
                        'reference' => $row->reference,
                        'actor' => $row->actor,
                        'created_at' => (string) $row->created_at,
                    ]),
            ],
            'can_retry' => $record->status === StorefrontProfitStatus::Failed && $user->can(PermissionCatalogue::name(PermissionModule::Order, PermissionAction::Edit)),
            'review_hold' => $record->review_hold,
            'can_clear_hold' => $record->review_hold !== null && $user->can(PermissionCatalogue::name(PermissionModule::Wallet, PermissionAction::AdjustWallet)),
            'can_correct' => $canCorrect,
            'password_confirmed' => $confirmed,
            'preview' => $canCorrect ? $this->preview($request, $record, $corrections) : null,
        ]);
    }

    /**
     * Staff confirm the cash collected on delivery for a Storefront order.
     */
    public function confirmCod(Request $request, string $order, SettleStorefrontProfit $settle): RedirectResponse
    {
        Gate::authorize(PermissionCatalogue::name(PermissionModule::Order, PermissionAction::Edit));

        /** @var Order|null $record */
        $record = Order::query()->where('public_id', $order)->first();
        abort_if($record === null, 404);

        $validated = $request->validate(['amount_collected' => ['required', new DecimalAmountRule]]);

        try {
            $settle->confirmCodCollection($this->person($request), $record, DecimalAmount::parse($validated['amount_collected'], Currency::from($record->currency_code)));
        } catch (InvalidArgumentException $refused) {
            throw ValidationException::withMessages(['amount_collected' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('storefront_profit.flash.cod_confirmed')]);

        return back();
    }

    public function retry(Request $request, string $profit, SettleStorefrontProfit $settle): RedirectResponse
    {
        Gate::authorize(PermissionCatalogue::name(PermissionModule::Order, PermissionAction::Edit));

        try {
            $settle->retry($this->person($request), $this->find($profit));
        } catch (InvalidArgumentException $refused) {
            throw ValidationException::withMessages(['retry' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('storefront_profit.flash.retried')]);

        return back();
    }

    public function correct(Request $request, string $profit, CorrectStorefrontProfitCost $correct): RedirectResponse
    {
        Gate::authorize(PermissionCatalogue::name(PermissionModule::Wallet, PermissionAction::AdjustWallet));

        $record = $this->find($profit);
        $validated = $request->validate([
            'line' => ['required', 'string'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*' => ['integer', 'min:0'],
            'reason' => ['required', 'string', 'min:'.CorrectStorefrontProfitCost::MINIMUM_REASON, 'max:1000'],
            'reference' => ['required', 'string', 'max:191'],
        ]);

        $actor = $this->person($request);

        try {
            $correct->handle(
                $actor,
                $record,
                $this->line($record, $validated['line']),
                $validated['allocations'],
                $validated['reason'],
                $validated['reference'],
                // Behind RequirePassword on the route: reaching here is the confirmation.
                passwordConfirmed: true,
                twoFactorEnabled: $actor->hasEnabledTwoFactorAuthentication(),
            );
        } catch (InvalidArgumentException|SensitiveActionRefused $refused) {
            throw ValidationException::withMessages(['allocations' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('storefront_profit.flash.corrected')]);

        return to_route('admin.storefront-profits.show', $record->public_id);
    }

    /**
     * Where every Storefront profit stands, counted on the server: pending (not
     * yet due), due (its hold has ended), released, reversed, blocked and
     * unresolved (a person must decide).
     *
     * @return array<string, int>
     */
    protected function summary(): array
    {
        $by = PartnerStorefrontProfit::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $due = PartnerStorefrontProfit::query()->due(CarbonImmutable::now())->count();
        $count = fn (string ...$statuses) => (int) collect($statuses)->sum(fn (string $status) => (int) ($by[$status] ?? 0));

        return [
            'pending' => $count(StorefrontProfitStatus::AwaitingFacts->value, StorefrontProfitStatus::Holding->value) - $due,
            'due' => $due,
            'released' => $count(StorefrontProfitStatus::Released->value),
            'reversed' => $count(StorefrontProfitStatus::Reversed->value),
            'blocked' => $count(StorefrontProfitStatus::Blocked->value),
            'unresolved' => $count(StorefrontProfitStatus::ReviewRequired->value, StorefrontProfitStatus::Failed->value),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(PartnerStorefrontProfit $profit): array
    {
        return [
            'id' => $profit->public_id,
            'order' => ['id' => $profit->order->public_id, 'reference' => $profit->order->reference],
            'partner' => ['name' => $profit->businessAccount->name, 'number' => $profit->businessAccount->partner_number],
            'status' => $profit->status->value,
            'status_label' => $profit->status->label(),
            'status_tone' => $profit->status->tone(),
            'status_reason' => $profit->status_reason,
            'selling' => $profit->selling_amount?->jsonSerialize(),
            'cost' => $profit->cost_amount?->jsonSerialize(),
            'profit' => $profit->profit_amount?->jsonSerialize(),
            // The first credit and any top-up since.
            'released' => $profit->creditedTotal()->jsonSerialize(),
            'reversed' => $profit->reversed_amount->jsonSerialize(),
            'delivered_at' => $profit->delivered_at?->toIso8601String(),
            'collected_at' => $profit->collected_at?->toIso8601String(),
            'release_at' => $profit->release_at?->toIso8601String(),
        ];
    }

    /**
     * The Partner's purchase lots of this line's product, bought before the sale
     * and with a known cost: what finance may choose from.
     *
     * @return list<array{id: string, purchased_at: string, quantity: int, unit_cost: array<string, mixed>|null, source: string|null}>
     */
    protected function candidateLots(OrderItem $item): array
    {
        $stockItemId = $item->stockReservation?->partner_stock_item_id;

        if ($stockItemId === null) {
            return [];
        }

        return array_values(PartnerStockCostLot::query()
            ->with('sourceOrder:id,reference')
            ->where('partner_stock_item_id', $stockItemId)
            ->where('cost_is_known', true)
            ->where('purchased_at', '<=', $item->order->placed_at)
            ->orderBy('purchased_at')
            ->get()
            ->map(fn (PartnerStockCostLot $lot) => [
                'id' => $lot->public_id,
                'purchased_at' => $lot->purchased_at->toIso8601String(),
                'quantity' => $lot->purchased_quantity,
                'unit_cost' => $lot->unit_cost?->jsonSerialize(),
                'source' => $lot->sourceOrder?->reference,
            ])
            ->all());
    }

    /**
     * What a chosen set of lots would make the line cost — worked out here, never
     * in the browser. Null until something has been chosen.
     *
     * @return array<string, mixed>|null
     */
    protected function preview(Request $request, PartnerStorefrontProfit $profit, CorrectStorefrontProfitCost $corrections): ?array
    {
        $lineId = $request->string('preview_line')->toString();
        $allocations = array_filter(array_map('intval', (array) $request->input('preview_allocations', [])), fn (int $units) => $units > 0);

        if ($lineId === '' || $allocations === []) {
            return null;
        }

        try {
            $result = $corrections->preview($profit, $this->line($profit, $lineId), $allocations);
        } catch (InvalidArgumentException $refused) {
            return ['line' => $lineId, 'error' => $refused->getMessage()];
        }

        return [
            'line' => $lineId,
            'cost' => $result['cost']->jsonSerialize(),
            'line_profit' => $result['line_profit']->jsonSerialize(),
            'error' => null,
        ];
    }

    protected function find(string $profit): PartnerStorefrontProfit
    {
        /** @var PartnerStorefrontProfit|null $record */
        $record = PartnerStorefrontProfit::query()->with(['order', 'businessAccount'])->where('public_id', $profit)->first();
        abort_if($record === null, 404);

        return $record;
    }

    protected function line(PartnerStorefrontProfit $profit, string $line): OrderItem
    {
        /** @var OrderItem|null $item */
        $item = OrderItem::query()->with(['order', 'stockReservation'])->where('order_id', $profit->order_id)->where('public_id', $line)->first();
        abort_if($item === null, 404);

        return $item;
    }

    protected function person(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
