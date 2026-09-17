<?php

namespace App\Domain\Website\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Package\Entitlements;
use App\Domain\Website\Data\WebsitePricingTerms;
use App\Domain\Website\Enums\WebhookEvent;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\WebsiteCategory;
use App\Domain\Website\Models\WebsiteProduct;
use App\Domain\Website\Queries\ResolveWebsitePriceRule;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * What a partner sets on one of their selected products (§15.1, P5-4, P5-6).
 *
 * Website category, display order, featured status, their own selling price, a
 * promotional price, a short promotional title and a short marketing
 * description — §15.1's list, and only where the administrator's rule permits
 * it.
 *
 * **Every figure is checked here, against the resolved terms**, on every save.
 * The screen is sent the bounds so a partner can see them, but a price is never
 * allowed because a form said it was in range: the browser's copy of the rule
 * is ten minutes old and the rule may have closed in nine.
 *
 * A locked field is refused rather than ignored. Silently dropping a change
 * somebody made and reporting success is worse than saying no.
 *
 * Any change marks the storefront's copy **pending**: the shop is showing the
 * old figure until something pushes the new one (§17.2, P5-7).
 */
class UpdateWebsiteProduct
{
    public function __construct(
        protected ResolveWebsitePriceRule $pricing,
        protected Entitlements $entitlements,
        protected RecordAuditLog $audit,
        protected SyncWebsiteProduct $sync,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  already shape-validated by the caller
     *
     * @throws WebsiteRefused
     */
    public function handle(WebsiteProduct $selection, User $actor, array $attributes): WebsiteProduct
    {
        $selection->loadMissing(['website', 'product']);

        if ($selection->website->status->isTerminal()) {
            throw WebsiteRefused::notEditable();
        }

        $terms = $this->pricing->for(
            $selection->product,
            $this->entitlements->activePackage($selection->website->businessAccount)?->package,
        );

        $currency = Currency::from($selection->currency_code);
        $changes = [];

        if (array_key_exists('price', $attributes)) {
            $changes['price_minor'] = $this->price($terms, $currency, $attributes['price'], 'price');
        }

        if (array_key_exists('promotional_price', $attributes)) {
            $promotion = $attributes['promotional_price'];

            $changes['promotional_price_minor'] = $promotion === null
                ? null
                : $this->price($terms, $currency, $promotion, 'promotional_price');
        }

        foreach (['promo_title', 'marketing_description', 'display_order', 'is_featured'] as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }

            $this->assertUnlocked($terms, $field === 'is_featured' ? 'is_featured' : $field);

            $changes[$field] = $attributes[$field];
        }

        if (array_key_exists('website_category_id', $attributes)) {
            $this->assertUnlocked($terms, 'website_category');

            $changes['website_category_id'] = $this->category($selection, $attributes['website_category_id']);
        }

        $this->assertPromotionIsAReduction($selection, $changes);

        if ($changes === []) {
            return $selection;
        }

        $selection->forceFill([
            ...$changes,

            // The storefront's copy is now behind (§17.2).
            'sync_status' => WebsiteSyncStatus::Pending,
        ])->save();

        $this->audit->handle(new AuditEntry(
            action: 'website.product_updated',
            actorId: $actor->id,
            auditableType: WebsiteProduct::class,
            auditableId: $selection->id,
            after: array_intersect_key($selection->getChanges(), $changes),
            accountId: $selection->business_account_id,
            module: 'website',
        ));

        // Only a product on sale is worth telling the storefront about; one
        // that is merely selected is not on the shop to be out of date.
        if ($selection->status === WebsiteProductStatus::Published) {
            $priceChanged = array_key_exists('price_minor', $changes) || array_key_exists('promotional_price_minor', $changes);

            $this->sync->handle(
                $selection,
                $priceChanged ? WebhookEvent::ProductPriceChanged : WebhookEvent::ProductUpdated,
            );
        }

        return $selection;
    }

    /**
     * One amount, checked against the terms rather than accepted.
     *
     * @throws WebsiteRefused
     */
    protected function price(WebsitePricingTerms $terms, Currency $currency, mixed $value, string $field): Money
    {
        $this->assertUnlocked($terms, $field);

        $amount = Money::of((int) $value, $currency);
        $refusal = $terms->refusalFor($amount);

        if ($refusal !== null) {
            throw WebsiteRefused::priceRefused($refusal, $terms);
        }

        return $amount;
    }

    /**
     * The category, if it belongs to this storefront.
     *
     * @throws WebsiteRefused
     */
    protected function category(WebsiteProduct $selection, mixed $publicId): ?int
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        /** @var WebsiteCategory|null $category */
        $category = WebsiteCategory::query()
            ->where('website_id', $selection->website_id)
            ->where('public_id', (string) $publicId)
            ->first();

        if ($category === null) {
            throw WebsiteRefused::categoryNotFound();
        }

        return $category->id;
    }

    /**
     * A promotion is a reduction, checked before the database says so.
     *
     * The column has the same CHECK, and this exists so the partner gets a
     * message about the field rather than a constraint violation.
     *
     * @param  array<string, mixed>  $changes
     *
     * @throws WebsiteRefused
     */
    protected function assertPromotionIsAReduction(WebsiteProduct $selection, array $changes): void
    {
        $price = $changes['price_minor'] ?? $selection->price_minor;
        $promotion = array_key_exists('promotional_price_minor', $changes)
            ? $changes['promotional_price_minor']
            : $selection->promotional_price_minor;

        if (! $promotion instanceof Money) {
            return;
        }

        if (! $price instanceof Money || ! $promotion->lessThan($price)) {
            throw WebsiteRefused::promotionNotAReduction();
        }
    }

    /**
     * @throws WebsiteRefused
     */
    protected function assertUnlocked(WebsitePricingTerms $terms, string $field): void
    {
        if ($terms->locks($field)) {
            throw WebsiteRefused::fieldLocked($field);
        }
    }
}
