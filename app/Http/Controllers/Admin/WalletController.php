<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Wallet\Actions\ExportWalletStatement;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletDepositObligation;
use App\Domain\Wallet\Models\WalletRestriction;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\Policies\WalletPolicy;
use App\Domain\Wallet\Queries\WalletStatement;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Looking up an account's wallet, and reading its ledger (§23, §32).
 *
 * The administrator's half of the same three screens the account holder has, and
 * deliberately the same figures: the balances come from
 * {@see Wallet::toBalances()} and the rows from {@see WalletStatement}, so the
 * two sides of a support call cannot be looking at different money.
 *
 * What differs is what may be seen and what may be done. An internal note is
 * shown only to somebody holding `ledger.view_sensitive_data`, and the controls
 * for moving money by hand live behind their own permissions again — see
 * {@see WalletAdjustmentController}.
 */
class WalletController extends Controller
{
    /** How many wallets the lookup shows before paging. */
    public const PER_PAGE = 25;

    public function __construct(
        protected WalletStatement $statement,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless(WalletPolicy::canViewAny($actor), 403);

        $search = $request->string('search')->toString();

        $wallets = Wallet::query()
            ->with('businessAccount:id,name,public_id,status')
            ->when($search !== '', fn (Builder $query) => $query
                ->whereHas('businessAccount', fn (Builder $account) => $account
                    ->where('name', 'ilike', "%{$search}%")))
            ->orderByDesc('total')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Wallet $wallet) => $this->summary($wallet));

        return Inertia::render('admin/wallets/index', [
            'wallets' => $wallets,
        ]);
    }

    public function show(Request $request, string $wallet): Response
    {
        $actor = $this->actor($request);
        $record = $this->wallet($wallet);

        abort_unless(WalletPolicy::canViewAny($actor), 403);

        $filters = $this->statement->acceptedFilters($request->all());
        $sensitive = WalletPolicy::canViewSensitive($actor);

        return Inertia::render('admin/wallets/show', [
            'wallet' => $this->summary($record),
            'balances' => $record->toBalances(),
            'transactions' => $this->statement->paginate($record, $filters)
                ->through(fn (WalletTransaction $row) => $this->statement->row($row, $sensitive)),
            'filters' => $filters,
            'options' => $this->statement->options(),

            /*
             * The §24 picture beside the money: what this account is held to,
             * where that came from, and what has been taken away because of it.
             * An administrator answering "why is this account restricted" should
             * not have to open three screens.
             */
            'obligation' => $this->obligationFor($record),
            'restrictions' => $record->restrictions()
                ->standing()
                ->get()
                ->map(fn (WalletRestriction $restriction) => [
                    'stage' => $restriction->stage->value,
                    'stage_label' => $restriction->stage->label(),
                    'stage_tone' => $restriction->stage->tone(),
                    'cause' => $restriction->cause,
                    'started_at' => $restriction->started_at->toIso8601String(),
                    'previous_account_status' => $restriction->previous_account_status,
                ])
                ->all(),

            'can' => [
                'adjust' => WalletPolicy::canAdjust($actor),
                'reverse' => WalletPolicy::canReverse($actor),
                'export' => WalletPolicy::canExport($actor, $record),
                'view_sensitive' => $sensitive,
            ],
        ]);
    }

    public function transaction(Request $request, string $wallet, string $transaction): Response
    {
        $actor = $this->actor($request);
        $record = $this->wallet($wallet);

        abort_unless(WalletPolicy::canViewAny($actor), 403);

        $movement = WalletTransaction::query()
            ->where('wallet_id', $record->id)
            ->where('public_id', $transaction)
            ->with(['ledgerEntries.corrects:id,reference,public_id', 'payment:id,reference,public_id'])
            ->firstOrFail();

        $sensitive = WalletPolicy::canViewSensitive($actor);

        return Inertia::render('admin/wallets/transaction', [
            'wallet' => $this->summary($record),
            'transaction' => [
                ...$this->statement->row($movement, $sensitive),
                'payment_reference' => $movement->payment?->reference,
                'currency' => $movement->currency_code,
                'idempotency_key' => $movement->idempotency_key,
                'entries' => $movement->ledgerEntries
                    ->map(fn ($entry) => [
                        'id' => $entry->public_id,
                        'reference' => $entry->reference,
                        'debit' => $entry->debit->jsonSerialize(),
                        'credit' => $entry->credit->jsonSerialize(),
                        'balance_before' => $entry->balance_before->jsonSerialize(),
                        'balance_after' => $entry->balance_after->jsonSerialize(),

                        // What this entry puts right, where it is a correction
                        // (§23.2). The link is the point of a correction: the
                        // original stays, and this says which one it answers.
                        'corrects' => $entry->corrects?->reference,
                        'at' => $entry->created_at->toIso8601String(),
                    ])
                    ->all(),
            ],
            'can' => [
                'reverse' => WalletPolicy::canReverse($actor),
                'view_sensitive' => $sensitive,
            ],
        ]);
    }

    public function export(Request $request, string $wallet, ExportWalletStatement $export): StreamedResponse
    {
        $actor = $this->actor($request);
        $record = $this->wallet($wallet);

        // A narrower permission than reading the screen: this is a copy of
        // another business's finances leaving the system.
        abort_unless(
            WalletPolicy::canViewAny($actor) && WalletPolicy::canExport($actor, $record),
            403,
        );

        return $export->handle($record, $this->statement->acceptedFilters($request->all()));
    }

    /**
     * What this account is currently held to, and where it came from (§24.1).
     *
     * The **captured** obligation, not the rule in force this morning. An
     * administrator looking at a restricted account needs to see the figures it
     * was actually restricted against.
     *
     * @return array<string, mixed>|null
     */
    protected function obligationFor(Wallet $wallet): ?array
    {
        /** @var WalletDepositObligation|null $obligation */
        $obligation = WalletDepositObligation::query()
            ->with('rule:id,public_id,scope,scope_id')
            ->where('wallet_id', $wallet->id)
            ->orderByDesc('id')
            ->first();

        if ($obligation === null) {
            return null;
        }

        return [
            'required_deposit' => $obligation->required_deposit->jsonSerialize(),
            'minimum_balance' => $obligation->minimum_balance->jsonSerialize(),
            'required_top_up' => $obligation->required_top_up->jsonSerialize(),
            'low_threshold' => $obligation->low_balance_threshold?->jsonSerialize(),
            'critical_threshold' => $obligation->critical_balance_threshold?->jsonSerialize(),
            'grace_period_days' => $obligation->grace_period_days,
            'refundability' => $obligation->refundability->value,
            'refundability_label' => $obligation->refundability->label(),
            'refundable_percent' => $obligation->refundable_percent,
            'reserved_until_cancellation' => $obligation->reserved_until_cancellation,
            'deposit_usable_for_charges' => $obligation->deposit_usable_for_charges,
            'withdrawable_after_liabilities' => $obligation->withdrawable_after_liabilities,
            'source' => $obligation->source,
            'captured_at' => $obligation->captured_at->toIso8601String(),
            'deposit_due_at' => $obligation->deposit_due_at?->toIso8601String(),

            // Which rule it came from, so "why this figure" has an answer.
            'rule_scope' => $obligation->rule?->scope->label(),
            'rule_id' => $obligation->rule?->public_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(Wallet $wallet): array
    {
        return [
            'id' => $wallet->public_id,
            'account' => $wallet->businessAccount?->name,
            'account_id' => $wallet->businessAccount?->public_id,
            'account_status' => $wallet->businessAccount?->status->value,
            'currency' => $wallet->currency_code,
            'total' => $wallet->total->jsonSerialize(),
            'usable' => $wallet->usableBalance()->jsonSerialize(),
            'reserved' => $wallet->reserved->jsonSerialize(),
            'hold' => $wallet->hold->jsonSerialize(),
            'meets_required_deposit' => $wallet->meetsRequiredDeposit(),
            'meets_obligation' => $wallet->meetsObligation(),
            'shortfall' => $wallet->shortfall()->jsonSerialize(),
            'obligation_shortfall' => $wallet->obligationShortfall()->jsonSerialize(),

            // §24.3's state, carried with its label so a list never has to
            // express it in colour alone (§33.9).
            'state' => $wallet->balance_state->value,
            'state_label' => $wallet->balance_state->label(),
            'state_tone' => $wallet->balance_state->tone(),
            'grace_ends_at' => $wallet->grace_ends_at?->toIso8601String(),
        ];
    }

    protected function wallet(string $publicId): Wallet
    {
        /** @var Wallet $wallet */
        $wallet = Wallet::query()
            ->with('businessAccount:id,name,public_id,status')
            ->where('public_id', $publicId)
            ->firstOrFail();

        return $wallet;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
