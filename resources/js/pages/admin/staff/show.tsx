import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { confirm as confirmPassword } from '@/routes/password';
import PlatformStaffController from '@/actions/App/Http/Controllers/Admin/PlatformStaffController';
import { index as staffIndex } from '@/routes/admin/staff';
import type { StatusTone } from '@/lib/status';
import ReasonTextarea from '@/components/forms/reason-textarea';

type StaffMember = {
    public_id: string;
    name: string;
    email: string;
    mobile: string | null;
    role_label: string | null;
    requires_two_factor: boolean;
    two_factor_enabled: boolean;
    identity_status: 'active' | 'locked' | 'suspended' | 'closed';
    identity_status_label: string;
    identity_status_tone: StatusTone;
    last_login_at: string | null;
};

type PermissionGroup = {
    module: string;
    actions: string[];
};

type RoleOption = {
    key: string;
    label: string;
};

type Props = {
    staffMember: StaffMember;
    permissionGroups: PermissionGroup[];
    roles: RoleOption[];
    assignedRoles: string[];
    can: { manageRoles: boolean; manageStatus: boolean };
    password_confirmed: boolean;
};

type StatusAction = 'activate' | 'suspend' | 'deactivate';

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * One platform staff member: their effective permissions, role assignment,
 * and sign-in status (Platform Staff management).
 *
 * Every safety guard lives in AssignPlatformRole, ChangeIdentityAccess and
 * UserPolicy -- this page only renders what `can` and `roles` already
 * decided server-side; a role missing from the `roles` list (Super Admin,
 * unless the viewer already holds it) is not offered here because granting
 * it would itself be the escalation the backend refuses.
 */
export default function PlatformStaffShow({
    staffMember,
    permissionGroups,
    roles,
    assignedRoles,
    can,
    password_confirmed: passwordConfirmed,
}: Props) {
    const { t } = useTranslation();
    const [statusAction, setStatusAction] = useState<StatusAction | null>(null);

    const statusActionRoute = {
        activate: PlatformStaffController.activate,
        suspend: PlatformStaffController.suspend,
        deactivate: PlatformStaffController.deactivate,
    }[statusAction ?? 'activate'];

    return (
        <>
            <Head title={staffMember.name} />

            <PageContainer width="narrow">
                <PageHeader
                    title={staffMember.name}
                    description={staffMember.email}
                    back={{
                        href: staffIndex(),
                        label: t('access.staff.back'),
                    }}
                    meta={
                        <StatusPill
                            tone={staffMember.identity_status_tone}
                            label={staffMember.identity_status_label}
                        />
                    }
                    actions={
                        can.manageRoles && (
                            <Form
                                {...PlatformStaffController.resendInvite.form(
                                    staffMember.public_id,
                                )}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        {processing && <Spinner />}
                                        {t('access.staff.resend_invite')}
                                    </Button>
                                )}
                            </Form>
                        )
                    }
                />

                {!passwordConfirmed &&
                    (can.manageRoles || can.manageStatus) && (
                        <SectionCard>
                            <Button variant="outline" asChild>
                                <Link href={confirmPassword()}>
                                    {t('access.staff.confirm_password')}
                                </Link>
                            </Button>
                        </SectionCard>
                    )}

                {can.manageStatus && passwordConfirmed && (
                    <SectionCard title={t('access.staff.sign_in_status')}>
                        {statusAction === null ? (
                            <div className="flex flex-wrap gap-2">
                                {staffMember.identity_status !== 'active' && (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            setStatusAction('activate')
                                        }
                                    >
                                        {t('access.staff.activate')}
                                    </Button>
                                )}
                                {staffMember.identity_status !== 'suspended' &&
                                    staffMember.identity_status !==
                                        'closed' && (
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                setStatusAction('suspend')
                                            }
                                        >
                                            {t('access.staff.suspend')}
                                        </Button>
                                    )}
                                {staffMember.identity_status !== 'closed' && (
                                    <Button
                                        variant="destructive"
                                        onClick={() =>
                                            setStatusAction('deactivate')
                                        }
                                    >
                                        {t('access.staff.deactivate')}
                                    </Button>
                                )}
                            </div>
                        ) : (
                            <Form
                                {...statusActionRoute.form(
                                    staffMember.public_id,
                                )}
                                options={{ preserveScroll: true }}
                                onSuccess={() => setStatusAction(null)}
                                className="grid gap-4"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <p className="text-sm font-medium">
                                            {t(
                                                `access.staff.confirm_${statusAction}`,
                                            )}
                                        </p>
                                        {statusAction === 'deactivate' && (
                                            <p className="text-danger text-xs">
                                                {t(
                                                    'access.staff.deactivate_is_permanent',
                                                )}
                                            </p>
                                        )}
                                        <div className="grid gap-2">
                                            <Label htmlFor="status-reason">
                                                {t('access.staff.reason')}
                                            </Label>
                                            <ReasonTextarea
                                                context="access"
                                                id="status-reason"
                                                name="reason"
                                                rows={2}
                                                required
                                                minLength={5}
                                                className={controlClass}
                                            />
                                            <InputError
                                                message={errors.reason}
                                            />
                                        </div>
                                        <div className="flex gap-2">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                onClick={() =>
                                                    setStatusAction(null)
                                                }
                                            >
                                                {t('common.actions.cancel')}
                                            </Button>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                {processing && <Spinner />}
                                                {t(
                                                    `access.staff.${statusAction}`,
                                                )}
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        )}
                    </SectionCard>
                )}

                {can.manageRoles && passwordConfirmed && (
                    <SectionCard title={t('access.staff.manage_roles')}>
                        <Form
                            {...PlatformStaffController.updateRoles.form(
                                staffMember.public_id,
                            )}
                            options={{ preserveScroll: true }}
                            className="grid gap-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <fieldset className="grid gap-2">
                                        <legend className="text-xs font-medium">
                                            {t('access.staff.roles')}
                                        </legend>
                                        {roles.map((role) => (
                                            <label
                                                key={role.key}
                                                className="flex items-center gap-2 text-sm"
                                            >
                                                <input
                                                    type="checkbox"
                                                    name="roles[]"
                                                    value={role.key}
                                                    defaultChecked={assignedRoles.includes(
                                                        role.key,
                                                    )}
                                                    className="accent-brand"
                                                />
                                                {role.label}
                                            </label>
                                        ))}
                                        <InputError message={errors.roles} />
                                    </fieldset>

                                    <div className="grid gap-2">
                                        <Label htmlFor="roles-reason">
                                            {t('access.staff.reason')}
                                        </Label>
                                        <ReasonTextarea
                                            context="access"
                                            id="roles-reason"
                                            name="reason"
                                            rows={2}
                                            required
                                            minLength={5}
                                            className={controlClass}
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            {t('access.staff.reason_help')}
                                        </p>
                                        <InputError message={errors.reason} />
                                    </div>

                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="w-fit"
                                    >
                                        {processing && <Spinner />}
                                        {t('access.staff.submit')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </SectionCard>
                )}

                <SectionCard title={t('access.staff.effective_permissions')}>
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
                </SectionCard>
            </PageContainer>
        </>
    );
}

PlatformStaffShow.layout = {
    breadcrumbs: [{ title: 'access.staff.title', href: staffIndex() }],
};
