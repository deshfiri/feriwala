import { Head, Link, router } from '@inertiajs/react';
import { Plus, Store } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import Notice from '@/components/notice';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import ExternalStoreController from '@/actions/App/Http/Controllers/Erp/ExternalStoreController';
import { create, edit, index, show } from '@/routes/stores';
import type { Column, Paginator } from '@/types';
import type { ExternalStoreRow, StoreAllowance } from '@/types/stores';

type Props = {
    stores: ExternalStoreRow[];
    allowance: StoreAllowance;
};

/**
 * The business's own stores on other platforms (D29).
 *
 * The account's own connections only, each with how its syncs are going.
 * Testing and syncing call the store from the server and never block the page.
 */
export default function StoresIndex({ stores, allowance }: Props) {
    const { t, locale } = useTranslation();

    const atLimit = allowance.remaining !== null && allowance.remaining <= 0;
    const mayConnect = allowance.allowed && !atLimit;
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

    const post = (url: string) =>
        router.post(
            url,
            {},
            { preserveScroll: true, only: ['stores', 'flash'] },
        );

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
            key: 'last_error',
            header: t('stores.columns.last_error'),
            cell: (row) =>
                row.last_error ? (
                    <span className="text-danger text-sm">
                        {row.last_error}
                    </span>
                ) : (
                    <span className="text-muted-foreground text-sm">—</span>
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
                <div className="flex flex-wrap justify-end gap-1">
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={show(row.id)}>
                            {t('stores.actions.view')}
                        </Link>
                    </Button>
                    {row.status === 'disconnected' ? (
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={edit(row.id)}>
                                {t('stores.actions.reconnect')}
                            </Link>
                        </Button>
                    ) : (
                        <>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    post(
                                        ExternalStoreController.test.url(
                                            row.id,
                                        ),
                                    )
                                }
                            >
                                {t('stores.actions.test')}
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    post(
                                        ExternalStoreController.sync.url(
                                            row.id,
                                        ),
                                    )
                                }
                            >
                                {t('stores.actions.sync')}
                            </Button>
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={edit(row.id)}>
                                    {t('stores.actions.edit')}
                                </Link>
                            </Button>
                        </>
                    )}
                </div>
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
                    meta={
                        allowance.allowed && (
                            <span className="text-muted-foreground text-sm">
                                {allowance.limit === null
                                    ? t('stores.allowance_unlimited', {
                                          used: count(allowance.used),
                                      })
                                    : t('stores.allowance', {
                                          used: count(allowance.used),
                                          limit: count(allowance.limit),
                                      })}
                            </span>
                        )
                    }
                    actions={
                        mayConnect ? (
                            <Button asChild size="sm">
                                <Link href={create()}>
                                    <Plus aria-hidden="true" />
                                    {t('stores.connect')}
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

                {!allowance.allowed && (
                    <Notice tone="warning">{t('stores.not_entitled')}</Notice>
                )}

                {allowance.allowed && atLimit && (
                    <Notice tone="warning">
                        {t('stores.limit_reached', {
                            limit: count(allowance.limit ?? 0),
                        })}
                    </Notice>
                )}

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
                            {row.last_error && (
                                <div className="text-danger text-xs">
                                    {row.last_error}
                                </div>
                            )}
                        </div>
                    )}
                    emptyState={
                        <EmptyState
                            icon={Store}
                            title={t('stores.empty')}
                            description={t('stores.empty_help')}
                            action={
                                mayConnect ? (
                                    <Button asChild>
                                        <Link href={create()}>
                                            {t('stores.connect')}
                                        </Link>
                                    </Button>
                                ) : undefined
                            }
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
