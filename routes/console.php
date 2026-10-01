<?php

use App\Domain\Billing\Actions\ExpireUnpaidPayments;
use App\Domain\Billing\Actions\ReconcileGatewayPayments;
use App\Domain\Billing\Actions\SettleRefund;
use App\Domain\Inventory\Actions\ReleaseExpiredReservations;
use App\Domain\Kyc\Actions\SweepKycDeadlines;
use App\Domain\Order\Actions\ExpireUnconfirmedCodOrders;
use App\Domain\Order\Actions\ExpireUnpaidOrders;
use App\Domain\Order\Actions\RetryCodConfirmationCodes;
use App\Domain\Package\Actions\SweepSubscriptionLifecycle;
use App\Domain\Referral\Actions\ReleaseDueReferralCommissions;
use App\Domain\Supplier\Actions\ExpireOverdueFulfilmentCommitments;
use App\Domain\Wallet\Actions\SweepWalletBalances;
use App\Domain\Wallet\Actions\VerifyLedgerIntegrity;
use App\Domain\Website\Actions\DispatchDueWebhookRetries;
use App\Domain\Website\Actions\PruneStorefrontRequests;
use App\Domain\Website\Actions\SweepWebsiteLifecycle;
use App\Domain\Website\Actions\SyncWebsiteCatalogue;
use App\Domain\Website\Actions\SyncWebsiteInventory;
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
Schedule::call(fn () => app(ExpireUnpaidOrders::class)->handle())
    ->name('wholesale-order-payment-sweep')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Confirm or cancel wholesale orders waiting for payment (§14, §19.1)');

/*
 * Cash-on-delivery orders nobody confirmed (contract §6.1.2, P6-10).
 *
 * Beside the sweep above rather than inside it: that one follows payments,
 * this one follows confirmations. The window belongs to the stock the order is
 * holding, so closing it gives those units back — once, under the same locks
 * a confirmation arriving at that instant takes, so the order ends in one
 * state whichever of them is second.
 */
Schedule::call(fn () => app(ExpireUnconfirmedCodOrders::class)->handle())
    ->name('cod-confirmation-sweep')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Close cash-on-delivery orders nobody confirmed (P6-10)');

/*
 * Supplier fulfilment commitments nobody confirmed in time (Advanced Order
 * Management batch, Commit 2). A Supplier confirmation deadline is measured
 * in hours, not minutes, so an hourly pass is frequent enough; the line
 * returns to staff review without corrupting the order, payable or
 * inventory state.
 */
Schedule::call(fn () => app(ExpireOverdueFulfilmentCommitments::class)->handle())
    ->name('supplier-fulfilment-confirmation-sweep')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Cancel Supplier fulfilment commitments past their confirmation deadline');

/*
 * Confirmation codes the SMS provider did not take (§6.2, P6-10). Only those:
 * a code that reached the provider is never resent unasked. Each retry is an
 * ordinary send, held to the resend cooldown and the per-order ceiling.
 */
Schedule::call(fn () => app(RetryCodConfirmationCodes::class)->handle())
    ->name('cod-confirmation-code-retry')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Resend cash-on-delivery codes the SMS provider did not take (P6-10)');

/*
 * Refunds a gateway accepted and has not yet confirmed (§26.3, P6-12). Asks
 * the provider what became of each and moves it forward only on an answer;
 * an unreachable provider leaves it exactly where it was. A refund that
 * failed outright is never re-sent from here: sending money is a person's
 * decision, behind a confirmed password, and stays one.
 */
Schedule::call(function (): void {
    $settle = app(SettleRefund::class);

    foreach ($settle->pending() as $refund) {
        $settle->handle($refund);
    }
})
    ->name('refund-settlement-sweep')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Ask gateways what became of refunds they accepted (§26.3)');

/*
 * The §16.4 website clock: grace, expiry, low balance, renewals (P5-11, P5-15).
 *
 * Nothing in §16.4 happens because somebody clicked something — a package
 * lapses, a balance falls below what was agreed, a domain reaches its last
 * month. Daily, and **after** the subscription sweep at 01:30: a term that
 * expired overnight should already have expired before this asks whether the
 * package still entitles a website.
 *
 * `onOneServer` and `withoutOverlapping` are not optional (§41). This pass
 * takes storefronts down and sends renewal warnings; two application servers
 * running it at once would mean two messages for one expiry. Idempotent on its
 * own as well: every move goes through the status map under a row lock, and the
 * reminder stage stored on each service is what stops a second message.
 */
Schedule::call(fn () => app(SweepWebsiteLifecycle::class)->handle())
    ->name('website-lifecycle-sweep')
    ->dailyAt('02:15')
    // The partner's day, not the server's: "your domain expires on the 30th"
    // has to mean the 30th where they are.
    ->timezone(config('app.timezone'))
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Move websites through grace, expiry, renewal and low balance (§16.4, §24.3)');

/*
 * Webhook retries that have come due (contract §7.3, P5-26).
 *
 * Every minute, because the first retry is ten seconds after a failure and the
 * schedule lives in the delivery record rather than in delayed queue jobs — a
 * worker restarting mid-backoff loses nothing. Idempotent: each attempt re-reads
 * its delivery under a row lock and sends nothing that is already finished.
 */
Schedule::call(fn () => app(DispatchDueWebhookRetries::class)->handle())
    ->name('website-webhook-retries')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Queue webhook deliveries whose next attempt is due (contract §7.3)');

/*
 * Near-real-time stock for storefronts (§17.2, P5-22, P5-25).
 *
 * Every five minutes: stock moves for reasons no website action announces, so
 * this compares each published product's availability with what its storefront
 * was last told and sends only the change.
 */
Schedule::call(fn () => app(SyncWebsiteInventory::class)->handle())
    ->name('website-inventory-sync')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Tell storefronts about stock that changed (contract §7.1)');

/*
 * Scheduled catalogue reconciliation (§17.2, P5-22, P5-25).
 *
 * Every fifteen minutes: catches what no partner action announced — Feriwala
 * changing a product centrally, a selection whose last event never went out.
 */
Schedule::call(fn () => app(SyncWebsiteCatalogue::class)->sweep())
    ->name('website-catalogue-sync')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Re-announce storefront products that are out of date (§17.2)');

/*
 * The referral commission outbox (D24, P7-43). Pays commissions whose holding
 * period has passed and retries reversals a wallet could not carry. Each row
 * is re-read under a lock and posted with its own idempotency key, so this
 * running beside the post-activation job pays nothing twice.
 */
/*
 * Answers to storefront writes, kept well past the contract's 24-hour replay
 * window and then forgotten (P5-21). The orders they created are untouched.
 */
Schedule::call(fn () => app(PruneStorefrontRequests::class)->handle())
    ->name('storefront-request-prune')
    ->dailyAt('03:40')
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Forget storefront write answers nobody may replay (P5-21)');

Schedule::call(fn () => app(ReleaseDueReferralCommissions::class)->handle())
    ->name('referral-commission-release')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping()
    ->description('Pay due referral commissions and retry owed reversals (D24)');
