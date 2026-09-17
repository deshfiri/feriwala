<?php

namespace App\Domain\Website\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteWebhookEndpoint;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Where a storefront is told things, and the secret it checks them with
 * (contract §7.2, P5-20).
 *
 * **One endpoint per website**, replaced in place when the address changes.
 * The address must be HTTPS and must not point inside the platform's own
 * network: the ERP is about to POST signed data to whatever this says, and an
 * address of `localhost` or a private range is a way to make the ERP call
 * itself.
 *
 * The signing secret is shown once, like a credential secret, and rotation
 * keeps the previous one valid for verification for a window so the storefront
 * can roll it without dropping deliveries.
 */
class ManageWebhookEndpoint
{
    public function __construct(
        protected RecordAuditLog $audit,
    ) {}

    /**
     * Set the address, issuing a secret the first time.
     *
     * @return array{endpoint: WebsiteWebhookEndpoint, secret: string|null}
     *
     * @throws WebsiteRefused
     */
    public function configure(Website $website, User $actor, string $url): array
    {
        if ($website->status->isTerminal()) {
            throw WebsiteRefused::notEditable();
        }

        $this->assertDeliverable($url);

        /** @var WebsiteWebhookEndpoint|null $endpoint */
        $endpoint = WebsiteWebhookEndpoint::query()->where('website_id', $website->id)->first();
        $secret = null;

        if ($endpoint === null) {
            $secret = $this->newSecret();

            $endpoint = WebsiteWebhookEndpoint::create([
                'website_id' => $website->id,
                'url' => $url,
                'secret' => $secret,
                'secret_hint' => substr($secret, -4),
                'is_active' => true,
                'created_by' => $actor->id,
            ]);
        } else {
            $endpoint->forceFill(['url' => $url, 'is_active' => true])->save();
        }

        $this->record($website, $actor, 'website.webhook_configured', ['url' => $url]);

        return ['endpoint' => $endpoint, 'secret' => $secret];
    }

    public function rotate(WebsiteWebhookEndpoint $endpoint, User $actor): string
    {
        $secret = $this->newSecret();

        $endpoint->forceFill([
            'previous_secret' => $endpoint->secret,
            'previous_secret_expires_at' => CarbonImmutable::now()
                ->addMinutes((int) config('website.api.rotation_grace_minutes', 1440)),
            'secret' => $secret,
            'secret_hint' => substr($secret, -4),
            'rotated_at' => CarbonImmutable::now(),
        ])->save();

        $endpoint->loadMissing('website');

        $this->record($endpoint->website, $actor, 'website.webhook_secret_rotated', [
            'previous_secret_expires_at' => $endpoint->previous_secret_expires_at?->toIso8601String(),
        ]);

        return $secret;
    }

    public function disable(WebsiteWebhookEndpoint $endpoint, User $actor): void
    {
        $endpoint->forceFill(['is_active' => false])->save();
        $endpoint->loadMissing('website');

        $this->record($endpoint->website, $actor, 'website.webhook_disabled', []);
    }

    /**
     * @throws WebsiteRefused
     */
    protected function assertDeliverable(string $url): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || $host === '') {
            throw WebsiteRefused::webhookAddressRefused();
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')) {
            throw WebsiteRefused::webhookAddressRefused();
        }

        $literal = trim($host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false
            && filter_var($literal, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw WebsiteRefused::webhookAddressRefused();
        }
    }

    protected function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * @param  array<string, mixed>  $after
     */
    protected function record(Website $website, User $actor, string $action, array $after): void
    {
        $this->audit->handle(new AuditEntry(
            action: $action,
            actorId: $actor->id,
            auditableType: Website::class,
            auditableId: $website->id,
            after: $after,
            accountId: $website->business_account_id,
            module: 'website',
            isSensitive: PermissionAction::ManageIntegrations->isSensitive(),
        ));
    }
}
