<?php

namespace App\Domain\Website\Enums;

/**
 * Whether a storefront has been told about a change yet (§15, §17.2, P5-7).
 *
 * The ERP is the authority; a storefront holds a copy. So every selected
 * product says where its copy stands — waiting to be sent, sent, or failed to
 * send — because "I changed the price an hour ago and the shop still shows the
 * old one" is the question this answers.
 *
 * `Pending` is the honest state for a change nobody has pushed yet, including
 * one that has only just been made. A product that has never synchronised is
 * pending, not failed: nothing went wrong.
 */
enum WebsiteSyncStatus: string
{
    case Pending = 'pending';
    case Synced = 'synced';
    case Failed = 'failed';

    public function needsAttention(): bool
    {
        return $this === self::Failed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting to synchronise',
            self::Synced => 'Synchronised',
            self::Failed => 'Synchronisation failed',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
