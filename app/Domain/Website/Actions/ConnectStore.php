<?php

namespace App\Domain\Website\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Website\Data\ConnectedStore;
use App\Domain\Website\Enums\CredentialPreset;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\LocalAddress;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCredential;
use App\Domain\Website\Models\WebsiteDomain;
use App\Domain\Website\StorefrontEnvironmentBlock;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/**
 * Connecting a partner's storefront in one step, on the partner's behalf (D32).
 *
 * Authorised platform staff give the website's address. In return there is a
 * **store id** and a **token**, which — with the ERP's own address — are all the
 * storefront's `.env` needs. Behind that one action:
 *
 *   1. the address is recorded as the website's domain (a local one such as
 *      `http://127.0.0.1:8001` is accepted as it is when local addresses are
 *      allowed, and is never offered in production);
 *   2. a credential with exactly the storefront scopes is issued — the store id
 *      is its key id, the token its secret;
 *   3. for a real, public address, the webhook endpoint is registered at
 *      `https://<address>/webhooks/feriwala` and its signing secret is returned
 *      with the rest. Best effort: a storefront that is not reachable yet is
 *      simply connected without it and refreshes its catalogue by cache expiry.
 *
 * Nothing here is new authority: it is the existing credential, domain and
 * webhook actions, called together. One store connection per website; to change
 * the token, rotate it.
 */
class ConnectStore
{
    /** The path the storefront receives the ERP's webhooks on. */
    public const WEBHOOK_PATH = '/webhooks/feriwala';

    public function __construct(
        protected ManageWebsiteCredentials $credentials,
        protected ProvisionWebsiteService $provision,
        protected ManageWebhookEndpoint $webhooks,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws WebsiteRefused
     */
    public function handle(Website $website, User $actor, string $address, int $termMonths = 12): ConnectedStore
    {
        $alreadyConnected = WebsiteCredential::query()
            ->where('website_id', $website->id)
            ->whereNull('revoked_at')
            ->exists();

        if ($alreadyConnected) {
            throw WebsiteRefused::alreadyConnected();
        }

        $local = LocalAddress::parse($address);
        $domain = $local['host'] ?? $this->normalise($address);

        if ($domain === null) {
            throw WebsiteRefused::addressInvalid();
        }

        $issued = $this->database->transaction(function () use ($website, $actor, $local, $domain, $termMonths) {
            $this->record($website, $domain, $local !== null, $actor, $termMonths);

            return $this->credentials->issue(
                $website->refresh(),
                $actor,
                'Storefront',
                CredentialPreset::Storefront->scopes(),
            );
        });

        $webhook = $local === null ? $this->registerWebhook($website, $actor, $domain) : null;

        $this->audit->handle(new AuditEntry(
            action: 'website.store_connected',
            actorId: $actor->id,
            auditableType: Website::class,
            auditableId: $website->id,
            after: ['address' => $domain, 'local' => $local !== null, 'webhook' => $webhook !== null],
            accountId: $website->business_account_id,
            module: 'website',
            isSensitive: true,
        ));

        return new ConnectedStore(
            credential: $issued->credential,
            token: $issued->secret,
            address: $domain,
            local: $local !== null,
            webhookSecret: $webhook['secret'] ?? null,
            webhookUrl: $webhook['url'] ?? null,
            erpBaseUrl: StorefrontEnvironmentBlock::origin((string) config('app.url')),
        );
    }

    /**
     * Put the address on the website: as a registered domain when it is a real
     * one, or straight onto the website for a local address.
     */
    protected function record(Website $website, string $domain, bool $local, User $actor, int $termMonths): void
    {
        if ($local) {
            $taken = Website::query()->where('domain', $domain)->whereKeyNot($website->id)->exists();

            if ($taken) {
                throw WebsiteRefused::domainTaken();
            }

            $website->forceFill(['domain' => $domain])->save();

            return;
        }

        $held = WebsiteDomain::query()
            ->where('website_id', $website->id)
            ->where('domain', $domain)
            ->exists();

        if ($held) {
            $website->forceFill(['domain' => $domain])->save();

            return;
        }

        $this->provision->registerDomain($website, $actor, $domain, $termMonths);
    }

    /**
     * @return array{url: string, secret: string}|null
     */
    protected function registerWebhook(Website $website, User $actor, string $domain): ?array
    {
        $url = 'https://'.$domain.self::WEBHOOK_PATH;

        try {
            $result = $this->webhooks->configure($website, $actor, $url);
        } catch (WebsiteRefused) {
            // Not reachable yet (no DNS, a private address): connect without it.
            return null;
        }

        // An existing endpoint keeps its secret, which is never shown again.
        return $result['secret'] === null ? null : ['url' => $url, 'secret' => $result['secret']];
    }

    /**
     * A host name from what was typed — a bare domain or a URL — or null.
     */
    protected function normalise(string $address): ?string
    {
        $value = Str::lower(trim($address));
        $value = (string) preg_replace('#^https?://#', '', $value);
        $value = rtrim(explode('/', $value)[0], '.');

        if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $value) !== 1
            || strlen($value) > 253) {
            return null;
        }

        return $value;
    }
}
