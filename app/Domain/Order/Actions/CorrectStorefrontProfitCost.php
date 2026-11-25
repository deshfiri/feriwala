<?php

namespace App\Domain\Order\Actions;

use App\Domain\Access\Data\SensitiveActionRequest;
use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\SensitiveActionGuard;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Inventory\Models\PartnerStockCostLot;
use App\Domain\Inventory\PartnerStockCostLots;
use App\Domain\Order\Enums\StorefrontProfitStatus;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\PartnerStorefrontProfit;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Finance decides what a Storefront line's units cost when the record cannot
 * prove it — a sale from the Partner's own stock made before cost lots existed,
 * when they had bought that product at more than one price.
 *
 * The person names the purchase lots and how many units came from each; the
 * cost is worked out from those lots' frozen purchase prices, never typed in.
 * The decision is kept exactly as made — who, why, the supporting reference and
 * the figures — and is never edited; a later decision is a new row that
 * supersedes it. It changes no lot's quantities: those units left the Partner's
 * stock long ago, and the lots hold what is on the shelf today.
 *
 * Only before the profit is credited; the credit itself then follows the
 * normal hold, under the same Wallet key, so nothing is ever credited twice.
 * A wallet-adjusting decision: wallet `adjust_wallet`, a confirmed password and
 * two-factor ({@see SensitiveActionRequest}).
 */
class CorrectStorefrontProfitCost
{
    public const MINIMUM_REASON = 10;

    public function __construct(
        protected DatabaseManager $database,
        protected SensitiveActionGuard $guard,
        protected PartnerStockCostLots $lots,
        protected SettleStorefrontProfit $settle,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * What the chosen lots would make the line cost and the order earn, without
     * recording anything.
     *
     * @param  array<string, int>  $allocations  lot public id => units
     * @return array{cost: Money, line_profit: Money, allocations: list<array{lot: string, quantity: int, cost: string}>}
     */
    public function preview(PartnerStorefrontProfit $profit, OrderItem $line, array $allocations): array
    {
        $priced = $this->price($profit, $line, $allocations);
        $netSelling = $line->line_subtotal->minus($line->discount)->minus($line->tax_included);

        return [
            'cost' => $priced['cost'],
            'line_profit' => $netSelling->minus($priced['cost']),
            'allocations' => $priced['allocations'],
        ];
    }

    /**
     * @param  array<string, int>  $allocations  lot public id => units
     */
    public function handle(
        User $actor,
        PartnerStorefrontProfit $profit,
        OrderItem $line,
        array $allocations,
        string $reason,
        string $reference,
        bool $passwordConfirmed,
        bool $twoFactorEnabled,
    ): PartnerStorefrontProfit {
        $reason = trim($reason);
        $reference = trim($reference);

        if (mb_strlen($reason) < self::MINIMUM_REASON || $reference === '') {
            throw new InvalidArgumentException(__('validation.required', ['attribute' => 'reason']));
        }

        $this->guard->authorize($actor, new SensitiveActionRequest(
            module: PermissionModule::Wallet,
            action: PermissionAction::AdjustWallet,
            reason: $reason,
            passwordConfirmed: $passwordConfirmed,
            twoFactorEnabled: $twoFactorEnabled,
        ));

        $this->database->transaction(function () use ($actor, $profit, $line, $allocations, $reason, $reference): void {
            /** @var PartnerStorefrontProfit $locked */
            $locked = PartnerStorefrontProfit::query()->lockForUpdate()->findOrFail($profit->id);

            if (! $locked->status->isUnsettled() || $locked->status === StorefrontProfitStatus::ReportedOnly) {
                throw new InvalidArgumentException(__('storefront_profit.correction.already_released'));
            }

            $priced = $this->price($locked, $line, $allocations);

            $id = DB::table('partner_storefront_profit_corrections')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'partner_storefront_profit_id' => $locked->id,
                'order_item_id' => $line->id,
                'currency_code' => $priced['cost']->currency->value,
                'allocations' => json_encode($priced['allocations'], JSON_THROW_ON_ERROR),
                'cost_amount' => $priced['cost']->toDecimal(),
                'reason' => $reason,
                'reference' => $reference,
                'actor_id' => $actor->id,
                'created_at' => now(),
            ]);

            $this->audit->handle(new AuditEntry(
                action: 'storefront_profit.cost_corrected',
                actorId: $actor->id,
                auditableType: PartnerStorefrontProfit::class,
                auditableId: $locked->id,
                after: [
                    'correction' => $id,
                    'line' => $line->public_id,
                    'cost' => $priced['cost']->toDecimal(),
                    'allocations' => $priced['allocations'],
                    'reference' => $reference,
                ],
                reason: $reason,
                accountId: $locked->business_account_id,
                module: PermissionModule::Wallet->value,
                isSensitive: true,
            ));
        });

        return $this->settle->sync($line->order) ?? $profit;
    }

    /**
     * @param  array<string, int>  $allocations
     * @return array{cost: Money, allocations: list<array{lot: string, quantity: int, cost: string}>}
     */
    protected function price(PartnerStorefrontProfit $profit, OrderItem $line, array $allocations): array
    {
        if ($line->order_id !== $profit->order_id || $line->stockReservation?->isPartnerSourced() !== true) {
            throw new InvalidArgumentException(__('storefront_profit.correction.lot_not_this_product'));
        }

        $units = array_sum(array_map('intval', $allocations));

        if ($units !== $line->quantity) {
            throw new InvalidArgumentException(__('storefront_profit.correction.units_must_match', ['quantity' => $line->quantity]));
        }

        $currency = Currency::from($line->currency_code);
        $total = Money::zero($currency);
        $rows = [];
        $stockItemId = $line->stockReservation->partner_stock_item_id;

        foreach ($allocations as $lotId => $quantity) {
            $quantity = (int) $quantity;

            if ($quantity <= 0) {
                continue;
            }

            $lot = PartnerStockCostLot::query()->where('public_id', $lotId)->first();

            if ($lot === null || $lot->partner_stock_item_id !== $stockItemId) {
                throw new InvalidArgumentException(__('storefront_profit.correction.lot_not_this_product'));
            }

            if (! $lot->cost_is_known || $lot->net_cost === null) {
                throw new InvalidArgumentException(__('storefront_profit.correction.lot_cost_unknown'));
            }

            if ($quantity > $lot->purchased_quantity) {
                throw new InvalidArgumentException(__('storefront_profit.correction.lot_too_small', ['quantity' => $lot->purchased_quantity]));
            }

            $cost = $this->lots->share($lot->net_cost, $lot->purchased_quantity, 0, $quantity);
            $total = $total->plus($cost);
            $rows[] = ['lot' => $lot->public_id, 'quantity' => $quantity, 'cost' => $cost->toDecimal()];
        }

        return ['cost' => $total, 'allocations' => $rows];
    }
}
