<?php

use App\Domain\Billing\Actions\ExpireUnpaidPayments;
use App\Domain\Billing\Actions\ReconcileGatewayPayments;
use App\Domain\Inventory\Actions\ReleaseExpiredReservations;
use App\Domain\Kyc\Actions\SweepKycDeadlines;
use App\Domain\Order\Actions\ExpireUnpaidWholesaleOrders;
use App\Domain\Package\Actions\SweepSubscriptionLifecycle;
use App\Domain\Wallet\Actions\SweepWalletBalances;
use App\Domain\Wallet\Actions\VerifyLedgerIntegrity;
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

/*
 * The §9 payment deadline: close checkouts nobody paid for.
 *
 * Hourly, because the deadline is configured in hours — a daily pass would make
 * "48 hours" mean anything up to 72.
 *
 * Same guards as the sweeps above (§41). This one gives coupon slots back, and
 * two servers releasing the same hold would hand out one more promotion than the
 * limit allows. It is idempotent on its own as well: the cancellation goes
 * through the status map under a row lock, so a second pass finds the payment
 * already cancelled and does nothing — and `Paid` has no move to `Cancelled` at
 * all, so money that arrived can never be swept away.
 *
 * Does nothing until an administrator sets `billing.payment_deadline_hours`.
 * Cancelling real checkouts on a window nobody chose is not a safe default.
 */
Schedule::call(fn () => app(ExpireUnpaidPayments::class)->handle())
    ->name('payment-deadline-sweep')
    ->hourly()
    ->timezone(config('app.timezone'))
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Close unpaid checkouts past their deadline (§9)');

/*
 * The §24.3 balance check: is every account still holding what it agreed to?
 *
 * Balances do not fall below a line by being written to — they fall below it
 * because a deadline arrived or a grace period ran out while nobody was looking.
 * So something has to come round and ask.
 *
 * Early, and before the reconciliation: an account that paid last night should
 * have its services back before anything else looks at it.
 *
 * `onOneServer` and `withoutOverlapping` are not optional here (§41). This pass
 * restricts accounts and sends messages; two application servers running it at
 * once would mean two texts for one shortfall. Idempotent on its own as well —
 * a live restriction can only exist once per stage, and a stamped grace deadline
 * is never moved — so a repeat pass finds the work done and writes nothing.
 */
Schedule::call(fn () => app(SweepWalletBalances::class)->handle())
    ->name('wallet-balance-check')
    ->dailyAt('02:30')
    // The account holder's day, not the server's: "your grace period ends on
    // the 30th" has to mean the 30th where they are.
    ->timezone(config('app.timezone'))
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Check every wallet against what its account must hold (§24.3)');

/*
 * The §28.1 wallet reconciliation: does every balance still add up?
 *
 * The ledger and the balance are written in one transaction, so they should
 * never diverge. "Should never" is not a control — this is. It re-derives every
 * wallet from its own entries and shouts when the two disagree, because the
 * failure mode of a financial system is rarely a crash; it is a number that has
 * been quietly wrong for a fortnight.
 *
 * Reads only, and repairs nothing: a sweep that silently corrected a balance
 * would destroy the evidence of whatever caused the drift, and §23.2 already has
 * a way to put things right that leaves a record.
 *
 * `onOneServer` because a second copy would double the alert, not the coverage.
 */
Schedule::call(fn () => app(VerifyLedgerIntegrity::class)->handle())
    ->name('ledger-integrity-check')
    ->dailyAt('03:00')
    ->timezone(config('app.timezone'))
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Re-derive every wallet from its ledger and alert on a mismatch (§28.1)');

/*
 * The §28.1 gateway reconciliation: does every provider agree with us?
 *
 * Notifications go missing. A payer closes the tab, an IPN is posted at a server
 * that was restarting, a webhook endpoint is misconfigured for an afternoon —
 * and a payment sits open while the money is sitting at the provider. Nothing
 * else in the system ever goes and asks.
 *
 * **Hourly**, unlike the daily sweeps above, because this one is about money
 * that has already left somebody's account. A day is a long time to hold a
 * confirmed payment open, and the pass is cheap: it asks only about payments
 * old enough to have finished, and only of providers that publish a status
 * lookup.
 *
 * Never downgrades anything. A settled payment is checked for a *mismatch* and
 * the disagreement is reported rather than applied — a stale provider answer
 * must not be able to un-pay something. A provider that cannot be reached leaves
 * every payment exactly as it was, including its checked timestamp, so an outage
 * is retried rather than recorded as a look.
 *
 * `onOneServer` because a second copy would double the provider traffic, not the
 * coverage. Both guards use the isolated Redis lock database (§41).
 */
Schedule::call(fn () => app(ReconcileGatewayPayments::class)->handle())
    ->name('gateway-reconciliation')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Ask each gateway what it thinks happened to our payments (§28.1)');

/*
 * The contract §6.1.2 expiry pass: give back stock held by reservations whose
 * window has run out.
 *
 * **Every minute**, because the shortest window is fifteen minutes: an hourly
 * pass would hold an unpaid online order's stock for up to seventy-five, and the
 * contract is explicit that expiry is the scheduler's job, not something that
 * happens lazily when somebody next reads the figure.
 *
 * `onOneServer` and `withoutOverlapping` for the same reasons as the sweeps
 * above (§41), and the pass is idempotent on its own as well: each reservation is
 * re-read under its own lock and expired only if it is still active and due, so
 * a second copy finds nothing left to do.
 */
Schedule::call(fn () => app(ReleaseExpiredReservations::class)->handle())
    ->name('stock-reservation-expiry')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Release stock reservations whose window has run out (§19.1)');

/*
 * ERP wholesale orders still waiting for payment (§14, §19.1, P4-10).
 *
 * Beside the reservation sweep and **every minute** for the same reason: an
 * unpaid order's stock is held against a fifteen-minute window. Confirms an order
 * whose payment settled without it, and cancels — giving the stock back — an
 * order whose payment failed, was cancelled or ran out of time.
 *
 * `onOneServer` and `withoutOverlapping` as above (§41). Idempotent on its own:
 * each order is re-read under its payment's settlement lock and its row lock.
 */
Schedule::call(fn () => app(ExpireUnpaidWholesaleOrders::class)->handle())
    ->name('wholesale-order-payment-sweep')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Confirm or cancel wholesale orders waiting for payment (§14, §19.1)');
