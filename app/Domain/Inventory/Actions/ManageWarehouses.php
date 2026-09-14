<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Policies\InventoryPolicy;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Warehouses: adding one, editing one, and choosing the default (§19, D15).
 *
 * The first warehouse becomes the default, so stock always has somewhere D15
 * looks first. Exactly one warehouse is ever the default — one partial unique
 * index holds that in the database — and it has to be active: the default is
 * switched off only after another has taken its place. Every write is audited,
 * because which warehouse is tried first decides where every order is filled
 * from.
 */
class ManageWarehouses
{
    public function __construct(
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @param  array{code: string, name: string, address?: string|null, priority?: int|null, is_active?: bool|null}  $attributes
     *
     * @throws AuthorizationException
     */
    public function create(User $actor, array $attributes): Warehouse
    {
        InventoryPolicy::authorize(InventoryPolicy::canEdit($actor));

        return DB::transaction(function () use ($actor, $attributes) {
            // Serialise creation, so two first warehouses cannot both become the default.
            DB::statement('LOCK TABLE warehouses IN SHARE ROW EXCLUSIVE MODE');

            $active = (bool) ($attributes['is_active'] ?? true);
            $isFirst = ! Warehouse::query()->where('is_default', true)->exists();

            $warehouse = Warehouse::create([
                'code' => mb_strtoupper(trim($attributes['code'])),
                'name' => trim($attributes['name']),
                'address' => $attributes['address'] ?? null,
                'priority' => (int) ($attributes['priority'] ?? 0),
                'is_active' => $active,
                'is_default' => $isFirst && $active,
            ]);

            $this->record($actor, 'inventory.warehouse_created', $warehouse, null, $this->snapshot($warehouse));

            return $warehouse;
        });
    }

    /**
     * @param  array{name: string, address?: string|null, priority?: int|null, is_active?: bool|null}  $attributes
     *
     * @throws AuthorizationException
     * @throws InventoryRefused when the default would be switched off
     */
    public function update(User $actor, Warehouse $warehouse, array $attributes): Warehouse
    {
        InventoryPolicy::authorize(InventoryPolicy::canEdit($actor));

        return DB::transaction(function () use ($actor, $warehouse, $attributes) {
            /** @var Warehouse $locked */
            $locked = Warehouse::query()->lockForUpdate()->findOrFail($warehouse->id);
            $before = $this->snapshot($locked);

            $active = (bool) ($attributes['is_active'] ?? $locked->is_active);

            if ($locked->is_default && ! $active) {
                throw InventoryRefused::defaultMustStayActive();
            }

            $locked->forceFill([
                'name' => trim($attributes['name']),
                'address' => $attributes['address'] ?? null,
                'priority' => (int) ($attributes['priority'] ?? $locked->priority),
                'is_active' => $active,
            ])->save();

            $this->record($actor, 'inventory.warehouse_updated', $locked, $before, $this->snapshot($locked));

            // A warehouse switched off or on changes what every product it holds
            // has available, without a single stock movement (P3-28).
            if ($before['is_active'] !== $locked->is_active) {
                foreach ($locked->stockItems()->distinct()->pluck('product_id') as $productId) {
                    DB::afterCommit(fn () => app(RespondToStockChange::class)->handle((int) $productId));
                }
            }

            $warehouse->setRawAttributes($locked->getAttributes(), sync: true);

            return $locked;
        });
    }

    /**
     * @throws AuthorizationException
     * @throws InventoryRefused when the warehouse is switched off
     */
    public function makeDefault(User $actor, Warehouse $warehouse): Warehouse
    {
        InventoryPolicy::authorize(InventoryPolicy::canEdit($actor));

        return DB::transaction(function () use ($actor, $warehouse) {
            DB::statement('LOCK TABLE warehouses IN SHARE ROW EXCLUSIVE MODE');

            /** @var Warehouse $locked */
            $locked = Warehouse::query()->findOrFail($warehouse->id);

            if (! $locked->is_active) {
                throw InventoryRefused::defaultMustBeActive();
            }

            if ($locked->is_default) {
                return $locked;
            }

            $previous = Warehouse::query()->where('is_default', true)->first();

            // The old default gives the flag up first; the partial unique index
            // would otherwise refuse the second holder.
            $previous?->forceFill(['is_default' => false])->save();
            $locked->forceFill(['is_default' => true])->save();

            $this->record(
                $actor,
                'inventory.warehouse_default_changed',
                $locked,
                ['default' => $previous?->code],
                ['default' => $locked->code],
            );

            $warehouse->setRawAttributes($locked->getAttributes(), sync: true);

            return $locked;
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshot(Warehouse $warehouse): array
    {
        return $warehouse->only(['code', 'name', 'address', 'priority', 'is_active', 'is_default']);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    protected function record(User $actor, string $action, Warehouse $warehouse, ?array $before, ?array $after): void
    {
        $this->audit->handle(new AuditEntry(
            action: $action,
            actorId: $actor->id,
            auditableType: Warehouse::class,
            auditableId: $warehouse->id,
            before: $before,
            after: $after,
            module: 'inventory',
        ));
    }
}
