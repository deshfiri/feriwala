<?php

namespace App\Integrations\Storage;

use App\Domain\Storage\Data\R2ConnectionTestResult;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Builds a Cloudflare R2 (S3-compatible) disk from admin-entered credentials
 * and proves, live, that it works (beta-critical batch, Commit 3).
 *
 * Never reads from `config/filesystems.php`'s static `s3` disk or from
 * `AWS_*` env vars for its primary path -- credentials come from {@see
 * \App\Domain\Storage\R2StorageSettings}, entered under Settings -> Storage
 * and encrypted at rest. Env vars stay an emergency fallback a deploy can
 * fall back to, never something this manager reads itself.
 *
 * Builds an ad-hoc disk with `Storage::build()` rather than registering a
 * disk in `config('filesystems.disks')`, so a credential rotation takes
 * effect on the next call rather than needing a config cache clear, and so
 * "test these not-yet-saved credentials" and "use what's actually stored"
 * are the exact same code path.
 */
class R2Manager
{
    /**
     * @param  array{account_id: ?string, access_key_id: ?string, secret_access_key: ?string, bucket: ?string, endpoint: ?string, region: string, public_domain: ?string, default_visibility: string}  $credentials
     * @return array<string, mixed>
     */
    public function diskConfig(array $credentials): array
    {
        return [
            'driver' => 's3',
            'key' => $credentials['access_key_id'],
            'secret' => $credentials['secret_access_key'],
            'region' => $credentials['region'] !== '' ? $credentials['region'] : 'auto',
            'bucket' => $credentials['bucket'],
            'endpoint' => $credentials['endpoint'],
            // R2 only serves a bucket at a virtual-hosted-style URL under a
            // custom domain; against the account endpoint itself it is
            // path-style only.
            'use_path_style_endpoint' => true,
            'url' => $credentials['public_domain'],
            'visibility' => $credentials['default_visibility'],
            'throw' => true,
            'report' => false,
        ];
    }

    /**
     * @param  array{account_id: ?string, access_key_id: ?string, secret_access_key: ?string, bucket: ?string, endpoint: ?string, region: string, public_domain: ?string, default_visibility: string}  $credentials
     */
    public function disk(array $credentials): Filesystem
    {
        /** @var Filesystem */
        return Storage::build($this->diskConfig($credentials));
    }

    /**
     * A genuine round trip against R2 -- never a cached boolean.
     *
     * Listing the bucket's root fails on a bad key, a bad secret, or a
     * bucket that does not exist or is not reachable from this account, and
     * succeeds only once all three are right. That is both the "connection"
     * check and the "bucket" check the settings screen asks for, in one
     * request.
     *
     * @param  array{account_id: ?string, access_key_id: ?string, secret_access_key: ?string, bucket: ?string, endpoint: ?string, region: string, public_domain: ?string, default_visibility: string}  $credentials
     */
    public function testConnection(array $credentials): R2ConnectionTestResult
    {
        try {
            $this->disk($credentials)->directoryExists('');

            return R2ConnectionTestResult::success();
        } catch (Throwable $exception) {
            return R2ConnectionTestResult::failure($this->sanitize($exception->getMessage()));
        }
    }

    /**
     * Strip anything resembling a signed-request credential out of a
     * provider's error message before it reaches a screen or an audit log --
     * an AWS-SDK exception can echo back the request it tried to sign.
     */
    protected function sanitize(string $message): string
    {
        $message = preg_replace('/AWS4-HMAC-SHA256.*/is', '[request details redacted]', $message) ?? $message;
        $message = preg_replace('/Authorization:.*/i', '[request details redacted]', $message) ?? $message;

        return mb_strimwidth($message, 0, 500, '...');
    }
}
