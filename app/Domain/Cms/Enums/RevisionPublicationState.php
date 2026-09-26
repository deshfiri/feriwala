<?php

namespace App\Domain\Cms\Enums;

use App\Support\StateMachine\TransitionableState;
use App\Support\Status\HasTranslatedLabel;

/**
 * One immutable revision's own fate (§34.1) — never edited once created,
 * only moved on: a scheduled revision becomes published when its moment
 * arrives, and a published revision becomes superseded the instant a newer
 * one takes its place. There is no path back to `Scheduled` or forward out
 * of `Superseded`; a rollback creates a brand new revision via
 * `restored_from_id` rather than reviving an old one in place.
 */
enum RevisionPublicationState: string implements TransitionableState
{
    use HasTranslatedLabel;

    case Scheduled = 'scheduled';
    case Published = 'published';
    case Superseded = 'superseded';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Scheduled => [self::Published],
            self::Published => [self::Superseded],
            self::Superseded => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Superseded;
    }

    protected static function statusLabelGroup(): string
    {
        return 'cms_revision';
    }
}
