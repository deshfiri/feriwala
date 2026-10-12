<?php

namespace App\Domain\Website\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Website\Enums\DomainRoutingStatus;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\WebsiteDomain;
use App\Integrations\Dns\Contracts\TxtRecordResolver;
use App\Integrations\Dns\Exceptions\DnsLookupFailed;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Prove, activate and suspend a custom domain for the shared storefront (D30 §3).
 *
 *   - **Issue** a verification token. Reissuing one returns the domain to
 *     unverified: proof of the old token says nothing about the new one.
 *   - **Verify** only on evidence: the domain's DNS must publish the token at
 *     `_banij-verify.{domain}`. A lookup that fails is a failure with a
 *     reason, never a pass and never a demotion.
 *   - **Activate** a verified (or suspended) domain whose registration is
 *     running, on a website that is not closed.
 *   - **Suspend** an active one, with a reason.
 *
 * Every step locks the row, moves through transitionTo(), and is audited. A
 * name already proven by another website cannot be proven again here — the
 * database's partial unique index settles a race, and the refusal says so.
 */
class ManageWebsiteDomainRouting
{
    public function __construct(
        protected TxtRecordResolver $dns,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws WebsiteRefused
     */
    public function issueVerification(WebsiteDomain $domain, User $actor): WebsiteDomain
    {
        return $this->database->transaction(function () use ($domain, $actor) {
            $locked = $this->lock($domain);
            $before = $locked->routing_status;

            if ($before !== DomainRoutingStatus::Unverified) {
                $locked->transitionTo(DomainRoutingStatus::Unverified);
            }

            $locked->forceFill([
                'verification_token' => 'banij-verify-'.Str::lower(Str::random(40)),
                'verification_issued_at' => CarbonImmutable::now(),
                'verification_checked_at' => null,
                'verification_error' => null,
                'verified_at' => null,
                'activated_at' => null,
                'suspended_at' => null,
            ])->save();

            $this->record($locked, $actor, 'website.domain_verification_issued', $before);

            return $locked;
        });
    }

    /**
     * Look for the token in the domain's DNS. True when it is there and the
     * domain is now verified.
     *
     * @throws WebsiteRefused
     */
    public function verify(WebsiteDomain $domain, User $actor): bool
    {
        return $this->database->transaction(function () use ($domain, $actor) {
            $locked = $this->lock($domain);

            if ($locked->verification_token === null) {
                throw WebsiteRefused::domainVerificationNotIssued();
            }

            if ($locked->routing_status !== DomainRoutingStatus::Unverified) {
                // Already proven; checking again changes nothing.
                return true;
            }

            $now = CarbonImmutable::now();
            $error = null;

            try {
                $records = $this->dns->txtRecords($locked->verificationRecordName());

                if (! in_array($locked->verification_token, $records, true)) {
                    $error = $this->line(
                        $records === [] ? 'website.domain_verification.no_record' : 'website.domain_verification.wrong_value',
                        ['name' => $locked->verificationRecordName()],
                    );
                }
            } catch (DnsLookupFailed) {
                $error = $this->line('website.domain_verification.lookup_failed');
            }

            if ($error !== null) {
                $locked->forceFill([
                    'verification_checked_at' => $now,
                    'verification_error' => mb_substr($error, 0, 255),
                ])->save();

                return false;
            }

            $locked->transitionTo(DomainRoutingStatus::Verified);
            $locked->forceFill([
                'verification_checked_at' => $now,
                'verification_error' => null,
                'verified_at' => $now,
            ]);

            try {
                // A savepoint: the unique index refusing must not abort the
                // surrounding transaction.
                $this->database->transaction(fn () => $locked->save());
            } catch (UniqueConstraintViolationException) {
                throw WebsiteRefused::domainHeldElsewhere();
            }

            $this->record($locked, $actor, 'website.domain_verified', DomainRoutingStatus::Unverified);

            return true;
        });
    }

    /**
     * @throws WebsiteRefused
     */
    public function activate(WebsiteDomain $domain, User $actor): WebsiteDomain
    {
        return $this->database->transaction(function () use ($domain, $actor) {
            $locked = $this->lock($domain);
            $before = $locked->routing_status;

            if (! in_array($before, [DomainRoutingStatus::Verified, DomainRoutingStatus::Suspended], true)) {
                throw WebsiteRefused::domainNotVerified();
            }

            if (! $locked->status->isRunning()) {
                throw WebsiteRefused::domainNotRegistered();
            }

            if ($locked->website->status->isTerminal()) {
                throw WebsiteRefused::closed();
            }

            $locked->transitionTo(DomainRoutingStatus::Active);
            $locked->forceFill(['activated_at' => CarbonImmutable::now(), 'suspended_at' => null])->save();

            $this->record($locked, $actor, 'website.domain_activated', $before);

            return $locked;
        });
    }

    /**
     * @throws WebsiteRefused
     */
    public function suspend(WebsiteDomain $domain, User $actor, string $reason): WebsiteDomain
    {
        return $this->database->transaction(function () use ($domain, $actor, $reason) {
            $locked = $this->lock($domain);

            if ($locked->routing_status !== DomainRoutingStatus::Active) {
                throw WebsiteRefused::domainNotActive();
            }

            $locked->transitionTo(DomainRoutingStatus::Suspended);
            $locked->forceFill(['suspended_at' => CarbonImmutable::now()])->save();

            $this->record($locked, $actor, 'website.domain_suspended', DomainRoutingStatus::Active, $reason);

            return $locked;
        });
    }

    /**
     * A translated line, always as a string.
     *
     * @param  array<string, string>  $replace
     */
    protected function line(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }

    protected function lock(WebsiteDomain $domain): WebsiteDomain
    {
        /** @var WebsiteDomain $locked */
        $locked = WebsiteDomain::query()->with('website')->whereKey($domain->id)->lockForUpdate()->firstOrFail();

        return $locked;
    }

    protected function record(
        WebsiteDomain $domain,
        User $actor,
        string $action,
        DomainRoutingStatus $before,
        ?string $reason = null,
    ): void {
        $this->audit->handle(new AuditEntry(
            action: $action,
            actorId: $actor->id,
            auditableType: WebsiteDomain::class,
            auditableId: $domain->id,
            before: ['routing_status' => $before->value],
            after: ['routing_status' => $domain->routing_status->value, 'domain' => $domain->domain],
            reason: $reason,
            accountId: $domain->website->business_account_id,
            module: 'website',
        ));
    }
}
