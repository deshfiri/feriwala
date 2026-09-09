<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Domain\Account\Actions\AcceptStaffInvitation;
use App\Domain\Account\Enums\Gender;
use App\Domain\Account\Models\AccountInvitation;
use App\Http\Responses\FailedPasswordResetLinkResponse;
use App\Http\Responses\LockoutResponse;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\PasskeyLoginResponse;
use App\Http\Responses\RegisterResponse;
use App\Http\Responses\TwoFactorLoginResponse;
use App\Http\Responses\VerifyEmailResponse;
use App\Support\Localization\Countries;
use App\Support\Security\LoginThrottle;
use App\Support\Security\PasswordPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Contracts\LockoutResponse as LockoutResponseContract;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(PasskeyLoginResponseContract::class, PasskeyLoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, TwoFactorLoginResponse::class);
        $this->app->singleton(LockoutResponseContract::class, LockoutResponse::class);

        /*
         * The reset form answers the same way whether or not the address has an
         * account (§31.3). Fortify's own response reports the broker's status,
         * which makes the form an enumeration oracle that needs no credentials.
         */
        $this->app->singleton(
            FailedPasswordResetLinkRequestResponseContract::class,
            FailedPasswordResetLinkResponse::class,
        );

        /*
         * Every Fortify action that touches the limiter — the throttle check,
         * both failure paths and the success path — asks the container for the
         * concrete class, so replacing it here replaces it for all of them
         * (§6). {@see LoginThrottle} adds the per-address limit Fortify's own
         * has no notion of.
         */
        $this->app->singleton(LoginRateLimiter::class, LoginThrottle::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        // Verification lands through the same resolver as login (D23): a fixed
        // `/dashboard` is what locked platform staff out at the front door.
        $this->app->singleton(
            VerifyEmailResponseContract::class,
            VerifyEmailResponse::class,
        );

        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
            'staffInvitation' => $this->staffInvitation($request),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => PasswordPolicy::hint(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn (Request $request) => Inertia::render('auth/register', [
            'staffInvitation' => $this->staffInvitation($request),

            /*
             * The same policy the validator applies, in the form a password
             * manager reads. Both of these screens declared the prop and neither
             * was given it, so the two flows where a password is *first* chosen
             * were the two with no guidance at all.
             */
            'passwordRules' => PasswordPolicy::hint(),

            /*
             * The pickers come from the same lists the validator checks against
             * (§5.2). A form offering options the rules refuse — or refusing
             * ones it offers — is the failure mode this avoids.
             */
            'countries' => app(Countries::class)->options(),
            'defaultCountry' => app(Countries::class)->default(),
            'genders' => Gender::options(),

            // Prefilled from a referral link so the code is not retyped, and so
            // a referrer who shared a link is actually credited (§25.1).
            'referralCode' => $request->string('ref')->toString() ?: null,
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            /*
             * Masked. The screen has to name the address so somebody who
             * mistyped it can see that they did, but it renders on a page a
             * shoulder-surfer can read and the full address is not needed to
             * recognise your own.
             */
            'email' => $this->maskEmail($request->user()?->email),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        /*
         * There is deliberately no `login` limiter here, and `fortify.limiters.login`
         * is null so Fortify uses its own pipeline instead (§6).
         *
         * The `throttle` middleware counts **requests**, and login is one of the
         * few endpoints where that is the wrong unit: a successful sign-in would
         * spend from the same budget as a guess, so somebody who signs in, signs
         * out and signs in again is treated as an attacker. {@see LoginThrottle}
         * counts failures only, which is what a limit on guessing means.
         */
        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }

    /**
     * The invitation a visitor arrived with, for the sign-in and register pages.
     *
     * Someone invited to work in an account usually has no login yet, so the
     * link lands them on register or sign-in first. Naming the business there
     * is what makes the detour make sense rather than look like a dead end.
     *
     * The token identifies the invitation; it authorises nothing. Acceptance
     * checks who is signed in ({@see AcceptStaffInvitation}).
     *
     * An address a person can recognise as theirs without it being readable
     * over their shoulder.
     *
     * The first character and the domain survive, which is enough to spot a
     * typo in your own address and not enough to be somebody else's.
     */
    private function maskEmail(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        $visible = mb_substr($local, 0, 1);
        $hidden = max(mb_strlen($local) - 1, 1);

        return $visible.str_repeat('•', min($hidden, 8)).'@'.$domain;
    }

    /**
     * @return array{token: string, account: string, role: string}|null
     */
    private function staffInvitation(Request $request): ?array
    {
        $token = $request->query('invitation');

        if (! is_string($token)) {
            return null;
        }

        $invitation = AccountInvitation::query()
            ->with('businessAccount')
            ->where('token', $token)
            ->live()
            ->first();

        if ($invitation === null) {
            return null;
        }

        return [
            'token' => $invitation->token,
            'account' => $invitation->businessAccount->name,
            'role' => $invitation->role->label(),
        ];
    }
}
