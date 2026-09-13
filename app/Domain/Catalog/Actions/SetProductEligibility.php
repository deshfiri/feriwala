<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Enums\AccountScope;
use App\Domain\Catalog\Enums\PackageScope;
use App\Domain\Catalog\Models\Product;
use App\Domain\Package\Models\Package;
use App\Models\User;
use Illuminate\Database\DatabaseManager;

/**
 * Decide which partners may see a product (§11.1, §12).
 *
 * Both scopes and both lists in one locked write, because they are one decision:
 * switching to "selected packages" and choosing those packages in two saves would
 * leave a moment where the product was offered to nobody — or, the other way
 * round, to everybody.
 *
 * The lists are kept even when their scope does not use them. Switching to "every
 * package" for a promotion and back should not lose which packages it was on.
 */
class SetProductEligibility
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  array<int, string>  $packagePublicIds
     * @param  array<int, string>  $accountPublicIds
     */
    public function handle(
        User $actor,
        Product $product,
        PackageScope $packageScope,
        array $packagePublicIds,
        AccountScope $accountScope,
        array $accountPublicIds,
    ): Product {
        return $this->database->transaction(function () use ($actor, $product, $packageScope, $packagePublicIds, $accountScope, $accountPublicIds) {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            $before = $this->snapshot($locked);

            $locked->forceFill([
                'package_scope' => $packageScope,
                'account_scope' => $accountScope,
            ])->save();

            $locked->eligiblePackages()->sync(
                Package::query()->whereIn('public_id', array_unique($packagePublicIds))->pluck('id')->all(),
            );

            $locked->eligibleAccounts()->sync(
                BusinessAccount::query()->whereIn('public_id', array_unique($accountPublicIds))->pluck('id')->all(),
            );

            $this->audit->handle(new AuditEntry(
                action: 'catalog.product_eligibility_set',
                actorId: $actor->id,
                auditableType: Product::class,
                auditableId: $locked->id,
                before: $before,
                after: $this->snapshot($locked),
                module: 'catalog',
            ));

            return $locked;
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshot(Product $product): array
    {
        return [
            'package_scope' => $product->package_scope->value,
            'packages' => $product->eligiblePackages()->pluck('packages.public_id')->sort()->values()->all(),
            'account_scope' => $product->account_scope->value,
            'accounts' => $product->eligibleAccounts()->pluck('business_accounts.public_id')->sort()->values()->all(),
        ];
    }
}
