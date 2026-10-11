import { Head, Link } from '@inertiajs/react';
import { Download, ShoppingBag } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import NativeSelect from '@/components/forms/native-select';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import { exportMethod as ordersExport, index } from '@/routes/orders';
import type { Column, Paginator } from '@/types';
import type { BusinessOrderRow } from '@/types/stores';

type Option = { value: string; label: string };

type Filters = {
    search: string | null;
    source: string | null;
    store: string | null;
    status: string | null;
    payment: string | null;
    from: string | null;
    to: string | null;
    sort: string;
    direction: string;
};

type Props = {
    orders: Paginator<BusinessOrderRow>;
    filters: Filters;
    options: {
        sources: Option[];
        stores: Option[];
        statuses: Option[];
        payments: Option[];
    };
};

/**
 * Every order the business has, from every source, in one list (D29).
 *
 * Filtered, searched, sorted and paged on the server, and only ever this
 * business's own orders. Each row keeps its source and opens on the detail
 * screen of its own kind.
 */
export default function OrdersIndex({ orders, filters, options }: Props) {
    const { t, locale } = useTranslation();
    const { setFilter } = useTableQuery({ only: ['orders', 'filters'] });

    const when = (value: string) => new Date(value).toLocaleString(locale);
    const filtered = [
        filters.search,
        filters.source,
        filters.store,
        filters.status,
        filters.payment,
        filters.from,
        filters.to,
    ].some((value) => value !== null);

    const exportQuery = Object.fromEntries(
        Object.entries(filters).filter(
            ([, value]) => value !== null && value !== '',
        ),
    ) as Record<string, string>;

    const select = (
        key: keyof Filters,
        label: string,
        all: string,
        choices: Option[],
    ) => (
        <NativeSelect
            size="sm"
            aria-label={label}
            value={filters[key] ?? ''}
            onChange={(event) =>
                setFilter(key, event.target.value || undefined)
            }
            className="w-44"
        >
            <option value="">{all}</option>
            {choices.map((choice) => (
                <option key={choice.value} value={choice.value}>
                    {choice.label}
                </option>
            ))}
        </NativeSelect>
    );

    const columns: Column<BusinessOrderRow>[] = [
        {
            key: 'number',
            header: t('orders.unified.columns.number'),
            priority: 'primary',
            sortable: true,
            cell: (row) => (
                <div className="text-sm">
                    <Link
                        href={row.href}
                        className="font-medium underline-offset-4 hover:underline"
                    >
                        {row.number}
                    </Link>
                    {row.secondary_number && (
                        <div className="text-muted-foreground font-mono text-xs">
                            {row.secondary_number}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'source',
            header: t('orders.unified.columns.source'),
            cell: (row) => (
                <div className="text-sm">
                    <div>{row.kind_label}</div>
                    {row.store && (
                        <div className="text-muted-foreground text-xs">
                            {row.store}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'customer',
            header: t('orders.unified.columns.customer'),
            cell: (row) => (
                <div className="text-sm">
                    <div>{row.customer ?? '—'}</div>
                    {row.customer_phone && (
                        <div className="text-muted-foreground text-xs">
                            {row.customer_phone}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'total',
            header: t('orders.unified.columns.total'),
            align: 'end',
            sortable: true,
            cell: (row) => (
                <MoneyAmount amount={row.total} direction="neutral" />
            ),
        },
        {
            key: 'payment',
            header: t('orders.unified.columns.payment'),
            cell: (row) =>
                row.payment_label ? (
                    <StatusPill
                        tone={row.payment_tone}
                        label={row.payment_label}
                    />
                ) : (
                    <span className="text-muted-foreground text-sm">—</span>
                ),
        },
        {
            key: 'status',
            header: t('orders.unified.columns.status'),
            priority: 'primary',
            cell: (row) => (
                <StatusPill tone={row.status_tone} label={row.status_label} />
            ),
        },
        {
            key: 'fulfillment',
            header: t('orders.unified.columns.fulfillment'),
            hiddenByDefault: true,
            cell: (row) =>
                row.fulfillment ? (
                    <StatusPill
                        tone={row.fulfillment.tone}
                        label={row.fulfillment.label}
                    />
                ) : (
                    <span className="text-muted-foreground text-sm">—</span>
                ),
        },
        {
            key: 'delivery',
            header: t('orders.unified.columns.delivery'),
            cell: (row) =>
                row.delivery ? (
                    <StatusPill
                        tone={row.delivery.tone}
                        label={row.delivery.label}
                    />
                ) : (
                    <span className="text-muted-foreground text-sm">—</span>
                ),
        },
        {
            key: 'placed_at',
            header: t('orders.unified.columns.placed_at'),
            sortable: true,
            cell: (row) => (
                <span className="text-sm whitespace-nowrap">
                    {when(row.placed_at)}
                </span>
            ),
        },
        {
            key: 'actions',
            header: '',
            alwaysVisible: true,
            align: 'end',
            cell: (row) => (
                <Button variant="ghost" size="sm" asChild>
                    <Link href={row.href}>{t('orders.unified.open')}</Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title={t('orders.unified.title')} />

            <PageContainer>
                <PageHeader
                    title={t('orders.unified.title')}
                    description={t('orders.unified.description')}
                    actions={
                        <Button variant="outline" size="sm" asChild>
                            <a href={ordersExport({ query: exportQuery }).url}>
                                <Download aria-hidden="true" />
                                {t('orders.unified.export')}
                            </a>
                        </Button>
                    }
                />

                <DataTable
                    columns={columns}
                    paginator={orders}
                    rowKey={(row) => `${row.kind}-${row.id}`}
                    caption={t('orders.unified.title')}
                    searchPlaceholder={t('orders.unified.search')}
                    onlyReload={['orders', 'filters']}
                    filters={
                        <div className="flex flex-wrap items-center gap-2">
                            {select(
                                'source',
                                t('orders.unified.columns.source'),
                                t('orders.unified.all_sources'),
                                options.sources,
                            )}
                            {options.stores.length > 0 &&
                                select(
                                    'store',
                                    t('orders.unified.columns.store'),
                                    t('orders.unified.all_stores'),
                                    options.stores,
                                )}
                            {select(
                                'status',
                                t('orders.unified.columns.status'),
                                t('orders.unified.all_statuses'),
                                options.statuses,
                            )}
                            {options.payments.length > 0 &&
                                select(
                                    'payment',
                                    t('orders.unified.columns.payment'),
                                    t('orders.unified.all_payments'),
                                    options.payments,
                                )}
                            <label className="flex items-center gap-1 text-sm">
                                <span className="text-muted-foreground">
                                    {t('orders.unified.from')}
                                </span>
                                <Input
                                    type="date"
                                    className="h-9 w-40"
                                    value={filters.from ?? ''}
                                    onChange={(event) =>
                                        setFilter(
                                            'from',
                                            event.target.value || undefined,
                                        )
                                    }
                                />
                            </label>
                            <label className="flex items-center gap-1 text-sm">
                                <span className="text-muted-foreground">
                                    {t('orders.unified.to')}
                                </span>
                                <Input
                                    type="date"
                                    className="h-9 w-40"
                                    value={filters.to ?? ''}
                                    onChange={(event) =>
                                        setFilter(
                                            'to',
                                            event.target.value || undefined,
                                        )
                                    }
                                />
                            </label>
                        </div>
                    }
                    renderCard={(row) => (
                        <div className="space-y-2">
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <Link
                                        href={row.href}
                                        className="truncate text-sm font-medium underline-offset-4 hover:underline"
                                    >
                                        {row.number}
                                    </Link>
                                    <div className="text-muted-foreground truncate text-xs">
                                        {row.kind_label}
                                        {row.store && ` · ${row.store}`}
                                    </div>
                                    <div className="truncate text-xs">
                                        {row.customer ?? '—'}
                                    </div>
                                </div>
                                <MoneyAmount
                                    amount={row.total}
                                    direction="neutral"
                                />
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusPill
                                    tone={row.status_tone}
                                    label={row.status_label}
                                />
                                {row.payment_label && (
                                    <StatusPill
                                        tone={row.payment_tone}
                                        label={row.payment_label}
                                    />
                                )}
                                <span className="text-muted-foreground text-xs">
                                    {when(row.placed_at)}
                                </span>
                            </div>
                        </div>
                    )}
                    emptyState={
                        <EmptyState
                            icon={ShoppingBag}
                            title={
                                filtered
                                    ? t('orders.unified.empty_filtered')
                                    : t('orders.unified.empty')
                            }
                            description={
                                filtered
                                    ? undefined
                                    : t('orders.unified.empty_help')
                            }
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

OrdersIndex.layout = {
    breadcrumbs: [{ title: 'nav.orders', href: index() }],
};
