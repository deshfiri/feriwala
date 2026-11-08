<?php

namespace App\Domain\Website\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Website\Enums\DomainRoutingStatus;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteDomain;
use App\Domain\Website\Models\WebsiteHosting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * What platform staff may correct about a partner's website after it exists:
 * its domain, its term, its registered domains and its hosting (§16.2, D9).
 *
 * Domains and hosting are arranged by hand (D9), so a record can be wrong — a
 * misspelt name, a term that was extended by phone, a provider that changed —
 * and until this existed the only way to fix one was to add another beside it.
 * Money is not here: fees, charges and deposits are financial records and change
 * only through the actions that write a ledger entry for them.
 *
 * **Renaming a registered domain starts it again.** Proof that somebody controls
 * `old.example` says nothing about `new.example`, so the name goes back to
 * unverified with its token, proof and activation cleared — and, if it was
 * serving, the storefront is told, in the same transaction, that it no longer
 * does. Where the website's own address was the old name, it follows the rename.
 *
 * The platform's generated subdomain label is deliberately not editable: the
 * storefront keys on it, and a person never types it — they type a domain. The
 * address the partner first asked for is likewise a record of what was asked,
 * not a setting.
 *
 * Changing a term re-arms its renewal reminders: they were counting down to a
 * date that no longer applies. A closed website is finished with and is not
 * edited. Every change is audited with what it was and what it became.
 */
class ManageWebsiteRecords
{
    /** The website columns {@see updateDetails()} will touch, and no others. */
    private const DETAIL_KEYS = ['domain', 'expires_at', 'grace_ends_at', 'maintenance_message'];

    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
        protected AnnounceWebsiteUpdated $announce,
    ) {}

    /**
     * The website's own domain, term and maintenance note.
     *
     * @param  array<string, mixed>  $attributes  already validated by the caller; unknown keys are ignored
     *
     * @throws WebsiteRefused
     */
    public function updateDetails(Website $website, User $actor, array $attributes): Website
    {
        $attributes = Arr::only($attributes, self::DETAIL_KEYS);

        return $this->database->transaction(function () use ($website, $actor, $attributes) {
            /** @var Website $locked */
            $locked = Website::query()->whereKey($website->id)->lockForUpdate()->firstOrFail();

            $this->assertEditable($locked);

            if (isset($attributes['domain']) && $attributes['domain'] !== $locked->domain) {
                $this->assertDomainAvailable($locked, (string) $attributes['domain']);
            }

            $before = $this->snapshot($locked, array_keys($attributes));

            $locked->fill($attributes);
            $changed = array_keys($locked->getDirty());

            // A save that changes nothing leaves no audit row and tells nobody.
            if ($changed === []) {
                return $locked;
            }

            $this->save($locked);

            $this->record($locked, $actor, 'website.details_updated', $locked->business_account_id, Website::class, $before, $changed);
            $this->announce->handle($locked);

            return $locked;
        });
    }

    /**
     * One registered domain: its name, registrar, term and whether it is the
     * website's address.
     *
     * @param  array<string, mixed>  $attributes  `domain`, `registrar`, `expires_at`, `auto_renew`, `make_primary`
     *
     * @throws WebsiteRefused
     */
    public function updateDomain(WebsiteDomain $domain, User $actor, array $attributes): WebsiteDomain
    {
        return $this->database->transaction(function () use ($domain, $actor, $attributes) {
            /** @var WebsiteDomain $locked */
            $locked = WebsiteDomain::query()->with('website')->whereKey($domain->id)->lockForUpdate()->firstOrFail();
            $website = $locked->website;

            $this->assertEditable($website);

            $keys = ['domain', 'registrar', 'expires_at', 'auto_renew'];
            $before = $this->snapshot($locked, $keys);

            $previous = $locked->domain;
            $name = isset($attributes['domain']) ? Str::lower(trim((string) $attributes['domain'])) : $previous;
            $renamed = $name !== $previous;
            $wasServing = $locked->routing_status === DomainRoutingStatus::Active;

            if ($renamed) {
                $this->assertDomainNameFree($locked, $name);
            }

            $locked->fill(Arr::only($attributes, ['registrar', 'expires_at', 'auto_renew']));

            if ($renamed) {
                if ($locked->routing_status !== DomainRoutingStatus::Unverified) {
                    $locked->transitionTo(DomainRoutingStatus::Unverified);
                }

                $locked->forceFill([
                    'domain' => $name,
                    'verification_token' => null,
                    'verification_issued_at' => null,
                    'verification_checked_at' => null,
                    'verification_error' => null,
                    'verified_at' => null,
                    'activated_at' => null,
                    'suspended_at' => null,
                    'tls_authorized_at' => null,
                ]);
            }

            if ($locked->isDirty('expires_at')) {
                $locked->forceFill(['reminded_at' => null, 'reminder_stage' => null]);
            }

            $changed = array_values(array_intersect($keys, array_keys($locked->getDirty())));
            $becomesPrimary = (bool) ($attributes['make_primary'] ?? false) && $website->domain !== $name;
            $followsRename = $renamed && $website->domain === $previous;

            if ($changed === [] && ! $becomesPrimary) {
                return $locked;
            }

            $this->database->transaction(function () use ($locked, $website, $name, $becomesPrimary, $followsRename) {
                try {
                    $locked->save();

                    if ($becomesPrimary || $followsRename) {
                        $website->forceFill(['domain' => $name])->save();
                    }
                } catch (UniqueConstraintViolationException) {
                    throw WebsiteRefused::domainTaken();
                }
            });

            $this->record(
                $locked,
                $actor,
                'website.domain_updated',
                $website->business_account_id,
                WebsiteDomain::class,
                $before,
                $changed,
                note: $renamed ? 'Renamed from '.$previous.'; routing returned to unverified.' : null,
                auditableId: $locked->id,
            );

            // Only what changes which hosts serve the storefront is announced.
            if (($renamed && $wasServing) || $becomesPrimary || $followsRename) {
                $this->announce->handle($website);
            }

            return $locked;
        });
    }

    /**
     * One hosting term: its plan, provider (internal) and when it runs out.
     *
     * @param  array<string, mixed>  $attributes  `plan`, `provider`, `expires_at`, `auto_renew`
     *
     * @throws WebsiteRefused
     */
    public function updateHosting(WebsiteHosting $hosting, User $actor, array $attributes): WebsiteHosting
    {
        return $this->database->transaction(function () use ($hosting, $actor, $attributes) {
            /** @var WebsiteHosting $locked */
            $locked = WebsiteHosting::query()->with('website')->whereKey($hosting->id)->lockForUpdate()->firstOrFail();

            $this->assertEditable($locked->website);

            $keys = ['plan', 'provider', 'expires_at', 'auto_renew'];
            $before = $this->snapshot($locked, $keys);

            $locked->fill(Arr::only($attributes, $keys));

            if ($locked->isDirty('expires_at')) {
                $locked->forceFill(['reminded_at' => null, 'reminder_stage' => null]);
            }

            $changed = array_values(array_intersect($keys, array_keys($locked->getDirty())));

            if ($changed === []) {
                return $locked;
            }

            $locked->save();

            $this->record(
                $locked,
                $actor,
                'website.hosting_updated',
                $locked->website->business_account_id,
                WebsiteHosting::class,
                $before,
                $changed,
                auditableId: $locked->id,
            );

            return $locked;
        });
    }

    /**
     * @throws WebsiteRefused
     */
    protected function assertEditable(Website $website): void
    {
        if ($website->status->isTerminal()) {
            throw WebsiteRefused::notEditable();
        }
    }

    /**
     * A website's own address, as typed: held by no other website's address.
     *
     * @throws WebsiteRefused
     */
    protected function assertDomainAvailable(Website $website, string $domain): void
    {
        if (Website::query()->where('domain', $domain)->whereKeyNot($website->id)->exists()) {
            throw WebsiteRefused::domainTaken();
        }
    }

    /**
     * A registered name, held by no other live registration.
     *
     * @throws WebsiteRefused
     */
    protected function assertDomainNameFree(WebsiteDomain $domain, string $name): void
    {
        $taken = WebsiteDomain::query()
            ->where('domain', $name)
            ->whereKeyNot($domain->id)
            ->whereIn('status', ['pending', 'active'])
            ->exists();

        if ($taken || Website::query()->where('domain', $name)->whereKeyNot($domain->website_id)->exists()) {
            throw WebsiteRefused::domainTaken();
        }
    }

    /**
     * Save inside a savepoint, so a race the pre-checks lost is a refusal and
     * not an aborted transaction.
     *
     * @throws WebsiteRefused
     */
    protected function save(Website $website): void
    {
        try {
            $this->database->transaction(fn () => $website->save());
        } catch (UniqueConstraintViolationException) {
            throw WebsiteRefused::domainTaken();
        }
    }

    /**
     * The named attributes as they stand, in a form an audit row can hold.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    protected function snapshot(Model $model, array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            $value = $model->getAttribute($key);
            $values[$key] = $value instanceof CarbonInterface ? $value->toIso8601String() : $value;
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $before  every attribute looked at, as it was
     * @param  list<string>  $changed  which of them changed
     * @param  class-string<Model>  $type
     */
    protected function record(
        Model $model,
        User $actor,
        string $action,
        int|string $accountId,
        string $type,
        array $before,
        array $changed,
        ?string $note = null,
        int|string|null $auditableId = null,
    ): void {
        $this->audit->handle(new AuditEntry(
            action: $action,
            actorId: $actor->id,
            auditableType: $type,
            auditableId: $auditableId ?? $model->getKey(),
            before: Arr::only($before, $changed),
            after: $this->snapshot($model, $changed),
            note: $note,
            accountId: $accountId,
            module: 'website',
        ));
    }
}
