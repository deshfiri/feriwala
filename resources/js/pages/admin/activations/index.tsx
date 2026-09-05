import { Head, Link } from '@inertiajs/react';
import { UserCheck } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { index, show } from '@/routes/admin/activations';
import type { ActivationQueueRow, Column, Paginator } from '@/types';

/**
 * The activation approval queue (§5.1, §44).
 *
 * Everything listed here has already cleared verification, KYC, and payment —
 * the queue exists for the one thing left, which is a person deciding. Ordered
 * by how long each account has been waiting on us rather than by when it
 * registered, because those are different questions.
 */
export default function AdminActivationsIndex({
    accounts,
}: {
    accounts: Paginator<ActivationQueueRow>;
}) {
    const { t, locale } = useTranslation();

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
        if (days === 0) return t('activation.waiting.today');
        if (days === 1) return t('activation.waiting.one_day');

        return t('activation.waiting.days', { count: days });
    };

    const columns: Column<ActivationQueueRow>[] = [
        {
            key: 'name',
            header: t('activation.columns.account'),
            sortable: true,
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate font-medium">{row.name}</div>
                    {/* The business is the subject; the owner is how a reviewer
                        recognises it. Both, because the two are now different
                        things and a queue showing only one is ambiguous. */}
                    <div className="text-muted-foreground truncate text-xs">
                        {[row.owner, row.email].filter(Boolean).join(' · ')}
                    </div>
                </div>
            ),
        },
        {
            key: 'country',
            header: t('activation.columns.country'),
            priority: 'secondary',
            cell: (row) => row.country ?? '—',
        },
        {
            key: 'ready_since',
            header: t('activation.columns.ready_since'),
            sortable: true,
            cell: (row) => formatDate(row.ready_since),
        },
        {
            key: 'waiting',
            header: t('activation.columns.waiting'),
            align: 'end',
            cell: (row) => (
                <span
                    // An account fully paid up and waiting a week on us is a
                    // service failure, and should read as one.
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
            header: t('activation.columns.status'),
            priority: 'secondary',
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
                    <Link href={show(row.id)}>
                        {t('activation.queue.open')}
                    </Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title={t('activation.queue.title')} />

            <div className="space-y-6 p-4">
                <PageHeader
                    title={t('activation.queue.title')}
                    description={t('activation.queue.description')}
                />

                <DataTable
                    columns={columns}
                    paginator={accounts}
                    rowKey={(row) => row.id}
                    caption={t('activation.queue.caption')}
                    searchPlaceholder={t('activation.queue.search_placeholder')}
                    onlyReload={['accounts']}
                    emptyState={
                        <EmptyState
                            icon={UserCheck}
                            title={t('activation.queue.empty_title')}
                            description={t(
                                'activation.queue.empty_description',
                            )}
                        />
                    }
                />
            </div>
        </>
    );
}

AdminActivationsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Activation approvals',
            href: index(),
        },
    ],
};
