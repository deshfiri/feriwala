<?php

namespace App\Domain\Damage\Enums;

use App\Support\Status\HasTranslatedLabel;

/**
 * What the most a damage can cost was worked out from (D35).
 *
 * Only ever something the order line itself froze when it was placed:
 *
 *   - **suggested_price** — the platform's suggested selling price, per unit.
 *   - **platform_retained** — what the line froze as the platform's share, for a
 *     line placed before the suggested price was kept on its own.
 *   - **none** — the line froze neither, so there is nothing to value it by and
 *     nothing is invented: the case can only be written off.
 *
 * Never the Partner's own store price, their profit, or a price edited since.
 */
enum DamageValuationBasis: string
{
    use HasTranslatedLabel;

    case SuggestedPrice = 'suggested_price';
    case PlatformRetained = 'platform_retained';
    case None = 'none';

    protected static function statusLabelGroup(): string
    {
        return 'damage_valuation';
    }
}
