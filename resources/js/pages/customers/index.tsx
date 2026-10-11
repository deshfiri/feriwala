import { Head, Link } from '@inertiajs/react';
import { Users } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import NativeSelect from '@/components/forms/native-select';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/customers';
import type { Column, Paginator } from '@/types';
import type { BusinessCustomerRow } from '@/types/stores';

type Props = {
    customers: Paginator<BusinessCustomerRow>;
    filters: {
        search: string | null;
        source: string | null;
        store: string | null;
    };
    stores: { value: string; label: string }[];
};

/**
 * The people who bought from the business, store by store (D29).
 *
 * A customer is always one store's customer — the same phone number in two
 * stores is two rows, never merged on a guess.
 */
export default function CustomersIndex({ customers, filters, stores }: Props) {
    const { t, locale } = useTranslation();
    const { setFilter } = useTableQuery({ only: ['customers', 'filters'] });
    const when = (value: string | null) =>
        value ? new Date(value).toLocaleDateString(locale) : '—';

    const columns: Column<BusinessCustomerRow>[] = [
        {
            key: 'name',
            header: t('orders.customers.columns.name'),
            priority: 'primary',
            cell: (row) => (
                <Link
                    href={row.href}
                    className="text-sm font-medium underline-offset-4 hover:underline"
                >
                    {row.name ?? t('orders.customers.unnamed')}
                </Link>
            ),
        },
        {
            key: 'contact',
            header: t('orders.customers.columns.contact'),
            cell: (row) => (
                <div className="text-sm">
                    <div>{row.phone ?? '—'}</div>
                    {row.email && (
                        <div className="text-muted-foreground text-xs">
                            {row.email}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'store',
            header: t('orders.customers.columns.store'),
            cell: (row) => (
                <div className="text-sm">
                    <div>{row.store}</div>
                    <div className="text-muted-foreground text-xs">
                        {row.kind_label}
                    </div>
                </div>
            ),
        },
        {
            key: 'orders',
            header: t('orders.customers.columns.orders'),
            align: 'end',
            cell: (row) => (
                <span className="tabular text-sm">
                    {row.orders_count.toLocaleString(locale)}
                </span>
            ),
        },
        {
            key: 'last_order',
            header: t('orders.customers.columns.last_order'),
            cell: (row) => (
                <span className="text-sm whitespace-nowrap">
                    {when(row.last_order_at)}
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
                    <Link href={row.href}>{t('orders.customers.open')}</Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title={t('orders.customers.title')} />

            <PageContainer>
                <PageHeader
                    title={t('orders.customers.title')}
                    description={t('orders.customers.description')}
                />

                <DataTable
                    columns={columns}
                    paginator={customers}
                    rowKey={(row) => `${row.kind}-${row.id}`}
                    caption={t('orders.customers.title')}
                    searchPlaceholder={t('orders.customers.search')}
                    onlyReload={['customers', 'filters']}
                    filters={
                        <div className="flex flex-wrap gap-2">
                            <NativeSelect
                                size="sm"
                                className="w-44"
                                aria-label={t('orders.unified.columns.source')}
                                value={filters.source ?? ''}
                                onChange={(event) =>
                                    setFilter(
                                        'source',
                                        event.target.value || undefined,
                                    )
                                }
                            >
                                <option value="">
                                    {t('orders.customers.all_sources')}
                                </option>
                                <option value="website">
                                    {t('orders.unified.sources.website')}
                                </option>
                                <option value="external">
                                    {t('orders.unified.sources.external')}
                                </option>
                            </NativeSelect>
                            {stores.length > 0 && (
                                <NativeSelect
                                    size="sm"
                                    className="w-44"
                                    aria-label={t(
                                        'orders.customers.columns.store',
                                    )}
                                    value={filters.store ?? ''}
                                    onChange={(event) =>
                                        setFilter(
                                            'store',
                                            event.target.value || undefined,
                                        )
                                    }
                                >
                                    <option value="">
                                        {t('orders.customers.all_stores')}
                                    </option>
                                    {stores.map((store) => (
                                        <option
                                            key={store.value}
                                            value={store.value}
                                        >
                                            {store.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            )}
                        </div>
                    }
                    renderCard={(row) => (
                        <div className="space-y-1">
                            <Link
                                href={row.href}
                                className="text-sm font-medium underline-offset-4 hover:underline"
                            >
                                {row.name ?? t('orders.customers.unnamed')}
                            </Link>
                            <div className="text-muted-foreground text-xs">
                                {row.phone ?? '—'} · {row.store}
                            </div>
                            <div className="text-muted-foreground text-xs">
                                {t('orders.customers.columns.orders')}:{' '}
                                {row.orders_count.toLocaleString(locale)} ·{' '}
                                {when(row.last_order_at)}
                            </div>
                        </div>
                    )}
                    emptyState={
                        <EmptyState
                            icon={Users}
                            title={t('orders.customers.empty')}
                            description={t('orders.customers.empty_help')}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

CustomersIndex.layout = {
    breadcrumbs: [{ title: 'nav.customers', href: index() }],
};
