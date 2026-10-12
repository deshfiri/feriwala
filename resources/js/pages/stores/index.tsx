import { Head, Link } from '@inertiajs/react';
import { Store } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import Notice from '@/components/notice';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { index, show } from '@/routes/stores';
import type { Column, Paginator } from '@/types';
import type { ExternalStoreRow } from '@/types/stores';

type Props = {
    stores: ExternalStoreRow[];
};

/**
 * The business's previously connected stores on other platforms, read-only
 * (D29, as amended by D30).
 *
 * Owners no longer connect stores themselves: their websites are served by
 * Feriwala's own storefront. What was connected before stays visible here,
 * with the orders it brought in, and nothing on this page changes it.
 */
export default function StoresIndex({ stores }: Props) {
    const { t, locale } = useTranslation();

    const when = (value: string | null) =>
        value ? new Date(value).toLocaleString(locale) : t('stores.never');
    const count = (value: number) => value.toLocaleString(locale);

    const paginator: Paginator<ExternalStoreRow> = {
        data: stores,
        current_page: 1,
        last_page: 1,
        per_page: Math.max(stores.length, 1),
        from: stores.length > 0 ? 1 : null,
        to: stores.length > 0 ? stores.length : null,
        total: stores.length,
    };

    const columns: Column<ExternalStoreRow>[] = [
        {
            key: 'name',
            header: t('stores.columns.name'),
            priority: 'primary',
            cell: (row) => (
                <div className="min-w-0 text-sm">
                    <Link
                        href={show(row.id)}
                        className="font-medium underline-offset-4 hover:underline"
                    >
                        {row.name}
                    </Link>
                    <div className="text-muted-foreground truncate font-mono text-xs">
                        {row.base_url}
                    </div>
                </div>
            ),
        },
        {
            key: 'platform',
            header: t('stores.columns.platform'),
            cell: (row) => (
                <span className="text-sm">{row.platform_label}</span>
            ),
        },
        {
            key: 'status',
            header: t('stores.columns.status'),
            priority: 'primary',
            cell: (row) => (
                <StatusPill tone={row.status_tone} label={row.status_label} />
            ),
        },
        {
            key: 'last_success',
            header: t('stores.columns.last_success'),
            cell: (row) => (
                <span className="text-sm whitespace-nowrap">
                    {when(row.last_success_at)}
                </span>
            ),
        },
        {
            key: 'orders',
            header: t('stores.columns.orders'),
            align: 'end',
            cell: (row) => (
                <span className="tabular text-sm">
                    {count(row.orders_count)}
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
                    <Link href={show(row.id)}>{t('stores.actions.view')}</Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title={t('stores.title')} />

            <PageContainer>
                <PageHeader
                    title={t('stores.title')}
                    description={t('stores.description')}
                />

                <Notice tone="neutral">{t('stores.retired_notice')}</Notice>

                <DataTable
                    columns={columns}
                    paginator={paginator}
                    rowKey={(row) => row.id}
                    caption={t('stores.title')}
                    searchable={false}
                    renderCard={(row) => (
                        <div className="space-y-2">
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <Link
                                        href={show(row.id)}
                                        className="truncate text-sm font-medium underline-offset-4 hover:underline"
                                    >
                                        {row.name}
                                    </Link>
                                    <div className="text-muted-foreground truncate text-xs">
                                        {row.platform_label} · {row.base_url}
                                    </div>
                                </div>
                                <StatusPill
                                    tone={row.status_tone}
                                    label={row.status_label}
                                />
                            </div>
                            <div className="text-muted-foreground text-xs">
                                {t('stores.columns.last_success')}:{' '}
                                {when(row.last_success_at)} ·{' '}
                                {t('stores.columns.orders')}:{' '}
                                {count(row.orders_count)}
                            </div>
                        </div>
                    )}
                    emptyState={
                        <EmptyState
                            icon={Store}
                            title={t('stores.empty')}
                            description={t('stores.retired_notice')}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

StoresIndex.layout = {
    breadcrumbs: [{ title: 'nav.stores', href: index() }],
};
