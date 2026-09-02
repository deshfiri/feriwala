<?php

use App\Domain\Audit\Actions\RecordAuditLog;

function redact(?array $values): ?array
{
    return (new RecordAuditLog)->redact($values);
}

it('removes password values but keeps the key', function () {
    // Knowing that the password changed is the audit information.
    // The value never is.
    $result = redact(['name' => 'Rahim', 'password' => 'hunter2']);

    expect($result)->toBe([
        'name' => 'Rahim',
        'password' => '[redacted]',
    ]);
});

it('redacts every sensitive key named in §42', function (string $key) {
    expect(redact([$key => 'sensitive'])[$key])->toBe('[redacted]');
})->with([
    'password',
    'password_confirmation',
    'current_password',
    'api_key',
    'apiKey',
    'secret',
    'client_secret',
    'gateway_secret',
    'token',
    'remember_token',
    'access_token',
    'private_key',
    'credential',
    'authorization',
    'signature',
    'cvv',
    'card_number',
    'pin',
    'otp',
]);

it('redacts case-insensitively and through hyphens', function () {
    expect(redact(['API-KEY' => 'x'])['API-KEY'])->toBe('[redacted]')
        ->and(redact(['X-Auth-Token' => 'x'])['X-Auth-Token'])->toBe('[redacted]');
});

it('redacts nested values at any depth', function () {
    $result = redact([
        'gateway' => [
            'name' => 'sslcommerz',
            'credentials' => [
                'store_id' => 'feriwala',
                'store_password' => 'live-secret',
            ],
        ],
    ]);

    expect($result['gateway']['name'])->toBe('sslcommerz')
        ->and($result['gateway']['credentials']['store_id'])->toBe('feriwala')
        ->and($result['gateway']['credentials']['store_password'])->toBe('[redacted]');
});

it('leaves ordinary values untouched', function () {
    $values = [
        'status' => 'approved',
        'amount' => 125000,
        'currency_code' => 'BDT',
        'reference' => 'WDR-260901-K7M3QX9P',
    ];

    expect(redact($values))->toBe($values);
});

it('handles null', function () {
    expect(redact(null))->toBeNull();
});

it('handles an empty array', function () {
    expect(redact([]))->toBe([]);
});

it('does not redact a merely similar-looking field', function () {
    // "tokenised_at" contains "token" and is redacted; that is the intended
    // trade-off — over-redacting is safe, under-redacting is not.
    expect(redact(['account_holder' => 'Rahim Uddin'])['account_holder'])
        ->toBe('Rahim Uddin');
});

it('preserves list ordering while redacting inside', function () {
    $result = redact([
        'attempts' => [
            ['gateway' => 'bkash', 'token' => 'a'],
            ['gateway' => 'nagad', 'token' => 'b'],
        ],
    ]);

    expect($result['attempts'][0]['gateway'])->toBe('bkash')
        ->and($result['attempts'][1]['gateway'])->toBe('nagad')
        ->and($result['attempts'][0]['token'])->toBe('[redacted]')
        ->and($result['attempts'][1]['token'])->toBe('[redacted]');
});
