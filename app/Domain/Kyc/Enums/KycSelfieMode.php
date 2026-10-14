<?php

namespace App\Domain\Kyc\Enums;

/**
 * Whether a batch asks for a live selfie (D30). Off unless an administrator
 * turns it on for that batch.
 */
enum KycSelfieMode: string
{
    case Disabled = 'disabled';
    case Optional = 'optional';
    case Required = 'required';

    public function isAsked(): bool
    {
        return $this !== self::Disabled;
    }

    public function label(): string
    {
        return __('kyc.batch_builder.selfie_modes.'.$this->value);
    }
}
