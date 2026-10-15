<?php

declare(strict_types=1);

namespace Storefront\Erp;

use RuntimeException;

/**
 * The ERP refused a request, or could not be reached.
 *
 * `code()` is the contract's stable machine-readable error code; the message is
 * the ERP's human wording and may change, so nothing branches on it.
 */
final class ErpException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly ?string $requestId = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $status);
    }

    public function is(string ...$codes): bool
    {
        return in_array($this->errorCode, $codes, true);
    }

    /** The ERP could not be asked, or failed on its side: nothing is known. */
    public function isUnavailable(): bool
    {
        return $this->status === 0 || $this->status >= 500 || $this->status === 429;
    }

    public static function unreachable(string $reason): self
    {
        return new self(0, 'erp_unreachable', $reason);
    }
}
