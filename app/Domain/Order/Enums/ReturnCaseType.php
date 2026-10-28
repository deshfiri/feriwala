<?php

namespace App\Domain\Order\Enums;

use App\Support\Status\HasTranslatedLabel;

/**
 * What a case asks for (D35): the goods back and the money settled, or the
 * goods back and a replacement sent.
 *
 * A damage claim is not a third type of case: it is a finding about goods, made
 * at inspection or on a delivered product, and has its own record that points at
 * the case or the order it concerns.
 */
enum ReturnCaseType: string
{
    use HasTranslatedLabel;

    case Return = 'return';
    case Exchange = 'exchange';

    public function isExchange(): bool
    {
        return $this === self::Exchange;
    }

    protected static function statusLabelGroup(): string
    {
        return 'return_case_type';
    }
}
