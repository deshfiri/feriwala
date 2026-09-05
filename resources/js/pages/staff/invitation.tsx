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
                <Head title={t('Invitation')} />
                <Heading
                    title={t('This invitation cannot be used')}
                    description={reason ?? undefined}
                />
            </div>
        );
    }

    const wrongPerson = viewer?.signedIn && !viewer.matches;
    const alreadyPlaced = viewer?.hasAccount ?? false;

    return (
        <div className="mx-auto max-w-md space-y-6 p-6">
            <Head title={t('Invitation')} />

            <Heading
                title={t('Join :account', { account: invitation.account })}
                description={
                    invitation.invitedBy
                        ? t(':inviter invited you as :role.', {
                              inviter: invitation.invitedBy,
                              role: invitation.roleLabel,
                          })
                        : t('You have been invited as :role.', {
                              role: invitation.roleLabel,
                          })
                }
            />

            {wrongPerson ? (
                <p className="text-muted-foreground text-sm">
                    {t(
                        'This invitation was sent to :email. Sign in as that person to accept it.',
                        { email: invitation.email },
                    )}
                </p>
            ) : null}

            {alreadyPlaced ? (
                <p className="text-muted-foreground text-sm">
                    {t(
                        'You already work in another account. Leave that one before joining this.',
                    )}
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
                            {t('Accept invitation')}
                        </Button>
                    </>
                )}
            </Form>
        </div>
    );
}
