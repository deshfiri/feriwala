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
import RolesController from '@/actions/App/Http/Controllers/Admin/RolesController';
import { index as rolesIndex } from '@/routes/admin/roles';
import ReasonTextarea from '@/components/forms/reason-textarea';

type PermissionOption = { name: string; label: string };
type PermissionGroup = { module: string; permissions: PermissionOption[] };

type Props = {
    permissionGroups: PermissionGroup[];
};

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Create a custom role (Role and Permission management).
 *
 * Only permissions the actor personally holds are offered here (unless the
 * actor is Super Admin) -- ManageCustomRole enforces the same ceiling
 * server-side, so this list is never an invitation to submit something the
 * server would refuse.
 */
export default function RoleCreate({ permissionGroups }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('access.roles.create')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('access.roles.create')}
                    description={t('access.roles.create_description')}
                    back={{
                        href: rolesIndex(),
                        label: t('access.roles.back'),
                    }}
                />

                <SectionCard>
                    <Form
                        {...RolesController.store.form()}
                        options={{ preserveScroll: true }}
                        className="grid gap-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="role-name">
                                        {t('access.roles.name')}
                                    </Label>
                                    <Input
                                        id="role-name"
                                        name="name"
                                        required
                                        autoComplete="off"
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        {t('access.roles.name_help')}
                                    </p>
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="role-description">
                                        {t('access.roles.description')}
                                    </Label>
                                    <textarea
                                        id="role-description"
                                        name="description"
                                        rows={2}
                                        className={controlClass}
                                    />
                                    <InputError message={errors.description} />
                                </div>

                                <fieldset className="grid gap-4">
                                    <legend className="text-xs font-medium">
                                        {t('access.roles.permissions_heading')}
                                    </legend>
                                    {permissionGroups.map((group) => (
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
                                                        key={permission.name}
                                                        className="flex items-center gap-2 text-sm"
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            name="permissions[]"
                                                            value={
                                                                permission.name
                                                            }
                                                            className="accent-brand"
                                                        />
                                                        {permission.label}
                                                    </label>
                                                ),
                                            )}
                                        </div>
                                    ))}
                                    <InputError message={errors.permissions} />
                                </fieldset>

                                <div className="grid gap-2">
                                    <Label htmlFor="role-reason">
                                        {t('access.roles.reason')}
                                    </Label>
                                    <ReasonTextarea
                                        context="access"
                                        id="role-reason"
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
            </PageContainer>
        </>
    );
}

RoleCreate.layout = {
    breadcrumbs: [{ title: 'access.roles.title', href: rolesIndex() }],
};
