<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Billing\Actions\ManageCoupons;
use App\Domain\Billing\Actions\ManageFeeRules;
use App\Domain\Billing\Enums\CouponScope;
use App\Domain\Billing\Enums\DiscountType;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Models\Coupon;
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
        protected ManageCoupons $coupons,
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

            'coupons' => Coupon::query()
                ->with('package:id,name')
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->get()
                ->map(fn (Coupon $coupon) => [
                    'id' => $coupon->public_id,
                    'code' => $coupon->code,
                    'name' => $coupon->name,
                    'discount_type' => $coupon->discount_type->value,
                    'discount_label' => $coupon->discount_type === DiscountType::Percentage
                        ? number_format($coupon->value / 100, 2).'%'
                        : Money::of($coupon->value, $coupon->currency())->format(),
                    'applies_to' => $coupon->applies_to->value,
                    'applies_to_label' => $coupon->applies_to->label(),
                    'package' => $coupon->package?->name,
                    'usage_limit' => $coupon->usage_limit,
                    'redeemed_count' => $coupon->redeemed_count,
                    'per_account_limit' => $coupon->per_account_limit,
                    'effective_from' => $coupon->effective_from->toIso8601String(),
                    'effective_until' => $coupon->effective_until?->toIso8601String(),
                    'is_active' => $coupon->is_active,
                    'is_open' => $coupon->isOpen(),
                ])
                ->all(),

            'discount_types' => array_map(fn (DiscountType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
            ], DiscountType::cases()),

            'coupon_scopes' => array_map(fn (CouponScope $scope) => [
                'value' => $scope->value,
                'label' => $scope->label(),
            ], CouponScope::cases()),

            'can' => ['manage' => BillingSettingsPolicy::canManage($actor)],
        ]);
    }

    public function storeCoupon(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(BillingSettingsPolicy::canManage($actor), 403);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:191'],
            'discount_type' => ['required', Rule::enum(DiscountType::class)],
            'value' => ['required', 'integer', 'min:1'],
            'applies_to' => ['required', Rule::enum(CouponScope::class)],
            'package' => ['nullable', 'string'],
            'minimum_spend_minor' => ['nullable', 'integer', 'min:0'],
            'maximum_discount_minor' => ['nullable', 'integer', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_account_limit' => ['nullable', 'integer', 'min:1'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ]);

        $package = empty($validated['package'])
            ? null
            : Package::query()->where('public_id', $validated['package'])->firstOrFail();

        try {
            $this->coupons->create(
                actor: $actor,
                code: $validated['code'],
                name: $validated['name'],
                type: DiscountType::from($validated['discount_type']),
                value: (int) $validated['value'],
                appliesTo: CouponScope::from($validated['applies_to']),
                currency: Currency::BDT,
                effectiveFrom: CarbonImmutable::parse($validated['effective_from']),
                effectiveUntil: isset($validated['effective_until'])
                    ? CarbonImmutable::parse($validated['effective_until'])
                    : null,
                packageId: $package?->id,
                minimumSpend: isset($validated['minimum_spend_minor'])
                    ? Money::of((int) $validated['minimum_spend_minor'], Currency::BDT)
                    : null,
                maximumDiscount: isset($validated['maximum_discount_minor'])
                    ? Money::of((int) $validated['maximum_discount_minor'], Currency::BDT)
                    : null,
                usageLimit: isset($validated['usage_limit']) ? (int) $validated['usage_limit'] : null,
                perAccountLimit: isset($validated['per_account_limit'])
                    ? (int) $validated['per_account_limit']
                    : null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['code' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('billing.coupons.created')]);

        return back();
    }

    public function withdrawCoupon(Request $request, string $coupon): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(BillingSettingsPolicy::canManage($actor), 403);

        $record = Coupon::query()->where('public_id', $coupon)->firstOrFail();

        $this->coupons->withdraw($actor, $record);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('billing.coupons.closed')]);

        return back();
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
