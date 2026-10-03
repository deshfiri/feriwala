<?php

namespace App\Domain\Sourcing\Enums;

/**
 * Whether a membership or variant mapping currently counts.
 *
 * Rows are never deleted: a removed mapping stays as the record of what was
 * true when an earlier order was placed.
 */
enum SourcingMappingStatus: string
{
    case Active = 'active';
    case Removed = 'removed';
}
