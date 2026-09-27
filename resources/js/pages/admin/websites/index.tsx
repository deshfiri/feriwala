import { Head, Link } from '@inertiajs/react';
import { MonitorSmartphone } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import { websiteHealthTone, websiteStatusTone } from '@/lib/website';
import { index, show } from '@/routes/admin/websites';
import type { Column, Paginator } from '@/types';
import type { WebsiteSummary } from '@/types/website';

type Props = {
    websites: Paginator<WebsiteSummary>;
    filters: { search: string | null; status: string | null };
    statuses: { value: string; label: string }[];
};

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-lg border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Every partner storefront on the platform (§16.3, §16.4).
 *
 * Websites with charges outstanding say so beside the status, because that is
 * the usual reason a build has not started and the usual thing a support call
 * is about.
 */
export default function AdminWebsites({ websites, filters, statuses }: Props) {
    const { t, locale } = useTranslation();
    const { setFilter } = useTableQuery({ only: ['websites', 'filters'] });

    const columns: Column<WebsiteSummary>[] = [
        {
            key: 'name',
            header: t('website.show.address'),
            cell: (row) => (
                <div className="min-w-0">
                    <Link
                        href={show(row.id)}
                        className="block truncate text-sm font-medium underline-offset-4 hover:underline"
                    >
                        {row.name}
                    </Link>
                    <div className="text-muted-foreground truncate font-mono text-xs">
                        {row.host}
                    </div>
                </div>
            ),
        },
        {
            key: 'account',
            header: t('website.admin.account'),
            cell: (row) => (
                <span className="text-sm">{row.account?.name ?? '—'}</span>
            ),
        },
        {
            key: 'status',
            header: t('website.show.status'),
            cell: (row) => (
                <div className="space-y-1">
                    <StatusPill
                        tone={websiteStatusTone(row.status)}
                        label={row.status_label}
                    />
                    {row.outstanding_charges > 0 && (
                        <div className="text-warning-foreground text-xs font-medium">
                            {t('website.index.outstanding', {
                                count: String(row.outstanding_charges),
                            })}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'health',
            header: t('website.show.connection'),
            priority: 'secondary',
            cell: (row) => (
                <div className="space-y-1">
                    <StatusPill
                        tone={websiteHealthTone(row.connection_health)}
                        label={t(`website.health.${row.connection_health}`)}
                    />
                    <div className="text-muted-foreground text-xs">
                        {row.last_synced_at
                            ? new Date(row.last_synced_at).toLocaleString(
                                  locale,
                              )
                            : t('website.show.never_synced')}
                    </div>
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title={t('website.admin_title')} />

            <PageContainer>
                <PageHeader
                    title={t('website.admin_title')}
                    description={t('website.admin_subtitle')}
                />

                <DataTable
                    columns={columns}
                    paginator={websites}
                    rowKey={(row) => row.id}
                    caption={t('website.admin_title')}
                    searchPlaceholder={t('website.admin.search')}
                    onlyReload={['websites', 'filters']}
                    filters={
                        <select
                            aria-label={t('website.admin.status_filter')}
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
                                {t('website.admin.all_statuses')}
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
                            <Link
                                href={show(row.id)}
                                className="block truncate text-sm font-medium underline-offset-4 hover:underline"
                            >
                                {row.name}
                            </Link>
                            <div className="text-muted-foreground truncate font-mono text-xs">
                                {row.host}
                            </div>
                            <StatusPill
                                tone={websiteStatusTone(row.status)}
                                label={row.status_label}
                            />
                        </div>
                    )}
                    emptyState={
                        <EmptyState
                            icon={MonitorSmartphone}
                            title={t('website.admin.empty')}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

AdminWebsites.layout = {
    breadcrumbs: [
        {
            title: 'nav.partner_websites',
            href: index(),
        },
    ],
};
