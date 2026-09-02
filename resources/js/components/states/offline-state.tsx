import { WifiOff } from 'lucide-react';
import StateShell from '@/components/states/state-shell';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';

/**
 * The connection dropped.
 *
 * Kept separate from the error state so the message can be honest about the
 * cause. Telling someone on a patchy mobile connection that "something went
 * wrong" sends them to support for a problem support cannot fix.
 */
export default function OfflineState({
    onRetry,
    className,
}: {
    onRetry?: () => void;
    className?: string;
}) {
    const { t } = useTranslation();

    return (
        <StateShell
            icon={WifiOff}
            tone="warning"
            title={t('common.states.offline_title')}
            description={t('common.states.offline_description')}
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
