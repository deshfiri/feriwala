import { Form, Head, router, usePage } from '@inertiajs/react';
import { UserPlus, Users } from 'lucide-react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import EmptyState from '@/components/states/empty-state';
import PermissionDeniedState from '@/components/states/permission-denied-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { useTranslation } from '@/hooks/use-translation';
import StaffController from '@/actions/App/Http/Controllers/Erp/StaffController';
import type {
    StaffAllowance,
    StaffInvitation,
    StaffMember,
} from '@/types/account';

type Props = {
    staff: StaffMember[];
    invitations: StaffInvitation[];
    allowance: StaffAllowance;
    roles: { value: string; label: string }[];
    can: { invite: boolean };
};

export default function Staff({
    staff,
    invitations,
    allowance,
    roles,
    can,
}: Props) {
    const { t } = useTranslation();
    const { account } = usePage().props;

    // A package with no staff facility has no staff page. The route refuses
    // too; this is what the person sees if they arrive by a stale link.
    if (!account?.allowsStaff) {
        return (
            <>
                <Head title={t('Staff')} />
                <PermissionDeniedState
                    title={t('Your package does not include staff')}
                    description={t(
                        'Upgrade to a package with staff to invite people into this account.',
                    )}
                />
            </>
        );
    }

    const seatsLeft =
        allowance.remaining === null
            ? t('Unlimited staff')
            : t(':used of :limit used', {
                  used: allowance.used,
                  limit: allowance.limit ?? 0,
              });

    return (
        <>
            <Head title={t('Staff')} />

            <h1 className="sr-only">{t('Staff')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('Staff')}
                    description={t(
                        'People who work in this account, and what they may do.',
                    )}
                />

                <p className="text-muted-foreground text-sm">{seatsLeft}</p>

                <ul className="divide-border divide-y rounded-lg border">
                    {staff.map((member) => (
                        <li
                            key={member.id}
                            className="flex flex-wrap items-center justify-between gap-3 p-4"
                        >
                            <div className="min-w-0 text-sm">
                                <p className="truncate font-medium">
                                    {member.name}
                                    {member.isYou ? (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            ({t('you')})
                                        </span>
                                    ) : null}
                                </p>
                                <p className="text-muted-foreground truncate">
                                    {member.email}
                                </p>
                            </div>

                            <div className="flex items-center gap-2">
                                <span className="text-sm">
                                    {member.roleLabel}
                                </span>

                                {/*
                                 * The owner has no controls at all — not
                                 * disabled ones. They cannot be removed or
                                 * given a different role through staff
                                 * management (D1), and offering the buttons
                                 * would suggest otherwise.
                                 */}
                                {member.canManage ? (
                                    <>
                                        <Select
                                            defaultValue={member.role}
                                            onValueChange={(role) => {
                                                if (role === member.role) {
                                                    return;
                                                }

                                                router.patch(
                                                    StaffController.updateRole.url(
                                                        member.id,
                                                    ),
                                                    { role },
                                                    { preserveScroll: true },
                                                );
                                            }}
                                        >
                                            <SelectTrigger
                                                className="w-36"
                                                aria-label={t(
                                                    'Permissions for :name',
                                                    { name: member.name },
                                                )}
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {roles.map((role) => (
                                                    <SelectItem
                                                        key={role.value}
                                                        value={role.value}
                                                    >
                                                        {role.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>

                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                router.delete(
                                                    StaffController.remove.url(
                                                        member.id,
                                                    ),
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('Remove')}
                                        </Button>
                                    </>
                                ) : null}
                            </div>
                        </li>
                    ))}
                </ul>

                {invitations.length > 0 ? (
                    <div className="space-y-3">
                        <Heading
                            variant="small"
                            title={t('Invitations')}
                            description={t(
                                'Sent but not yet accepted. Each one holds a place against your staff limit until it is used, withdrawn or expires.',
                            )}
                        />

                        <ul className="divide-border divide-y rounded-lg border">
                            {invitations.map((invitation) => (
                                <li
                                    key={invitation.id}
                                    className="flex flex-wrap items-center justify-between gap-3 p-4"
                                >
                                    <div className="min-w-0 text-sm">
                                        <p className="truncate font-medium">
                                            {invitation.email}
                                        </p>
                                        <p className="text-muted-foreground truncate">
                                            {invitation.roleLabel}
                                        </p>
                                    </div>

                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            router.delete(
                                                StaffController.revokeInvitation.url(
                                                    invitation.id,
                                                ),
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {t('Withdraw')}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                {staff.length === 1 && invitations.length === 0 ? (
                    <EmptyState
                        icon={Users}
                        title={t('You are the only person here')}
                        description={t(
                            'Invite someone to work in this account with you.',
                        )}
                    />
                ) : null}

                {can.invite ? (
                    <>
                        <Separator />

                        <Heading
                            variant="small"
                            title={t('Invite someone')}
                            description={t(
                                'They receive an email with a link. Only the address you enter here can use it.',
                            )}
                        />

                        <Form
                            {...StaffController.invite.form()}
                            options={{ preserveScroll: true }}
                            resetOnSuccess
                            className="space-y-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="email">
                                            {t('Email address')}
                                        </Label>
                                        <Input
                                            id="email"
                                            name="email"
                                            type="email"
                                            required
                                            autoComplete="off"
                                        />
                                        <InputError message={errors.email} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="role">
                                            {t('Permissions')}
                                        </Label>
                                        <select
                                            id="role"
                                            name="role"
                                            defaultValue="staff"
                                            className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                        >
                                            {roles.map((role) => (
                                                <option
                                                    key={role.value}
                                                    value={role.value}
                                                >
                                                    {role.label}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError message={errors.role} />
                                    </div>

                                    <Button type="submit" disabled={processing}>
                                        <UserPlus className="size-4" />
                                        {t('Send invitation')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </>
                ) : null}
            </div>
        </>
    );
}
