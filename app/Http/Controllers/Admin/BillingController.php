<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Billing\Actions\ManageFeeRules;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Models\FeeRule;
use App\Domain\Billing\Policies\BillingSettingsPolicy;
use App\Domain\Package\Models\Package;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * What Feriwala charges (§9).
 *
 * Fee rules, and later coupons and tax rules, on one screen: they are one
 * decision with three shapes, they all change the amount on somebody's invoice,
 * and splitting them across three destinations would mean three places to look
 * before answering "why is this total what it is".
 *
 * Behind `payment.manage_settings` — the person who reconciles the money is the
 * person who should be able to price it, and deliberately not the person who
 * writes package copy.
 */
class BillingController extends Controller
{
    public function __construct(
        protected ManageFeeRules $feeRules,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless(BillingSettingsPolicy::canView($actor), 403);

        return Inertia::render('admin/billing', [
            'fee_rules' => FeeRule::query()
                ->with('package:id,name')
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->get()
                ->map(fn (FeeRule $rule) => [
                    'id' => $rule->public_id,
                    'fee_type' => $rule->fee_type->value,
                    'fee_type_label' => $rule->fee_type->label(),
                    'package' => $rule->package?->name,
                    'amount' => $rule->amount_minor->jsonSerialize(),
                    'effective_from' => $rule->effective_from->toIso8601String(),
                    'effective_until' => $rule->effective_until?->toIso8601String(),
                    'is_active' => $rule->is_active,
                    'in_force' => $rule->isInForce(),
                    'note' => $rule->note,
                ])
                ->all(),

            // Only plans on sale can take a scoped rule: pricing a fee for an
            // archived package would configure something nobody can buy.
            'packages' => Package::query()
                ->available()
                ->orderBy('name')
                ->get(['slug', 'name', 'public_id'])
                ->map(fn (Package $package) => [
                    'id' => $package->public_id,
                    'name' => $package->name,
                ])
                ->all(),

            'fee_types' => array_map(fn (FeeType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
            ], FeeType::cases()),

            'can' => ['manage' => BillingSettingsPolicy::canManage($actor)],
        ]);
    }

    public function storeFeeRule(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(BillingSettingsPolicy::canManage($actor), 403);

        $validated = $request->validate([
            'fee_type' => ['required', Rule::enum(FeeType::class)],
            'package' => ['nullable', 'string'],
            'amount_minor' => ['required', 'integer', 'min:0'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $package = empty($validated['package'])
            ? null
            : Package::query()->where('public_id', $validated['package'])->firstOrFail();

        try {
            $this->feeRules->create(
                actor: $actor,
                type: FeeType::from($validated['fee_type']),
                amount: Money::of((int) $validated['amount_minor'], Currency::BDT),
                effectiveFrom: CarbonImmutable::parse($validated['effective_from']),
                effectiveUntil: isset($validated['effective_until'])
                    ? CarbonImmutable::parse($validated['effective_until'])
                    : null,
                packageId: $package?->id,
                note: $validated['note'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            // An overlapping window is a legitimate answer to a request, not an
            // error page: the administrator has to close the open rule first.
            throw ValidationException::withMessages([
                'effective_from' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('billing.fees.created')]);

        return back();
    }

    public function closeFeeRule(Request $request, string $rule): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(BillingSettingsPolicy::canManage($actor), 403);

        $record = FeeRule::query()->where('public_id', $rule)->firstOrFail();

        $this->feeRules->close($actor, $record);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('billing.fees.closed')]);

        return back();
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
