<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Inventory\Data\WarehouseEvidence;
use App\Domain\Inventory\Queries\PartnerStockWarehouseEvidence;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;

/**
 * Proves, lot by lot, which Banij warehouse holds Partner stock bought before
 * lots recorded it -- and assigns it only where it is proven.
 *
 * Every lot is classified:
 *
 *  - `assigned`: it already names its warehouse;
 *  - `safe`: exactly one warehouse is proven, and nothing of it is unaccounted for;
 *  - `ambiguous`: the records name more than one, or name one for only part of it;
 *  - `missing`: no record names any.
 *
 * A purchase lot is proven by its own wholesale line. An opening lot -- units on
 * the shelf before lots existed -- only by every purchase that ever credited its
 * stock row agreeing on one warehouse. An adjustment lot has no purchase behind
 * it, so it is always `missing`.
 *
 * **Read-only unless asked.** Applying assigns `safe` lots alone, each under its
 * own row lock and only while it is still unassigned, so a second run or a racing
 * sale changes nothing twice. Ambiguous and missing lots stay unassigned, for
 * staff -- never filled in with a default, or with "the only warehouse there is".
 */
class AssignPartnerLotWarehouses
{
    public function __construct(
        protected DatabaseManager $database,
        protected PartnerStockWarehouseEvidence $evidence,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @return array{counts: array<string, int>, review: list<array{lot: string, stock_item: int, classification: string, warehouses: list<int>}>, assigned_now: int}
     */
    public function handle(bool $apply, ?User $actor = null): array
    {
        $counts = ['assigned' => 0, WarehouseEvidence::SAFE => 0, WarehouseEvidence::AMBIGUOUS => 0, WarehouseEvidence::MISSING => 0];
        $review = [];
        $assignedNow = 0;
        $byWarehouse = [];

        $this->database->table('partner_stock_cost_lots')
            ->orderBy('id')
            ->select(['id', 'public_id', 'partner_stock_item_id', 'source_order_item_id', 'provenance', 'warehouse_id'])
            ->chunkById(500, function (Collection $lots) use ($apply, &$counts, &$review, &$assignedNow, &$byWarehouse): void {
                foreach ($lots as $lot) {
                    if ($lot->warehouse_id !== null) {
                        $counts['assigned']++;

                        continue;
                    }

                    $evidence = $this->evidenceFor($lot);
                    $classification = $evidence->classification();
                    $counts[$classification]++;

                    if ($classification !== WarehouseEvidence::SAFE) {
                        $review[] = [
                            'lot' => (string) $lot->public_id,
                            'stock_item' => (int) $lot->partner_stock_item_id,
                            'classification' => $classification,
                            'warehouses' => $evidence->warehouses,
                        ];

                        continue;
                    }

                    $warehouseId = (int) $evidence->warehouse();

                    if ($apply && $this->assign((int) $lot->id, $warehouseId, (string) $evidence->source)) {
                        $assignedNow++;
                        $byWarehouse[$warehouseId] = ($byWarehouse[$warehouseId] ?? 0) + 1;
                    }
                }
            });

        if ($apply && $assignedNow > 0) {
            $this->audit->handle(new AuditEntry(
                action: 'inventory.partner_lot_warehouses_assigned',
                actorId: $actor?->id,
                actorType: $actor === null ? 'system' : 'user',
                actorLabel: $actor === null ? 'inventory:partner-lot-warehouses' : null,
                // Counts only: which warehouse proved how many lots.
                after: ['assigned' => $assignedNow, 'by_warehouse' => $byWarehouse, 'left_for_review' => count($review)],
                module: 'inventory',
            ));
        }

        return ['counts' => $counts, 'review' => $review, 'assigned_now' => $assignedNow];
    }

    /**
     * @param  object{provenance: string, source_order_item_id: int|null, partner_stock_item_id: int}  $lot
     */
    protected function evidenceFor(object $lot): WarehouseEvidence
    {
        return match ($lot->provenance) {
            'purchase' => $lot->source_order_item_id === null
                ? new WarehouseEvidence([], true, null)
                : $this->evidence->forPurchaseLine((int) $lot->source_order_item_id),
            'opening' => $this->evidence->forStockRow((int) $lot->partner_stock_item_id),
            default => new WarehouseEvidence([], true, null),
        };
    }

    protected function assign(int $lotId, int $warehouseId, string $source): bool
    {
        return $this->database->transaction(function () use ($lotId, $warehouseId, $source): bool {
            $locked = $this->database->table('partner_stock_cost_lots')->where('id', $lotId)->lockForUpdate()->first(['warehouse_id']);

            if ($locked === null || $locked->warehouse_id !== null) {
                return false;
            }

            return $this->database->table('partner_stock_cost_lots')
                ->where('id', $lotId)
                ->whereNull('warehouse_id')
                ->update(['warehouse_id' => $warehouseId, 'warehouse_evidence' => $source]) === 1;
        });
    }
}
