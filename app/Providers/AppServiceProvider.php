<?php

namespace App\Providers;

use App\Domain\Billing\Models\RefundRequest;
use App\Domain\Order\Actions\SyncReturnRefund;
use App\Domain\Settings\SettingsRepository;
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
        /*
         * One settings reader per request.
         *
         * It holds the whole table for the life of the request so ten reads
         * cost one Redis call — which only works if there is one of it.
         * Resolved fresh each time, two instances kept divergent caches, and a
         * write through one was invisible to the other for the rest of the
         * request: a gateway credential saved and then immediately read back as
         * "not configured", a switch turned off that the same request still
         * reported as on.
         */
        $this->app->singleton(SettingsRepository::class);
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

        /*
         * A refund for returned goods tells its return what happened to the
         * money (P6-12). Hung off the refund row itself because a decision, a
         * gateway answer and the pending-refund sweep all move it, and one hook
         * here reaches all three — and billing stays unaware that returns exist.
         */
        RefundRequest::updated(function (RefundRequest $refund): void {
            if ($refund->wasChanged('status')) {
                app(SyncReturnRefund::class)->handle($refund);
            }
        });
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
