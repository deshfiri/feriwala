import { Head, Link } from '@inertiajs/react';
import { ShieldAlert, ShieldCheck, UserCog } from 'lucide-react';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { index as rolesIndex, show as roleShow } from '@/routes/admin/roles';
import { index as staffIndex } from '@/routes/admin/staff-access';

type RoleSummary = {
    key: string;
    label: string;
    is_protected: boolean;
    requires_two_factor: boolean;
    permission_count: number;
    holder_count: number;
};

type Props = {
    roles: RoleSummary[];
};

/**
 * The twenty-one PlatformRole cases (commit-order item 6).
 *
 * Read-only: this application has no safe way to create or clone a role
 * without a code deploy, so there is no "add role" button here — only the
 * fixed catalogue to browse and search.
 */
export default function RolesIndex({ roles }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('access.roles.title')} />

            <PageContainer>
                <PageHeader
                    title={t('access.roles.title')}
                    description={t('access.roles.description')}
                    actions={
                        <Button variant="outline" size="sm" asChild>
                            <Link href={staffIndex()}>
                                <UserCog aria-hidden="true" />
                                {t('access.staff.title')}
                            </Link>
                        </Button>
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
                                {role.is_protected && (
                                    <StatusPill
                                        tone="info"
                                        label={t('access.roles.protected')}
                                        icon={ShieldCheck}
                                    />
                                )}
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
