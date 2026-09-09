<?php

namespace App\Providers;

use App\Listeners\RecordEmailVerification;
use App\Listeners\SecureAccountAfterPasswordReset;
use App\Support\Security\PasswordPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Registered explicitly rather than left to discovery: an audit entry
        // that stops being written because a convention changed is one nobody
        // notices is missing.
        Event::listen(Verified::class, RecordEmailVerification::class);

        /*
         * A reset changes the password and, on its own, changes nothing about
         * a session that is already open — which is exactly the situation a
         * reset is usually being done about (§6, P1-19).
         */
        Event::listen(PasswordReset::class, SecureAccountAfterPasswordReset::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        /*
         * §6's strong-password rule, in every environment.
         *
         * It was production-only, and returning null there means Laravel falls
         * back to eight characters and nothing else — so development, staging
         * and the entire test suite ran a policy nobody had chosen, and the real
         * one was never exercised anywhere it could be observed.
         */
        Password::defaults(fn (): Password => PasswordPolicy::rules());
    }
}
