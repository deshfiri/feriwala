import { Form, Head, Link } from '@inertiajs/react';
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
import StaffAccessController from '@/actions/App/Http/Controllers/Admin/StaffAccessController';
import { index as staffIndex } from '@/routes/admin/staff-access';
import type { StatusTone } from '@/lib/status';

type StaffMember = {
    public_id: string;
    name: string;
    email: string;
    role_label: string | null;
    requires_two_factor: boolean;
    two_factor_enabled: boolean;
    identity_status_label: string;
    identity_status_tone: StatusTone;
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
    can: { manage: boolean };
    password_confirmed: boolean;
};

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * One platform staff member's effective permissions, and the form that
 * changes their role (commit-order item 6).
 *
 * Every safety guard lives in AssignPlatformRole and UserPolicy::assignRole
 * -- this page only renders what `can.manage` and `roles` already decided
 * server-side; a role missing from the `roles` list (Super Admin, unless
 * the viewer already holds it) is not offered here because granting it
 * would itself be the escalation the backend refuses.
 */
export default function StaffAccessShow({
    staffMember,
    permissionGroups,
    roles,
    can,
    password_confirmed: passwordConfirmed,
}: Props) {
    const { t } = useTranslation();

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
                />

                {can.manage && (
                    <SectionCard title={t('access.staff.change_role')}>
                        {!passwordConfirmed ? (
                            <Button variant="outline" asChild>
                                <Link href={confirmPassword()}>
                                    {t('access.staff.confirm_password')}
                                </Link>
                            </Button>
                        ) : (
                            <Form
                                {...StaffAccessController.updateRole.form(
                                    staffMember.public_id,
                                )}
                                options={{ preserveScroll: true }}
                                className="grid gap-4"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="staff-current-role">
                                                {t('access.staff.current_role')}
                                            </Label>
                                            <p
                                                id="staff-current-role"
                                                className="text-muted-foreground text-sm"
                                            >
                                                {staffMember.role_label ??
                                                    t('access.staff.no_role')}
                                            </p>
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="staff-new-role">
                                                {t('access.staff.new_role')}
                                            </Label>
                                            <select
                                                id="staff-new-role"
                                                name="role"
                                                required
                                                className={controlClass}
                                                defaultValue=""
                                            >
                                                <option value="" disabled>
                                                    {t('access.staff.new_role')}
                                                </option>
                                                {roles.map((role) => (
                                                    <option
                                                        key={role.key}
                                                        value={role.key}
                                                    >
                                                        {role.label}
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError message={errors.role} />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="staff-reason">
                                                {t('access.staff.reason')}
                                            </Label>
                                            <textarea
                                                id="staff-reason"
                                                name="reason"
                                                rows={2}
                                                required
                                                minLength={5}
                                                className={controlClass}
                                            />
                                            <p className="text-muted-foreground text-xs">
                                                {t('access.staff.reason_help')}
                                            </p>
                                            <InputError
                                                message={errors.reason}
                                            />
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
                        )}
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

StaffAccessShow.layout = {
    breadcrumbs: [{ title: 'access.staff.title', href: staffIndex() }],
};
