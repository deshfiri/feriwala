<?php

namespace App\Domain\Wallet\Queries;

use App\Domain\Wallet\Enums\LedgerDirection;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\WalletTransactionStatus;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use Illuminate\Contracts\Database\Eloquent\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;

/**
 * One wallet's history, filtered, in exactly one shape (§33.7, P2-8).
 *
 * The account's own statement, the administrator's view of the same wallet, and
 * the exported file are all this query. Written once for the same reason the
 * derived balances live in one place: two descriptions of the same money
 * eventually disagree, and nothing afterwards says which was right.
 *
 * Built on the **transaction** rather than the ledger entry, because a statement
 * that listed only entries would be missing the reasons the spendable balance is
 * lower than the total. A reservation moves no value and writes no entry, but it
 * is why five thousand taka cannot be spent, and a statement that cannot explain
 * that is a statement somebody rings up about. Each row carries the entry when
 * value did move, so the running balance is there for the rows that have one.
 *
 * `internal_note` is never selected unless the caller says the reader may see it
 * (§23.2). The default is that they may not.
 */
class WalletStatement
{
    public const PER_PAGE = 20;

    /**
     * A filtered page of the statement, newest first.
     *
     * @param  array<string, string>  $filters
     * @return LengthAwarePaginator<int, WalletTransaction>
     */
    public function paginate(Wallet $wallet, array $filters, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $this->query($wallet, $filters)
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * The whole filtered statement, for a file rather than a screen.
     *
     * @param  array<string, string>  $filters
     * @return LazyCollection<int, WalletTransaction>
     */
    public function stream(Wallet $wallet, array $filters): LazyCollection
    {
        // Lazily, because an export is the one read with no page size to hide
        // behind: a five-year-old wallet must not have to fit in memory.
        return $this->query($wallet, $filters)->lazy();
    }

    /**
     * One row, as every surface renders it.
     *
     * @return array<string, mixed>
     */
    public function row(WalletTransaction $transaction, bool $withSensitive = false): array
    {
        $entry = $transaction->ledgerEntries->last();

        return [
            'id' => $transaction->public_id,
            'reference' => $transaction->reference,
            'type' => $transaction->type->value,
            'type_label' => $transaction->type->label(),
            'direction' => $transaction->direction->value,
            'description' => $transaction->description,
            'source' => $transaction->source,
            'amount' => $transaction->amount->jsonSerialize(),

            // Both sides, so a column of debits sums without sign handling —
            // and null where nothing moved, because a reservation is not a
            // debit of zero, it is not a debit at all.
            'debit' => $entry?->debit->jsonSerialize(),
            'credit' => $entry?->credit->jsonSerialize(),

            /*
             * The balance this movement left behind. Absent for a claim, and
             * deliberately not filled in with the current balance: a
             * reservation did not leave the wallet at any particular figure.
             */
            'balance_after' => $entry?->balance_after->jsonSerialize(),
            'entry_reference' => $entry?->reference,

            'status' => $transaction->status->value,
            'status_label' => $transaction->status->label(),
            'status_tone' => $transaction->status->tone(),
            'is_correction' => $transaction->type->isCorrection(),
            'reason' => $transaction->reason,
            'at' => $transaction->created_at?->toIso8601String(),

            // Staff-only (§23.2). Absent, not null-with-a-key, unless the
            // reader is allowed it.
            ...($withSensitive ? ['internal_note' => $transaction->internal_note] : []),
        ];
    }

    /**
     * The filters a statement screen offers, with their labels.
     *
     * @return array<string, array<int, array{value: string, label: string}>>
     */
    public function options(): array
    {
        return [
            'types' => array_map(fn (LedgerTransactionType $case) => [
                'value' => $case->value,
                'label' => $case->label(),
            ], LedgerTransactionType::cases()),

            'statuses' => array_map(fn (WalletTransactionStatus $case) => [
                'value' => $case->value,
                'label' => $case->label(),
            ], WalletTransactionStatus::cases()),

            'directions' => array_map(fn (LedgerDirection $case) => [
                'value' => $case->value,
                'label' => $case->label(),
            ], LedgerDirection::cases()),
        ];
    }

    /**
     * Only the filters that mean something, so the screen echoes back what it
     * actually applied rather than whatever was in the query string.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function acceptedFilters(array $input): array
    {
        $string = function (string $key) use ($input): string {
            $value = $input[$key] ?? '';

            return is_string($value) ? trim($value) : '';
        };

        $type = $string('type');
        $status = $string('status');
        $direction = $string('direction');

        return [
            'type' => LedgerTransactionType::tryFrom($type) === null ? '' : $type,
            'status' => WalletTransactionStatus::tryFrom($status) === null ? '' : $status,
            'direction' => LedgerDirection::tryFrom($direction) === null ? '' : $direction,
            'from' => $this->date($string('from')),
            'to' => $this->date($string('to')),
            'search' => $string('search'),
        ];
    }

    /**
     * @param  array<string, string>  $filters
     * @return Builder<WalletTransaction>
     */
    protected function query(Wallet $wallet, array $filters): Builder
    {
        $filters = $this->acceptedFilters($filters);

        return WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            // `wallet_transaction_id` alongside the member-visible columns
            // because the relation cannot match rows it cannot see the key of.
            ->with(['ledgerEntries' => fn ($entries) => $entries->select([
                ...LedgerEntry::MEMBER_VISIBLE, 'wallet_transaction_id',
            ])])
            ->when($filters['type'] !== '', fn (QueryBuilder $query) => $query
                ->where('type', $filters['type']))
            ->when($filters['status'] !== '', fn (QueryBuilder $query) => $query
                ->where('status', $filters['status']))
            ->when($filters['direction'] !== '', fn (QueryBuilder $query) => $query
                ->where('direction', $filters['direction']))
            ->when($filters['from'] !== '', fn (QueryBuilder $query) => $query
                ->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay()))
            // Inclusive of the closing day: somebody asking for "to the 30th"
            // means the whole of the 30th, not up to midnight at the start of it.
            ->when($filters['to'] !== '', fn (QueryBuilder $query) => $query
                ->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay()))
            ->when($filters['search'] !== '', fn (QueryBuilder $query) => $query
                ->where(fn (QueryBuilder $inner) => $inner
                    ->where('reference', 'ilike', '%'.$filters['search'].'%')
                    ->orWhere('description', 'ilike', '%'.$filters['search'].'%')))

            // Newest first, with the id as the tie-break: several postings land
            // in the same second, and without it their order changes between
            // page loads and a statement reads differently each time.
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * A date filter, or nothing. An unparseable one is dropped rather than
     * throwing: a mistyped query string should show an unfiltered statement,
     * not a 500.
     */
    protected function date(string $value): string
    {
        if ($value === '') {
            return '';
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return '';
        }
    }
}
