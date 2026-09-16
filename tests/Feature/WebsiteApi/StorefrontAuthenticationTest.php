<?php

use App\Domain\Website\Actions\ManageWebsiteCredentials;
use App\Domain\Website\Api\RequestSignature;
use App\Domain\Website\Enums\CredentialScope;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\ApiLog;
use App\Domain\Website\Models\Website;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Who is calling, proved rather than claimed (contract §3, §4.6, §9, P5-18, P5-19, P5-27).
 *
 * Every check the contract lists, failed on its own: the rest of the request is
 * valid each time, so a test that passes is a test of that one check.
 */
beforeEach(function () {
    $this->account = websiteTestAccount();
    $this->website = Website::factory()->forAccount($this->account)->active()->create();

    [$this->credential, $this->secret] = storefrontCredential($this->website);
});

describe('a correctly signed request', function () {
    it('is answered, stamped with a request identifier and its budget, and logged', function () {
        $response = storefrontCall($this->credential, $this->secret, 'connection');

        $response->assertOk()
            ->assertJsonPath('website.id', $this->website->public_id)
            ->assertJsonPath('credential.key_id', $this->credential->key_id)
            ->assertHeader('X-RateLimit-Limit')
            ->assertHeader('X-RateLimit-Remaining');

        $requestId = $response->headers->get('X-Request-Id');
        $log = ApiLog::query()->where('request_id', $requestId)->firstOrFail();

        expect($log->website_id)->toBe($this->website->id)
            ->and($log->website_credential_id)->toBe($this->credential->id)
            ->and($log->status)->toBe(200)
            ->and($this->website->refresh()->api_connected_at)->not->toBeNull();
    });

    it('answers the liveness check without a credential, and says nothing else', function () {
        $this->get('https://localhost/api/storefront/v1/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    });
});

describe('a request that fails a check', function () {
    it('is refused over plain HTTP rather than redirected', function () {
        storefrontCall($this->credential, $this->secret, 'connection', overrides: ['https' => false])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'https_required');
    });

    it('is refused without an Authorization header', function () {
        storefrontCall($this->credential, $this->secret, 'connection', overrides: ['authorization' => null])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    });

    it('is refused for a key nobody issued, with the same answer as any other failure', function () {
        $unknown = 'wsk_'.Str::upper((string) Str::ulid());

        $response = storefrontCall($this->credential, $this->secret, 'connection', overrides: ['key_id' => $unknown]);

        $response->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');

        // The caller learns nothing; the log says exactly what happened.
        expect(ApiLog::query()->where('request_id', $response->headers->get('X-Request-Id'))->value('error_code'))
            ->toBe('unknown_credential');
    });

    it('is refused when the signature was made with another secret', function () {
        $response = storefrontCall($this->credential, $this->secret, 'connection', overrides: [
            'secret' => str_repeat('a', 64),
        ]);

        $response->assertUnauthorized();

        expect(ApiLog::query()->where('request_id', $response->headers->get('X-Request-Id'))->value('error_code'))
            ->toBe('invalid_signature');
    });

    it('is refused when the query was changed after signing', function () {
        // Signed for one page size, sent with another: the canonical request
        // covers the query, so the signature no longer matches.
        $timestamp = (string) now()->getTimestamp();
        $nonce = (string) Str::uuid();

        $signature = RequestSignature::sign(
            $this->secret,
            RequestSignature::canonical('GET', '/api/storefront/v1/connection', 'limit=10', $timestamp, $nonce, ''),
        );

        $this->call('GET', 'https://localhost/api/storefront/v1/connection?limit=200', [], [], [], [
            'HTTP_AUTHORIZATION' => RequestSignature::authorization($this->credential->key_id, $signature),
            'HTTP_X_FERIWALA_TIMESTAMP' => $timestamp,
            'HTTP_X_FERIWALA_NONCE' => $nonce,
            'HTTP_ACCEPT' => 'application/json',
        ])->assertUnauthorized();
    });

    it('is refused more than five minutes either side of the server clock', function () {
        foreach ([-301, 301] as $skew) {
            $response = storefrontCall($this->credential, $this->secret, 'connection', overrides: [
                'timestamp' => (string) (now()->getTimestamp() + $skew),
            ]);

            $response->assertUnauthorized();

            expect(ApiLog::query()->where('request_id', $response->headers->get('X-Request-Id'))->value('error_code'))
                ->toBe('stale_timestamp');
        }
    });

    it('is refused the second time the same nonce is used', function () {
        $nonce = (string) Str::uuid();

        storefrontCall($this->credential, $this->secret, 'connection', overrides: ['nonce' => $nonce])->assertOk();

        $replay = storefrontCall($this->credential, $this->secret, 'connection', overrides: ['nonce' => $nonce]);

        $replay->assertUnauthorized();

        expect(ApiLog::query()->where('request_id', $replay->headers->get('X-Request-Id'))->value('error_code'))
            ->toBe('replayed_nonce');
    });

    it('does not let a forged request burn a nonce the storefront is about to use', function () {
        $nonce = (string) Str::uuid();

        storefrontCall($this->credential, $this->secret, 'connection', overrides: [
            'nonce' => $nonce,
            'secret' => str_repeat('b', 64),
        ])->assertUnauthorized();

        storefrontCall($this->credential, $this->secret, 'connection', overrides: ['nonce' => $nonce])->assertOk();
    });

    it('is refused once the credential is revoked', function () {
        app(ManageWebsiteCredentials::class)->revoke($this->credential, $this->account->owner, 'Leaked in a screenshot.');

        storefrontCall($this->credential->refresh(), $this->secret, 'connection')->assertUnauthorized();
    });

    it('is refused for a website that is not being served', function () {
        $this->website->forceFill([
            'status' => WebsiteStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => 'Testing.',
        ])->save();

        storefrontCall($this->credential, $this->secret, 'connection')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'website_unavailable');
    });

    it('is refused a scope the credential was not issued', function () {
        [$credential, $secret] = storefrontCredential($this->website, [CredentialScope::InventoryRead]);

        storefrontCall($credential, $secret, 'products')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'insufficient_scope');
    });
});

describe('rotation', function () {
    it('keeps the previous secret working for its window, and not after', function () {
        $rotated = app(ManageWebsiteCredentials::class)->rotate($this->credential, $this->account->owner);

        storefrontCall($rotated->credential, $rotated->secret, 'connection')->assertOk();
        storefrontCall($rotated->credential, $this->secret, 'connection')->assertOk();

        $this->travel((int) config('website.api.rotation_grace_minutes') + 1)->minutes();

        storefrontCall($rotated->credential->refresh(), $this->secret, 'connection')->assertUnauthorized();
        storefrontCall($rotated->credential, $rotated->secret, 'connection')->assertOk();
    });
});

describe('rate limits', function () {
    it('stops a credential at its budget and says when to come back', function () {
        config(['website.api.rate_limits.other' => 2]);

        storefrontCall($this->credential, $this->secret, 'connection')->assertOk();
        storefrontCall($this->credential, $this->secret, 'connection')->assertOk();

        storefrontCall($this->credential, $this->secret, 'connection')
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('error.code', 'rate_limited');
    });

    it('counts per credential, so one storefront never spends another\'s budget', function () {
        config(['website.api.rate_limits.other' => 1]);

        $others = Website::factory()->forAccount(websiteTestAccount())->active()->create();
        [$otherCredential, $otherSecret] = storefrontCredential($others);

        storefrontCall($this->credential, $this->secret, 'connection')->assertOk();
        storefrontCall($this->credential, $this->secret, 'connection')->assertStatus(429);

        storefrontCall($otherCredential, $otherSecret, 'connection')->assertOk();
    });
});

describe('the log', function () {
    it('never keeps the signature or the secret', function () {
        storefrontCall($this->credential, $this->secret, 'connection', ['secret' => $this->secret]);

        $log = ApiLog::query()->latest('id')->firstOrFail();
        $stored = json_encode($log->request_summary);

        expect($stored)->not->toContain($this->secret)
            ->and(ApiLog::query()->whereRaw('request_summary::text ilike ?', ['%'.$this->secret.'%'])->exists())->toBeFalse();
    });

    it('cannot be edited', function () {
        storefrontCall($this->credential, $this->secret, 'connection');

        $log = ApiLog::query()->latest('id')->firstOrFail();

        expect(fn () => DB::table('api_logs')->where('id', $log->id)->update(['status' => 500]))
            ->toThrow(QueryException::class);
    });
});
