<?php

use App\Domain\Kyc\Actions\SweepKycDeadlines;
use App\Domain\Package\Actions\SweepSubscriptionLifecycle;
use Illuminate\Support\Facades\Schedule;

/*
 * Expired staff invitations are not pruned, deliberately.
 *
 * The starter kit deleted them nightly. An expired invitation stops being live
 * the moment its window closes — it occupies no staff seat and cannot be
 * accepted — so deleting it buys nothing and destroys the answer to "who did we
 * invite, and what happened to it", which is the sort of question that only
 * gets asked once the record is gone.
 */

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
    // 02:00 where the account holders are, not where the server is. Without
    // this a host in another region runs the sweep at a different local hour
    // and "overdue today" means a different day to the person it affects.
    ->timezone(config('app.timezone'))
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Warn and enforce KYC deadlines (§7.4)');

/*
 * The §8.4 subscription clock: renewal due, grace period, expiry.
 *
 * Same guards and for the same reason (§41). This pass sends messages and takes
 * package features away; two application servers running it at once would mean
 * two notifications for one event. `onOneServer` and `withoutOverlapping` both
 * use the isolated Redis lock database, so a cache flush cannot drop them.
 *
 * Idempotent on its own as well, not only by the lock: every move is made under
 * a row lock and only from a state that permits it, so a repeat pass finds the
 * term already moved and does nothing.
 *
 * Daily and early, before the KYC sweep, because a term expiring today should be
 * expired before anything else reasons about what the account is entitled to.
 */
Schedule::call(fn () => app(SweepSubscriptionLifecycle::class)->handle())
    ->name('subscription-lifecycle-sweep')
    ->dailyAt('01:30')
    // The account holder's day, not the server's: "expires on the 30th" has to
    // mean the 30th where they are.
    ->timezone(config('app.timezone'))
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Move subscription terms through renewal, grace and expiry (§8.4)');
