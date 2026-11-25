import { Head, Link } from '@inertiajs/react';
import { Coins } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import type { StatusTone } from '@/lib/status';
import { index, show } from '@/routes/admin/storefront-profits';
import type { Column, Paginator } from '@/types';

export type StorefrontProfitRow = {
    id: string;
    order: { id: string; reference: string };
    partner: { name: string; number: string | null };
    status: string;
    status_label: string;
    status_tone: string;
    status_reason: string | null;
    selling: Money | null;
    cost: Money | null;
    profit: Money | null;
    released: Money;
    reversed: Money;
    delivered_at: string | null;
    collected_at: string | null;
    release_at: string | null;
};

type Props = {
    profits: Paginator<StorefrontProfitRow>;
    filters: { status: string | null; search: string | null };
    statuses: { value: string; label: string }[];
};

const controlClass =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-lg border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Every Partner's Storefront profit: held 24 hours after delivery, then
 * credited to their withdrawable balance. Filtered on the server.
 */
export default function StorefrontProfits({
    profits,
    filters,
    statuses,
}: Props) {
    const { t, locale } = useTranslation();
    const { setFilter } = useTableQuery({ only: ['profits', 'filters'] });
    const when = (value: string | null) =>
        value === null ? '—' : new Date(value).toLocaleString(locale);

    const reason = (row: StorefrontProfitRow) =>
        row.status_reason
            ? t(`storefront_profit.reasons.${row.status_reason}`)
            : null;

    const columns: Column<StorefrontProfitRow>[] = [
        {
            key: 'order',
            header: t('storefront_profit.fields.order'),
            cell: (row) => (
                <Link
                    href={show(row.id)}
                    className="text-sm font-medium underline-offset-4 hover:underline"
                >
                    {row.order.reference}
                </Link>
            ),
        },
        {
            key: 'partner',
            header: t('storefront_profit.fields.partner'),
            cell: (row) => (
                <div className="text-sm">
                    <div>{row.partner.name}</div>
                    {row.partner.number && (
                        <div className="text-muted-foreground text-xs">
                            {row.partner.number}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'profit',
            header: t('storefront_profit.fields.profit'),
            align: 'end',
            cell: (row) =>
                row.profit ? <MoneyAmount amount={row.profit} /> : '—',
        },
        {
            key: 'release_at',
            header: t('storefront_profit.fields.release_at'),
            cell: (row) => (
                <span className="text-sm whitespace-nowrap">
                    {when(row.release_at)}
                </span>
            ),
        },
        {
            key: 'status',
            header: t('storefront_profit.fields.status'),
            cell: (row) => (
                <div className="space-y-1">
                    <StatusPill
                        tone={row.status_tone as StatusTone}
                        label={row.status_label}
                    />
                    {reason(row) && (
                        <div className="text-muted-foreground text-xs">
                            {reason(row)}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'actions',
            header: '',
            alwaysVisible: true,
            align: 'end',
            cell: (row) => (
                <Button variant="ghost" size="sm" asChild>
                    <Link href={show(row.id)}>
                        {t('storefront_profit.actions.view')}
                    </Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title={t('storefront_profit.admin_title')} />

            <PageContainer>
                <PageHeader
                    title={t('storefront_profit.admin_title')}
                    description={t('storefront_profit.admin_description')}
                />

                <DataTable
                    columns={columns}
                    paginator={profits}
                    rowKey={(row) => row.id}
                    caption={t('storefront_profit.admin_title')}
                    searchPlaceholder={t('storefront_profit.fields.order')}
                    onlyReload={['profits', 'filters']}
                    filters={
                        <select
                            aria-label={t('storefront_profit.fields.status')}
                            value={filters.status ?? ''}
                            onChange={(e) =>
                                setFilter('status', e.target.value || undefined)
                            }
                            className={`${controlClass} w-44`}
                        >
                            <option value="">—</option>
                            {statuses.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    }
                    renderCard={(row) => (
                        <div className="space-y-2">
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <Link
                                        href={show(row.id)}
                                        className="block truncate text-sm font-medium"
                                    >
                                        {row.order.reference}
                                    </Link>
                                    <div className="text-muted-foreground text-xs">
                                        {row.partner.name}
                                    </div>
                                </div>
                                {row.profit && (
                                    <MoneyAmount amount={row.profit} />
                                )}
                            </div>
                            <StatusPill
                                tone={row.status_tone as StatusTone}
                                label={row.status_label}
                            />
                            {row.release_at && (
                                <div className="text-muted-foreground text-xs">
                                    {t('storefront_profit.fields.release_at')}:{' '}
                                    {when(row.release_at)}
                                </div>
                            )}
                        </div>
                    )}
                    emptyState={
                        <EmptyState
                            icon={Coins}
                            title={t('storefront_profit.empty')}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

StorefrontProfits.layout = {
    breadcrumbs: [{ title: 'storefront_profit.admin_title', href: index() }],
};
