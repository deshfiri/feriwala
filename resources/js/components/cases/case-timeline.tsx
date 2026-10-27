import { useTranslation } from '@/hooks/use-translation';
import type { TimelineEntry } from '@/types/cases';

const dot: Record<TimelineEntry['actor_type'], string> = {
    partner: 'bg-brand',
    staff: 'bg-info',
    customer: 'bg-warning',
    system: 'bg-muted-foreground',
};

/**
 * One timeline for every kind of case (D35): what happened, who did it by kind
 * of party, when, why, and — for a move — from what to what.
 *
 * The dot's colour is never the only signal: the party is said in words beside
 * the time. Only what the server recorded for this reader is shown, so a Partner
 * is never given a staff member's name or note.
 */
export default function CaseTimeline({
    entries,
}: {
    entries: TimelineEntry[];
}) {
    const { t, locale } = useTranslation();

    if (entries.length === 0) {
        return (
            <p className="text-muted-foreground text-sm">
                {t('cases.timeline.empty')}
            </p>
        );
    }

    return (
        <ol className="space-y-4" aria-label={t('cases.timeline.title')}>
            {entries.map((entry) => (
                <li key={entry.key} className="flex gap-3">
                    <span
                        aria-hidden="true"
                        className={`mt-1.5 size-2 shrink-0 rounded-full ${dot[entry.actor_type]}`}
                    />
                    <div className="min-w-0 space-y-0.5 text-sm">
                        <div className="flex flex-wrap items-baseline gap-x-2">
                            <span className="font-medium">{entry.event}</span>
                            {entry.next && (
                                <span>
                                    {entry.previous && (
                                        <>
                                            <span className="text-muted-foreground">
                                                {entry.previous}
                                            </span>
                                            {' → '}
                                        </>
                                    )}
                                    <span className="font-medium">
                                        {entry.next}
                                    </span>
                                </span>
                            )}
                        </div>
                        <div className="text-muted-foreground text-xs">
                            {new Date(entry.at).toLocaleString(locale)} ·{' '}
                            {t(`cases.actors.${entry.actor_type}`)}
                            {entry.actor ? ` (${entry.actor})` : ''}
                        </div>
                        {entry.note && (
                            <p className="text-sm break-words">{entry.note}</p>
                        )}
                    </div>
                </li>
            ))}
        </ol>
    );
}
