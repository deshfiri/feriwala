<?php

namespace App\Domain\Website\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * A Non-Conditional partner choosing, storefront by storefront, whether the shop
 * lists the whole eligible catalogue (the default) or only the products they chose.
 *
 * Switching to **selected only** takes off what the default listed and nothing the
 * owner chose; the rows stay, so switching back puts the same ones on sale again
 * at the prices they had. Switching to **all** lists whatever is new
 * ({@see ListWholeCatalogueOnWebsite}). Either way the storefront's copy is marked
 * pending and brought up to date by the scheduled catalogue pass.
 *
 * Refused for a Conditional account, which has no default listing to switch.
 */
class SetWebsiteCatalogueMode
{
    public function __construct(
        protected ListWholeCatalogueOnWebsite $listing,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws WebsiteRefused
     */
    public function handle(Website $website, User $actor, bool $wholeCatalogue): Website
    {
        if ($website->status->isTerminal()) {
            throw WebsiteRefused::notEditable();
        }

        if (! $website->businessAccount->isNonConditional()) {
            throw WebsiteRefused::catalogueModeNeedsNonConditional();
        }

        $before = $website->lists_whole_catalogue;

        if ($before === $wholeCatalogue) {
            return $website;
        }

        $this->database->transaction(function () use ($website, $wholeCatalogue): void {
            $website->forceFill(['lists_whole_catalogue' => $wholeCatalogue])->save();

            if (! $wholeCatalogue) {
                WebsiteProduct::query()
                    ->where('website_id', $website->id)
                    ->where('auto_listed', true)
                    ->where('status', WebsiteProductStatus::Published->value)
                    ->update([
                        'status' => WebsiteProductStatus::Unpublished->value,
                        'unpublished_at' => CarbonImmutable::now(),
                        'sync_status' => WebsiteSyncStatus::Pending->value,
                    ]);
            }
        });

        if ($wholeCatalogue) {
            $this->listing->handle($website);
        }

        $this->audit->handle(new AuditEntry(
            action: 'website.catalogue_mode_changed',
            actorId: $actor->id,
            auditableType: Website::class,
            auditableId: $website->id,
            before: ['lists_whole_catalogue' => $before],
            after: ['lists_whole_catalogue' => $wholeCatalogue],
            accountId: $website->business_account_id,
            module: 'website',
        ));

        return $website;
    }
}
