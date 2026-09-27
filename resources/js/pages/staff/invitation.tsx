import { Form, Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import StaffInvitationController from '@/actions/App/Http/Controllers/Erp/StaffInvitationController';

type Props = {
    invitation: {
        token: string;
        account: string;
        role: string;
        roleLabel: string;
        invitedBy: string | null;
        email: string;
        expiresAt: string;
    } | null;
    viewer?: {
        signedIn: boolean;
        matches: boolean;
        hasAccount: boolean;
    };
    reason: string | null;
};

/**
 * The page an invited person lands on.
 *
 * Every reason they might not be able to accept is worked out on the server and
 * said plainly here, because the common failures are quiet ones: signed in as
 * the wrong person, or already working in another business. An "Accept" button
 * that simply fails teaches nothing.
 */
export default function StaffInvitationPage({
    invitation,
    viewer,
    reason,
}: Props) {
    const { t } = useTranslation();

    if (!invitation) {
        return (
            <div className="mx-auto max-w-md p-6">
                <Head title={t('common.staff_invitation.title')} />
                <Heading
                    title={t('common.staff_invitation.cannot_use')}
                    description={reason ?? undefined}
                />
            </div>
        );
    }

    const wrongPerson = viewer?.signedIn && !viewer.matches;
    const alreadyPlaced = viewer?.hasAccount ?? false;

    return (
        <div className="mx-auto max-w-md space-y-6 p-6">
            <Head title={t('common.staff_invitation.title')} />

            <Heading
                title={t('common.staff_invitation.join', {
                    account: invitation.account,
                })}
                description={
                    invitation.invitedBy
                        ? t('common.staff_invitation.invited_by', {
                              inviter: invitation.invitedBy,
                              role: invitation.roleLabel,
                          })
                        : t('common.staff_invitation.invited_as', {
                              role: invitation.roleLabel,
                          })
                }
            />

            {wrongPerson ? (
                <p className="text-muted-foreground text-sm">
                    {t('common.staff_invitation.wrong_person', {
                        email: invitation.email,
                    })}
                </p>
            ) : null}

            {alreadyPlaced ? (
                <p className="text-muted-foreground text-sm">
                    {t('common.staff_invitation.already_placed')}
                </p>
            ) : null}

            <Form
                {...StaffInvitationController.accept.form(invitation.token)}
                className="space-y-4"
            >
                {({ processing, errors }) => (
                    <>
                        <InputError message={errors.invitation} />

                        <Button
                            type="submit"
                            disabled={
                                processing || wrongPerson || alreadyPlaced
                            }
                        >
                            {t('common.staff_invitation.accept')}
                        </Button>
                    </>
                )}
            </Form>
        </div>
    );
}
