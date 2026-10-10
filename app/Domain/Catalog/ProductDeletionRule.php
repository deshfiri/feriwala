<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Settings\SettingsRepository;

/**
 * Which product statuses may be moved to Trash.
 *
 * Either every status (the behaviour before this was configurable, so it is
 * the default for an installation nobody has configured) or Draft only, for
 * an administrator who wants anything that has been reviewed, sold or retired
 * to be archived rather than deleted. One setting, one question
 * everything else asks instead of re-deriving it.
 */
class ProductDeletionRule
{
    public const SETTING = 'catalog.product_deletion_scope';

    public const ANY_STATUS = 'any_status';

    public const DRAFTS_ONLY = 'drafts_only';

    /**
     * @var array<int, string>
     */
    public const SCOPES = [self::ANY_STATUS, self::DRAFTS_ONLY];

    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    public function scope(): string
    {
        $value = $this->settings->get(self::SETTING);

        return in_array($value, self::SCOPES, true) ? $value : self::ANY_STATUS;
    }

    public function allows(ProductStatus $status): bool
    {
        return $this->scope() === self::ANY_STATUS || $status === ProductStatus::Draft;
    }
}
