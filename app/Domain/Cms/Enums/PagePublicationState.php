<?php

namespace App\Domain\Cms\Enums;

use App\Support\StateMachine\TransitionableState;
use App\Support\Status\HasTranslatedLabel;

/**
 * A CMS page's own lifecycle (§4, §34.1) — distinct from any one revision's
 * state. A page can be Draft with no revision published yet, Published with
 * one live, Scheduled with one waiting for its moment, or Unpublished
 * (taken down, its revisions kept for history and possible republish).
 */
enum PagePublicationState: string implements TransitionableState
{
    use HasTranslatedLabel;

    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Unpublished = 'unpublished';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Draft => [self::Scheduled, self::Published],
            self::Scheduled => [self::Published, self::Draft],
            self::Published => [self::Scheduled, self::Unpublished],
            self::Unpublished => [self::Scheduled, self::Published],
        };
    }

    public function isTerminal(): bool
    {
        return false;
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::Scheduled => 'info',
            self::Published => 'success',
            self::Unpublished => 'warning',
        };
    }

    protected static function statusLabelGroup(): string
    {
        return 'cms_page';
    }
}
