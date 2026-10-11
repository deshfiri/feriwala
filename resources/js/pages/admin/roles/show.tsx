import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import ReasonDialog from '@/components/forms/reason-dialog';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import EmptyState from '@/components/states/empty-state';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import RolesController from '@/actions/App/Http/Controllers/Admin/RolesController';
import { index as rolesIndex } from '@/routes/admin/roles';
import type { StatusTone } from '@/lib/status';
import ReasonTextarea from '@/components/forms/reason-textarea';

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

type PermissionGroup = {
    module: string;
    actions: string[];
};

type PermissionOption = { name: string; label: string };
type EditablePermissionGroup = {
    module: string;
    permissions: PermissionOption[];
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
    editablePermissionGroups?: EditablePermissionGroup[];
    assignedPermissionNames?: string[];
    can: { manage: boolean; clone: boolean };
};

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * One role's detail: the twenty-one fixed PlatformRole cases stay exactly
 * the read-only view they always were, while a custom role additionally
 * gets an editable permission set, a clone action, and an archive action
 * (Role and Permission management).
 */
export default function RoleShow({
    role,
    permissionGroups,
    holders,
    editablePermissionGroups,
    assignedPermissionNames,
    can,
}: Props) {
    const { t } = useTranslation();
    const [cloning, setCloning] = useState(false);
    const [archiving, setArchiving] = useState(false);

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
                            {role.type === 'custom' && !role.is_archived && (
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
                    description={
                        role.description ??
                        t('access.roles.holder_count', {
                            count: role.holder_count,
                        })
                    }
                    actions={
                        can.clone && (
                            <Button
                                variant="outline"
                                onClick={() => setCloning(true)}
                            >
                                {t('access.roles.clone')}
                            </Button>
                        )
                    }
                />

                {role.type === 'custom' && role.is_archived && (
                    <SectionCard>
                        <p className="text-muted-foreground text-sm">
                            {t('access.roles.archived_notice')}
                        </p>
                    </SectionCard>
                )}

                {can.manage &&
                editablePermissionGroups &&
                assignedPermissionNames ? (
                    <SectionCard title={t('access.roles.edit_permissions')}>
                        <Form
                            {...RolesController.update.form(role.key)}
                            options={{ preserveScroll: true }}
                            className="grid gap-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="role-edit-name">
                                            {t('access.roles.name')}
                                        </Label>
                                        <Input
                                            id="role-edit-name"
                                            name="name"
                                            defaultValue={role.key}
                                            required
                                            autoComplete="off"
                                        />
                                        <InputError message={errors.name} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="role-edit-description">
                                            {t('access.roles.description')}
                                        </Label>
                                        <textarea
                                            id="role-edit-description"
                                            name="description"
                                            rows={2}
                                            defaultValue={
                                                role.description ?? ''
                                            }
                                            className={controlClass}
                                        />
                                        <InputError
                                            message={errors.description}
                                        />
                                    </div>

                                    <fieldset className="grid gap-4">
                                        <legend className="text-xs font-medium">
                                            {t(
                                                'access.roles.permissions_heading',
                                            )}
                                        </legend>
                                        {editablePermissionGroups.map(
                                            (group) => (
                                                <div
                                                    key={group.module}
                                                    className="grid gap-1.5"
                                                >
                                                    <div className="text-xs font-semibold">
                                                        {group.module}
                                                    </div>
                                                    {group.permissions.map(
                                                        (permission) => (
                                                            <label
                                                                key={
                                                                    permission.name
                                                                }
                                                                className="flex items-center gap-2 text-sm"
                                                            >
                                                                <input
                                                                    type="checkbox"
                                                                    name="permissions[]"
                                                                    value={
                                                                        permission.name
                                                                    }
                                                                    defaultChecked={assignedPermissionNames.includes(
                                                                        permission.name,
                                                                    )}
                                                                    className="accent-brand"
                                                                />
                                                                {
                                                                    permission.label
                                                                }
                                                            </label>
                                                        ),
                                                    )}
                                                </div>
                                            ),
                                        )}
                                        <InputError
                                            message={errors.permissions}
                                        />
                                    </fieldset>

                                    <div className="grid gap-2">
                                        <Label htmlFor="role-edit-reason">
                                            {t('access.roles.reason')}
                                        </Label>
                                        <ReasonTextarea
                                            context="access"
                                            id="role-edit-reason"
                                            name="reason"
                                            rows={2}
                                            required
                                            minLength={5}
                                            className={controlClass}
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            {t('access.roles.reason_help')}
                                        </p>
                                        <InputError message={errors.reason} />
                                    </div>

                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="w-fit"
                                    >
                                        {processing && <Spinner />}
                                        {t('access.roles.submit')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </SectionCard>
                ) : (
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
                )}

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

                {role.type === 'custom' && can.manage && (
                    <SectionCard
                        title={t('access.roles.archive')}
                        tone="destructive"
                    >
                        <Button
                            variant="destructive"
                            onClick={() => setArchiving(true)}
                        >
                            {t('access.roles.archive')}
                        </Button>
                    </SectionCard>
                )}
            </PageContainer>

            {can.clone && (
                <Dialog
                    open={cloning}
                    onOpenChange={(next) => !next && setCloning(false)}
                >
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>
                                {t('access.roles.clone_title')}
                            </DialogTitle>
                            <DialogDescription>
                                {t('access.roles.clone_description')}
                            </DialogDescription>
                        </DialogHeader>

                        <Form
                            {...RolesController.clone.form(role.key)}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setCloning(false)}
                            className="grid gap-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="clone-name">
                                            {t('access.roles.name')}
                                        </Label>
                                        <Input
                                            id="clone-name"
                                            name="name"
                                            required
                                            autoComplete="off"
                                        />
                                        <InputError message={errors.name} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="clone-description">
                                            {t('access.roles.description')}
                                        </Label>
                                        <textarea
                                            id="clone-description"
                                            name="description"
                                            rows={2}
                                            className={controlClass}
                                        />
                                        <InputError
                                            message={errors.description}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="clone-reason">
                                            {t('access.roles.reason')}
                                        </Label>
                                        <ReasonTextarea
                                            context="access"
                                            id="clone-reason"
                                            name="reason"
                                            rows={2}
                                            required
                                            minLength={5}
                                            className={controlClass}
                                        />
                                        <InputError message={errors.reason} />
                                    </div>

                                    <DialogFooter className="gap-2 sm:gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => setCloning(false)}
                                            disabled={processing}
                                        >
                                            {t('common.actions.cancel')}
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {t('access.roles.clone')}
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            )}

            <ReasonDialog
                open={archiving}
                onClose={() => setArchiving(false)}
                title={t('access.roles.archive_title')}
                description={t('access.roles.archive_description')}
                form={RolesController.archive.form(role.key)}
                confirmLabel={t('access.roles.archive')}
                destructive
            />
        </>
    );
}

RoleShow.layout = {
    breadcrumbs: [{ title: 'access.roles.title', href: rolesIndex() }],
};
