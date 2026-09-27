import { Head, Link } from '@inertiajs/react';
import { ShoppingBag } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import { orderPaymentTone } from '@/lib/website-order';
import { index as websitesIndex, show as websiteShow } from '@/routes/websites';
import { show as orderShow } from '@/routes/websites/orders';
import type { Column, Paginator } from '@/types';
import type { WebsiteOrderRow } from '@/types/website-order';

type Props = {
    website: { id: string; name: string };
    orders: Paginator<WebsiteOrderRow>;
    filters: { search: string | null; status: string | null };
    statuses: { value: string; label: string }[];
};

/**
 * The orders one partner website took (§16.3, §18.4, P5-13, P6-8).
 *
 * This shop's own, and nobody else's: the list is scoped to the website on the
 * server, and an order of another partner's is not found at all.
 */
export default function WebsiteOrders({
    website,
    orders,
    filters,
    statuses,
}: Props) {
    const { t, locale } = useTranslation();
    const { setFilter } = useTableQuery({ only: ['orders', 'filters'] });

    const when = (value: string) => new Date(value).toLocaleString(locale);

    const columns: Column<WebsiteOrderRow>[] = [
        {
            key: 'placed_at',
            header: t('website.orders.placed'),
            cell: (row) => (
                <span className="text-sm whitespace-nowrap">
                    {when(row.placed_at)}
                </span>
            ),
        },
        {
            key: 'reference',
            header: t('website.orders.reference'),
            cell: (row) => (
                <div className="text-sm">
                    <Link
                        href={orderShow([website.id, row.id])}
                        className="font-medium underline-offset-4 hover:underline"
                    >
                        {row.reference}
                    </Link>
                    {row.storefront_reference && (
                        <div className="text-muted-foreground font-mono text-xs">
                            {row.storefront_reference}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'customer',
            header: t('website.orders.customer'),
            cell: (row) => <span className="text-sm">{row.customer}</span>,
        },
        {
            key: 'status',
            header: t('website.orders.status'),
            cell: (row) => (
                <StatusPill
                    tone={row.status_tone}
                    label={t(`orders.statuses.${row.status}`)}
                />
            ),
        },
        {
            key: 'payment',
            header: t('website.orders.payment'),
            cell: (row) => (
                <div className="space-y-1">
                    {row.payment_state === null ? (
                        <span className="text-muted-foreground text-sm">—</span>
                    ) : (
                        <StatusPill
                            tone={orderPaymentTone(row.payment_state)}
                            label={t(
                                `website.orders.payment_states.${row.payment_state}`,
                            )}
                        />
                    )}
                    <div className="text-muted-foreground text-xs">
                        {t(
                            `website.orders.payment_methods.${row.payment_method}`,
                        )}
                    </div>
                </div>
            ),
        },
        {
            key: 'total',
            header: t('website.orders.total'),
            align: 'end',
            cell: (row) => <MoneyAmount amount={row.total} />,
        },
        {
            key: 'actions',
            header: '',
            alwaysVisible: true,
            align: 'end',
            cell: (row) => (
                <Button variant="ghost" size="sm" asChild>
                    <Link href={orderShow([website.id, row.id])}>
                        {t('website.orders.open')}
                    </Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title={t('website.orders.title')} />

            <PageContainer>
                <PageHeader
                    title={t('website.orders.title')}
                    description={t('website.orders.description', {
                        website: website.name,
                    })}
                    actions={
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={websiteShow(website.id)}>
                                {website.name}
                            </Link>
                        </Button>
                    }
                />

                <DataTable
                    columns={columns}
                    paginator={orders}
                    rowKey={(row) => row.id}
                    caption={t('website.orders.title')}
                    searchPlaceholder={t('website.orders.search')}
                    onlyReload={['orders', 'filters']}
                    filters={
                        <select
                            aria-label={t('website.orders.status')}
                            value={filters.status ?? ''}
                            onChange={(event) =>
                                setFilter(
                                    'status',
                                    event.target.value || undefined,
                                )
                            }
                            className="border-input bg-background focus-visible:ring-ring h-9 w-44 rounded-lg border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <option value="">
                                {t('website.orders.all_statuses')}
                            </option>
                            {statuses.map((status) => (
                                <option key={status.value} value={status.value}>
                                    {status.label}
                                </option>
                            ))}
                        </select>
                    }
                    renderCard={(row) => (
                        <div className="space-y-2">
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <Link
                                        href={orderShow([website.id, row.id])}
                                        className="truncate text-sm font-medium underline-offset-4 hover:underline"
                                    >
                                        {row.reference}
                                    </Link>
                                    <div className="text-muted-foreground truncate text-xs">
                                        {row.customer}
                                    </div>
                                </div>
                                <MoneyAmount amount={row.total} />
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusPill
                                    tone={row.status_tone}
                                    label={t(`orders.statuses.${row.status}`)}
                                />
                                <span className="text-muted-foreground text-xs">
                                    {when(row.placed_at)}
                                </span>
                            </div>
                        </div>
                    )}
                    emptyState={
                        <EmptyState
                            icon={ShoppingBag}
                            title={t('website.orders.empty')}
                            description={t('website.orders.empty_help')}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

WebsiteOrders.layout = {
    breadcrumbs: [
        {
            title: 'nav.websites',
            href: websitesIndex(),
        },
    ],
};
