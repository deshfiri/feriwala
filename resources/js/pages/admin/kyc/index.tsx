import { Head, Link } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import { index, show } from '@/routes/admin/kyc';
import type { Column, KycQueueRow, Paginator } from '@/types';

const ALL = 'all';

/**
 * The only prop a filter, sort, or page change needs back. Shared by both
 * readers of the query state so neither falls back to refetching the whole
 * page — translations and permissions do not change because a reviewer picked
 * a status.
 */
const RELOAD_PROPS = ['submissions'];

/**
 * The KYC review queue (§7.3).
 *
 * Ordered oldest-first by default, and the waiting column is the reason: the
 * question a reviewer is answering is "who has been left longest", which a
 * submission date alone makes them work out row by row.
 */
export default function AdminKycIndex({
    submissions,
    statuses,
}: {
    submissions: Paginator<KycQueueRow>;
    statuses: { value: string; label: string }[];
}) {
    const { t, locale } = useTranslation();
    const { getFilter, setFilter } = useTableQuery({ only: RELOAD_PROPS });

    const formatDate = (value: string | null) =>
        value === null
            ? '—'
            : new Date(value).toLocaleDateString(locale, {
                  day: 'numeric',
                  month: 'short',
                  year: 'numeric',
              });

    const formatWaiting = (days: number | null) => {
        if (days === null) return '—';
        if (days === 0) return t('kyc.waiting.today');
        if (days === 1) return t('kyc.waiting.one_day');

        return t('kyc.waiting.days', { count: days });
    };

    const columns: Column<KycQueueRow>[] = [
        {
            key: 'applicant',
            header: t('kyc.columns.applicant'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate font-medium">
                        {row.applicant.name ?? '—'}
                    </div>
                    <div className="text-muted-foreground truncate text-xs">
                        {row.applicant.email ?? ''}
                    </div>
                </div>
            ),
        },
        {
            key: 'country',
            header: t('kyc.columns.country'),
            priority: 'secondary',
            cell: (row) => row.applicant.country ?? '—',
        },
        {
            key: 'round',
            header: t('kyc.columns.round'),
            sortable: true,
            align: 'end',
            width: '5rem',
            priority: 'secondary',
            cell: (row) => row.round,
        },
        {
            key: 'submitted_at',
            header: t('kyc.columns.submitted'),
            sortable: true,
            cell: (row) => formatDate(row.submitted_at),
        },
        {
            key: 'waiting',
            header: t('kyc.columns.waiting'),
            align: 'end',
            cell: (row) => (
                <span
                    // Past a week the queue has a problem, and the reviewer
                    // should see it without reading the date.
                    className={
                        (row.waiting_days ?? 0) >= 7
                            ? 'text-danger font-medium'
                            : undefined
                    }
                >
                    {formatWaiting(row.waiting_days)}
                </span>
            ),
        },
        {
            key: 'status',
            header: t('kyc.columns.status'),
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
                    <Link href={show(row.id)}>{t('kyc.queue.open')}</Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title={t('kyc.queue.title')} />

            <PageContainer>
                <PageHeader
                    title={t('kyc.queue.title')}
                    description={t('kyc.queue.description')}
                />

                <DataTable
                    columns={columns}
                    paginator={submissions}
                    rowKey={(row) => row.id}
                    caption={t('kyc.queue.caption')}
                    searchPlaceholder={t('kyc.queue.search_placeholder')}
                    onlyReload={RELOAD_PROPS}
                    filters={
                        <Select
                            value={getFilter('status') ?? ALL}
                            onValueChange={(value) =>
                                setFilter(
                                    'status',
                                    value === ALL ? undefined : value,
                                )
                            }
                        >
                            <SelectTrigger
                                size="sm"
                                className="w-44"
                                aria-label={t('kyc.columns.status')}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>
                                    {t('kyc.queue.all_statuses')}
                                </SelectItem>
                                {statuses.map((status) => (
                                    <SelectItem
                                        key={status.value}
                                        value={status.value}
                                    >
                                        {status.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    }
                    emptyState={
                        <EmptyState
                            icon={ShieldCheck}
                            title={t('kyc.queue.empty_title')}
                            description={t('kyc.queue.empty_description')}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

AdminKycIndex.layout = {
    breadcrumbs: [
        {
            title: 'KYC review',
            href: index(),
        },
    ],
};
