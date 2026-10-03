<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Billing\Actions\ManageDeliveryChargeRules;
use App\Domain\Billing\Actions\SetDeliveryChargeSettings;
use App\Domain\Billing\DeliveryChargeSettings;
use App\Domain\Billing\Models\DeliveryChargeRule;
use App\Domain\Courier\Models\CourierProvider;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Rules\DecimalAmountRule;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The admin screen for delivery-charge configuration (beta-critical batch,
 * Commit 2) -- the global knobs {@see
 * \App\Domain\Billing\CalculateDeliveryCharge} applies, and the dated
 * weight-tier rules it resolves against, each gated on its own
 * `delivery_settings.*` permission rather than any courier or order ability.
 */
class DeliverySettingsController extends Controller
{
    public function __construct(
        protected DeliveryChargeSettings $settings,
        protected SetDeliveryChargeSettings $setSettings,
        protected ManageDeliveryChargeRules $manageRules,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless($actor->can(PermissionCatalogue::name(PermissionModule::DeliverySettings, PermissionAction::View)), 403);

        $canEdit = $actor->can(PermissionCatalogue::name(PermissionModule::DeliverySettings, PermissionAction::Edit));
        $canManageSettings = $actor->can(PermissionCatalogue::name(PermissionModule::DeliverySettings, PermissionAction::ManageSettings));

        $currency = Currency::base();

        return Inertia::render('admin/delivery-settings/index', [
            'settings' => [
                'volumetric_divisor' => $this->settings->volumetricDivisor(),
                'use_greater_of_actual_and_volumetric' => $this->settings->useGreaterOfActualAndVolumetric(),
                'additional_per_kg_charge' => $this->settings->additionalPerKgCharge($currency)->jsonSerialize(),
                'per_box_charge' => $this->settings->perBoxCharge($currency)->jsonSerialize(),
                'fragile_handling_charge' => $this->settings->fragileHandlingCharge($currency)->jsonSerialize(),
                'minimum_charge' => $this->settings->minimumCharge($currency)->jsonSerialize(),
                'maximum_charge' => $this->settings->maximumCharge($currency)?->jsonSerialize(),
                'free_delivery_threshold' => $this->settings->freeDeliveryThreshold($currency)?->jsonSerialize(),
                'delivery_success_fee_percent' => $this->settings->deliverySuccessFeePercent(),
            ],
            'rules' => DeliveryChargeRule::query()
                ->with('courierProvider:id,code,name')
                ->orderByDesc('is_active')
                ->orderByDesc('effective_from')
                ->get()
                ->map(fn (DeliveryChargeRule $rule) => [
                    'id' => $rule->public_id,
                    'weight_from_grams' => $rule->weight_from_grams,
                    'weight_to_grams' => $rule->weight_to_grams,
                    'base_charge' => $rule->base_charge->jsonSerialize(),
                    'per_kg_charge' => $rule->per_kg_charge?->jsonSerialize(),
                    'area' => $rule->area,
                    'courier_provider' => $rule->courierProvider?->name,
                    'priority' => $rule->priority,
                    'effective_from' => $rule->effective_from->toIso8601String(),
                    'effective_until' => $rule->effective_until?->toIso8601String(),
                    'is_active' => $rule->is_active,
                    'note' => $rule->note,
                ])
                ->all(),
            'courier_providers' => CourierProvider::query()
                ->orderBy('id')
                ->get()
                ->map(fn (CourierProvider $provider) => ['id' => $provider->id, 'label' => $provider->name])
                ->all(),
            'can' => [
                'edit' => $canEdit,
                'manage_settings' => $canManageSettings,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless($actor->can(PermissionCatalogue::name(PermissionModule::DeliverySettings, PermissionAction::ManageSettings)), 403);

        $currency = Currency::base();

        $validated = $request->validate([
            'volumetric_divisor' => ['required', 'integer', 'min:1', 'max:100000'],
            'use_greater_of_actual_and_volumetric' => ['required', 'boolean'],
            'additional_per_kg_charge' => ['required', new DecimalAmountRule],
            'per_box_charge' => ['required', new DecimalAmountRule],
            'fragile_handling_charge' => ['required', new DecimalAmountRule],
            'minimum_charge' => ['required', new DecimalAmountRule],
            'maximum_charge' => ['nullable', new DecimalAmountRule],
            'free_delivery_threshold' => ['nullable', new DecimalAmountRule],
            'delivery_success_fee_percent' => ['required', 'numeric', 'between:0,100', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
        ]);

        try {
            $this->setSettings->handle(
                $actor,
                volumetricDivisor: (int) $validated['volumetric_divisor'],
                useGreaterOfActualAndVolumetric: (bool) $validated['use_greater_of_actual_and_volumetric'],
                additionalPerKgCharge: DecimalAmount::parse($validated['additional_per_kg_charge'], $currency),
                perBoxCharge: DecimalAmount::parse($validated['per_box_charge'], $currency),
                fragileHandlingCharge: DecimalAmount::parse($validated['fragile_handling_charge'], $currency),
                minimumCharge: DecimalAmount::parse($validated['minimum_charge'], $currency),
                maximumCharge: DecimalAmount::parseOrNull($validated['maximum_charge'] ?? null, $currency),
                freeDeliveryThreshold: DecimalAmount::parseOrNull($validated['free_delivery_threshold'] ?? null, $currency),
                deliverySuccessFeePercent: (string) $validated['delivery_success_fee_percent'],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['minimum_charge' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('delivery_settings.admin.settings_saved')]);

        return back();
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless($actor->can(PermissionCatalogue::name(PermissionModule::DeliverySettings, PermissionAction::Edit)), 403);

        $currency = Currency::base();

        $validated = $request->validate([
            'weight_from_grams' => ['required', 'integer', 'min:0'],
            'weight_to_grams' => ['nullable', 'integer', 'min:1'],
            'base_charge' => ['required', new DecimalAmountRule],
            'per_kg_charge' => ['nullable', new DecimalAmountRule],
            'area' => ['nullable', 'string', 'max:120'],
            'courier_provider_id' => ['nullable', 'integer', 'exists:courier_providers,id'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->manageRules->create(
                $actor,
                weightFromGrams: (int) $validated['weight_from_grams'],
                weightToGrams: isset($validated['weight_to_grams']) ? (int) $validated['weight_to_grams'] : null,
                baseCharge: DecimalAmount::parse($validated['base_charge'], $currency),
                perKgCharge: DecimalAmount::parseOrNull($validated['per_kg_charge'] ?? null, $currency),
                effectiveFrom: CarbonImmutable::parse($validated['effective_from']),
                effectiveUntil: isset($validated['effective_until']) ? CarbonImmutable::parse($validated['effective_until']) : null,
                area: $validated['area'] ?? null,
                courierProviderId: $validated['courier_provider_id'] ?? null,
                priority: (int) ($validated['priority'] ?? 0),
                note: $validated['note'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['base_charge' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('delivery_settings.admin.rule_created')]);

        return back();
    }

    public function closeRule(Request $request, string $rule): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless($actor->can(PermissionCatalogue::name(PermissionModule::DeliverySettings, PermissionAction::Edit)), 403);

        /** @var DeliveryChargeRule $record */
        $record = DeliveryChargeRule::query()->where('public_id', $rule)->firstOrFail();

        $this->manageRules->close($actor, $record);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('delivery_settings.admin.rule_closed')]);

        return back();
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
