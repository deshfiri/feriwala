<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Wallet\Actions\ExportWalletStatement;
use App\Domain\Wallet\Models\Wallet;
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
            ->orderByDesc('total_minor')
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
                        'debit' => $entry->debit_minor->jsonSerialize(),
                        'credit' => $entry->credit_minor->jsonSerialize(),
                        'balance_before' => $entry->balance_before_minor->jsonSerialize(),
                        'balance_after' => $entry->balance_after_minor->jsonSerialize(),

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
            'total' => $wallet->total_minor->jsonSerialize(),
            'usable' => $wallet->usableBalance()->jsonSerialize(),
            'reserved' => $wallet->reserved_minor->jsonSerialize(),
            'hold' => $wallet->hold_minor->jsonSerialize(),
            'meets_required_deposit' => $wallet->meetsRequiredDeposit(),
            'shortfall' => $wallet->shortfall()->jsonSerialize(),
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
