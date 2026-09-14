<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Inventory\Actions\ManageWarehouses;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Policies\InventoryPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where central stock is held (§19, D15).
 *
 * Reading the list is `inventory.view`; adding, editing and choosing the default
 * is `inventory.edit`, asked before any rule runs so a refused person learns
 * nothing about what their request lacked. A partner holds neither (§19).
 */
class WarehouseController extends Controller
{
    public function __construct(
        protected ManageWarehouses $warehouses,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless(InventoryPolicy::canViewAny($actor), 403);

        return Inertia::render('admin/inventory/warehouses', [
            'warehouses' => Warehouse::query()
                ->withCount('stockItems')
                ->inPriorityOrder()
                ->get()
                ->map(fn (Warehouse $warehouse) => [
                    'id' => $warehouse->public_id,
                    'code' => $warehouse->code,
                    'name' => $warehouse->name,
                    'address' => $warehouse->address,
                    'priority' => $warehouse->priority,
                    'is_active' => $warehouse->is_active,
                    'is_default' => $warehouse->is_default,
                    'stock_items_count' => (int) $warehouse->getAttribute('stock_items_count'),
                ])
                ->all(),
            'can' => [
                'edit' => InventoryPolicy::canEdit($actor),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(InventoryPolicy::canEdit($actor), 403);

        if (is_string($request->input('code'))) {
            $request->merge(['code' => mb_strtoupper(trim($request->input('code')))]);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9][A-Z0-9-]*$/', Rule::unique(Warehouse::class, 'code')],
            ...$this->rules(),
        ]);

        $warehouse = $this->warehouses->create($actor, $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('inventory.warehouses.created', ['name' => $warehouse->name])]);

        return back();
    }

    public function update(Request $request, string $warehouse): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(InventoryPolicy::canEdit($actor), 403);

        $record = $this->warehouse($warehouse);

        $validated = $request->validate([
            // Printed on labels and pick lists, so fixed once saved — refused
            // by name rather than silently ignored.
            'code' => ['missing'],
            ...$this->rules(),
        ], [
            'code.missing' => __('inventory.warehouses.code_fixed'),
        ]);

        try {
            $this->warehouses->update($actor, $record, $validated);
        } catch (InventoryRefused $refused) {
            throw ValidationException::withMessages(['is_active' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('inventory.warehouses.updated', ['name' => $record->name])]);

        return back();
    }

    public function makeDefault(Request $request, string $warehouse): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(InventoryPolicy::canEdit($actor), 403);

        $record = $this->warehouse($warehouse);

        try {
            $this->warehouses->makeDefault($actor, $record);
        } catch (InventoryRefused $refused) {
            throw ValidationException::withMessages(['warehouse' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('inventory.warehouses.default_changed', ['name' => $record->name])]);

        return back();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['boolean'],
        ];
    }

    protected function warehouse(string $publicId): Warehouse
    {
        /** @var Warehouse $warehouse */
        $warehouse = Warehouse::query()->where('public_id', $publicId)->firstOrFail();

        return $warehouse;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
