<?php

use App\Domain\Account\Actions\ChangeLocale;
use App\Domain\Account\Data\LocaleChange;
use App\Http\Middleware\SetLocale;
use App\Support\Localization\Locale;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

function localeTestSession(): Store
{
    return new Store('feriwala_test', new ArraySessionHandler(120));
}

it('records the choice in the session', function () {
    $store = localeTestSession();

    (new ChangeLocale($store))->handle(
        new LocaleChange(Locale::Bangla, user: null),
    );

    expect($store->get(SetLocale::SESSION_KEY))->toBe('bn');
});

it('returns the locale it applied', function () {
    $applied = (new ChangeLocale(localeTestSession()))->handle(
        new LocaleChange(Locale::Bangla, user: null),
    );

    expect($applied)->toBe(Locale::Bangla);
});

it('works for a guest without touching an account', function () {
    $change = new LocaleChange(Locale::English, user: null);

    expect($change->isForSignedInUser())->toBeFalse();

    // A guest still gets their choice honoured for the visit.
    $store = localeTestSession();
    (new ChangeLocale($store))->handle($change);

    expect($store->get(SetLocale::SESSION_KEY))->toBe('en');
});

it('degrades to session-only when the user is not an Eloquent model', function () {
    $store = localeTestSession();

    // Fortify allows non-Eloquent authenticatables; the action must not assume.
    $user = new class implements Authenticatable
    {
        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return 1;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return 'remember_token';
        }
    };

    (new ChangeLocale($store))->handle(new LocaleChange(Locale::Bangla, $user));

    expect($store->get(SetLocale::SESSION_KEY))->toBe('bn');
});
