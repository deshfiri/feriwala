import { Head } from '@inertiajs/react';
import {
    Activity,
    ChevronDown,
    Cog,
    History,
    Search,
    ShieldAlert,
    Users,
    X,
} from 'lucide-react';
import { useState } from 'react';
import TablePagination from '@/components/data-table/table-pagination';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatCard, { StatCardGrid } from '@/components/stat-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { index } from '@/routes/admin/activity-log';
import type { Paginator } from '@/types';

type ActivityEntry = {
    id: string;
    action: string;
    module: string | null;
    actor: string | null;
    actor_type: string;
    subject: string | null;
    note: string | null;
    reason: string | null;
    before: Record<string, unknown> | null;
    after: Record<string, unknown> | null;
    ip_address: string | null;
    is_sensitive: boolean;
    created_at: string;
};

type Person = {
    id: string;
    name: string;
    week: number;
    total: number;
    last_at: string;
};

type People = { staff: Person[]; system: number };

type Filters = {
    search: string | null;
    person: string | null;
    module: string | null;
    sensitive: boolean;
    from: string | null;
    to: string | null;
};

type Summary = {
    today: number;
    sensitive_today: number;
    people_today: number;
    week: number;
};

type Props = {
    entries: Paginator<ActivityEntry>;
    filters: Filters;
    summary: Summary;
    people: People;
    modules: string[];
};

const controlClass =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-lg border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none';

/** "inventory.stock_tracked" → "Stock tracked". */
function humanise(action: string): string {
    const last = action.split('.').pop() ?? action;
    const words = last.replace(/[_-]+/g, ' ').trim();

    return words.charAt(0).toUpperCase() + words.slice(1);
}

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

function show(value: unknown): string {
    if (value === null || value === undefined) return '—';
    if (typeof value === 'string') return value;

    return JSON.stringify(value);
}

/**
 * What staff and the system did (§36.2).
 *
 * A day-by-day feed rather than a table: an investigation reads "who did what,
 * in what order", and a timeline says that without the reader reassembling it
 * from columns. Read-only — the log is append-only, so no control here edits or
 * removes an entry. Values were redacted when they were written; they are shown
 * as recorded.
 */
export default function AdminActivityLog({
    entries,
    filters,
    summary,
    people,
    modules,
}: Props) {
    const { t, locale } = useTranslation();
    const { search, setSearch, setFilter, goToPage, clearAll } = useTableQuery({
        only: ['entries', 'filters'],
    });

    const filtered =
        search !== '' ||
        Object.values(filters).some(
            (value) => value !== null && value !== false,
        );

    const dayLabel = (iso: string) => {
        const date = new Date(iso);
        const startOfDay = (value: Date) =>
            new Date(
                value.getFullYear(),
                value.getMonth(),
                value.getDate(),
            ).getTime();
        const days = Math.round(
            (startOfDay(new Date()) - startOfDay(date)) / 86_400_000,
        );

        if (days === 0) return t('audit.today');
        if (days === 1) return t('audit.yesterday');

        return date.toLocaleDateString(locale, {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        });
    };

    const groups = entries.data.reduce<
        { label: string; items: ActivityEntry[] }[]
    >((carry, entry) => {
        const label = dayLabel(entry.created_at);
        const last = carry[carry.length - 1];

        if (last && last.label === label) {
            last.items.push(entry);
        } else {
            carry.push({ label, items: [entry] });
        }

        return carry;
    }, []);

    return (
        <>
            <Head title={t('audit.title')} />

            <PageContainer>
                <PageHeader
                    title={t('audit.title')}
                    description={t('audit.description')}
                />

                <StatCardGrid>
                    <StatCard
                        label={t('audit.stats.today')}
                        value={summary.today}
                        icon={Activity}
                        tone="brand"
                    />
                    <StatCard
                        label={t('audit.stats.sensitive')}
                        value={summary.sensitive_today}
                        icon={ShieldAlert}
                        tone="warning"
                    />
                    <StatCard
                        label={t('audit.stats.people')}
                        value={summary.people_today}
                        icon={Users}
                    />
                    <StatCard
                        label={t('audit.stats.week')}
                        value={summary.week}
                        icon={History}
                    />
                </StatCardGrid>

                <section
                    aria-label={t('audit.people.title')}
                    className="space-y-3"
                >
                    <h2 className="text-sm font-semibold">
                        {t('audit.people.title')}
                    </h2>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        <PersonCard
                            label={t('audit.people.everyone')}
                            detail={t('audit.people.everyone_help')}
                            selected={filters.person === null}
                            onSelect={() => setFilter('person', undefined)}
                        />
                        {people.staff.map((person) => (
                            <PersonCard
                                key={person.id}
                                label={person.name}
                                avatar={initials(person.name)}
                                detail={t('audit.people.week', {
                                    count: person.week,
                                })}
                                lastAt={person.last_at}
                                selected={filters.person === person.id}
                                onSelect={() => setFilter('person', person.id)}
                            />
                        ))}
                        {people.system > 0 && (
                            <PersonCard
                                label={t('audit.system')}
                                detail={t('audit.people.month', {
                                    count: people.system,
                                })}
                                system
                                selected={filters.person === 'system'}
                                onSelect={() => setFilter('person', 'system')}
                            />
                        )}
                    </div>
                </section>

                <div className="bg-card overflow-hidden rounded-xl border shadow-sm">
                    <div className="flex flex-wrap items-center gap-2 border-b px-4 py-3">
                        <div className="relative w-full sm:w-72">
                            <Search
                                className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                                aria-hidden="true"
                            />
                            <Input
                                type="search"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                placeholder={t('audit.search')}
                                aria-label={t('audit.search')}
                                className="h-9 pl-9"
                            />
                        </div>

                        <select
                            aria-label={t('audit.filter_module')}
                            value={filters.module ?? ''}
                            onChange={(event) =>
                                setFilter(
                                    'module',
                                    event.target.value || undefined,
                                )
                            }
                            className={controlClass}
                        >
                            <option value="">{t('audit.all_modules')}</option>
                            {modules.map((module) => (
                                <option key={module} value={module}>
                                    {module}
                                </option>
                            ))}
                        </select>

                        <div className="flex items-center gap-1.5">
                            <Input
                                type="date"
                                aria-label={t('audit.from')}
                                value={filters.from ?? ''}
                                onChange={(event) =>
                                    setFilter(
                                        'from',
                                        event.target.value || undefined,
                                    )
                                }
                                className="h-9 w-37"
                            />
                            <span
                                className="text-muted-foreground text-sm"
                                aria-hidden="true"
                            >
                                →
                            </span>
                            <Input
                                type="date"
                                aria-label={t('audit.to')}
                                value={filters.to ?? ''}
                                onChange={(event) =>
                                    setFilter(
                                        'to',
                                        event.target.value || undefined,
                                    )
                                }
                                className="h-9 w-37"
                            />
                        </div>

                        <Button
                            type="button"
                            size="sm"
                            variant={filters.sensitive ? 'default' : 'outline'}
                            aria-pressed={filters.sensitive}
                            onClick={() =>
                                setFilter(
                                    'sensitive',
                                    filters.sensitive ? undefined : '1',
                                )
                            }
                            className="h-9"
                        >
                            <ShieldAlert
                                className="size-4"
                                aria-hidden="true"
                            />
                            {t('audit.sensitive_only')}
                        </Button>

                        {filtered && (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={clearAll}
                                className="h-9"
                            >
                                <X className="size-4" aria-hidden="true" />
                                {t('audit.clear')}
                            </Button>
                        )}

                        <span className="text-muted-foreground ml-auto text-xs tabular-nums">
                            {t('audit.count', { count: entries.total })}
                        </span>
                    </div>

                    {entries.data.length === 0 ? (
                        <EmptyState
                            icon={History}
                            title={t(
                                filtered ? 'audit.no_matches' : 'audit.empty',
                            )}
                            description={t(
                                filtered
                                    ? 'audit.no_matches_help'
                                    : 'audit.empty_help',
                            )}
                        />
                    ) : (
                        <div className="space-y-6 px-4 py-5 sm:px-6">
                            {groups.map((group) => (
                                <section
                                    key={group.label}
                                    aria-label={group.label}
                                >
                                    <h2 className="text-muted-foreground mb-3 flex items-center gap-3 text-xs font-semibold tracking-wider uppercase">
                                        {group.label}
                                        <span className="bg-border h-px flex-1" />
                                    </h2>
                                    <ol className="space-y-1">
                                        {group.items.map((entry, position) => (
                                            <EntryRow
                                                key={entry.id}
                                                entry={entry}
                                                last={
                                                    position ===
                                                    group.items.length - 1
                                                }
                                            />
                                        ))}
                                    </ol>
                                </section>
                            ))}
                        </div>
                    )}

                    {entries.data.length > 0 && (
                        <TablePagination
                            paginator={entries}
                            onPageChange={goToPage}
                        />
                    )}
                </div>
            </PageContainer>
        </>
    );
}

/**
 * One person to open the log for — or everyone, or the system itself.
 */
function PersonCard({
    label,
    detail,
    avatar,
    lastAt,
    system = false,
    selected,
    onSelect,
}: {
    label: string;
    detail: string;
    avatar?: string;
    lastAt?: string;
    system?: boolean;
    selected: boolean;
    onSelect: () => void;
}) {
    const { t, locale } = useTranslation();

    const ago = (iso: string) => {
        const minutes = Math.round(
            (new Date(iso).getTime() - Date.now()) / 60_000,
        );
        const format = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

        if (Math.abs(minutes) < 60) return format.format(minutes, 'minute');
        if (Math.abs(minutes) < 1440)
            return format.format(Math.round(minutes / 60), 'hour');

        return format.format(Math.round(minutes / 1440), 'day');
    };

    return (
        <button
            type="button"
            aria-pressed={selected}
            onClick={onSelect}
            className={cn(
                'bg-card hover:bg-surface-hover flex items-center gap-3 rounded-xl border p-3 text-left shadow-sm transition-colors',
                selected && 'border-brand ring-brand/30 ring-2',
            )}
        >
            <span
                className={cn(
                    'flex size-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold',
                    avatar
                        ? 'bg-brand-subtle text-brand'
                        : 'bg-muted text-muted-foreground',
                )}
                aria-hidden="true"
            >
                {avatar ??
                    (system ? (
                        <Cog className="size-5" />
                    ) : (
                        <Users className="size-5" />
                    ))}
            </span>
            <span className="min-w-0">
                <span className="block truncate text-sm font-semibold">
                    {label}
                </span>
                <span className="text-muted-foreground block truncate text-xs">
                    {detail}
                </span>
                {lastAt && (
                    <span className="text-muted-foreground block truncate text-xs">
                        {t('audit.people.last_active', { time: ago(lastAt) })}
                    </span>
                )}
            </span>
        </button>
    );
}

/**
 * One recorded action on the timeline: who, what, why, and — folded away — the
 * exact change.
 */
function EntryRow({ entry, last }: { entry: ActivityEntry; last: boolean }) {
    const { t, locale } = useTranslation();
    const [open, setOpen] = useState(false);

    const isSystem = entry.actor === null;
    const name = entry.actor ?? t('audit.system');

    const keys = Array.from(
        new Set([
            ...Object.keys(entry.before ?? {}),
            ...Object.keys(entry.after ?? {}),
        ]),
    );
    const hasChanges = keys.length > 0;
    const hasDetails =
        hasChanges || entry.note !== null || entry.ip_address !== null;

    const time = new Date(entry.created_at);

    return (
        <li className="relative flex gap-3 pb-4">
            {!last && (
                <span
                    className="bg-border absolute top-9 bottom-0 left-4 w-px"
                    aria-hidden="true"
                />
            )}

            <span
                className={cn(
                    'z-10 flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                    isSystem
                        ? 'bg-muted text-muted-foreground'
                        : 'bg-brand-subtle text-brand',
                )}
                aria-hidden="true"
            >
                {isSystem ? <Cog className="size-4" /> : initials(name) || '?'}
            </span>

            <div className="hover:bg-surface-hover -mt-1 min-w-0 flex-1 rounded-lg px-3 py-1.5 transition-colors">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span className="text-sm font-semibold">{name}</span>
                    <span className="text-sm">{humanise(entry.action)}</span>
                    {entry.module && (
                        <StatusPill tone="neutral" label={entry.module} />
                    )}
                    {entry.is_sensitive && (
                        <StatusPill
                            tone="warning"
                            label={t('audit.sensitive')}
                        />
                    )}
                    <time
                        dateTime={entry.created_at}
                        title={time.toLocaleString(locale)}
                        className="text-muted-foreground ml-auto text-xs whitespace-nowrap tabular-nums"
                    >
                        {time.toLocaleTimeString(locale, {
                            hour: '2-digit',
                            minute: '2-digit',
                        })}
                    </time>
                </div>

                <div className="text-muted-foreground mt-0.5 flex flex-wrap items-center gap-x-2 text-xs">
                    <span className="font-mono">{entry.action}</span>
                    {entry.subject && <span>· {entry.subject}</span>}
                </div>

                {entry.reason && (
                    <blockquote className="border-border text-muted-foreground mt-2 border-l-2 pl-3 text-sm italic">
                        {entry.reason}
                    </blockquote>
                )}

                {hasDetails && (
                    <button
                        type="button"
                        onClick={() => setOpen((value) => !value)}
                        aria-expanded={open}
                        className="text-muted-foreground hover:text-foreground mt-2 inline-flex items-center gap-1 text-xs font-medium transition-colors"
                    >
                        <ChevronDown
                            className={cn(
                                'size-3.5 transition-transform',
                                open && 'rotate-180',
                            )}
                            aria-hidden="true"
                        />
                        {t(open ? 'audit.hide_details' : 'audit.details')}
                    </button>
                )}

                {open && (
                    <div className="bg-surface-subtle mt-2 space-y-3 rounded-lg border p-3 text-sm">
                        {entry.note && <p>{entry.note}</p>}

                        {hasChanges && (
                            <table className="w-full border-collapse text-xs">
                                <thead>
                                    <tr className="text-muted-foreground text-left">
                                        <th className="pr-3 pb-1 font-medium">
                                            {t('audit.field')}
                                        </th>
                                        <th className="pr-3 pb-1 font-medium">
                                            {t('audit.before')}
                                        </th>
                                        <th className="pb-1 font-medium">
                                            {t('audit.after')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {keys.map((key) => {
                                        const before = entry.before?.[key];
                                        const after = entry.after?.[key];
                                        const changed =
                                            JSON.stringify(before) !==
                                            JSON.stringify(after);

                                        return (
                                            <tr
                                                key={key}
                                                className="border-border border-t align-top"
                                            >
                                                <td className="py-1.5 pr-3 font-mono">
                                                    {key}
                                                </td>
                                                <td
                                                    className={cn(
                                                        'py-1.5 pr-3 break-all',
                                                        changed &&
                                                            before !==
                                                                undefined &&
                                                            'text-danger',
                                                    )}
                                                >
                                                    {show(before)}
                                                </td>
                                                <td
                                                    className={cn(
                                                        'py-1.5 break-all',
                                                        changed &&
                                                            after !==
                                                                undefined &&
                                                            'text-success font-medium',
                                                    )}
                                                >
                                                    {show(after)}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        )}

                        {entry.ip_address && (
                            <p className="text-muted-foreground text-xs">
                                {t('audit.ip')}:{' '}
                                <span className="font-mono">
                                    {entry.ip_address}
                                </span>
                            </p>
                        )}
                    </div>
                )}
            </div>
        </li>
    );
}

AdminActivityLog.layout = {
    breadcrumbs: [
        {
            title: 'nav.activity_log',
            href: index(),
        },
    ],
};
