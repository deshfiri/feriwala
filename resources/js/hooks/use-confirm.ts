import { useCallback, type ReactNode } from 'react';
import { useConfirmContext } from '@/components/confirm/confirm-provider';
import { useTranslation } from '@/hooks/use-translation';

export type ConfirmRequest = {
    /** Defaults to "Are you sure?". */
    title?: string;
    /** What is about to happen, in words. Defaults to a generic warning. */
    description?: ReactNode;
    /** Defaults to "Confirm"; pass the action's own label ("Delete", "Cancel order"). */
    confirmLabel?: ReactNode;
    /** False for a routine confirmation; dangerous by default. */
    destructive?: boolean;
};

/**
 * Ask "Are you sure?" and wait for the answer.
 *
 *     const confirm = useConfirm();
 *     if (await confirm({ confirmLabel: t('common.actions.delete') })) {
 *         router.delete(url);
 *     }
 *
 * Outside the app's provider (a component rendered on its own in a test) it
 * falls back to the browser's own confirm, so nothing dangerous ever runs
 * unasked.
 */
export function useConfirm(): (request?: ConfirmRequest) => Promise<boolean> {
    const ask = useConfirmContext();
    const { t } = useTranslation();

    return useCallback(
        (request: ConfirmRequest = {}) => {
            const title = request.title ?? t('common.confirm.title');
            const description =
                request.description ?? t('common.confirm.description');

            if (ask === null) {
                return Promise.resolve(
                    typeof window !== 'undefined' &&
                        typeof window.confirm === 'function' &&
                        window.confirm(
                            typeof description === 'string'
                                ? `${title}\n\n${description}`
                                : title,
                        ) === true,
                );
            }

            return ask({
                title,
                description,
                confirmLabel:
                    request.confirmLabel ?? t('common.actions.confirm'),
                cancelLabel: t('common.actions.cancel'),
                destructive: request.destructive ?? true,
            });
        },
        [ask, t],
    );
}
