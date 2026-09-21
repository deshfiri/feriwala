import { Head, Link } from '@inertiajs/react';
import { Undo2 } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import type { StatusTone } from '@/lib/status';
import { index, show } from '@/routes/admin/returns';
import type { Column, Paginator } from '@/types';

type ReturnRow = {
    id: string;
    reference: string;
    order: string;
    account: string;
    website: string | null;
    status: string;
    status_tone: StatusTone;
    refund_state: string;
    refund_tone: StatusTone;
    needs_attention: boolean;
    reason: string;
    quantity: number;
    requested_at: string;
};

type Props = {
    returns: Paginator<ReturnRow>;
    filters: { search: string | null; status: string | null };
    statuses: string[];
};

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-lg border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Every return asked for, for the staff who decide them (§18.2, P6-12).
 *
 * Those waiting for a decision come first, then goods on their way, then
 * money. A refund that needs somebody — failed, or to be settled by hand — is
 * said in words beside the status, never by colour alone.
 */
export default function AdminReturns({ returns, filters, statuses }: Props) {
    const { t, locale } = useTranslation();
    const { setFilter } = useTableQuery({ only: ['returns', 'filters'] });
    const filtered = Boolean(filters.search || filters.status);

    const status = (row: ReturnRow) => (
        <div className="min-w-0 space-y-1">
            <StatusPill
                tone={row.status_tone}
                label={t(`returns.statuses.${row.status}`)}
            />
            <div className="text-muted-foreground text-xs">
                {t(`returns.refund_states.${row.refund_state}`)}
            </div>
            {row.needs_attention && (
                <div className="text-warning-foreground text-xs font-medium">
                    {t('returns.admin.needs_attention')}
                </div>
            )}
        </div>
    );

    const columns: Column<ReturnRow>[] = [
        {
            key: 'reference',
            header: t('returns.admin.columns.reference'),
            cell: (row) => (
                <div className="min-w-0">
                    <Link
                        href={show(row.id)}
                        className="font-mono text-sm font-medium underline-offset-4 hover:underline"
                    >
                        {row.reference}
                    </Link>
                    <div className="text-muted-foreground truncate font-mono text-xs">
                        {row.order}
                    </div>
                </div>
            ),
        },
        {
            key: 'account',
            header: t('returns.admin.columns.account'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate text-sm">{row.account}</div>
                    {row.website && (
                        <div className="text-muted-foreground truncate text-xs">
                            {row.website}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'status',
            header: t('returns.admin.columns.status'),
            cell: status,
        },
        {
            key: 'quantity',
            header: t('returns.admin.columns.quantity'),
            align: 'end',
            cell: (row) => (
                <span className="text-sm tabular-nums">{row.quantity}</span>
            ),
        },
        {
            key: 'requested',
            header: t('returns.admin.columns.requested'),
            priority: 'secondary',
            cell: (row) => (
                <div className="text-sm">
                    <div>
                        {new Date(row.requested_at).toLocaleString(locale)}
                    </div>
                    <div className="text-muted-foreground text-xs">
                        {t(`returns.reasons.${row.reason}`)}
                    </div>
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title={t('returns.admin.title')} />

            <PageContainer>
                <PageHeader
                    title={t('returns.admin.title')}
                    description={t('returns.admin.description')}
                />

                <DataTable
                    columns={columns}
                    paginator={returns}
                    rowKey={(row) => row.id}
                    caption={t('returns.admin.caption')}
                    searchPlaceholder={t('returns.admin.search')}
                    onlyReload={['returns', 'filters']}
                    filters={
                        <select
                            aria-label={t('returns.admin.filter_status')}
                            value={filters.status ?? ''}
                            onChange={(event) =>
                                setFilter(
                                    'status',
                                    event.target.value || undefined,
                                )
                            }
                            className={selectClass}
                        >
                            <option value="">
                                {t('returns.admin.all_statuses')}
                            </option>
                            {statuses.map((value) => (
                                <option key={value} value={value}>
                                    {t(`returns.statuses.${value}`)}
                                </option>
                            ))}
                        </select>
                    }
                    renderCard={(row) => (
                        <div className="space-y-2">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <Link
                                        href={show(row.id)}
                                        className="block truncate font-mono text-sm font-medium underline-offset-4 hover:underline"
                                    >
                                        {row.reference}
                                    </Link>
                                    <div className="text-muted-foreground truncate text-xs">
                                        {row.account} · {row.order}
                                    </div>
                                </div>
                                <span className="text-sm tabular-nums">
                                    {row.quantity}
                                </span>
                            </div>
                            {status(row)}
                        </div>
                    )}
                    emptyState={
                        <EmptyState
                            icon={Undo2}
                            title={t(
                                filtered
                                    ? 'returns.admin.no_matches'
                                    : 'returns.admin.empty',
                            )}
                            description={t(
                                filtered
                                    ? 'returns.admin.no_matches_help'
                                    : 'returns.admin.empty_help',
                            )}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

AdminReturns.layout = {
    breadcrumbs: [
        {
            title: 'Returns',
            href: index(),
        },
    ],
};
