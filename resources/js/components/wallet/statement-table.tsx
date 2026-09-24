import { Link, router } from '@inertiajs/react';
import { Receipt } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import MoneyAmount from '@/components/money-amount';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { isZero } from '@/lib/money';
import type { Column, Paginator } from '@/types';
import type {
    WalletFilterOptions,
    WalletMovement,
    WalletStatementFilters,
} from '@/types/wallet';

const selectClass =
    'border-input bg-background focus-visible:ring-ring rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

const dateClass =
    'border-input bg-background focus-visible:ring-ring rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * A wallet's movements, filtered (§33.7, P2-8).
 *
 * The account holder's statement and the administrator's view of the same wallet
 * are this component, given a different URL to filter against. Two tables would
 * eventually describe one wallet two ways, which is the failure a ledger exists
 * to make impossible.
 *
 * Out and in are separate columns rather than one signed figure. A ledger stores
 * a debit as a positive amount with a debit type, so a single column would need
 * the reader to know the sign convention — and would put the burden of the
 * arithmetic on the browser, which §36.1 does not allow.
 *
 * A row with no figures is not a gap: a reservation moves nothing and writes no
 * entry. It is on the statement because it is why the spendable balance is lower
 * than the total, and it says so through its status rather than a blank.
 */
export default function WalletStatementTable({
    transactions,
    filters,
    options,
    filterUrl,
    detailUrl,
    exportUrl,
    caption,
}: {
    transactions: Paginator<WalletMovement>;
    filters: WalletStatementFilters;
    options: WalletFilterOptions;
    /** Where a filter change goes. */
    filterUrl: string;
    detailUrl: (row: WalletMovement) => string;
    exportUrl?: string;
    caption?: string;
}) {
    const { t, locale } = useTranslation();

    const when = (value: string | null) =>
        value === null
            ? '—'
            : new Date(value).toLocaleDateString(locale, {
                  day: 'numeric',
                  month: 'short',
                  year: 'numeric',
              });

    const filter = (key: keyof WalletStatementFilters, value: string) =>
        router.get(
            filterUrl,
            { ...filters, [key]: value },
            {
                preserveState: true,
                replace: true,
                only: ['transactions', 'filters'],
            },
        );

    const columns: Column<WalletMovement>[] = [
        {
            key: 'created_at',
            header: t('wallet.columns.date'),
            cell: (row) => (
                <div className="min-w-0">
                    <div>{when(row.at)}</div>
                    <div className="text-muted-foreground truncate font-mono text-xs">
                        {row.reference}
                    </div>
                </div>
            ),
        },
        {
            key: 'description',
            header: t('wallet.columns.description'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate">{row.description}</div>
                    <div className="text-muted-foreground truncate text-xs">
                        {row.type_label}
                    </div>
                </div>
            ),
        },
        {
            key: 'debit',
            header: t('wallet.columns.debit'),
            align: 'end',
            cell: (row) =>
                row.debit === null || isZero(row.debit) ? (
                    <span className="text-muted-foreground">—</span>
                ) : (
                    <MoneyAmount
                        amount={row.debit}
                        direction="debit"
                        showSign
                    />
                ),
        },
        {
            key: 'credit',
            header: t('wallet.columns.credit'),
            align: 'end',
            cell: (row) =>
                row.credit === null || isZero(row.credit) ? (
                    <span className="text-muted-foreground">—</span>
                ) : (
                    <MoneyAmount
                        amount={row.credit}
                        direction="credit"
                        showSign
                    />
                ),
        },
        {
            key: 'balance_after',
            header: t('wallet.columns.balance'),
            align: 'end',
            priority: 'secondary',
            cell: (row) =>
                row.balance_after === null ? (
                    <span className="text-muted-foreground">—</span>
                ) : (
                    <MoneyAmount amount={row.balance_after} />
                ),
        },
        {
            key: 'status',
            header: t('wallet.columns.status'),
            cell: (row) => (
                <StatusPill tone={row.status_tone} label={row.status_label} />
            ),
        },
        {
            key: 'actions',
            header: '',
            alwaysVisible: true,
            align: 'end',
            cell: (row) => (
                <Button variant="ghost" size="sm" asChild>
                    <Link href={detailUrl(row)}>
                        {t('wallet.statement.open')}
                    </Link>
                </Button>
            ),
        },
    ];

    return (
        <DataTable
            columns={columns}
            paginator={transactions}
            rowKey={(row) => row.id}
            caption={caption ?? t('wallet.statement.caption')}
            searchPlaceholder={t('wallet.statement.search_placeholder')}
            onlyReload={['transactions', 'filters']}
            actions={
                exportUrl === undefined ? undefined : (
                    <Button variant="secondary" size="sm" asChild>
                        {/*
                         * A real link, not a fetch: the browser's own download
                         * handling is what turns a streamed response into a
                         * saved file.
                         */}
                        <a href={exportUrl}>{t('wallet.statement.export')}</a>
                    </Button>
                )
            }
            filters={
                <>
                    <select
                        aria-label={t('wallet.columns.type')}
                        className={selectClass}
                        value={filters.type}
                        onChange={(event) => filter('type', event.target.value)}
                    >
                        <option value="">
                            {t('wallet.statement.all_types')}
                        </option>
                        {options.types.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>

                    <select
                        aria-label={t('wallet.columns.status')}
                        className={selectClass}
                        value={filters.status}
                        onChange={(event) =>
                            filter('status', event.target.value)
                        }
                    >
                        <option value="">
                            {t('wallet.statement.all_statuses')}
                        </option>
                        {options.statuses.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>

                    <select
                        aria-label={t('wallet.statement.all_directions')}
                        className={selectClass}
                        value={filters.direction}
                        onChange={(event) =>
                            filter('direction', event.target.value)
                        }
                    >
                        <option value="">
                            {t('wallet.statement.all_directions')}
                        </option>
                        {options.directions.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>

                    <input
                        type="date"
                        aria-label={t('wallet.statement.from')}
                        className={dateClass}
                        value={filters.from}
                        onChange={(event) => filter('from', event.target.value)}
                    />

                    <input
                        type="date"
                        aria-label={t('wallet.statement.to')}
                        className={dateClass}
                        value={filters.to}
                        onChange={(event) => filter('to', event.target.value)}
                    />
                </>
            }
            emptyState={
                <EmptyState
                    icon={Receipt}
                    title={t('wallet.statement.empty_title')}
                    description={t('wallet.statement.empty_description')}
                />
            }
        />
    );
}
