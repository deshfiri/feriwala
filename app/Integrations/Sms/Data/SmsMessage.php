<?php

namespace App\Integrations\Sms\Data;

use App\Support\Localization\Locale;

/**
 * One SMS about to be sent.
 */
class SmsMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $body,
        public readonly Locale $locale = Locale::English,
        public readonly ?string $event = null,
        public readonly ?int $userId = null,
    ) {}

    /**
     * Bangla text is encoded as UCS-2 by every provider, which cuts the segment
     * length from 160 characters to 70. Knowing this before sending is what
     * stops a "one message" template quietly costing three (§30.2 cost tracking).
     */
    public function segments(): int
    {
        $isUnicode = preg_match('/[^\x00-\x7F]/', $this->body) === 1;

        $length = mb_strlen($this->body);
        $single = $isUnicode ? 70 : 160;
        $concatenated = $isUnicode ? 67 : 153;

        return $length <= $single
            ? 1
            : (int) ceil($length / $concatenated);
    }

    public function isUnicode(): bool
    {
        return preg_match('/[^\x00-\x7F]/', $this->body) === 1;
    }
}
