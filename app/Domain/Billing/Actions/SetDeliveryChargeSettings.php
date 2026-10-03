<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\DeliveryChargeSettings;
use App\Domain\Inventory\Actions\SetReservationWindows;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use App\Support\Money\Money;
use InvalidArgumentException;

/**
 * Sets the global delivery-charge knobs {@see
 * \App\Domain\Billing\CalculateDeliveryCharge} applies on top of whichever
 * weight-tier rule it resolves (beta-critical batch, Commit 2).
 *
 * Mirrors {@see SetReservationWindows}'s own
 * shape for the same reason: these are server-wide defaults with no history
 * to preserve, unlike the dated weight-tier rules {@see
 * ManageDeliveryChargeRules} manages.
 */
class SetDeliveryChargeSettings
{
    public function __construct(
        protected SettingsRepository $settings,
        protected DeliveryChargeSettings $current,
        protected RecordAuditLog $audit,
    ) {}

    public function handle(
        User $actor,
        int $volumetricDivisor,
        bool $useGreaterOfActualAndVolumetric,
        Money $additionalPerKgCharge,
        Money $perBoxCharge,
        Money $fragileHandlingCharge,
        Money $minimumCharge,
        ?Money $maximumCharge,
        ?Money $freeDeliveryThreshold,
        string $deliverySuccessFeePercent = DeliveryChargeSettings::DEFAULT_SUCCESS_FEE_PERCENT,
    ): void {
        if ($volumetricDivisor <= 0) {
            throw new InvalidArgumentException('The volumetric divisor must be greater than zero.');
        }

        if (! is_numeric($deliverySuccessFeePercent) || bccomp($deliverySuccessFeePercent, '0', 4) < 0 || bccomp($deliverySuccessFeePercent, '100', 4) > 0) {
            throw new InvalidArgumentException('The delivery success fee must be a percentage between 0 and 100.');
        }

        foreach ([$additionalPerKgCharge, $perBoxCharge, $fragileHandlingCharge, $minimumCharge] as $amount) {
            if ($amount->isNegative()) {
                throw new InvalidArgumentException('A delivery charge cannot be negative.');
            }
        }

        if ($maximumCharge !== null) {
            if ($maximumCharge->isNegative()) {
                throw new InvalidArgumentException('A delivery charge cannot be negative.');
            }

            if ($maximumCharge->lessThan($minimumCharge)) {
                throw new InvalidArgumentException('The maximum charge cannot be lower than the minimum charge.');
            }
        }

        if ($freeDeliveryThreshold !== null && $freeDeliveryThreshold->isNegative()) {
            throw new InvalidArgumentException('The free-delivery threshold cannot be negative.');
        }

        $before = [
            'volumetric_divisor' => $this->current->volumetricDivisor(),
            'use_greater_of_actual_and_volumetric' => $this->current->useGreaterOfActualAndVolumetric(),
            'additional_per_kg_charge' => $this->current->additionalPerKgCharge($additionalPerKgCharge->currency)->toDecimal(),
            'per_box_charge' => $this->current->perBoxCharge($perBoxCharge->currency)->toDecimal(),
            'fragile_handling_charge' => $this->current->fragileHandlingCharge($fragileHandlingCharge->currency)->toDecimal(),
            'minimum_charge' => $this->current->minimumCharge($minimumCharge->currency)->toDecimal(),
            'maximum_charge' => $this->current->maximumCharge($minimumCharge->currency)?->toDecimal(),
            'free_delivery_threshold' => $this->current->freeDeliveryThreshold($minimumCharge->currency)?->toDecimal(),
            'delivery_success_fee_percent' => $this->current->deliverySuccessFeePercent(),
        ];

        $this->settings->define(DeliveryChargeSettings::VOLUMETRIC_DIVISOR, 'delivery', SettingType::Integer, label: 'Volumetric-weight divisor');
        $this->settings->define(DeliveryChargeSettings::USE_GREATER_OF_ACTUAL_AND_VOLUMETRIC, 'delivery', SettingType::Boolean, label: 'Use the greater of actual and volumetric weight');
        $this->settings->define(DeliveryChargeSettings::ADDITIONAL_PER_KG_CHARGE, 'delivery', SettingType::Money, label: 'Additional per-kg charge');
        $this->settings->define(DeliveryChargeSettings::PER_BOX_CHARGE, 'delivery', SettingType::Money, label: 'Per-box handling charge');
        $this->settings->define(DeliveryChargeSettings::FRAGILE_HANDLING_CHARGE, 'delivery', SettingType::Money, label: 'Fragile / special-handling charge');
        $this->settings->define(DeliveryChargeSettings::MINIMUM_CHARGE, 'delivery', SettingType::Money, label: 'Minimum delivery charge');
        $this->settings->define(DeliveryChargeSettings::MAXIMUM_CHARGE, 'delivery', SettingType::Money, label: 'Maximum delivery charge');
        $this->settings->define(DeliveryChargeSettings::FREE_DELIVERY_THRESHOLD, 'delivery', SettingType::Money, label: 'Free-delivery order threshold');
        $this->settings->define(DeliveryChargeSettings::SUCCESS_FEE_PERCENT, 'delivery', SettingType::Decimal, label: 'Delivery success fee (%)');

        $this->settings->set(DeliveryChargeSettings::VOLUMETRIC_DIVISOR, $volumetricDivisor, $actor->id);
        $this->settings->set(DeliveryChargeSettings::USE_GREATER_OF_ACTUAL_AND_VOLUMETRIC, $useGreaterOfActualAndVolumetric, $actor->id);
        $this->settings->set(DeliveryChargeSettings::ADDITIONAL_PER_KG_CHARGE, $additionalPerKgCharge, $actor->id);
        $this->settings->set(DeliveryChargeSettings::PER_BOX_CHARGE, $perBoxCharge, $actor->id);
        $this->settings->set(DeliveryChargeSettings::FRAGILE_HANDLING_CHARGE, $fragileHandlingCharge, $actor->id);
        $this->settings->set(DeliveryChargeSettings::MINIMUM_CHARGE, $minimumCharge, $actor->id);
        $this->settings->set(DeliveryChargeSettings::MAXIMUM_CHARGE, $maximumCharge, $actor->id);
        $this->settings->set(DeliveryChargeSettings::FREE_DELIVERY_THRESHOLD, $freeDeliveryThreshold, $actor->id);
        $this->settings->set(DeliveryChargeSettings::SUCCESS_FEE_PERCENT, $deliverySuccessFeePercent, $actor->id);

        $this->audit->handle(new AuditEntry(
            action: 'delivery_settings.updated',
            actorId: $actor->id,
            before: $before,
            after: [
                'volumetric_divisor' => $volumetricDivisor,
                'use_greater_of_actual_and_volumetric' => $useGreaterOfActualAndVolumetric,
                'additional_per_kg_charge' => $additionalPerKgCharge->toDecimal(),
                'per_box_charge' => $perBoxCharge->toDecimal(),
                'fragile_handling_charge' => $fragileHandlingCharge->toDecimal(),
                'minimum_charge' => $minimumCharge->toDecimal(),
                'maximum_charge' => $maximumCharge?->toDecimal(),
                'free_delivery_threshold' => $freeDeliveryThreshold?->toDecimal(),
                'delivery_success_fee_percent' => $deliverySuccessFeePercent,
            ],
            module: 'delivery_settings',
        ));
    }
}
