import { Building2 } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { useTranslation } from '@/hooks/use-translation';

export type StaffInvitationContext = {
    token: string;
    account: string;
    role: string;
};

/**
 * Shown on sign-in and register when the visitor arrived from an invitation.
 *
 * Someone invited to work in an account usually has no login yet, so the link
 * sends them here first. Naming the business is what makes the detour make
 * sense — without it, being asked to register is indistinguishable from having
 * clicked the wrong thing.
 */
export default function StaffInvitationAlert({
    invitation,
    className,
}: {
    invitation: StaffInvitationContext;
    className?: string;
}) {
    const { t } = useTranslation();

    return (
        <Alert className={className}>
            <Building2 className="size-4" />
            <AlertTitle>
                {t('Invitation to :account', { account: invitation.account })}
            </AlertTitle>
            <AlertDescription>
                {t(
                    'Sign in or create an account to join as :role. Use the address the invitation was sent to.',
                    { role: invitation.role },
                )}
            </AlertDescription>
        </Alert>
    );
}
