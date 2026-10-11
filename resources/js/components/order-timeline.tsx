import { useTranslation } from '@/hooks/use-translation';
import type { TimelineEntry } from '@/types/stores';

const sourceDot: Record<TimelineEntry['source'], string> = {
    partner: 'bg-brand',
    staff: 'bg-info',
    system: 'bg-muted-foreground',
    external_store: 'bg-success',
    courier: 'bg-warning',
};

/**
 * An order's recorded history, oldest first (D29).
 *
 * Each entry says what changed, from what to what, when, and who moved it —
 * by kind of party, in words, so the dot's colour is never the only signal
 * (§33.9). Only what the server recorded is shown; nothing is filled in here.
 */
export default function OrderTimeline({
    entries,
}: {
    entries: TimelineEntry[];
}) {
    const { t, locale } = useTranslation();

    if (entries.length === 0) {
        return (
            <p className="text-muted-foreground text-sm">
                {t('orders.timeline.empty')}
            </p>
        );
    }

    return (
        <ol className="space-y-4">
            {entries.map((entry) => (
                <li key={entry.key} className="flex gap-3">
                    <span
                        aria-hidden="true"
                        className={`mt-1.5 size-2 shrink-0 rounded-full ${sourceDot[entry.source]}`}
                    />
                    <div className="min-w-0 space-y-0.5 text-sm">
                        <div className="flex flex-wrap items-baseline gap-x-2">
                            <span className="font-medium">
                                {entry.type_label}
                            </span>
                            {entry.new_label && (
                                <span>
                                    {entry.previous_label ? (
                                        <>
                                            <span className="text-muted-foreground">
                                                {entry.previous_label}
                                            </span>
                                            {' → '}
                                        </>
                                    ) : null}
                                    <span className="font-medium">
                                        {entry.new_label}
                                    </span>
                                </span>
                            )}
                            {entry.reference && (
                                <span className="text-muted-foreground font-mono text-xs">
                                    {entry.reference}
                                </span>
                            )}
                        </div>
                        {entry.description && (
                            <p className="text-muted-foreground">
                                {entry.description}
                            </p>
                        )}
                        <p className="text-muted-foreground text-xs">
                            <time dateTime={entry.at}>
                                {new Date(entry.at).toLocaleString(locale)}
                            </time>
                            {' · '}
                            {entry.source_label}
                            {entry.actor &&
                                ` · ${t('orders.timeline.by', { name: entry.actor })}`}
                        </p>
                    </div>
                </li>
            ))}
        </ol>
    );
}
