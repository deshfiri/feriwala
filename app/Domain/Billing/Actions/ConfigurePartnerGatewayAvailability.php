<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\Exceptions\PartnerGatewayRefused;
use App\Domain\Billing\PartnerGatewayAvailability;
use App\Domain\Billing\Policies\BillingSettingsPolicy;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Admin decides whether Partners may use their own gateway, and which providers (D36).
 *
 * Behind `payment.manage_integrations`, the permission that already guards
 * Feriwala's own gateway credentials. Turning the feature off, or taking a
 * provider off the list, stops **new** configuration and **new** payment
 * attempts. It never touches a payment already made.
 */
class ConfigurePartnerGatewayAvailability
{
    public function __construct(
        protected SettingsRepository $settings,
        protected PaymentGatewayManager $gateways,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @param  list<string>|null  $providers  null leaves the list as it is
     *
     * @throws AuthorizationException
     * @throws PartnerGatewayRefused when a named provider has no driver or cannot verify a payment
     */
    public function handle(User $actor, bool $enabled, ?array $providers = null): void
    {
        if (! BillingSettingsPolicy::canManageGateways($actor)) {
            throw new AuthorizationException('You may not change which payment gateways Partners can use.');
        }

        if ($providers !== null) {
            $providers = array_values(array_unique($providers));

            foreach ($providers as $provider) {
                if (! $this->gateways->isImplemented($provider)) {
                    throw PartnerGatewayRefused::providerNotAllowed($provider);
                }

                if (! $this->gateways->driver($provider)->supports(GatewayCapability::Verify)) {
                    throw PartnerGatewayRefused::cannotVerify($provider);
                }
            }
        }

        $before = [
            'enabled' => $this->settings->get(PartnerGatewayAvailability::ENABLED) === true,
            'providers' => $this->settings->get(PartnerGatewayAvailability::PROVIDERS, []),
        ];

        $this->settings->define(PartnerGatewayAvailability::ENABLED, 'payment', SettingType::Boolean, false, label: 'Partners may use their own payment gateway');
        $this->settings->set(PartnerGatewayAvailability::ENABLED, $enabled, $actor->id);

        if ($providers !== null) {
            $this->settings->define(PartnerGatewayAvailability::PROVIDERS, 'payment', SettingType::Json, [], label: 'Gateways Partners may configure');
            $this->settings->set(PartnerGatewayAvailability::PROVIDERS, $providers, $actor->id);
        }

        $this->audit->handle(new AuditEntry(
            action: 'payment.partner_gateways_availability_changed',
            actorId: $actor->id,
            before: $before,
            after: [
                'enabled' => $enabled,
                'providers' => $providers ?? $before['providers'],
            ],
            module: 'payment',
            isSensitive: true,
        ));
    }
}
