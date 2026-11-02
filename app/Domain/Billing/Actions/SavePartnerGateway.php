<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\Exceptions\PartnerGatewayRefused;
use App\Domain\Billing\Models\PartnerGatewayConfiguration;
use App\Domain\Billing\Models\PartnerGatewayConfigurationVersion;
use App\Domain\Billing\PartnerGatewayAvailability;
use App\Domain\Billing\Policies\PartnerGatewayPolicy;
use App\Domain\Website\Models\Website;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Gateways\GatewayCredentials;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A Partner sets up, or changes, the payment gateway of one of their own Storefronts (D36).
 *
 * Every save writes a **new version** holding the whole credential set. Versions
 * are never edited, so a payment already made keeps the exact credentials it
 * started with; this only decides what the next attempt uses.
 *
 * Write-only, like {@see ConfigureGateway}: a blank field means "keep what is
 * there", so a screen that cannot show a secret can still change the mode or
 * one field. The exception is a change between sandbox and live, which needs
 * every credential entered again — keeping sandbox keys into live mode, or the
 * reverse, would point test traffic at a real merchant account.
 *
 * The audit entry carries field names, never values, and so do the refusals.
 */
class SavePartnerGateway
{
    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected PartnerGatewayAvailability $availability,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  array<string, string|null>  $credentials  keyed by the driver's own required keys
     *
     * @throws AuthorizationException when the person does not speak for the Storefront's owner
     * @throws PartnerGatewayRefused
     */
    public function handle(User $actor, Website $website, string $provider, string $mode, array $credentials): PartnerGatewayConfigurationVersion
    {
        if (! PartnerGatewayPolicy::canConfigure($actor, $website)) {
            throw new AuthorizationException('Only the business that owns a Storefront may set its payment gateway.');
        }

        if (! $this->availability->isEnabled()) {
            throw PartnerGatewayRefused::featureOff();
        }

        if (! $this->availability->allows($provider)) {
            throw PartnerGatewayRefused::providerNotAllowed($provider);
        }

        if (! in_array($mode, [GatewayCredentials::SANDBOX, GatewayCredentials::LIVE], true)) {
            throw PartnerGatewayRefused::unknownMode();
        }

        // The platform driver is asked only what the provider is and needs: its
        // capabilities and the names of its credentials, never a value.
        $driver = $this->gateways->driver($provider);

        if (! $driver->supports(GatewayCapability::Verify)) {
            throw PartnerGatewayRefused::cannotVerify($provider);
        }

        $keys = array_values($driver->requiredConfiguration());

        $version = $this->write($actor, $website, $provider, $mode, $keys, $credentials);

        $this->audit->handle(new AuditEntry(
            action: 'payment.partner_gateway_configured',
            actorId: $actor->id,
            auditableType: PartnerGatewayConfiguration::class,
            auditableId: $version->partner_gateway_configuration_id,
            // Names only. An audit log that records a credential is a second
            // place it leaks from.
            after: [
                'website' => $website->public_id,
                'provider' => $provider,
                'mode' => $mode,
                'version' => $version->version,
                'credentials_present' => $version->presentKeys(),
            ],
            accountId: $website->business_account_id,
            module: 'payment',
            isSensitive: true,
        ));

        return $version;
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, string|null>  $credentials
     */
    protected function write(User $actor, Website $website, string $provider, string $mode, array $keys, array $credentials): PartnerGatewayConfigurationVersion
    {
        try {
            return $this->database->transaction(fn () => $this->writeLocked($actor, $website, $provider, $mode, $keys, $credentials));
        } catch (UniqueConstraintViolationException) {
            // Two saves raced to create this Storefront's configuration or its
            // next version; the second simply runs again against what the first wrote.
            return $this->database->transaction(fn () => $this->writeLocked($actor, $website, $provider, $mode, $keys, $credentials));
        }
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, string|null>  $credentials
     */
    protected function writeLocked(User $actor, Website $website, string $provider, string $mode, array $keys, array $credentials): PartnerGatewayConfigurationVersion
    {
        /** @var PartnerGatewayConfiguration $configuration */
        $configuration = PartnerGatewayConfiguration::query()->firstOrCreate(
            ['website_id' => $website->id],
            ['business_account_id' => $website->business_account_id, 'provider' => $provider, 'status' => PartnerGatewayConfiguration::ACTIVE],
        );

        /** @var PartnerGatewayConfiguration $locked */
        $locked = PartnerGatewayConfiguration::query()->lockForUpdate()->findOrFail($configuration->id);

        $previous = $locked->versions()->first();

        // What a blank field keeps: only the same provider in the same mode.
        $kept = $previous !== null && $previous->provider === $provider && $previous->mode === $mode
            ? $previous->credentials
            : [];

        $merged = [];

        foreach ($keys as $key) {
            $value = $credentials[$key] ?? null;

            $merged[$key] = is_string($value) && trim($value) !== ''
                ? trim($value)
                : ($kept[$key] ?? null);
        }

        $missing = array_keys(array_filter($merged, fn (?string $value) => $value === null || $value === ''));

        if ($missing !== []) {
            throw $previous !== null && $previous->provider === $provider && $previous->mode !== $mode
                ? PartnerGatewayRefused::modeChangeNeedsCredentials()
                : PartnerGatewayRefused::incomplete($provider, $missing);
        }

        $locked->forceFill(['provider' => $provider, 'status' => PartnerGatewayConfiguration::ACTIVE])->save();

        return PartnerGatewayConfigurationVersion::query()->create([
            'partner_gateway_configuration_id' => $locked->id,
            'business_account_id' => $locked->business_account_id,
            'website_id' => $locked->website_id,
            'version' => ($previous->version ?? 0) + 1,
            'provider' => $provider,
            'mode' => $mode,
            'credentials' => $merged,
            'created_by' => $actor->id,
        ]);
    }
}
