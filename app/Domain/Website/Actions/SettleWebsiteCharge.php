<?php

namespace App\Domain\Website\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\DepositGuard;
use App\Domain\Wallet\Exceptions\WalletOperationRefused;
use App\Domain\Wallet\WalletService;
use App\Domain\Website\Enums\WebsiteChargeStatus;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Enums\WebsiteStatusChangeSource;
use App\Domain\Website\Enums\WebsiteStatusReason;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCharge;
use App\Models\User;
use App\Support\Concurrency\DistributedLock;
use App\Support\Concurrency\Exceptions\LockTimeout;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Pay one website charge out of the account's wallet (§16.2, §24, P5-10).
 *
 * The wallet is the only way these are paid. Feriwala is merchant of record for
 * everything a partner sells (D12) and already holds their balance, so a
 * separate gateway checkout for a hosting charge would be a second money path
 * to reconcile for no gain — a partner tops the wallet up (P2-19) and the
 * charge comes out of it.
 *
 * **Paid exactly once.** The debit carries the charge's own idempotency key, so
 * a double-clicked button, a retried job and a replayed request all produce the
 * one wallet transaction; the unique key on `wallet_transaction_id` means that
 * transaction can never settle a second charge. A charge that is already paid
 * is handed back as it is rather than refused, because pressing pay twice is
 * not an error the person can do anything about.
 *
 * A wallet that cannot cover it moves the website to **deposit pending** and
 * says so. That is §16.4's state for exactly this, and it is what the wallet
 * top-up screen exists to resolve.
 */
class SettleWebsiteCharge
{
    /** How long one settlement may hold its charge. */
    protected const LOCK_TTL = 15;

    protected const LOCK_WAIT = 5;

    public function __construct(
        protected WalletService $wallet,
        protected DepositGuard $guard,
        protected MoveWebsiteStatus $move,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
        protected DistributedLock $lock,
    ) {}

    /**
     * @throws WebsiteRefused
     * @throws LockTimeout
     */
    public function handle(WebsiteCharge $charge, ?User $actor = null): WebsiteCharge
    {
        /** @var WebsiteCharge $settled */
        $settled = $this->lock->run(
            key: 'website:charge:'.$charge->id,
            callback: fn () => $this->settle($charge, $actor),
            ttlSeconds: self::LOCK_TTL,
            waitSeconds: self::LOCK_WAIT,
        );

        return $settled;
    }

    /**
     * @throws WebsiteRefused
     */
    protected function settle(WebsiteCharge $charge, ?User $actor): WebsiteCharge
    {
        /** @var WebsiteCharge $current */
        $current = WebsiteCharge::query()->with('website.businessAccount')->findOrFail($charge->id);

        if ($current->status === WebsiteChargeStatus::Paid) {
            return $current;
        }

        if ($current->status->isClosed()) {
            throw WebsiteRefused::chargeNotOutstanding();
        }

        $website = $current->website;
        $wallet = $this->guard->walletFor($website->businessAccount);

        if ($wallet === null || $wallet->usableBalance()->lessThan($current->amount)) {
            $this->askForADeposit($website);

            throw WebsiteRefused::insufficientBalance();
        }

        try {
            $paid = $this->database->transaction(function () use ($current, $website, $wallet, $actor) {
                $transaction = $this->wallet->debit(
                    $wallet,
                    $current->type->ledgerType(),
                    $current->amount,
                    new PostingContext(
                        source: 'website',
                        description: $current->type->label().' — '.$website->name,

                        // The charge's own identity, so every route into paying
                        // it — button, retry, queued job — posts once.
                        idempotencyKey: $current->settlementKey(),
                        actorId: $actor?->id,
                    ),
                );

                $current->forceFill([
                    'status' => WebsiteChargeStatus::Paid,
                    'wallet_transaction_id' => $transaction->id,
                    'paid_at' => CarbonImmutable::now(),
                ])->save();

                return $current;
            });
        } catch (WalletOperationRefused) {
            // The balance went between the check above and the lock inside the
            // wallet. The wallet is the authority; this only reports it.
            $this->askForADeposit($website);

            throw WebsiteRefused::insufficientBalance();
        }

        $this->audit->handle(new AuditEntry(
            action: 'website.charge_paid',
            actorId: $actor?->id,
            actorType: $actor === null ? 'system' : 'user',
            auditableType: WebsiteCharge::class,
            auditableId: $paid->id,
            after: [
                'type' => $paid->type->value,
                'amount' => $paid->amount->jsonSerialize(),
                'currency' => $paid->currency_code,
                'wallet_transaction_id' => $paid->wallet_transaction_id,
            ],
            accountId: $paid->business_account_id,
            module: 'website',
        ));

        $this->openTheBuild($website, $actor);

        return $paid;
    }

    /**
     * Nothing is owed any more, so the build can start (§16.4).
     *
     * Only from the two states that are waiting for money. A live website with
     * a paid maintenance charge is not moved anywhere.
     */
    protected function openTheBuild(Website $website, ?User $actor): void
    {
        if (! in_array($website->status, [WebsiteStatus::SetupPending, WebsiteStatus::DepositPending], true)) {
            return;
        }

        if (WebsiteCharge::query()->where('website_id', $website->id)->outstanding()->exists()) {
            return;
        }

        $this->move->handle(
            $website,
            WebsiteStatus::Development,
            WebsiteStatusChangeSource::Billing,
            new StatusChange(
                actorId: $actor?->id,
                reason: WebsiteStatusReason::ChargesSettled->value,
                publicNote: WebsiteStatusReason::ChargesSettled->note(),
            ),
        );
    }

    /**
     * Say what the website is waiting for, where that is the wallet.
     */
    protected function askForADeposit(Website $website): void
    {
        if ($website->status !== WebsiteStatus::SetupPending) {
            return;
        }

        $this->move->handle(
            $website,
            WebsiteStatus::DepositPending,
            WebsiteStatusChangeSource::Billing,
            new StatusChange(
                reason: WebsiteStatusReason::InsufficientBalance->value,
                publicNote: WebsiteStatusReason::InsufficientBalance->note(),
            ),
        );
    }
}
