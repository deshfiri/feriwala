<?php

namespace App\Domain\Website\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\ProductEligibility;
use App\Domain\Package\Entitlements;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Website\Enums\WebhookEvent;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\WebsiteProduct;
use App\Models\User;
use App\Support\Concurrency\DistributedLock;
use App\Support\Concurrency\Exceptions\LockTimeout;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Putting a selected product on sale, and taking it off (§15, §8.1, P5-2, P5-3).
 *
 * Three things have to hold before a product goes on sale, and all three are
 * checked here rather than on the screen that offered the button:
 *
 *   1. it has a **price** somebody set — the database refuses a published row
 *      without one, and this says so in words first;
 *   2. the product is **still eligible** for this account on the dropshipping
 *      channel — a product withdrawn since it was chosen must not go live;
 *   3. the account has **not used up its publish limit** (§8.1).
 *
 * The limit is counted **under a lock, across the account's storefronts**, and
 * re-checked inside it. Two tabs publishing at once would otherwise both read
 * forty-nine of fifty and both succeed, which is exactly how a package limit
 * becomes a suggestion.
 *
 * Unpublishing is always allowed. Taking your own shop's product down is never
 * something the platform should refuse.
 */
class SetWebsiteProductPublication
{
    protected const LOCK_TTL = 10;

    protected const LOCK_WAIT = 5;

    public function __construct(
        protected Entitlements $entitlements,
        protected ProductEligibility $eligibility,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
        protected DistributedLock $lock,
        protected SyncWebsiteProduct $sync,
    ) {}

    /**
     * @throws WebsiteRefused
     * @throws LockTimeout
     */
    public function publish(WebsiteProduct $selection, User $actor): WebsiteProduct
    {
        $selection->loadMissing(['website.businessAccount', 'product']);
        $website = $selection->website;

        if (! $website->status->acceptsSync() && ! $website->status->isBeingBuilt()) {
            throw WebsiteRefused::statusDoesNotAllow();
        }

        if ($selection->price === null) {
            throw WebsiteRefused::priceRequired();
        }

        if (! $this->eligibility->isEligible($selection->product, $website->businessAccount, SalesChannel::Dropshipping)) {
            throw WebsiteRefused::productNotEligible();
        }

        /** @var WebsiteProduct $published */
        $published = $this->lock->run(
            // Per account, because the limit is the account's, not the shop's.
            key: 'website:publish:'.$selection->business_account_id,
            callback: function () use ($selection, $website, $actor) {
                $account = $website->businessAccount;

                $current = WebsiteProduct::query()
                    ->forAccount($account)
                    ->published()
                    ->whereKeyNot($selection->id)
                    ->count();

                if (! $this->entitlements->hasCapacityFor($account, PackageFeature::ProductPublishLimit, $current)) {
                    throw WebsiteRefused::publishLimitReached(
                        $this->entitlements->limit($account, PackageFeature::ProductPublishLimit) ?? 0,
                    );
                }

                return $this->database->transaction(function () use ($selection, $actor) {
                    $selection->forceFill([
                        'status' => WebsiteProductStatus::Published,
                        'published_at' => CarbonImmutable::now(),
                        'unpublished_at' => null,

                        // The storefront has not been told yet (§17.2).
                        'sync_status' => WebsiteSyncStatus::Pending,
                    ])->save();

                    $this->record($selection, $actor, 'website.product_published');

                    return $selection;
                });
            },
            ttlSeconds: self::LOCK_TTL,
            waitSeconds: self::LOCK_WAIT,
        );

        // Real time (§17.2): the storefront hears about it as it happens.
        $this->sync->handle($published, WebhookEvent::ProductPublished);

        return $published;
    }

    public function unpublish(WebsiteProduct $selection, User $actor): WebsiteProduct
    {
        if ($selection->status !== WebsiteProductStatus::Published) {
            return $selection;
        }

        $selection->forceFill([
            'status' => WebsiteProductStatus::Unpublished,
            'unpublished_at' => CarbonImmutable::now(),
            'sync_status' => WebsiteSyncStatus::Pending,
        ])->save();

        $this->record($selection, $actor, 'website.product_unpublished');

        $this->sync->handle($selection, WebhookEvent::ProductUnpublished);

        return $selection;
    }

    protected function record(WebsiteProduct $selection, User $actor, string $action): void
    {
        $this->audit->handle(new AuditEntry(
            action: $action,
            actorId: $actor->id,
            auditableType: WebsiteProduct::class,
            auditableId: $selection->id,
            after: ['status' => $selection->status->value],
            accountId: $selection->business_account_id,
            module: 'website',
        ));
    }
}
