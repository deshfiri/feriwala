import { Head, Link } from '@inertiajs/react';
import { UserCog } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { index as rolesIndex } from '@/routes/admin/roles';
import {
    index as staffIndex,
    show as staffShow,
} from '@/routes/admin/staff-access';
import type { StatusTone } from '@/lib/status';
import type { Column, Paginator } from '@/types';

type StaffRow = {
    public_id: string;
    name: string;
    email: string;
    role_label: string | null;
    requires_two_factor: boolean;
    two_factor_enabled: boolean;
    identity_status_label: string;
    identity_status_tone: StatusTone;
};

type Props = {
    staff: Paginator<StaffRow>;
    filters: { search: string };
};

/**
 * Platform staff and the role each one holds (commit-order item 6).
 *
 * Every login here has no business account at all (D23) -- a business
 * account's own staff are a different scope entirely, reached from the
 * account dossier instead.
 */
export default function StaffAccessIndex({ staff }: Props) {
    const { t } = useTranslation();

    const columns: Column<StaffRow>[] = [
        {
            key: 'name',
            header: t('access.staff.columns.name'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="font-medium">{row.name}</div>
                    <div className="text-muted-foreground truncate text-xs">
                        {row.email}
                    </div>
                </div>
            ),
        },
        {
            key: 'role',
            header: t('access.staff.columns.role'),
            cell: (row) => row.role_label ?? t('access.staff.no_role'),
        },
        {
            key: 'two_factor',
            header: t('access.staff.columns.two_factor'),
            priority: 'secondary',
            cell: (row) =>
                row.requires_two_factor ? (
                    <StatusPill
                        tone={row.two_factor_enabled ? 'success' : 'danger'}
                        label={
                            row.two_factor_enabled
                                ? t('access.staff.two_factor_enabled')
                                : t('access.staff.two_factor_disabled')
                        }
                    />
                ) : (
                    '—'
                ),
        },
        {
            key: 'status',
            header: t('access.staff.columns.status'),
            cell: (row) => (
                <StatusPill
                    tone={row.identity_status_tone}
                    label={row.identity_status_label}
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
                    <Link href={staffShow(row.public_id)}>
                        {t('access.staff.change_role')}
                    </Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title={t('access.staff.title')} />

            <PageContainer>
                <PageHeader
                    title={t('access.staff.title')}
                    description={t('access.staff.description')}
                    back={{
                        href: rolesIndex(),
                        label: t('access.roles.title'),
                    }}
                />

                <DataTable
                    columns={columns}
                    paginator={staff}
                    rowKey={(row) => row.public_id}
                    caption={t('access.staff.title')}
                    searchPlaceholder={t('access.staff.search_placeholder')}
                    onlyReload={['staff']}
                    renderCard={(row) => (
                        <Link
                            href={staffShow(row.public_id)}
                            className="flex items-center justify-between gap-3"
                        >
                            <div className="min-w-0">
                                <div className="truncate font-medium">
                                    {row.name}
                                </div>
                                <div className="text-muted-foreground truncate text-xs">
                                    {row.role_label ??
                                        t('access.staff.no_role')}
                                </div>
                            </div>
                            <StatusPill
                                tone={row.identity_status_tone}
                                label={row.identity_status_label}
                            />
                        </Link>
                    )}
                    emptyState={
                        <EmptyState
                            icon={UserCog}
                            title={t('access.staff.empty_title')}
                            description={t('access.staff.empty_description')}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

StaffAccessIndex.layout = {
    breadcrumbs: [{ title: 'access.staff.title', href: staffIndex() }],
};
