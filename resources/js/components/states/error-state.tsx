import { AlertTriangle } from 'lucide-react';
import StateShell from '@/components/states/state-shell';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Something failed and the user can try again.
 *
 * The retry action is offered whenever a caller supplies one, because a dead end
 * with no way forward is the worst version of this screen (§33.10).
 */
export default function ErrorState({
    title,
    description,
    onRetry,
    className,
}: {
    title?: string;
    description?: string;
    onRetry?: () => void;
    className?: string;
}) {
    const { t } = useTranslation();

    return (
        <StateShell
            icon={AlertTriangle}
            tone="danger"
            title={title ?? t('common.states.error_title')}
            description={description ?? t('common.states.error_description')}
            action={
                onRetry && (
                    <Button variant="outline" size="sm" onClick={onRetry}>
                        {t('common.actions.retry')}
                    </Button>
                )
            }
            className={className}
        />
    );
}
