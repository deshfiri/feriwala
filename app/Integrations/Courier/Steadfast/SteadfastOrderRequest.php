<?php

namespace App\Integrations\Courier\Steadfast;

use App\Support\Money\Money;

/**
 * What Banij sends to `POST /create_order`: exactly the fields Steadfast's
 * maintained package demonstrates (`invoice`, `recipient_name`,
 * `recipient_phone`, `recipient_address`, `cod_amount`) and nothing more. The
 * package shows `note` as optional; it is not sent, because no note is needed.
 * No other field is documented, so none is invented.
 */
final class SteadfastOrderRequest
{
    public function __construct(
        public readonly string $invoice,
        public readonly string $recipientName,
        public readonly string $recipientPhone,
        public readonly string $recipientAddress,
        public readonly Money $codAmount,
    ) {}

    /**
     * The JSON body. `cod_amount` is written as an exact number token (never
     * through a float): `1250` or `1250.50`.
     */
    public function toJson(): string
    {
        $json = json_encode([
            'invoice' => $this->invoice,
            'recipient_name' => $this->recipientName,
            'recipient_phone' => $this->recipientPhone,
            'recipient_address' => $this->recipientAddress,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return substr($json, 0, -1).',"cod_amount":'.$this->codNumber().'}';
    }

    /**
     * What the request says about itself, safe to keep: no name, number or
     * address.
     *
     * @return array{invoice: string, cod_amount: string}
     */
    public function summary(): array
    {
        return ['invoice' => $this->invoice, 'cod_amount' => $this->codAmount->toDecimal()];
    }

    protected function codNumber(): string
    {
        $decimal = $this->codAmount->toDecimal();

        return str_ends_with($decimal, '.00') ? substr($decimal, 0, -3) : $decimal;
    }
}
