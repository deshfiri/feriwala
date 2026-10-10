import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import PlatformStaffController from '@/actions/App/Http/Controllers/Admin/PlatformStaffController';
import { index as staffIndex } from '@/routes/admin/staff';
import ReasonTextarea from '@/components/forms/reason-textarea';

type RoleOption = {
    key: string;
    label: string;
};

type Props = {
    roles: RoleOption[];
};

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Invite a brand-new platform staff member (Platform Staff management).
 *
 * No password field exists on purpose: an administrator never chooses or
 * views this person's password (§6). Submitting creates the login and
 * emails them a link to set their own, through the same password-reset
 * mechanism a "forgot password" flow already uses.
 */
export default function PlatformStaffCreate({ roles }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('access.staff.invite')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('access.staff.invite')}
                    description={t('access.staff.invite_description')}
                    back={{
                        href: staffIndex(),
                        label: t('access.staff.back'),
                    }}
                />

                <SectionCard>
                    <Form
                        {...PlatformStaffController.store.form()}
                        options={{ preserveScroll: true }}
                        className="grid gap-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="staff-name">
                                        {t('access.staff.name')}
                                    </Label>
                                    <Input
                                        id="staff-name"
                                        name="name"
                                        required
                                        autoComplete="off"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="staff-email">
                                        {t('access.staff.email')}
                                    </Label>
                                    <Input
                                        id="staff-email"
                                        name="email"
                                        type="email"
                                        required
                                        autoComplete="off"
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="staff-mobile">
                                        {t('access.staff.mobile')}
                                    </Label>
                                    <Input
                                        id="staff-mobile"
                                        name="mobile"
                                        autoComplete="off"
                                    />
                                    <InputError message={errors.mobile} />
                                </div>

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
                                                className="accent-brand"
                                            />
                                            {role.label}
                                        </label>
                                    ))}
                                    <InputError message={errors.roles} />
                                </fieldset>

                                <div className="grid gap-2">
                                    <Label htmlFor="staff-reason">
                                        {t('access.staff.reason')}
                                    </Label>
                                    <ReasonTextarea
                                        context="access"
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
                                    <InputError message={errors.reason} />
                                </div>

                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="w-fit"
                                >
                                    {processing && <Spinner />}
                                    {t('access.staff.invite')}
                                </Button>
                            </>
                        )}
                    </Form>
                </SectionCard>
            </PageContainer>
        </>
    );
}

PlatformStaffCreate.layout = {
    breadcrumbs: [{ title: 'access.staff.title', href: staffIndex() }],
};
