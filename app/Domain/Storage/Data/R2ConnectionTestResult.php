<?php

namespace App\Domain\Storage\Data;

use App\Http\Controllers\Admin\PaymentGatewayController;

/**
 * The outcome of a genuine round trip against Cloudflare R2.
 *
 * Never a cached "last verified" flag -- {@see
 * \App\Integrations\Storage\R2Manager::testConnection()} builds this result
 * from a live `ListObjectsV2` call every time it is asked, the same "a
 * 'last verified' field that a screen writes is a field that can be wrong"
 * reasoning {@see PaymentGatewayController}
 * already applies to a gateway's own verification timestamp.
 */
class R2ConnectionTestResult
{
    private function __construct(
        public readonly bool $success,
        public readonly string $message,
    ) {}

    public static function success(string $message = 'Connected to the bucket successfully.'): self
    {
        return new self(true, $message);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
        ];
    }
}
