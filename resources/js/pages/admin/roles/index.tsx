import { Head, Link } from '@inertiajs/react';
import { Plus, ShieldAlert, ShieldCheck } from 'lucide-react';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import {
    create as roleCreate,
    index as rolesIndex,
    show as roleShow,
} from '@/routes/admin/roles';
import { index as permissionsIndex } from '@/routes/admin/permissions';

type RoleSummary = {
    key: string;
    label: string;
    type: 'system' | 'custom';
    description?: string | null;
    is_protected: boolean;
    is_archived: boolean;
    requires_two_factor: boolean;
    permission_count: number;
    holder_count: number;
};

type Props = {
    roles: RoleSummary[];
    can: { create: boolean };
};

/**
 * The platform's roles: the twenty-one fixed PlatformRole cases (read-only,
 * commit-order item 6) plus any custom, database-backed role Role and
 * Permission management has created.
 */
export default function RolesIndex({ roles, can }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('access.roles.title')} />

            <PageContainer>
                <PageHeader
                    title={t('access.roles.title')}
                    description={t('access.roles.description')}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <Button variant="outline" asChild>
                                <Link href={permissionsIndex()}>
                                    {t('access.roles.manage_permissions')}
                                </Link>
                            </Button>
                            {can.create && (
                                <Button asChild>
                                    <Link href={roleCreate()}>
                                        <Plus
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        {t('access.roles.create')}
                                    </Link>
                                </Button>
                            )}
                        </div>
                    }
                />

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {roles.map((role) => (
                        <Link
                            key={role.key}
                            href={roleShow(role.key)}
                            className="border-border hover:bg-accent/50 focus-visible:ring-ring flex flex-col gap-3 rounded-xl border p-4 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <div className="flex items-start justify-between gap-2">
                                <span className="text-sm font-medium">
                                    {role.label}
                                </span>
                                <div className="flex gap-1">
                                    {role.is_protected && (
                                        <StatusPill
                                            tone="info"
                                            label={t('access.roles.protected')}
                                            icon={ShieldCheck}
                                        />
                                    )}
                                    {role.type === 'custom' &&
                                        !role.is_archived && (
                                            <StatusPill
                                                tone="neutral"
                                                label={t('access.roles.custom')}
                                            />
                                        )}
                                    {role.is_archived && (
                                        <StatusPill
                                            tone="neutral"
                                            label={t('access.roles.archived')}
                                        />
                                    )}
                                </div>
                            </div>

                            <div className="text-muted-foreground flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
                                <span>
                                    {t('access.roles.permission_count', {
                                        count: role.permission_count,
                                    })}
                                </span>
                                <span>
                                    {t('access.roles.holder_count', {
                                        count: role.holder_count,
                                    })}
                                </span>
                                {role.requires_two_factor && (
                                    <span className="inline-flex items-center gap-1">
                                        <ShieldAlert
                                            aria-hidden="true"
                                            className="size-3.5"
                                        />
                                        {t('access.roles.requires_two_factor')}
                                    </span>
                                )}
                            </div>
                        </Link>
                    ))}
                </div>
            </PageContainer>
        </>
    );
}

RolesIndex.layout = {
    breadcrumbs: [{ title: 'access.roles.title', href: rolesIndex() }],
};
