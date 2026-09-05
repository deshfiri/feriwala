<?php

use App\Domain\Kyc\Actions\SweepKycDeadlines;
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
