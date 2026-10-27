import { Head, Link } from '@inertiajs/react';
import { Undo2 } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import NativeSelect from '@/components/forms/native-select';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Input } from '@/components/ui/input';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { index as casesIndex } from '@/routes/admin/cases';
import { show as showDamage } from '@/routes/admin/cases/damages';
import { show as showReturn } from '@/routes/admin/cases/returns';
import { show as showLegacyReturn } from '@/routes/admin/returns';
import type { Column, Paginator } from '@/types';
import type { CaseKind, QueueCounts, QueueRow } from '@/types/cases';

type Props = {
    cases: Paginator<QueueRow>;
    filters: {
        type: string | null;
        status: string | null;
        partner: string | null;
        store: string | null;
        responsibility: string | null;
        from: string | null;
        to: string | null;
        search: string | null;
    };
    counts: QueueCounts;
    options: {
        kinds: CaseKind[];
        partners: { id: string; name: string }[];
        stores: { id: string; name: string }[];
        statuses: { value: string; label: string; kind: string }[];
    };
};

const TABS = [
    { key: 'all', type: undefined },
    { key: 'return', type: 'return' },
    { key: 'exchange', type: 'exchange' },
    { key: 'damage', type: 'damage' },
] as const;

function link(row: QueueRow) {
    if (row.kind === 'damage') {
        return showDamage(row.id);
    }

    return row.legacy ? showLegacyReturn(row.id) : showReturn(row.id);
}

/**
 * Returns, exchanges and damages in one queue (D35).
 *
 * One list for a day's work: what is waiting for somebody comes first, then
 * newest. The tabs and their counts cover only the kinds this person may see, so a
 * badge never reveals how many cases of a kind they are not allowed to open. A row
 * names the step it is waiting for — as a step this person may take, or as who it
 * is waiting on — and never carries a customer's name, number or address.
 */
export default function AdminCases({ cases, filters, counts, options }: Props) {
    const { t, locale } = useTranslation();
    const { setFilter } = useTableQuery({
        only: ['cases', 'filters', 'counts'],
    });
    const activeType = filters.type ?? undefined;
    const filtered = Boolean(
        filters.search ||
        filters.status ||
        filters.partner ||
        filters.store ||
        filters.responsibility ||
        filters.from ||
        filters.to,
    );

    const visibleTabs = TABS.filter(
        (tab) => tab.key === 'all' || options.kinds.includes(tab.key),
    );

    const columns: Column<QueueRow>[] = [
        {
            key: 'reference',
            header: t('cases.columns.case'),
            cell: (row) => (
                <div className="min-w-0 space-y-0.5">
                    <Link
                        href={link(row)}
                        className="font-mono text-sm font-medium underline-offset-4 hover:underline"
                    >
                        {row.reference}
                    </Link>
                    <div className="text-muted-foreground text-xs">
                        {t(`cases.kinds.${row.kind}`)}
                        {row.legacy ? ` · ${t('cases.legacy')}` : ''}
                    </div>
                </div>
            ),
        },
        {
            key: 'order',
            header: t('cases.columns.order'),
            cell: (row) => (
                <div className="min-w-0 font-mono text-xs">
                    <div className="truncate">{row.order.reference}</div>
                    {row.exchange_order && (
                        <div className="text-muted-foreground truncate">
                            {row.exchange_order}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'partner',
            header: t('cases.columns.partner'),
            priority: 'secondary',
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate text-sm">{row.partner}</div>
                    {row.store && (
                        <div className="text-muted-foreground truncate text-xs">
                            {row.store}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'product',
            header: t('cases.columns.product'),
            priority: 'secondary',
            cell: (row) => (
                <div className="min-w-0 text-sm">
                    <div className="truncate">{row.product.name}</div>
                    <div className="text-muted-foreground text-xs">
                        {row.product.variant ? `${row.product.variant} · ` : ''}
                        {t('cases.quantity_short', {
                            count: String(row.quantity),
                        })}
                        {row.product.more > 0
                            ? ` · ${t('cases.more_items', { count: String(row.product.more) })}`
                            : ''}
                    </div>
                </div>
            ),
        },
        {
            key: 'status',
            header: t('cases.columns.status'),
            cell: (row) => (
                <div className="min-w-0 space-y-1">
                    <StatusPill
                        tone={row.status_tone}
                        label={row.status_label}
                    />
                    {row.responsibility && (
                        <div className="text-muted-foreground text-xs">
                            {t(`cases.parties.${row.responsibility}`)}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'action',
            header: t('cases.columns.action'),
            cell: (row) =>
                row.next_action ? (
                    <span className="text-sm font-medium">
                        {row.next_action.label}
                    </span>
                ) : row.waiting_for ? (
                    <span className="text-muted-foreground text-xs">
                        {t('cases.waiting_for', { step: row.waiting_for })}
                    </span>
                ) : (
                    <span className="text-muted-foreground text-xs">—</span>
                ),
        },
        {
            key: 'submitted',
            header: t('cases.columns.submitted'),
            priority: 'secondary',
            cell: (row) => (
                <span className="text-sm whitespace-nowrap">
                    {new Date(row.submitted_at).toLocaleDateString(locale)}
                </span>
            ),
        },
    ];

    const tabs = (
        <div
            role="tablist"
            aria-label={t('cases.tabs.label')}
            className="-mx-4 flex gap-1 overflow-x-auto border-b px-4 sm:mx-0 sm:px-0"
        >
            {visibleTabs.map((tab) => {
                const selected = (activeType ?? 'all') === tab.key;
                const count = counts[tab.key];

                return (
                    <button
                        key={tab.key}
                        type="button"
                        role="tab"
                        aria-selected={selected}
                        onClick={() => setFilter('type', tab.type)}
                        className={cn(
                            'focus-visible:ring-ring -mb-px flex items-center gap-2 border-b-2 px-3 py-2 text-sm whitespace-nowrap focus-visible:ring-2 focus-visible:outline-none',
                            selected
                                ? 'border-primary text-foreground font-medium'
                                : 'text-muted-foreground hover:text-foreground border-transparent',
                        )}
                    >
                        {t(`cases.tabs.${tab.key}`)}
                        {count && (
                            <span className="bg-muted rounded-full px-1.5 text-xs tabular-nums">
                                {count.total}
                                {count.actionable > 0 && (
                                    <span className="sr-only">
                                        {' '}
                                        {t('cases.tabs.waiting', {
                                            count: String(count.actionable),
                                        })}
                                    </span>
                                )}
                            </span>
                        )}
                        {count && count.actionable > 0 && (
                            <span
                                aria-hidden="true"
                                title={t('cases.tabs.waiting', {
                                    count: String(count.actionable),
                                })}
                                className="bg-warning text-warning-foreground rounded-full px-1.5 text-xs tabular-nums"
                            >
                                {count.actionable}
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );

    const statusOptions = options.statuses.filter(
        (option) =>
            activeType === undefined ||
            (activeType === 'damage'
                ? option.kind === 'damage'
                : option.kind === 'return'),
    );

    const filterBar = (
        <div className="flex flex-wrap items-center gap-2">
            <NativeSelect
                size="sm"
                aria-label={t('cases.filters.status')}
                value={filters.status ?? ''}
                onChange={(event) =>
                    setFilter('status', event.target.value || undefined)
                }
                className="sm:w-44"
            >
                <option value="">{t('cases.filters.all_statuses')}</option>
                {statusOptions.map((option) => (
                    <option
                        key={`${option.kind}-${option.value}`}
                        value={option.value}
                    >
                        {option.label}
                    </option>
                ))}
            </NativeSelect>

            <NativeSelect
                size="sm"
                aria-label={t('cases.filters.partner')}
                value={filters.partner ?? ''}
                onChange={(event) =>
                    setFilter('partner', event.target.value || undefined)
                }
                className="sm:w-44"
            >
                <option value="">{t('cases.filters.all_partners')}</option>
                {options.partners.map((partner) => (
                    <option key={partner.id} value={partner.id}>
                        {partner.name}
                    </option>
                ))}
            </NativeSelect>

            <NativeSelect
                size="sm"
                aria-label={t('cases.filters.store')}
                value={filters.store ?? ''}
                onChange={(event) =>
                    setFilter('store', event.target.value || undefined)
                }
                className="sm:w-40"
            >
                <option value="">{t('cases.filters.all_stores')}</option>
                {options.stores.map((store) => (
                    <option key={store.id} value={store.id}>
                        {store.name}
                    </option>
                ))}
            </NativeSelect>

            <NativeSelect
                size="sm"
                aria-label={t('cases.filters.responsibility')}
                value={filters.responsibility ?? ''}
                onChange={(event) =>
                    setFilter('responsibility', event.target.value || undefined)
                }
                className="sm:w-40"
            >
                <option value="">
                    {t('cases.filters.any_responsibility')}
                </option>
                <option value="partner">{t('cases.parties.partner')}</option>
                <option value="banij">{t('cases.parties.banij')}</option>
            </NativeSelect>

            <label className="flex items-center gap-1.5 text-xs">
                <span className="text-muted-foreground">
                    {t('cases.filters.from')}
                </span>
                <Input
                    type="date"
                    value={filters.from ?? ''}
                    onChange={(event) =>
                        setFilter('from', event.target.value || undefined)
                    }
                    className="h-8 w-36"
                />
            </label>
            <label className="flex items-center gap-1.5 text-xs">
                <span className="text-muted-foreground">
                    {t('cases.filters.to')}
                </span>
                <Input
                    type="date"
                    value={filters.to ?? ''}
                    onChange={(event) =>
                        setFilter('to', event.target.value || undefined)
                    }
                    className="h-8 w-36"
                />
            </label>
        </div>
    );

    return (
        <>
            <Head title={t('cases.title')} />

            <PageContainer>
                <PageHeader
                    title={t('cases.title')}
                    description={t('cases.description')}
                />

                {tabs}

                <DataTable
                    columns={columns}
                    paginator={cases}
                    rowKey={(row) => `${row.kind}-${row.id}`}
                    caption={t('cases.caption')}
                    searchPlaceholder={t('cases.search')}
                    onlyReload={['cases', 'filters', 'counts']}
                    filters={filterBar}
                    renderCard={(row) => (
                        <div className="space-y-2">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <Link
                                        href={link(row)}
                                        className="block truncate font-mono text-sm font-medium underline-offset-4 hover:underline"
                                    >
                                        {row.reference}
                                    </Link>
                                    <div className="text-muted-foreground truncate text-xs">
                                        {t(`cases.kinds.${row.kind}`)} ·{' '}
                                        {row.order.reference}
                                        {row.exchange_order
                                            ? ` → ${row.exchange_order}`
                                            : ''}
                                    </div>
                                </div>
                                <StatusPill
                                    tone={row.status_tone}
                                    label={row.status_label}
                                />
                            </div>
                            <div className="text-sm break-words">
                                {row.product.name}
                                {row.product.variant
                                    ? ` · ${row.product.variant}`
                                    : ''}{' '}
                                × {row.quantity}
                            </div>
                            <div className="text-muted-foreground text-xs">
                                {row.partner}
                                {row.store ? ` · ${row.store}` : ''}
                            </div>
                            {row.next_action ? (
                                <div className="text-sm font-medium">
                                    {row.next_action.label}
                                </div>
                            ) : row.waiting_for ? (
                                <div className="text-muted-foreground text-xs">
                                    {t('cases.waiting_for', {
                                        step: row.waiting_for,
                                    })}
                                </div>
                            ) : null}
                        </div>
                    )}
                    emptyState={
                        <EmptyState
                            icon={Undo2}
                            title={t(
                                filtered ? 'cases.no_matches' : 'cases.empty',
                            )}
                            description={t(
                                filtered
                                    ? 'cases.no_matches_help'
                                    : 'cases.empty_help',
                            )}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

AdminCases.layout = {
    breadcrumbs: [{ title: 'nav.cases', href: casesIndex() }],
};
