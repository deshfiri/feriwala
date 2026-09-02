<?php

use App\Domain\Account\VerificationCodes;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Hashing\BcryptHasher;

function verificationCodes(): VerificationCodes
{
    return new VerificationCodes(
        new Repository(new ArrayStore(true)),
        new BcryptHasher(['rounds' => 4]),
    );
}

const MOBILE = '+8801712345678';

describe('issuing', function () {
    it('returns a numeric code of the configured length', function () {
        $code = verificationCodes()->issue('mobile', MOBILE);

        expect($code)->toHaveLength(VerificationCodes::LENGTH)
            ->and($code)->toMatch('/^\d+$/');
    });

    it('zero-pads so the code is always the same length', function () {
        // A code rendered as "42" instead of "000042" looks broken and cannot
        // be typed into a fixed-length input.
        $codes = verificationCodes();

        foreach (range(1, 40) as $i) {
            $codes->forget('mobile', MOBILE);
            expect($codes->issue('mobile', MOBILE))->toHaveLength(6);
        }
    });

    it('issues different codes on reissue', function () {
        $codes = verificationCodes();

        $first = $codes->issue('mobile', MOBILE);
        $codes->forget('mobile', MOBILE);
        $second = $codes->issue('mobile', MOBILE);

        // Not a strict guarantee for a 6-digit code, but a repeat across two
        // draws would suggest the generator is not random at all.
        expect($first)->not->toBe($second);
    });
});

describe('verifying', function () {
    it('accepts the correct code', function () {
        $codes = verificationCodes();
        $code = $codes->issue('mobile', MOBILE);

        expect($codes->verify('mobile', MOBILE, $code))->toBeTrue();
    });

    it('rejects a wrong code', function () {
        $codes = verificationCodes();
        $codes->issue('mobile', MOBILE);

        expect($codes->verify('mobile', MOBILE, '000000'))->toBeFalse();
    });

    it('consumes the code so it cannot be replayed', function () {
        $codes = verificationCodes();
        $code = $codes->issue('mobile', MOBILE);

        expect($codes->verify('mobile', MOBILE, $code))->toBeTrue()
            ->and($codes->verify('mobile', MOBILE, $code))->toBeFalse();
    });

    it('rejects a code that was never issued', function () {
        expect(verificationCodes()->verify('mobile', MOBILE, '123456'))->toBeFalse();
    });

    it('does not distinguish an unissued code from a wrong one', function () {
        // Both return false, so a caller cannot learn whether a number is
        // currently mid-verification.
        $codes = verificationCodes();

        $neverIssued = $codes->verify('mobile', '+8801999999999', '123456');
        $codes->issue('mobile', MOBILE);
        $wrong = $codes->verify('mobile', MOBILE, '000000');

        expect($neverIssued)->toBe($wrong);
    });

    it('keeps codes separate per identifier', function () {
        $codes = verificationCodes();

        $mine = $codes->issue('mobile', MOBILE);
        $codes->issue('mobile', '+8801888888888');

        expect($codes->verify('mobile', '+8801888888888', $mine))->toBeFalse();
    });

    it('keeps codes separate per purpose', function () {
        $codes = verificationCodes();

        $forMobile = $codes->issue('mobile', MOBILE);

        expect($codes->verify('withdrawal', MOBILE, $forMobile))->toBeFalse();
    });
});

describe('brute force protection', function () {
    it('destroys the code once the attempt budget is spent', function () {
        // A six-digit code is only a million possibilities — nothing to a
        // script. The budget is what makes it safe.
        $codes = verificationCodes();
        $code = $codes->issue('mobile', MOBILE);

        foreach (range(1, VerificationCodes::MAX_ATTEMPTS) as $attempt) {
            expect($codes->verify('mobile', MOBILE, '000000'))->toBeFalse();
        }

        // Even the correct code no longer works.
        expect($codes->verify('mobile', MOBILE, $code))->toBeFalse();
    });

    it('counts attempts', function () {
        $codes = verificationCodes();
        $codes->issue('mobile', MOBILE);

        $codes->verify('mobile', MOBILE, '000000');
        $codes->verify('mobile', MOBILE, '111111');

        expect($codes->attemptsUsed('mobile', MOBILE))->toBe(2);
    });

    it('still accepts the right code within the budget', function () {
        $codes = verificationCodes();
        $code = $codes->issue('mobile', MOBILE);

        $codes->verify('mobile', MOBILE, '000000');
        $codes->verify('mobile', MOBILE, '111111');

        expect($codes->verify('mobile', MOBILE, $code))->toBeTrue();
    });
});

describe('resend cooldown', function () {
    it('blocks an immediate resend', function () {
        $codes = verificationCodes();
        $codes->issue('mobile', MOBILE);

        expect($codes->canIssue('mobile', MOBILE))->toBeFalse()
            ->and($codes->secondsUntilResend('mobile', MOBILE))->toBeGreaterThan(0);
    });

    it('allows the first send', function () {
        expect(verificationCodes()->canIssue('mobile', MOBILE))->toBeTrue();
    });

    it('lifts the cooldown once the code is used', function () {
        $codes = verificationCodes();
        $code = $codes->issue('mobile', MOBILE);

        $codes->verify('mobile', MOBILE, $code);

        expect($codes->canIssue('mobile', MOBILE))->toBeTrue();
    });
});

it('never stores the code in readable form', function () {
    $store = new ArrayStore(true);
    $codes = new VerificationCodes(new Repository($store), new BcryptHasher(['rounds' => 4]));

    $code = $codes->issue('mobile', MOBILE);

    // Walk everything the cache holds; the plain code must appear nowhere.
    $serialised = json_encode($store->all());

    expect($serialised)->not->toContain($code);
});

it('does not put the phone number in the cache key', function () {
    $store = new ArrayStore(true);
    $codes = new VerificationCodes(new Repository($store), new BcryptHasher(['rounds' => 4]));

    $codes->issue('mobile', MOBILE);

    // A keyspace listing must not become a list of phone numbers.
    expect(implode(' ', array_keys($store->all())))->not->toContain(MOBILE);
});
