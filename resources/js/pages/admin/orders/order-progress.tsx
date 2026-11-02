import Notice from '@/components/notice';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import {
    AXES,
    AXIS_LABEL_KEY,
    STATUS_GROUP,
    axesNeedingAttention,
    progressOf,
    usualNextStep,
    type LifecycleAxis,
    type OrderLifecycle,
} from '@/pages/admin/orders/order-lifecycle';

/**
 * Where the order actually is, on each of its three tracks, in one place.
 *
 * Fulfilment, delivery and courier are separate state machines, so none of them
 * alone answers "where is this order?". Each track shows its server-given
 * status, how far along its usual path that is, and the usual next step; a
 * status off the path (a hold, a failed attempt, a return) is said plainly as
 * an exception, and anything needing a person is called out first.
 */
export default function OrderProgress({
    lifecycle,
}: {
    lifecycle: OrderLifecycle;
}) {
    const { t } = useTranslation();
    const attention = axesNeedingAttention(lifecycle);

    return (
        <SectionCard
            title={t('orders.admin.progress.title')}
            description={t('orders.admin.progress.description')}
        >
            <div className="space-y-5">
                {attention.length > 0 && (
                    <Notice
                        tone="warning"
                        role="note"
                        title={t('orders.admin.progress.attention_title')}
                        inset
                    >
                        <ul className="list-disc ps-4">
                            {attention.map((axis) => (
                                <li key={axis}>
                                    {t('orders.admin.progress.attention_item', {
                                        axis: t(AXIS_LABEL_KEY[axis]),
                                        status: lifecycle[axis].status_label,
                                    })}
                                </li>
                            ))}
                        </ul>
                    </Notice>
                )}

                {/* As many tracks per row as fit: the column is narrow beside the side panels. */}
                <div className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-5">
                    {AXES.map((axis) => (
                        <Track key={axis} axis={axis} lifecycle={lifecycle} />
                    ))}
                </div>
            </div>
        </SectionCard>
    );
}

function Track({
    axis,
    lifecycle,
}: {
    axis: LifecycleAxis;
    lifecycle: OrderLifecycle;
}) {
    const { t } = useTranslation();
    const summary = lifecycle[axis];
    const progress = progressOf(axis, summary.status);
    const next = usualNextStep(axis, summary);

    const caption =
        progress.kind === 'exception'
            ? t('orders.admin.progress.exception')
            : progress.complete
              ? t('orders.admin.progress.complete')
              : t('orders.admin.progress.step', {
                    current: String(progress.step),
                    total: String(progress.total),
                });

    return (
        <div className="min-w-0 space-y-2" data-test={`progress-${axis}`}>
            <div className="flex flex-wrap items-start justify-between gap-x-2 gap-y-1">
                <p className="text-sm font-medium">{t(AXIS_LABEL_KEY[axis])}</p>
                <StatusPill
                    tone={summary.status_tone}
                    label={summary.status_label}
                />
            </div>

            <div
                role="progressbar"
                aria-label={t(AXIS_LABEL_KEY[axis])}
                aria-valuemin={0}
                aria-valuemax={progress.kind === 'path' ? progress.total : 1}
                aria-valuenow={progress.kind === 'path' ? progress.step : 0}
                aria-valuetext={`${summary.status_label}. ${caption}`}
                className="flex gap-1"
            >
                {Array.from({
                    length: progress.kind === 'path' ? progress.total : 6,
                }).map((_, position) => (
                    <span
                        key={position}
                        aria-hidden="true"
                        className={cn(
                            'h-1.5 flex-1 rounded-full',
                            progress.kind === 'path'
                                ? position < progress.step
                                    ? 'bg-primary'
                                    : 'bg-muted'
                                : 'bg-warning/40',
                        )}
                    />
                ))}
            </div>

            <p className="text-muted-foreground text-xs">
                {summary.locked_reason
                    ? t('orders.admin.progress.locked')
                    : caption}
            </p>

            {next !== null && summary.can_manage && (
                <p className="text-xs">
                    <span className="text-muted-foreground">
                        {t('orders.admin.progress.next')}
                        {': '}
                    </span>
                    <span className="font-medium">
                        {t(`status.${STATUS_GROUP[axis]}.${next}`)}
                    </span>
                </p>
            )}
        </div>
    );
}
