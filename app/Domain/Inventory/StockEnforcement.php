<?php

namespace App\Domain\Inventory;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;

/**
 * Whether stock can stop an order.
 *
 * **On by default.** With it on, a cart or an order for more than is available
 * is refused. With it off, partners can always order: stock is still held and
 * counted for the platform where it exists, but running short never refuses a
 * cart line, a checkout or an order.
 */
class StockEnforcement
{
    public const SETTING = 'inventory.stock_blocks_orders';

    public function __construct(
        protected SettingsRepository $settings,
        protected RecordAuditLog $audit,
    ) {}

    public function enforced(): bool
    {
        return (bool) $this->settings->get(self::SETTING, true);
    }

    public function switchTo(bool $enforced, User $actor, string $reason): void
    {
        $before = $this->enforced();

        $this->settings->define(
            self::SETTING,
            'inventory',
            SettingType::Boolean,
            true,
            label: 'Stock can block orders',
            description: 'When on, orders beyond available stock are refused. When off, stock is only used for platform calculation and partners can always order.',
        );

        $this->settings->set(self::SETTING, $enforced, $actor->id);

        $this->audit->handle(new AuditEntry(
            action: 'inventory.stock_enforcement_switched',
            actorId: $actor->id,
            auditableType: null,
            auditableId: null,
            before: ['enforced' => $before],
            after: ['enforced' => $enforced],
            reason: $reason,
            module: 'inventory',
        ));
    }
}
