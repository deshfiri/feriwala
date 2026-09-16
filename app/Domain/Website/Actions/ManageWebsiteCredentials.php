<?php

namespace App\Domain\Website\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Website\Data\IssuedCredential;
use App\Domain\Website\Enums\CredentialScope;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCredential;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Issuing, rotating and revoking a storefront's credentials (§17.3, contract §3.1, P5-17).
 *
 * **The secret is shown once.** It is thirty-two random bytes, encrypted before
 * it is stored, and handed back to the caller only from here — a screen that can
 * show a secret again is a screen that can leak it again.
 *
 * **Rotation keeps the old secret for a stated window.** A storefront cannot
 * redeploy in the same instant a partner presses "rotate", so the previous
 * secret keeps verifying until the window closes, and then stops. The window is
 * configuration rather than a constant because how long a deploy takes is a
 * fact about the storefront, not about this code.
 *
 * **Revocation is immediate and final.** A leaked key is not something to wind
 * down gently, and a revoked credential is never un-revoked: issue a new one.
 *
 * Every step is audited, and none of them writes the secret into the audit
 * trail — the key identifier and the last four characters are enough to say
 * which one it was.
 */
class ManageWebsiteCredentials
{
    public function __construct(
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @param  array<int, CredentialScope>  $scopes
     *
     * @throws WebsiteRefused
     */
    public function issue(Website $website, User $actor, string $name, array $scopes): IssuedCredential
    {
        if ($website->status->isTerminal()) {
            throw WebsiteRefused::notEditable();
        }

        $secret = $this->newSecret();

        $credential = WebsiteCredential::create([
            'website_id' => $website->id,
            'name' => $name,
            'key_id' => 'wsk_'.Str::upper((string) Str::ulid()),
            'secret' => $secret,
            'secret_hint' => substr($secret, -4),
            'scopes' => array_values(array_unique(array_map(
                fn (CredentialScope $scope) => $scope->value,
                $scopes === [] ? CredentialScope::defaults() : $scopes,
            ))),
            'created_by' => $actor->id,
        ]);

        $this->record($credential, $actor, 'website.credential_issued', [
            'key_id' => $credential->key_id,
            'scopes' => $credential->scopes,
        ]);

        return new IssuedCredential($credential, $secret);
    }

    /**
     * @throws WebsiteRefused
     */
    public function rotate(WebsiteCredential $credential, User $actor): IssuedCredential
    {
        if ($credential->isRevoked()) {
            throw WebsiteRefused::credentialRevoked();
        }

        $secret = $this->newSecret();
        $now = CarbonImmutable::now();

        $credential->forceFill([
            'previous_secret' => $credential->secret,
            'previous_secret_expires_at' => $now->addMinutes((int) config('website.api.rotation_grace_minutes', 1440)),
            'secret' => $secret,
            'secret_hint' => substr($secret, -4),
            'rotated_at' => $now,
        ])->save();

        $this->record($credential, $actor, 'website.credential_rotated', [
            'key_id' => $credential->key_id,
            'previous_secret_expires_at' => $credential->previous_secret_expires_at?->toIso8601String(),
        ]);

        return new IssuedCredential($credential, $secret);
    }

    public function revoke(WebsiteCredential $credential, User $actor, string $reason): WebsiteCredential
    {
        if ($credential->isRevoked()) {
            return $credential;
        }

        $credential->forceFill([
            'revoked_at' => CarbonImmutable::now(),
            'revoked_reason' => $reason,
            'revoked_by' => $actor->id,

            // Nothing verifies against a revoked credential, the previous
            // secret included.
            'previous_secret' => null,
            'previous_secret_expires_at' => null,
        ])->save();

        $this->record($credential, $actor, 'website.credential_revoked', [
            'key_id' => $credential->key_id,
        ], $reason);

        return $credential;
    }

    /**
     * Thirty-two random bytes, as hexadecimal (contract §3.1).
     */
    protected function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * @param  array<string, mixed>  $after
     */
    protected function record(
        WebsiteCredential $credential,
        User $actor,
        string $action,
        array $after,
        ?string $reason = null,
    ): void {
        $credential->loadMissing('website');

        $this->audit->handle(new AuditEntry(
            action: $action,
            actorId: $actor->id,
            auditableType: WebsiteCredential::class,
            auditableId: $credential->id,
            after: $after,
            reason: $reason,
            accountId: $credential->website->business_account_id,
            module: 'website',
            isSensitive: PermissionAction::ManageIntegrations->isSensitive(),
        ));
    }
}
