<?php

namespace App\Domain\Website\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Website\Enums\WebhookEvent;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Enums\WebsiteStatusChangeSource;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Notifications\Website\WebsiteStatusChanged;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;

/**
 * The one thing that moves a website's status (§16.4, P5-9).
 *
 * Every route into a status change — a partner paying a charge, an
 * administrator suspending a shop, the nightly sweep finding a lapsed package —
 * comes through here, so a move is always checked against the map, always
 * recorded, and always audited. A status written directly would be a storefront
 * that went dark with nothing saying why.
 *
 * The move and its history row are made **together or not at all**, under a row
 * lock, by the shared status-history contract (P0-16). `$attributes` are the
 * columns that belong with the move — the date a website was activated, the
 * reason it was suspended — set in the same transaction so they cannot describe
 * a move that did not happen.
 *
 * Returning the website unchanged when it is already in that state is
 * deliberate: the sweep runs daily and would otherwise refuse every website it
 * already put right.
 */
class MoveWebsiteStatus
{
    public function __construct(
        protected DatabaseManager $database,
        protected RecordAuditLog $audit,
        protected PublishWebsiteEvent $publish,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  website columns set with the move
     *
     * @throws WebsiteRefused when the move is not one the status map allows
     */
    public function handle(
        Website $website,
        WebsiteStatus $to,
        WebsiteStatusChangeSource $source,
        ?StatusChange $change = null,
        array $attributes = [],
    ): Website {
        $change ??= StatusChange::bySystem();

        $result = $this->database->transaction(function () use ($website, $to, $source, $change, $attributes) {
            /** @var Website $locked */
            $locked = Website::query()->lockForUpdate()->findOrFail($website->id);

            if ($locked->status === $to) {
                return null;
            }

            if (! $locked->canTransitionTo($to)) {
                throw WebsiteRefused::statusDoesNotAllow();
            }

            // Read before the move: after it, the model no longer knows where
            // it started.
            $previous = $locked->status;

            if ($attributes !== []) {
                $locked->forceFill($attributes);
            }

            $locked->transitionWithHistory($to, $change, ['source' => $source->value]);

            $website->setRawAttributes($locked->getAttributes(), sync: true);

            return [$locked, $previous];
        });

        if ($result === null) {
            return $website;
        }

        [$moved, $previous] = $result;

        $this->audit->handle(new AuditEntry(
            action: 'website.status_changed',
            actorId: $change->actorId,
            actorType: $change->actorId === null ? 'system' : 'user',
            auditableType: Website::class,
            auditableId: $moved->id,
            before: ['status' => $previous->value],
            after: ['status' => $to->value, 'source' => $source->value],
            reason: $change->reason,
            note: $change->internalNote,
            accountId: $moved->business_account_id,
            module: 'website',
        ));

        $this->tell($moved, $to, $change->publicNote);
        $this->announce($moved, $previous, $to);

        return $website;
    }

    /**
     * Tell the storefront itself when it stops or starts being served
     * (contract §7.1 `website.suspended`, `website.restored`).
     */
    protected function announce(Website $website, WebsiteStatus $previous, WebsiteStatus $to): void
    {
        $wasLive = $previous->isLive() || $previous === WebsiteStatus::Maintenance;
        $isLive = $to->isLive() || $to === WebsiteStatus::Maintenance;

        if ($wasLive === $isLive) {
            return;
        }

        $this->publish->handle(
            $website,
            $isLive ? WebhookEvent::WebsiteRestored : WebhookEvent::WebsiteSuspended,
            ['status' => $to->value],
            'website_status',
            $website->id,
        );
    }

    /**
     * Tell the account holder, where the move is one they need to know about.
     *
     * Only the public note travels: the internal note is written about them,
     * not to them (§7.2's reasoning, applied to §16.4).
     */
    protected function tell(Website $website, WebsiteStatus $to, ?string $publicNote): void
    {
        if (! in_array($to, [
            WebsiteStatus::Active,
            WebsiteStatus::Suspended,
            WebsiteStatus::TemporarilyDisabled,
            WebsiteStatus::GracePeriod,
            WebsiteStatus::PackageExpired,
        ], true)) {
            return;
        }

        $owner = $website->businessAccount->owner;

        $owner?->notify(new WebsiteStatusChanged(
            website: $website->name,
            websiteId: $website->public_id,
            status: $to,
            publicNote: $publicNote,
        ));
    }
}
