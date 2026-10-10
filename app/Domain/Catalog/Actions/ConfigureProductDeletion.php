<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Domain\Catalog\ProductDeletionRule;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * Chooses which product statuses may be deleted: every status, or drafts only.
 *
 * Audited because it decides whether a product that has already been sold or
 * published can be taken out of circulation.
 */
class ConfigureProductDeletion
{
    public function __construct(
        protected SettingsRepository $settings,
        protected ProductDeletionRule $rule,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException when the scope is not one of {@see ProductDeletionRule::SCOPES}
     */
    public function handle(User $actor, string $scope): void
    {
        CatalogPolicy::authorize(CatalogPolicy::canManageSettings($actor), 'You may not change catalogue settings.');

        if (! in_array($scope, ProductDeletionRule::SCOPES, true)) {
            throw new InvalidArgumentException('Unknown product deletion scope.');
        }

        $before = $this->rule->scope();

        $this->settings->define(
            ProductDeletionRule::SETTING,
            'catalog',
            SettingType::String,
            ProductDeletionRule::ANY_STATUS,
            label: 'Product statuses that may be deleted',
        );

        $this->settings->set(ProductDeletionRule::SETTING, $scope, $actor->id);

        $this->audit->handle(new AuditEntry(
            action: 'catalog.product_deletion_scope_changed',
            actorId: $actor->id,
            before: ['scope' => $before],
            after: ['scope' => $scope],
            module: 'catalog',
            isSensitive: true,
        ));
    }
}
