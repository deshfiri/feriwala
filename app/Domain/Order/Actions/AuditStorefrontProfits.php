<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderProceedsSettlement;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Classifies every Storefront order that was ever delivered: what its Partner
 * earns, and whether that would be credited, is already credited, waits, or
 * needs a person.
 *
 * **Read-only unless told otherwise.** Each order is worked out by the very same
 * {@see SettleStorefrontProfit::sync()} the live system uses, inside a
 * transaction that is always rolled back — so the report is exactly what the
 * system would record, and nothing is written, no notification is sent and no
 * Wallet is touched.
 *
 * With `$apply`, the classification is committed. Even then nothing is credited
 * here: a profit that is due is credited by the minute sweep under its own
 * Wallet key, and one that needs review never is.
 */
class AuditStorefrontProfits
{
    public function __construct(
        protected DatabaseManager $database,
        protected SettleStorefrontProfit $settle,
    ) {}

    /**
     * @return Collection<int, array{order: string, account: int, status: string, reason: string|null, selling: string|null, cost: string|null, profit: string|null, legacy_credited: string, release_at: string|null, bases: string, error: string|null}>
     */
    public function handle(bool $apply, ?int $accountId = null): Collection
    {
        $rows = collect();

        $this->candidates($accountId)->chunkById(100, function (Collection $orders) use ($apply, $rows): void {
            foreach ($orders as $order) {
                $rows->push($this->classify($order, $apply));
            }
        });

        return $rows;
    }

    /**
     * @return Builder<Order>
     */
    protected function candidates(?int $accountId): Builder
    {
        return Order::query()
            ->where('source', OrderSource::Website->value)
            ->when($accountId !== null, fn ($query) => $query->where('business_account_id', $accountId))
            ->where(fn ($query) => $query
                ->whereExists(fn ($history) => $history->from('order_delivery_status_history')
                    ->whereColumn('order_delivery_status_history.order_id', 'orders.id')
                    ->where('new_status', OrderDeliveryStatus::Delivered->value))
                ->orWhereIn('id', OrderProceedsSettlement::query()->whereNotNull('wallet_transaction_id')->select('order_id')))
            ->orderBy('id');
    }

    /**
     * @return array{order: string, account: int, status: string, reason: string|null, selling: string|null, cost: string|null, profit: string|null, legacy_credited: string, release_at: string|null, bases: string, error: string|null}
     */
    protected function classify(Order $order, bool $apply): array
    {
        $connection = $this->database->connection();
        $connection->beginTransaction();

        try {
            $profit = $this->settle->sync($order);
            $profit?->load('lines');

            $row = [
                'order' => $order->reference,
                'account' => $order->business_account_id,
                'status' => $profit === null ? 'not_storefront' : $profit->status->value,
                'reason' => $profit?->status_reason,
                'selling' => $profit?->selling_amount?->toDecimal(),
                'cost' => $profit?->cost_amount?->toDecimal(),
                'profit' => $profit?->profit_amount?->toDecimal(),
                'legacy_credited' => $profit?->legacy_credited_amount->toDecimal() ?? '0.00',
                'release_at' => $profit?->release_at?->toIso8601String(),
                'bases' => $profit === null ? '' : $profit->lines
                    ->map(fn ($line) => $line->cost_basis.($line->unproven_reason !== null ? ' ('.$line->unproven_reason.')' : ''))
                    ->unique()
                    ->implode(', '),
                'error' => null,
            ];

            $apply ? $connection->commit() : $connection->rollBack();

            return $row;
        } catch (Throwable $throwable) {
            $connection->rollBack();

            return [
                'order' => $order->reference,
                'account' => $order->business_account_id,
                'status' => 'error',
                'reason' => null,
                'selling' => null,
                'cost' => null,
                'profit' => null,
                'legacy_credited' => '0.00',
                'release_at' => null,
                'bases' => '',
                'error' => $throwable->getMessage(),
            ];
        }
    }
}
