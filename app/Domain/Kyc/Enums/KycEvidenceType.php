<?php

namespace App\Domain\Kyc\Enums;

/**
 * What one evidence slot takes (D30).
 *
 * A requirement is made of slots, so "National ID" is two image slots (front,
 * back), "Passport" an image and an optional visa page, "TIN" a typed value —
 * and a future document with five files or three fields needs nothing built.
 */
enum KycEvidenceType: string
{
    case File = 'file';
    case Image = 'image';
    case Pdf = 'pdf';
    case Value = 'value';
    case Declaration = 'declaration';

    public function takesFile(): bool
    {
        return in_array($this, [self::File, self::Image, self::Pdf], true);
    }

    public function takesValue(): bool
    {
        return in_array($this, [self::Value, self::Declaration], true);
    }

    /**
     * The MIME types a slot of this type accepts when its own list is empty.
     *
     * @return array<int, string>
     */
    public function defaultMimeTypes(): array
    {
        return match ($this) {
            self::Image => ['image/jpeg', 'image/png'],
            self::Pdf => ['application/pdf'],
            self::File => ['image/jpeg', 'image/png', 'application/pdf'],
            default => [],
        };
    }
}
