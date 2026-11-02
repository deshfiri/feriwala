<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\Models\PartnerGatewayConfiguration;
use App\Domain\Billing\Policies\PartnerGatewayPolicy;
use App\Domain\Website\Models\Website;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

/**
 * A Partner switches a Storefront back to the Platform gateway (D36).
 *
 * Only the choice changes. The saved versions stay, and so does every payment
 * already made through them: a payment that was started with the Partner's
 * gateway is still verified and reconciled with that gateway, whatever the
 * Storefront uses next.
 */
class UsePlatformGateway
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws AuthorizationException when the person does not speak for the Storefront's owner
     */
    public function handle(User $actor, Website $website): void
    {
        if (! PartnerGatewayPolicy::canConfigure($actor, $website)) {
            throw new AuthorizationException('Only the business that owns a Storefront may set its payment gateway.');
        }

        $this->database->transaction(function () use ($actor, $website) {
            /** @var PartnerGatewayConfiguration|null $configuration */
            $configuration = PartnerGatewayConfiguration::query()
                ->where('website_id', $website->id)
                ->lockForUpdate()
                ->first();

            if ($configuration === null || ! $configuration->isActive()) {
                return;
            }

            $configuration->forceFill(['status' => PartnerGatewayConfiguration::DISABLED])->save();

            $this->audit->handle(new AuditEntry(
                action: 'payment.partner_gateway_disabled',
                actorId: $actor->id,
                auditableType: PartnerGatewayConfiguration::class,
                auditableId: $configuration->id,
                after: ['website' => $website->public_id, 'provider' => $configuration->provider],
                accountId: $website->business_account_id,
                module: 'payment',
                isSensitive: true,
            ));
        });
    }
}
