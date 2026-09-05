<?php

use App\Domain\Kyc\Actions\SweepKycDeadlines;
use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

/*
 * The §7.4 KYC deadline pass: warn what is due, act on what is overdue.
 *
 * `onOneServer` and `withoutOverlapping` are not optional here (§41). This sweep
 * restricts accounts and sends messages; running it twice concurrently on two
 * application servers would mean two texts and two audit entries for one event.
 * Both guards use the isolated Redis lock database, so a cache flush cannot drop
 * them.
 *
 * Does nothing until an administrator configures `kyc.deadline_days` — see
 * App\Domain\Kyc\KycDeadlines for why the default is "no deadline at all".
 */
Schedule::call(fn () => app(SweepKycDeadlines::class)->handle())
    // Named before onOneServer: a closure has no command string to key the
    // server lock on, and Laravel refuses rather than guessing.
    ->name('kyc-deadline-sweep')
    ->dailyAt('02:00')
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Warn and enforce KYC deadlines (§7.4)');
