import { Head, Link } from '@inertiajs/react';
import { UserX } from 'lucide-react';
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
import { index, show } from '@/routes/admin/deletions';
import type { Column, DeletionQueueRow, Paginator } from '@/types';

/** The only prop a filter, search or page change needs back. */
const RELOAD_PROPS = ['requests'];

/**
 * Requests to have an account erased (§5.3).
 *
 * Oldest first, with how long each has waited: an erasure request is a person's
 * right to be forgotten, and the ones left longest are the ones to answer first.
 * Pending is what the queue shows by default; the filter reaches the rest.
 */
export default function AdminDeletionsIndex({
    requests,
    statuses,
}: {
    requests: Paginator<DeletionQueueRow>;
    statuses: { value: string; label: string }[];
}) {
    const { t, locale } = useTranslation();
    const { getFilter, setFilter } = useTableQuery({ only: RELOAD_PROPS });

    const columns: Column<DeletionQueueRow>[] = [
        {
            key: 'requester',
            header: t('deletion.admin.columns.requester'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate font-medium">
                        {row.name ?? '—'}
                    </div>
                    <div className="text-muted-foreground truncate text-xs">
                        {row.email ?? ''}
                    </div>
                </div>
            ),
        },
        {
            key: 'kind',
            header: t('deletion.admin.columns.kind'),
            priority: 'secondary',
            cell: (row) => t(`deletion.kind.${row.kind}`),
        },
        {
            key: 'requested_at',
            header: t('deletion.admin.columns.requested'),
            cell: (row) =>
                new Date(row.requested_at).toLocaleDateString(locale, {
                    day: 'numeric',
                    month: 'short',
                    year: 'numeric',
                }),
        },
        {
            key: 'waiting',
            header: t('deletion.admin.columns.waiting'),
            align: 'end',
            cell: (row) =>
                t('deletion.admin.days', { count: row.waiting_days }),
        },
        {
            key: 'status',
            header: t('deletion.admin.columns.status'),
            cell: (row) => (
                <StatusPill
                    tone={row.status === 'pending' ? 'warning' : 'neutral'}
                    label={row.status_label}
                />
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
                        {t('deletion.admin.review')}
                    </Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title={t('deletion.admin.title')} />

            <PageContainer>
                <PageHeader
                    title={t('deletion.admin.title')}
                    description={t('deletion.admin.description')}
                />

                <DataTable
                    columns={columns}
                    paginator={requests}
                    rowKey={(row) => row.id}
                    caption={t('deletion.admin.caption')}
                    searchPlaceholder={t('deletion.admin.search')}
                    onlyReload={RELOAD_PROPS}
                    filters={
                        <Select
                            value={getFilter('status') ?? 'pending'}
                            onValueChange={(value) =>
                                setFilter('status', value)
                            }
                        >
                            <SelectTrigger
                                size="sm"
                                className="w-44"
                                aria-label={t('deletion.admin.columns.status')}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
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
                            icon={UserX}
                            title={t('deletion.admin.empty_title')}
                            description={t('deletion.admin.empty_description')}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

AdminDeletionsIndex.layout = {
    breadcrumbs: [{ title: 'nav.deletion_requests', href: index() }],
};
