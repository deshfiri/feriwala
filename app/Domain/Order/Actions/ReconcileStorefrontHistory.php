<?php

namespace App\Domain\Order\Actions;

use App\Domain\Account\Enums\AccountType;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Courier\Actions\IssueShipmentLabel;
use App\Domain\Courier\Actions\ReconcileSteadfastBooking;
use App\Domain\Courier\Actions\SynchroniseSteadfastShipment;
use App\Domain\Courier\Enums\CourierBookingStatus;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Exceptions\ShipmentLabelRefused;
use App\Domain\Courier\Models\CourierBookingAttempt;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Courier\Models\ShipmentLabel;
use App\Domain\Inventory\Actions\AssignPartnerLotWarehouses;
use App\Domain\Inventory\Actions\OpenPartnerStockCostLots;
use App\Domain\Order\Data\StorefrontReconciliationOptions;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\StorefrontProfitStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderDeliveryStatusChange;
use App\Domain\Order\Models\OrderPlatformReceivable;
use App\Domain\Order\Models\OrderProceedsSettlement;
use App\Domain\Order\Models\PartnerStorefrontProfit;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Integrations\Courier\Steadfast\SteadfastException;
use App\Integrations\Courier\Steadfast\SteadfastFailureKind;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Brings every Storefront order that predates the live flows into line, once,
 * safely, and as often as it is asked (D40).
 *
 * For each order, in this order and never out of it:
 *
 *   1. the account type it was placed under — corrected only from a frozen
 *      placement record, never from the Partner's type today;
 *   2. its courier consignment — never booked again: an uncertain booking is
 *      settled by invoice lookup, a live parcel is asked about through the
 *      read-only status call, and whatever Steadfast says moves the parcel only
 *      through the existing legal transitions;
 *   3. its Delivered time — from the delivery history and nothing else;
 *   4. its collection — a settled Banij-gateway payment or a COD collection
 *      already recorded; Delivered alone is never collection;
 *   5–7. its cost, profit and settlement — through the live
 *      {@see SettleStorefrontProfit}, under the same key, released at once when
 *      its 24 hours after Delivered have already passed;
 *   8. its label — only when every proven fact is there;
 *   9. its classification and result.
 *
 * **Dry run by default:** each order is worked out inside a transaction that
 * is always rolled back, so the report is exactly what `--apply` would do and
 * nothing is written. With `--apply`, each order is one transaction, locked;
 * one that fails is recorded and the rest go on. A run keeps a checkpoint so an
 * interruption resumes rather than restarting, and every effect is keyed, so a
 * second run has none.
 *
 * @phpstan-type ReconciliationRun object{id: int, public_id: string, status: string, options: array<string, mixed>, counts: array<string, mixed>, checkpoint_order_id: int|null}
 */
class ReconcileStorefrontHistory
{
    public const LABEL_REASON = 'Reconstructed by the legacy reconciliation from the records as they stood; the Storefront branding is its current branding, not necessarily what the parcel originally shipped with.';

    /** @var list<string> */
    public const COUNTS = [
        'orders_scanned', 'courier_records_reconciled', 'delivered_statuses_updated', 'awaiting_cod_collection',
        'account_types_corrected', 'cost_lots_resolved', 'settlements_created', 'profits_credited',
        'withdrawable_credited', 'already_credited_or_skipped', 'labels_generated', 'missing_courier_references',
        'missing_warehouse', 'ambiguous_cost_basis', 'possible_duplicates', 'review_required', 'technical_failures',
    ];

    protected bool $courierApiDown = false;

    public function __construct(
        protected DatabaseManager $database,
        protected SettleStorefrontProfit $settle,
        protected ReconcileSteadfastBooking $reconcileBooking,
        protected SynchroniseSteadfastShipment $syncShipment,
        protected IssueShipmentLabel $issueLabel,
        protected OpenPartnerStockCostLots $openLots,
        protected AssignPartnerLotWarehouses $assignWarehouses,
        protected RecordAuditLog $audit,
        protected LogManager $log,
    ) {}

    /**
     * @param  callable(string): void|null  $progress
     * @return array{run: string|null, counts: array<string, int|string>, rows: list<array<string, mixed>>, report: array{json: string, csv: string}}
     */
    public function run(StorefrontReconciliationOptions $options, ?callable $progress = null): array
    {
        $progress ??= fn (string $line) => null;
        $run = $this->openRun($options);
        $options = $run === null ? $options : StorefrontReconciliationOptions::fromArray($run->options, $options);

        $counts = array_fill_keys(self::COUNTS, 0);
        $counts['withdrawable_credited'] = Money::zero()->toDecimal();
        $rows = [];

        // The Partners' cost lots first, once per run: what the orders' costs are read from.
        $lotsDone = $run !== null && (bool) ($run->counts['lots_done'] ?? false);

        if (! $lotsDone) {
            $counts['cost_lots_resolved'] = $this->resolveCostLots($options->apply);
        }

        if ($run !== null) {
            $this->database->table('storefront_reconciliation_runs')->where('id', $run->id)->update([
                'counts' => json_encode([...$counts, 'lots_done' => true]),
                'updated_at' => now(),
            ]);
        }

        $processed = 0;

        foreach ($this->orders($options, $run) as $orderId) {
            if ($options->limit !== null && $processed >= $options->limit) {
                break;
            }

            $processed++;
            $row = $this->process($orderId, $options);
            $rows[] = $row;
            $this->tally($counts, $row);

            if ($run !== null) {
                $this->record($run->id, $orderId, $row);
            }

            $progress(sprintf('%s  %s  %s', str_pad((string) $processed, 5, ' ', STR_PAD_LEFT), $row['order'], $row['classification'].($row['reasons'] === [] ? '' : ' ('.implode(', ', $row['reasons']).')')));
        }

        if ($run !== null) {
            $previous = $this->previousCounts($run->id, $counts);
            $this->database->table('storefront_reconciliation_runs')->where('id', $run->id)->update([
                'status' => $counts['technical_failures'] > 0 ? 'failed' : 'completed',
                'counts' => json_encode([...$previous, 'lots_done' => true]),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $counts = $previous;
        }

        return [
            'run' => $run?->public_id,
            'counts' => $counts,
            'rows' => $rows,
            'report' => $this->export($run?->public_id, $options, $counts, $rows),
        ];
    }

    /**
     * One order, start to finish. Never throws: a failure is the row's result.
     *
     * @return array<string, mixed>
     */
    public function process(int $orderId, StorefrontReconciliationOptions $options): array
    {
        /** @var Order $order */
        $order = Order::query()->findOrFail($orderId);
        $connection = $this->database->connection();

        try {
            if (! $options->apply) {
                $connection->beginTransaction();

                try {
                    return $this->steps($order, $options);
                } finally {
                    $connection->rollBack();
                }
            }

            return $this->database->transaction(fn () => $this->steps($order, $options));
        } catch (Throwable $throwable) {
            $this->log->channel('wallet')->error('A Storefront order could not be reconciled', [
                'order' => $order->reference,
                'error' => mb_substr($throwable->getMessage(), 0, 300),
            ]);

            return $this->row($order, 'technical_failure', ['technical_failure'], [], mb_substr(class_basename($throwable).': '.$throwable->getMessage(), 0, 500));
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function steps(Order $order, StorefrontReconciliationOptions $options): array
    {
        /** @var Order $order */
        $order = Order::query()->lockForUpdate()->findOrFail($order->id);
        $reasons = [];
        $effects = [];

        // 1. The account type it was placed under.
        $this->accountType($order, $reasons, $effects);

        // 2. The courier consignment. 3. The delivery it reports.
        $deliveredBefore = $this->deliveredHistory($order) !== null;
        $this->courier($order, $options, $reasons, $effects);
        $order->refresh();
        $deliveredRow = $this->deliveredHistory($order);
        $deliveredByThisRun = ! $deliveredBefore && $deliveredRow !== null;

        if ($deliveredByThisRun) {
            $effects['delivered_status_updated'] = true;
        }

        if ($order->delivery_status === OrderDeliveryStatus::Delivered && $deliveredRow === null) {
            $reasons[] = 'delivered_time_review_required';
        }

        // 4–7. Collection, cost, profit, settlement and release.
        $classification = $this->profit($order, $deliveredByThisRun, $reasons, $effects);

        // 8. The label, from proven facts only.
        $this->labels($order, $options, $reasons, $effects);

        // 9. The result.
        return $this->row($order, $classification, array_values(array_unique($reasons)), $effects);
    }

    /**
     * @param  list<string>  $reasons
     * @param  array<string, mixed>  $effects
     */
    protected function accountType(Order $order, array &$reasons, array &$effects): void
    {
        if ($order->source !== OrderSource::Website || $order->account_type !== AccountType::Conditional) {
            return;
        }

        $snapshot = OrderPlatformReceivable::query()->where('order_id', $order->id)->value('account_type_at_placement');

        // Written on a line only for a Non-Conditional account's central-stock
        // sale, at placement: a placement-time record of the type.
        $lineProof = $order->items()->where(fn (Builder $query) => $query->whereNotNull('platform_retained_amount')->orWhereNotNull('product_cost'))->exists();

        if ($snapshot === AccountType::Conditional->value && $lineProof) {
            $reasons[] = 'account_type_review_required';

            return;
        }

        if ($snapshot === AccountType::NonConditional->value || ($snapshot === null && $lineProof)) {
            DB::statement("SET LOCAL feriwala.account_type_reconciliation = 'on'");
            DB::table('orders')->where('id', $order->id)->update(['account_type' => AccountType::NonConditional->value]);
            DB::statement("SET LOCAL feriwala.account_type_reconciliation = 'off'");

            $this->audit->handle(new AuditEntry(
                action: 'order.account_type_reconciled',
                actorType: 'system',
                auditableType: Order::class,
                auditableId: $order->id,
                before: ['account_type' => AccountType::Conditional->value],
                after: ['account_type' => AccountType::NonConditional->value, 'proof' => $snapshot !== null ? 'platform_receivable_snapshot' : 'placement_line_snapshot'],
                accountId: $order->business_account_id,
                module: 'orders',
            ));

            $effects['account_type_corrected'] = true;

            return;
        }

        // Nothing frozen proves the type. Only worth a person's look when the
        // account is Non-Conditional today — a hint, never proof.
        if ($snapshot === null && BusinessAccount::query()->whereKey($order->business_account_id)->value('account_type') === AccountType::NonConditional->value) {
            $reasons[] = 'account_type_review_required';
        }
    }

    /**
     * Never books. Settles uncertain bookings by invoice lookup and asks about
     * live parcels through the read-only status call; every move goes through
     * the existing legal transitions.
     *
     * @param  list<string>  $reasons
     * @param  array<string, mixed>  $effects
     */
    protected function courier(Order $order, StorefrontReconciliationOptions $options, array &$reasons, array &$effects): void
    {
        if ($options->provider !== null && $options->provider !== CourierProviderCode::Steadfast->value) {
            return;
        }

        $steadfast = CourierProviderCode::Steadfast->value;
        $useApi = $options->courierApi && ! $this->courierApiDown;

        $uncertain = CourierBookingAttempt::query()
            ->where('order_id', $order->id)
            ->where('status', CourierBookingStatus::Uncertain->value)
            ->whereIn('courier_provider_id', DB::table('courier_providers')->where('code', $steadfast)->select('id'))
            ->get();

        foreach ($uncertain as $attempt) {
            if (! $useApi) {
                $reasons[] = 'courier_booking_uncertain';

                continue;
            }

            $settled = $this->withRetry($options, fn () => $this->reconcileBooking->handle($attempt, null));

            if ($settled === null || $settled->status === CourierBookingStatus::Uncertain) {
                $reasons[] = 'courier_booking_uncertain';
            } else {
                $effects['courier_records_reconciled'] = ($effects['courier_records_reconciled'] ?? 0) + 1;
            }
        }

        $shipments = Shipment::query()
            ->where('order_id', $order->id)
            ->whereHas('courierProvider', fn (Builder $query) => $query->where('code', $steadfast))
            ->get();

        foreach ($shipments as $shipment) {
            if ($useApi && ! $shipment->status->isTerminal() && ! $this->courierApiDown) {
                $applied = $this->withRetry($options, function () use ($shipment) {
                    try {
                        return $this->syncShipment->handle($shipment);
                    } catch (SteadfastException $exception) {
                        if (in_array($exception->kind, [SteadfastFailureKind::Authentication, SteadfastFailureKind::NotConfigured], true)) {
                            $this->courierApiDown = true;
                        }

                        throw $exception;
                    }
                }, $lookupFailed);

                if ($lookupFailed) {
                    $reasons[] = 'courier_lookup_failed';
                } elseif ($applied === null) {
                    // Steadfast does not know a parcel Banij booked: never read as "not booked".
                    $reasons[] = 'courier_review_required';
                } elseif ($applied->changed) {
                    $effects['courier_records_reconciled'] = ($effects['courier_records_reconciled'] ?? 0) + 1;
                }

                $this->pause($options);
            }

            $shipment->refresh();

            if ($shipment->status !== OrderCourierStatus::Cancelled && blank($shipment->external_id) && blank($shipment->tracking_number)) {
                $reasons[] = 'courier_reference_required';
            }

            if ($shipment->needs_review) {
                $reasons[] = 'courier_review_required';
            }
        }
    }

    /**
     * @param  list<string>  $reasons
     * @param  array<string, mixed>  $effects
     */
    protected function profit(Order $order, bool $deliveredByThisRun, array &$reasons, array &$effects): string
    {
        $existing = PartnerStorefrontProfit::query()->where('order_id', $order->id)->first();
        $releasedBefore = $existing?->released_at !== null;

        $profit = $this->settle->sync($order);

        if ($profit === null) {
            return 'not_storefront';
        }

        if ($existing === null) {
            $effects['settlement_created'] = true;
        }

        // A Delivered time this run itself recorded is when Banij learned of it,
        // not when it happened: never the start of a 24-hour hold.
        if ($deliveredByThisRun && $profit->status->isUnsettled()) {
            $profit = $this->settle->placeReviewHold($profit, 'delivered_time_unproven');
        }

        if ($profit->status->isUnsettled() && $profit->review_hold === null && $this->possibleDuplicate($order, $profit)) {
            $profit = $this->settle->placeReviewHold($profit, 'possible_duplicate');
        }

        if ($profit->status === StorefrontProfitStatus::Holding && $profit->release_at?->lessThanOrEqualTo(CarbonImmutable::now()) === true) {
            $profit = $this->settle->release($profit->id);
        }

        $profit->refresh();

        if (! $releasedBefore && $profit->status === StorefrontProfitStatus::Released) {
            $effects['profit_credited'] = $profit->released_amount->toDecimal();
        }

        return match (true) {
            $profit->status === StorefrontProfitStatus::Released => $releasedBefore ? 'already_credited' : 'credited',
            $profit->status === StorefrontProfitStatus::Holding => 'settlement_pending',
            $profit->status_reason === 'awaiting_collection' => 'awaiting_cod_collection',
            $profit->status_reason === 'awaiting_delivery' => 'awaiting_delivery',
            $profit->status_reason === 'cost_unproven' => 'cost_basis_review_required',
            $profit->status_reason === 'possible_duplicate' => 'possible_duplicate_review_required',
            $profit->status_reason === 'delivered_time_unproven' => 'delivered_time_review_required',
            $profit->status === StorefrontProfitStatus::NoProfit && $profit->status_reason === 'already_credited' => 'already_credited',
            $profit->status === StorefrontProfitStatus::NoProfit => 'no_profit',
            $profit->status === StorefrontProfitStatus::ReportedOnly => 'reported_only',
            $profit->status === StorefrontProfitStatus::Cancelled => 'cancelled',
            $profit->status === StorefrontProfitStatus::Blocked => 'blocked',
            $profit->status === StorefrontProfitStatus::Failed => 'technical_failure',
            default => 'review_required',
        };
    }

    /**
     * An earlier SalesCredit naming this order that neither this settlement nor
     * the earlier per-line settlement accounts for: it may already be this
     * profit, and that cannot be proven either way.
     */
    protected function possibleDuplicate(Order $order, PartnerStorefrontProfit $profit): bool
    {
        $known = OrderProceedsSettlement::query()->where('order_id', $order->id)->whereNotNull('wallet_transaction_id')->pluck('wallet_transaction_id')
            ->push($profit->wallet_transaction_id)
            ->filter()
            ->all();

        return WalletTransaction::query()
            ->where('business_account_id', $order->business_account_id)
            ->where('type', LedgerTransactionType::SalesCredit->value)
            ->whereNotIn('id', $known)
            ->where(fn (Builder $query) => $query
                ->where('idempotency_key', $profit->creditKey())
                ->orWhere('description', 'ilike', '%'.$order->reference.'%')
                ->orWhere('reference', $order->reference))
            ->exists();
    }

    /**
     * @param  list<string>  $reasons
     * @param  array<string, mixed>  $effects
     */
    protected function labels(Order $order, StorefrontReconciliationOptions $options, array &$reasons, array &$effects): void
    {
        $shipments = Shipment::query()->where('order_id', $order->id)->where('status', '!=', OrderCourierStatus::Cancelled->value)->get();

        foreach ($shipments as $shipment) {
            if (ShipmentLabel::query()->current()->where('shipment_id', $shipment->id)->exists()) {
                continue;
            }

            try {
                $label = $this->issueLabel->handle($shipment, null, self::LABEL_REASON);

                if ($label->wasRecentlyCreated) {
                    $effects['labels_generated'] = ($effects['labels_generated'] ?? 0) + 1;
                }
            } catch (ShipmentLabelRefused $refused) {
                $reasons[] = match ($refused->reason) {
                    'waiting_for_courier', 'barcode_unencodable' => 'courier_reference_required',
                    'warehouse_undetermined', 'warehouse_not_ready' => 'missing_warehouse',
                    default => 'label_review_required',
                };
            }
        }
    }

    protected function deliveredHistory(Order $order): ?OrderDeliveryStatusChange
    {
        return OrderDeliveryStatusChange::query()
            ->where('order_id', $order->id)
            ->where('new_status', OrderDeliveryStatus::Delivered->value)
            ->orderBy('changed_at')
            ->first();
    }

    /**
     * A courier call, retried a few times on a temporary failure with growing
     * pauses. A lasting failure leaves the parcel as it was and is reported.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T|null
     */
    protected function withRetry(StorefrontReconciliationOptions $options, callable $call, ?bool &$failed = null): mixed
    {
        $failed = false;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return $call();
            } catch (SteadfastException $exception) {
                $temporary = in_array($exception->kind, [SteadfastFailureKind::Unreachable, SteadfastFailureKind::Timeout], true);

                if (! $temporary || $attempt === 3) {
                    $failed = true;

                    return null;
                }

                usleep($options->retryBaseMilliseconds * (2 ** ($attempt - 1)) * 1000);
            }
        }

        $failed = true;

        return null;
    }

    protected function pause(StorefrontReconciliationOptions $options): void
    {
        if ($options->pauseMilliseconds > 0) {
            usleep($options->pauseMilliseconds * 1000);
        }
    }

    protected function resolveCostLots(bool $apply): int
    {
        $opened = $this->openLots->handle($apply);
        $assigned = $this->assignWarehouses->handle($apply);

        return $opened['purchase_lots'] + $opened['opening_lots'] + $assigned['assigned_now'];
    }

    /**
     * @param  ReconciliationRun|null  $run
     * @return \Generator<int, int>
     */
    protected function orders(StorefrontReconciliationOptions $options, ?object $run): \Generator
    {
        $after = $run === null ? 0 : (int) $run->checkpoint_order_id;
        $runId = $run?->id;

        do {
            $ids = Order::query()
                ->where('source', OrderSource::Website->value)
                ->where('id', '>', $after)
                ->when($options->order !== null, fn (Builder $query) => $query->where('reference', $options->order))
                ->when($options->partner !== null, fn (Builder $query) => $query->whereIn('business_account_id', BusinessAccount::query()->where('public_id', $options->partner)->select('id')))
                ->when($options->website !== null, fn (Builder $query) => $query->whereIn('website_id', DB::table('websites')->where('public_id', $options->website)->select('id')))
                ->when($options->from !== null, fn (Builder $query) => $query->where('placed_at', '>=', CarbonImmutable::parse((string) $options->from)->startOfDay()))
                ->when($options->to !== null, fn (Builder $query) => $query->where('placed_at', '<=', CarbonImmutable::parse((string) $options->to)->endOfDay()))
                ->when($options->provider !== null, fn (Builder $query) => $query->whereIn('id', Shipment::query()->whereHas('courierProvider', fn (Builder $provider) => $provider->where('code', $options->provider))->select('order_id')))
                ->when($runId !== null, fn (Builder $query) => $query->whereNotIn('id', DB::table('storefront_reconciliation_items')->where('storefront_reconciliation_run_id', $runId)->select('order_id')))
                ->orderBy('id')
                ->limit(50)
                ->pluck('id');

            foreach ($ids as $id) {
                yield $id;
                $after = $id;
            }
        } while ($ids->count() === 50);
    }

    /**
     * @return ReconciliationRun|null
     */
    protected function openRun(StorefrontReconciliationOptions $options): ?object
    {
        if ($options->resume !== null) {
            $stored = DB::table('storefront_reconciliation_runs')->where('public_id', $options->resume)->first();

            if ($stored === null || $stored->status === 'completed') {
                throw new RuntimeException("No unfinished reconciliation run {$options->resume} to resume.");
            }

            DB::table('storefront_reconciliation_runs')->where('id', $stored->id)->update(['status' => 'running', 'updated_at' => now()]);

            return $this->runShape($stored, (array) json_decode((string) $stored->options, true), (array) json_decode((string) ($stored->counts ?? '{}'), true));
        }

        if (! $options->apply) {
            return null;
        }

        $publicId = (string) Str::ulid();

        DB::table('storefront_reconciliation_runs')->insert([
            'public_id' => $publicId,
            'status' => 'running',
            'options' => json_encode($options->toArray()),
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->runShape(DB::table('storefront_reconciliation_runs')->where('public_id', $publicId)->sole(), $options->toArray(), []);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $counts
     * @return ReconciliationRun
     */
    protected function runShape(object $stored, array $options, array $counts): object
    {
        /** @var object{id: int|string, public_id: string, status: string, checkpoint_order_id: int|string|null} $stored */
        return (object) [
            'id' => (int) $stored->id,
            'public_id' => $stored->public_id,
            'status' => $stored->status,
            'options' => $options,
            'counts' => $counts,
            'checkpoint_order_id' => $stored->checkpoint_order_id === null ? null : (int) $stored->checkpoint_order_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function record(int $runId, int $orderId, array $row): void
    {
        DB::table('storefront_reconciliation_items')->insertOrIgnore([
            'storefront_reconciliation_run_id' => $runId,
            'order_id' => $orderId,
            'classification' => $row['classification'],
            'reasons' => json_encode($row['reasons']),
            'effects' => json_encode($row['effects']),
            'failure_message' => $row['failure'],
            'processed_at' => now(),
        ]);

        DB::table('storefront_reconciliation_runs')->where('id', $runId)->update(['checkpoint_order_id' => $orderId, 'updated_at' => now()]);
    }

    /**
     * A resumed run's counts cover every order it has finished, not only this sitting's.
     *
     * @param  array<string, int|string>  $current
     * @return array<string, int|string>
     */
    protected function previousCounts(int $runId, array $current): array
    {
        $counts = array_fill_keys(self::COUNTS, 0);
        $counts['withdrawable_credited'] = Money::zero()->toDecimal();
        $counts['cost_lots_resolved'] = $current['cost_lots_resolved'];

        foreach (DB::table('storefront_reconciliation_items')->where('storefront_reconciliation_run_id', $runId)->get() as $item) {
            $this->tally($counts, [
                'classification' => $item->classification,
                'reasons' => json_decode((string) $item->reasons, true),
                'effects' => json_decode((string) $item->effects, true),
            ]);
        }

        return $counts;
    }

    /**
     * @param  array<string, int|string>  $counts
     * @param  array<string, mixed>  $row
     */
    protected function tally(array &$counts, array $row): void
    {
        $reasons = (array) $row['reasons'];
        $effects = (array) $row['effects'];
        $this->bump($counts, 'orders_scanned');
        $this->bump($counts, 'courier_records_reconciled', (int) ($effects['courier_records_reconciled'] ?? 0));
        $this->bump($counts, 'delivered_statuses_updated', isset($effects['delivered_status_updated']) ? 1 : 0);
        $this->bump($counts, 'account_types_corrected', isset($effects['account_type_corrected']) ? 1 : 0);
        $this->bump($counts, 'settlements_created', isset($effects['settlement_created']) ? 1 : 0);
        $this->bump($counts, 'labels_generated', (int) ($effects['labels_generated'] ?? 0));

        if (isset($effects['profit_credited'])) {
            $this->bump($counts, 'profits_credited');
            $counts['withdrawable_credited'] = Money::fromDecimal((string) $counts['withdrawable_credited'])->plus(Money::fromDecimal((string) $effects['profit_credited']))->toDecimal();
        }

        $bucket = match ($row['classification']) {
            'awaiting_cod_collection' => 'awaiting_cod_collection',
            'already_credited', 'no_profit', 'reported_only', 'cancelled', 'not_storefront' => 'already_credited_or_skipped',
            'cost_basis_review_required' => 'ambiguous_cost_basis',
            'possible_duplicate_review_required' => 'possible_duplicates',
            'technical_failure' => 'technical_failures',
            'review_required', 'delivered_time_review_required' => 'review_required',
            default => null,
        };

        if ($bucket !== null) {
            $this->bump($counts, $bucket);
        }

        $this->bump($counts, 'missing_courier_references', in_array('courier_reference_required', $reasons, true) ? 1 : 0);
        $this->bump($counts, 'missing_warehouse', in_array('missing_warehouse', $reasons, true) ? 1 : 0);

        if ($row['classification'] !== 'review_required' && $row['classification'] !== 'delivered_time_review_required'
            && array_intersect($reasons, ['account_type_review_required', 'courier_review_required', 'courier_booking_uncertain', 'courier_lookup_failed', 'label_review_required', 'delivered_time_review_required']) !== []) {
            $this->bump($counts, 'review_required');
        }
    }

    /**
     * @param  array<string, int|string>  $counts
     */
    protected function bump(array &$counts, string $key, int $by = 1): void
    {
        $counts[$key] = (int) $counts[$key] + $by;
    }

    /**
     * References, codes and amounts only — never the customer.
     *
     * @param  list<string>  $reasons
     * @param  array<string, mixed>  $effects
     * @return array<string, mixed>
     */
    protected function row(Order $order, string $classification, array $reasons, array $effects, ?string $failure = null): array
    {
        $profit = PartnerStorefrontProfit::query()->where('order_id', $order->id)->first();

        return [
            'order' => $order->reference,
            'partner' => BusinessAccount::query()->whereKey($order->business_account_id)->value('partner_number'),
            'classification' => $classification,
            'reasons' => $reasons,
            'effects' => $effects,
            'profit_status' => $profit?->status->value,
            'profit' => $profit?->profit_amount?->toDecimal(),
            'release_at' => $profit?->release_at?->toIso8601String(),
            'failure' => $failure,
        ];
    }

    /**
     * @param  array<string, int|string>  $counts
     * @param  list<array<string, mixed>>  $rows
     * @return array{json: string, csv: string}
     */
    protected function export(?string $run, StorefrontReconciliationOptions $options, array $counts, array $rows): array
    {
        $name = 'reconciliation/storefront-'.($run ?? 'dry-run-'.now()->format('Ymd-His')).'-'.Str::lower(Str::random(4));
        $disk = Storage::disk('local');

        $disk->put($name.'.json', (string) json_encode(['run' => $run, 'apply' => $options->apply, 'counts' => $counts, 'rows' => $rows], JSON_PRETTY_PRINT));

        $csv = fopen('php://temp', 'r+');

        if ($csv === false) {
            throw new RuntimeException('Could not open a buffer for the CSV report.');
        }

        fputcsv($csv, ['order', 'partner', 'classification', 'reasons', 'profit_status', 'profit', 'release_at', 'credited', 'failure']);

        foreach ($rows as $row) {
            fputcsv($csv, [$row['order'], $row['partner'], $row['classification'], implode('|', $row['reasons']), $row['profit_status'], $row['profit'], $row['release_at'], $row['effects']['profit_credited'] ?? '', $row['failure']]);
        }

        rewind($csv);
        $disk->put($name.'.csv', (string) stream_get_contents($csv));
        fclose($csv);

        return ['json' => $disk->path($name.'.json'), 'csv' => $disk->path($name.'.csv')];
    }
}
