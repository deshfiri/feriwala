<?php

namespace App\Domain\Courier\Enums;

use App\Integrations\Courier\CourierManager;

/**
 * Every courier provider §21 names, implemented or not (D8).
 *
 * Only {@see self::Manual} has a driver behind it in this batch --
 * {@see self::Steadfast} and {@see self::Pathao} exist as disabled,
 * credential-less rows (`courier_providers`) so the UI can name them rather
 * than pretend they do not exist. Whether a code is actually usable is read
 * from that table (`is_enabled` and whether
 * {@see CourierManager} has a driver configured
 * for it), never hard-coded here.
 */
enum CourierProviderCode: string
{
    case Manual = 'manual';
    case Steadfast = 'steadfast';
    case Pathao = 'pathao';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Steadfast => 'Steadfast Courier',
            self::Pathao => 'Pathao Courier',
        };
    }
}
