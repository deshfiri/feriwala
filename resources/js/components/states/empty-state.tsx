import { Inbox, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import StateShell from '@/components/states/state-shell';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Nothing to show yet — and, importantly, nothing went wrong.
 *
 * Distinct from the error state because the two call for different words and
 * different actions: an empty list invites the user to create something, a
 * failed list invites them to retry.
 */
export default function EmptyState({
    title,
    description,
    icon = Inbox,
    action,
    className,
}: {
    title?: string;
    description?: string;
    icon?: LucideIcon;
    action?: ReactNode;
    className?: string;
}) {
    const { t } = useTranslation();

    return (
        <StateShell
            icon={icon}
            title={title ?? t('common.states.empty_title')}
            description={description ?? t('common.states.empty_description')}
            action={action}
            className={className}
        />
    );
}
