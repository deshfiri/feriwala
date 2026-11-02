import SectionCard from '@/components/section-card';
import { useTranslation } from '@/hooks/use-translation';
import {
    AXES,
    AXIS_LABEL_KEY,
    type OrderLifecycle,
} from '@/pages/admin/orders/order-lifecycle';

export type OrderStatusHistoryEntry = {
    previous_status: string | null;
    new_status: string;
    at: string;
    source: string;
    actor: string | null;
    reason: string | null;
    internal_note: string | null;
};

type Entry = {
    key: string;
    /** Which of the four status records this belongs to. */
    track: 'order' | 'fulfillment' | 'delivery' | 'courier';
    from: string | null;
    to: string;
    at: string;
    /** Where the change came from, for the order's own history. */
    source: string | null;
    actor: string | null;
    reason: string | null;
    note: string | null;
};

/**
 * Everything that happened to the order, newest first, whichever of its four
 * status records it was on.
 *
 * The order's own status and its fulfilment, delivery and courier tracks keep
 * separate histories; read apart, "what happened last?" means opening four
 * lists and comparing times. Here they are one list, each entry saying which
 * track moved, from what to what, when, who did it and why.
 */
export default function OrderActivity({
    history,
    lifecycle,
}: {
    history: OrderStatusHistoryEntry[];
    lifecycle: OrderLifecycle;
}) {
    const { t, locale } = useTranslation();

    const entries: Entry[] = [
        ...history.map((entry, position): Entry => ({
            key: `order-${position}`,
            track: 'order',
            from: entry.previous_status
                ? t(`orders.statuses.${entry.previous_status}`)
                : null,
            to: t(`orders.statuses.${entry.new_status}`),
            at: entry.at,
            source: entry.source,
            actor: entry.actor,
            reason: entry.reason,
            note: entry.internal_note,
        })),
        ...AXES.flatMap((axis) =>
            lifecycle[axis].history.map((entry, position): Entry => ({
                key: `${axis}-${position}`,
                track: axis,
                from: entry.previous_status,
                to: entry.new_status,
                at: entry.changed_at,
                source: null,
                actor: entry.changed_by,
                reason: entry.reason,
                note: null,
            })),
        ),
    ].sort((a, b) => new Date(b.at).getTime() - new Date(a.at).getTime());

    return (
        <SectionCard title={t('orders.admin.activity.title')}>
            {entries.length === 0 ? (
                <p className="text-muted-foreground text-sm">
                    {t('orders.admin.activity.empty')}
                </p>
            ) : (
                <ol className="border-border space-y-4 border-s ps-4">
                    {entries.map((entry) => (
                        <li key={entry.key}>
                            <p className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                <span className="bg-muted text-muted-foreground rounded-full px-2 py-0.5 text-xs font-medium">
                                    {entry.track === 'order'
                                        ? t('orders.admin.activity.order')
                                        : t(AXIS_LABEL_KEY[entry.track])}
                                </span>
                                {entry.from
                                    ? t('orders.admin.activity.moved', {
                                          from: entry.from,
                                          to: entry.to,
                                      })
                                    : entry.to}
                            </p>
                            <p className="text-muted-foreground text-xs">
                                <time dateTime={entry.at}>
                                    {new Date(entry.at).toLocaleString(locale)}
                                </time>
                                {entry.source && (
                                    <>
                                        {' · '}
                                        {t(
                                            `orders.admin.change_sources.${entry.source}`,
                                        )}
                                    </>
                                )}
                                {' · '}
                                {entry.actor ??
                                    t('orders.admin.history.system')}
                            </p>
                            {entry.reason && (
                                <p className="mt-1 text-sm">
                                    {t('orders.admin.history.reason', {
                                        reason: entry.reason,
                                    })}
                                </p>
                            )}
                            {entry.note && (
                                <p className="mt-1 text-sm">
                                    {t('orders.admin.history.internal', {
                                        note: entry.note,
                                    })}
                                </p>
                            )}
                        </li>
                    ))}
                </ol>
            )}
        </SectionCard>
    );
}
