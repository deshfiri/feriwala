import { Head } from '@inertiajs/react';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import EmptyState from '@/components/states/empty-state';
import { useTranslation } from '@/hooks/use-translation';
import { index as rolesIndex } from '@/routes/admin/roles';
import type { StatusTone } from '@/lib/status';

type RoleSummary = {
    key: string;
    label: string;
    is_protected: boolean;
    requires_two_factor: boolean;
    permission_count: number;
    holder_count: number;
};

type PermissionGroup = {
    module: string;
    actions: string[];
};

type Holder = {
    public_id: string;
    name: string;
    email: string;
    identity_status_label: string;
    identity_status_tone: StatusTone;
};

type Props = {
    role: RoleSummary;
    permissionGroups: PermissionGroup[];
    holders: Holder[];
};

export default function RoleShow({ role, permissionGroups, holders }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={role.label} />

            <PageContainer width="narrow">
                <PageHeader
                    title={role.label}
                    back={{
                        href: rolesIndex(),
                        label: t('access.roles.back'),
                    }}
                    meta={
                        <>
                            {role.is_protected && (
                                <StatusPill
                                    tone="info"
                                    label={t('access.roles.protected')}
                                />
                            )}
                            {role.requires_two_factor && (
                                <StatusPill
                                    tone="warning"
                                    label={t(
                                        'access.roles.requires_two_factor',
                                    )}
                                />
                            )}
                        </>
                    }
                    description={t('access.roles.holder_count', {
                        count: role.holder_count,
                    })}
                />

                <SectionCard title={t('access.roles.permissions_heading')}>
                    {role.is_protected ? (
                        <p className="text-muted-foreground text-sm">
                            {t('access.roles.grants_everything')}
                        </p>
                    ) : (
                        <ul className="divide-border divide-y">
                            {permissionGroups.map((group) => (
                                <li
                                    key={group.module}
                                    className="py-3 first:pt-0 last:pb-0"
                                >
                                    <div className="text-sm font-medium">
                                        {group.module}
                                    </div>
                                    <div className="text-muted-foreground text-xs">
                                        {group.actions.join(', ')}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>

                <SectionCard title={t('access.roles.holders_heading')}>
                    {holders.length === 0 ? (
                        <EmptyState
                            description={t('access.roles.no_holders')}
                        />
                    ) : (
                        <ul className="divide-border divide-y">
                            {holders.map((holder) => (
                                <li
                                    key={holder.public_id}
                                    className="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                                >
                                    <div className="min-w-0">
                                        <div className="truncate text-sm font-medium">
                                            {holder.name}
                                        </div>
                                        <div className="text-muted-foreground truncate text-xs">
                                            {holder.email}
                                        </div>
                                    </div>
                                    <StatusPill
                                        tone={holder.identity_status_tone}
                                        label={holder.identity_status_label}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </PageContainer>
        </>
    );
}

RoleShow.layout = {
    breadcrumbs: [{ title: 'access.roles.title', href: rolesIndex() }],
};
