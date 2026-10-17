import { Check } from 'lucide-react';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

export type TimelineStep = {
    key: string;
    label: string;
    state: 'done' | 'current' | 'upcoming';
    at: string | null;
    by: string | null;
};

export type StoreOrderTimelineData = {
    steps: TimelineStep[];
    next_action: { key: string; label: string } | null;
    exception: string | null;
    cancelled: boolean;
    payment: string | null;
};

/**
 * A Store Order's progress: every step it has passed (with when and by whom),
 * the one it is at, and the steps still to come. Read-only — there is no status
 * to choose here; moving on is a named action beside it. An exception (a failed
 * delivery, a return, a hold, a cancellation) is stated apart, never as progress.
 */
export default function StoreOrderTimeline({
    timeline,
}: {
    timeline: StoreOrderTimelineData;
}) {
    const { t, locale } = useTranslation();

    return (
        <div className="space-y-3" data-test="store-order-timeline">
            <ol className="space-y-0" aria-label={t('store_orders.timeline')}>
                {timeline.steps.map((step) => (
                    <li
                        key={step.key}
                        aria-current={
                            step.state === 'current' ? 'step' : undefined
                        }
                        data-state={step.state}
                        className={cn(
                            'relative flex gap-3 pb-4 last:pb-0',
                            step.state === 'upcoming' && 'opacity-55',
                        )}
                    >
                        <span
                            className={cn(
                                'mt-0.5 grid size-5 shrink-0 place-items-center rounded-full border text-[10px]',
                                step.state === 'done' &&
                                    'border-emerald-600 bg-emerald-600 text-white',
                                step.state === 'current' &&
                                    'border-primary bg-primary text-primary-foreground ring-primary/25 ring-4',
                            )}
                            aria-hidden="true"
                        >
                            {step.state === 'done' && (
                                <Check className="size-3" />
                            )}
                        </span>
                        <div className="min-w-0">
                            <p
                                className={cn(
                                    'text-sm',
                                    step.state === 'current' && 'font-semibold',
                                )}
                            >
                                {step.label}
                            </p>
                            {step.at && (
                                <p className="text-muted-foreground text-xs">
                                    {new Date(step.at).toLocaleString(locale)}
                                    {step.by ? ` · ${step.by}` : ''}
                                </p>
                            )}
                        </div>
                    </li>
                ))}
            </ol>

            {timeline.exception && (
                <p
                    role="status"
                    className="border-warning/40 bg-warning/10 rounded-lg border px-3 py-2 text-sm"
                    data-test="timeline-exception"
                >
                    {t(`store_orders.exceptions.${timeline.exception}`)}
                </p>
            )}
            {timeline.cancelled && (
                <p
                    role="status"
                    className="border-destructive/40 bg-destructive/10 rounded-lg border px-3 py-2 text-sm"
                    data-test="timeline-cancelled"
                >
                    {t('store_orders.cancelled_notice')}
                </p>
            )}
        </div>
    );
}
