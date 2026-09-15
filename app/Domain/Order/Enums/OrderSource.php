<?php

namespace App\Domain\Order\Enums;

/**
 * Where an order came from (§18.1, P6-2).
 *
 * Every source §18.1 names, typed, because an order's source decides which rules
 * apply to it — who pays whom, whether a website is involved, which fulfilment
 * rules follow (D13). Stored on the order and held to this list by the database.
 *
 * Only ERP wholesale orders are created today (P4-9); the others arrive with the
 * modules that create them.
 */
enum OrderSource: string
{
    /** Bought by a business account through the ERP wholesale checkout (§14). */
    case ErpWholesale = 'erp_wholesale';

    /** Placed by a customer on a dedicated website (§16, §17). */
    case Website = 'website';

    /** Entered by an authorised user of the account (§18.4). */
    case ManualEntry = 'manual';

    /** Submitted through the API (§17.3). */
    case Api = 'api';

    /** Entered by an administrator. */
    case AdminEntry = 'admin';

    /** A channel connected later. */
    case ExternalChannel = 'external';

    public function label(): string
    {
        return match ($this) {
            self::ErpWholesale => 'ERP wholesale',
            self::Website => 'Dedicated website',
            self::ManualEntry => 'Manual entry',
            self::Api => 'API',
            self::AdminEntry => 'Admin entry',
            self::ExternalChannel => 'External channel',
        };
    }
}
