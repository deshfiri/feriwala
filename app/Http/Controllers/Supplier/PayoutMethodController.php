<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Supplier\Actions\SavePayoutMethod;
use App\Domain\Supplier\Enums\SupplierPayoutMethodStatus;
use App\Domain\Supplier\Enums\SupplierPayoutMethodType;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierPayoutMethod;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A Supplier's own payout methods (D25, P13-24).
 *
 * Never returns an unmasked account number: {@see SupplierPayoutMethod}
 * hides `details` unconditionally, and every response here is built from
 * {@see SupplierPayoutMethod::toSnapshot()} plus `maskedNumber()`. Creating,
 * updating or archiving a method is sensitive — the supplier guard has no
 * Fortify confirm-password flow, so this asks for the current password
 * inline, the same `current_password:supplier` rule
 * {@see AccountController::updatePassword()} already uses.
 */
class PayoutMethodController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $methods = SupplierPayoutMethod::query()
            ->where('supplier_id', $supplier->id)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get()
            ->map(fn (SupplierPayoutMethod $method) => $this->row($method));

        return Inertia::render('supplier/payout-methods/index', [
            'methods' => $methods,
            'types' => array_map(
                fn (SupplierPayoutMethodType $case) => ['value' => $case->value, 'label' => $case->label(), 'fields' => $case->detailFields()],
                SupplierPayoutMethodType::cases(),
            ),
        ]);
    }

    public function store(Request $request, SavePayoutMethod $save): RedirectResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $validated = $this->validated($request);

        $save->handle(
            $supplier,
            SupplierPayoutMethodType::from($validated['type']),
            $validated['label'],
            $validated['details'],
            $validated['is_default'],
        );

        return back()->with('success', __('supplier.payout_methods.created'));
    }

    public function update(Request $request, string $method, SavePayoutMethod $save): RedirectResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        /** @var SupplierPayoutMethod $existing */
        $existing = SupplierPayoutMethod::query()
            ->where('supplier_id', $supplier->id)
            ->where('public_id', $method)
            ->firstOrFail();

        $validated = $this->validated($request);

        $save->handle(
            $supplier,
            SupplierPayoutMethodType::from($validated['type']),
            $validated['label'],
            $validated['details'],
            $validated['is_default'],
            existing: $existing,
        );

        return back()->with('success', __('supplier.payout_methods.updated'));
    }

    public function archive(Request $request, string $method, SavePayoutMethod $save): RedirectResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $request->validate(['current_password' => ['required', 'string', 'current_password:supplier']]);

        /** @var SupplierPayoutMethod $existing */
        $existing = SupplierPayoutMethod::query()
            ->where('supplier_id', $supplier->id)
            ->where('public_id', $method)
            ->firstOrFail();

        $save->archive($existing);

        return back()->with('success', __('supplier.payout_methods.archived'));
    }

    /**
     * @return array{type: string, label: string, details: array<string, mixed>, is_default: bool}
     */
    protected function validated(Request $request): array
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password:supplier'],
            'type' => ['required', Rule::enum(SupplierPayoutMethodType::class)],
            'label' => ['required', 'string', 'max:80'],
            'details' => ['required', 'array'],
            'details.*' => ['required', 'string', 'max:120'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $type = SupplierPayoutMethodType::from($validated['type']);
        $allowed = $type->detailFields();
        $details = array_intersect_key($validated['details'], array_flip($allowed));

        $missing = array_filter($allowed, fn (string $field) => trim((string) ($details[$field] ?? '')) === '');

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'details' => __('supplier.payout_methods.missing_fields', ['fields' => implode(', ', $missing)]),
            ]);
        }

        return [
            'type' => $validated['type'],
            'label' => $validated['label'],
            'details' => $details,
            'is_default' => (bool) ($validated['is_default'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(SupplierPayoutMethod $method): array
    {
        return [
            'id' => $method->public_id,
            'type' => $method->type->value,
            'type_label' => $method->type->label(),
            'label' => $method->label,
            'masked_number' => $method->maskedNumber(),
            'is_default' => $method->is_default,
            'is_active' => $method->status === SupplierPayoutMethodStatus::Active,
            'status_label' => $method->status->label(),
            'verified_at' => $method->verified_at?->toIso8601String(),
            'created_at' => $method->created_at->toIso8601String(),
        ];
    }
}
