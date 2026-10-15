<?php

namespace App\Domain\Kyc\Enums;

/**
 * Who a KYC document type is asked of.
 *
 * Package and country rules are an account concern -- a Supplier has neither --
 * so for Suppliers a type's own `is_required` is the whole answer.
 */
enum KycAudience: string
{
    case Account = 'account';
    case Supplier = 'supplier';
    case Both = 'both';

    public function label(): string
    {
        return __("kyc.batch_builder.audiences.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $audience) => $audience->value, self::cases());
    }
}
