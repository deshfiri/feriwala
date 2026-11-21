<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Billing\Actions\ManageDeliveryCharges;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\FeeRuleResolver;
use App\Domain\Billing\Models\FeeRule;
use App\Domain\Billing\OrderDeliveryCharges;
use App\Domain\Billing\Policies\BillingSettingsPolicy;
use App\Domain\Tax\Enums\TaxScope;
use App\Domain\Tax\TaxEngine;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Money;
use App\Support\Money\Rules\DecimalAmountRule;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Admin → Orders → Delivery Charges: the Inside Dhaka and Outside Dhaka
 * customer rates (D37) and the separate flat wholesale charge, on the existing
 * fee rules.
 *
 * The same rules Admin → Billing lists, under the same billing permission, so
 * the two screens can never disagree about who may change them. Every figure,
 * the preview included, is worked out here: the page only shows it.
 */
class DeliveryChargeController extends Controller
{
    /** The order subtotal the preview is worked out on. */
    public const PREVIEW_SUBTOTAL = '1000.00';

    public function __construct(
        protected OrderDeliveryCharges $charges,
        protected FeeRuleResolver $resolver,
        protected ManageDeliveryCharges $manage,
        protected TaxEngine $tax,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless(BillingSettingsPolicy::canView($actor), 403);

        $now = CarbonImmutable::now();
        $rates = [];

        foreach (ManageDeliveryCharges::rates() as $key => $type) {
            $rates[] = $this->rate($key, $type, $now);
        }

        return Inertia::render('admin/delivery-charges/index', [
            'rates' => $rates,
            // Customer checkout fails closed until both areas have a rate.
            'customer_checkout_ready' => $this->charges->missingRates($now) === [],
            'can_manage' => BillingSettingsPolicy::canManage($actor),
        ]);
    }

    /**
     * Set a rate from now.
     */
    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(BillingSettingsPolicy::canManage($actor), 403);

        $validated = $request->validate([
            'rate' => ['required', Rule::in(array_keys(ManageDeliveryCharges::rates()))],
            // Flat Taka as typed (D26); zero is "Free delivery".
            'amount' => ['required', new DecimalAmountRule],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->manage->set(
                $actor,
                ManageDeliveryCharges::rates()[$validated['rate']],
                DecimalAmount::parse($validated['amount']),
                $validated['note'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('delivery_charges.flash.saved')]);

        return back();
    }

    /**
     * Switch a rate off from now.
     */
    public function destroy(Request $request, string $rule): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(BillingSettingsPolicy::canManage($actor), 403);

        /** @var FeeRule $record */
        $record = FeeRule::query()
            ->where('public_id', $rule)
            ->whereNull('package_id')
            ->whereIn('fee_type', array_map(fn (FeeType $type) => $type->value, ManageDeliveryCharges::rates()))
            ->firstOrFail();

        $this->manage->deactivate($actor, $record);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('delivery_charges.flash.deactivated')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rate(string $key, FeeType $type, CarbonImmutable $now): array
    {
        $rule = $this->resolver->ruleFor($type, null, $now);

        return [
            'key' => $key,
            'group' => $type === FeeType::WholesaleDelivery ? 'wholesale' : 'customer',
            'rule' => $rule === null ? null : [
                'id' => $rule->public_id,
                'amount' => $rule->amount->jsonSerialize(),
                'is_free' => $rule->amount->isZero(),
                'since' => $rule->effective_from->toIso8601String(),
            ],
            'preview' => $rule === null ? null : $this->preview($type, $rule->amount, $now),
        ];
    }

    /**
     * An example order at {@see self::PREVIEW_SUBTOTAL}, with the delivery tax
     * the tax rules assess on it now.
     *
     * @return array<string, mixed>
     */
    protected function preview(FeeType $type, Money $delivery, CarbonImmutable $now): array
    {
        $subtotal = Money::fromDecimal(self::PREVIEW_SUBTOTAL, $delivery->currency);
        $scope = $type === FeeType::WholesaleDelivery ? FeeType::WholesaleDelivery->value : OrderDeliveryCharges::taxScopeValue();
        $deliveryTax = $this->tax->charge(amount: $delivery, at: $now, scope: TaxScope::Fee, scopeValue: $scope);
        $added = $deliveryTax->mode->isInclusive() ? Money::zero($delivery->currency) : $deliveryTax->tax;

        return [
            'subtotal' => $subtotal->jsonSerialize(),
            'delivery' => $delivery->jsonSerialize(),
            'is_free' => $delivery->isZero(),
            'delivery_tax' => $deliveryTax->tax->isZero() ? null : [
                'amount' => $deliveryTax->tax->jsonSerialize(),
                'label' => $deliveryTax->label,
                'is_inclusive' => $deliveryTax->mode->isInclusive(),
            ],
            'total' => $subtotal->plus($delivery)->plus($added)->jsonSerialize(),
        ];
    }

    protected function actor(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
