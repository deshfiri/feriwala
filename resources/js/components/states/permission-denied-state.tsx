import { Lock } from 'lucide-react';
import StateShell from '@/components/states/state-shell';
import { useTranslation } from '@/hooks/use-translation';

/**
 * The user is signed in but their role does not cover this.
 *
 * Says what to do about it — ask an administrator — rather than only stating the
 * refusal. It never names the missing permission or hints at what the page would
 * have contained, since that leaks the shape of the system to someone who is not
 * entitled to it (§32, §36).
 */
export default function PermissionDeniedState({
    title,
    description,
    className,
}: {
    title?: string;
    description?: string;
    className?: string;
}) {
    const { t } = useTranslation();

    return (
        <StateShell
            icon={Lock}
            tone="warning"
            title={title ?? t('common.states.forbidden_title')}
            description={
                description ?? t('common.states.forbidden_description')
            }
            className={className}
        />
    );
}
