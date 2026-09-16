<?php

namespace App\Domain\Website\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Website\Enums\WebsiteServiceStatus;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteDomain;
use App\Domain\Website\Models\WebsiteHosting;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Record the domain or hosting somebody has actually provisioned (§16.2, D9, P5-11).
 *
 * D9 is explicit that domains and hosting are arranged **by hand** in v1 —
 * there is no registrar API and no provisioning automation. So this does not
 * register anything; it records that a person did, with the term they bought,
 * which is what the expiry tracking and the renewal reminders read.
 *
 * Registering a domain also makes it the website's address. The subdomain stays
 * as it was: a shop that has answered on `name.feriwala.shop` for months should
 * not stop doing so the day its own domain arrives.
 */
class ProvisionWebsiteService
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws WebsiteRefused
     */
    public function registerDomain(
        Website $website,
        User $actor,
        string $domain,
        int $termMonths,
        ?string $registrar = null,
        ?Money $fee = null,
        bool $makePrimary = true,
    ): WebsiteDomain {
        $domain = Str::lower(trim($domain));
        $now = CarbonImmutable::now();

        try {
            return $this->database->transaction(function () use (
                $website, $actor, $domain, $termMonths, $registrar, $fee, $makePrimary, $now
            ) {
                $registration = WebsiteDomain::create([
                    'website_id' => $website->id,
                    'domain' => $domain,
                    'registrar' => $registrar,
                    'status' => WebsiteServiceStatus::Active,
                    'currency_code' => $website->currency_code,
                    'fee_minor' => $fee ?? $website->domain_fee_minor,
                    'registered_at' => $now,
                    'expires_at' => $now->addMonths($termMonths),
                    'created_by' => $actor->id,
                ]);

                if ($makePrimary) {
                    $website->forceFill(['domain' => $domain])->save();
                }

                $this->audit->handle(new AuditEntry(
                    action: 'website.domain_registered',
                    actorId: $actor->id,
                    auditableType: WebsiteDomain::class,
                    auditableId: $registration->id,
                    after: [
                        'domain' => $domain,
                        'expires_at' => $registration->expires_at?->toIso8601String(),
                        'registrar' => $registrar,
                    ],
                    accountId: $website->business_account_id,
                    module: 'website',
                ));

                return $registration;
            });
        } catch (UniqueConstraintViolationException) {
            throw WebsiteRefused::domainTaken();
        }
    }

    public function startHosting(
        Website $website,
        User $actor,
        string $plan,
        int $termMonths,
        ?string $provider = null,
        ?Money $fee = null,
    ): WebsiteHosting {
        $now = CarbonImmutable::now();

        $hosting = WebsiteHosting::create([
            'website_id' => $website->id,
            'plan' => $plan,

            // Internal, and it stays internal: a partner is told when their
            // hosting runs out, never where their shop is hosted.
            'provider' => $provider,
            'status' => WebsiteServiceStatus::Active,
            'currency_code' => $website->currency_code,
            'fee_minor' => $fee ?? $website->hosting_fee_minor,
            'started_at' => $now,
            'expires_at' => $now->addMonths($termMonths),
            'created_by' => $actor->id,
        ]);

        $this->audit->handle(new AuditEntry(
            action: 'website.hosting_started',
            actorId: $actor->id,
            auditableType: WebsiteHosting::class,
            auditableId: $hosting->id,
            after: [
                'plan' => $plan,
                'expires_at' => $hosting->expires_at?->toIso8601String(),
            ],
            accountId: $website->business_account_id,
            module: 'website',
        ));

        return $hosting;
    }
}
