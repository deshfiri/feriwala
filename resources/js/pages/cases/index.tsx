import { Head, Link } from '@inertiajs/react';
import { Undo2 } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import NativeSelect from '@/components/forms/native-select';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import { index, show } from '@/routes/cases';
import type { Column, Paginator } from '@/types';
import type { PartnerCaseRow } from '@/types/cases';

type Props = {
    cases: Paginator<PartnerCaseRow>;
    filters: { type: string | null; search: string | null };
};

/**
 * A Partner's Returns & Exchanges: every one they have asked for (D35).
 *
 * Said in plain words — what is happening to it and what, if anything, the Partner
 * has to do — with the product, the quantity, the linked orders, who bears the
 * cost, what they are charged and what is refunded. Only their own: the list is
 * scoped by the signed-in account.
 */
export default function PartnerCases({ cases, filters }: Props) {
    const { t, locale } = useTranslation();
    const { setFilter } = useTableQuery({ only: ['cases', 'filters'] });
    const filtered = Boolean(filters.type || filters.search);

    const charge = (row: PartnerCaseRow) =>
        row.charge_covered_by_banij ? (
            <span className="text-muted-foreground text-xs">
                {t('cases.financials.covered_by_banij')}
            </span>
        ) : row.charge ? (
            <div className="text-right">
                <MoneyAmount amount={row.charge} direction="debit" />
                <div className="text-muted-foreground text-xs">
                    {t(
                        row.charge_is_final
                            ? 'cases.partner.charge_final'
                            : 'cases.partner.charge_estimated',
                    )}
                </div>
            </div>
        ) : (
            <span className="text-muted-foreground text-xs">—</span>
        );

    const columns: Column<PartnerCaseRow>[] = [
        {
            key: 'reference',
            header: t('cases.columns.case'),
            cell: (row) => (
                <div className="min-w-0">
                    <Link
                        href={show(row.id)}
                        className="font-mono text-sm font-medium underline-offset-4 hover:underline"
                    >
                        {row.reference}
                    </Link>
                    <div className="text-muted-foreground text-xs">
                        {row.kind_label}
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
                            {row.exchange_order.reference}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'product',
            header: t('cases.columns.product'),
            priority: 'secondary',
            cell: (row) =>
                row.product ? (
                    <div className="min-w-0 text-sm">
                        <div className="truncate">{row.product.name}</div>
                        <div className="text-muted-foreground text-xs">
                            {row.product.variant
                                ? `${row.product.variant} · `
                                : ''}
                            {t('cases.quantity_short', {
                                count: String(row.quantity),
                            })}
                            {row.product.more > 0
                                ? ` · ${t('cases.more_items', { count: String(row.product.more) })}`
                                : ''}
                        </div>
                    </div>
                ) : null,
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
                    {row.responsibility_label && (
                        <div className="text-muted-foreground text-xs">
                            {row.responsibility_label}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'charge',
            header: t('cases.columns.charge'),
            align: 'end',
            priority: 'secondary',
            cell: charge,
        },
        {
            key: 'refund',
            header: t('cases.columns.refund'),
            align: 'end',
            priority: 'secondary',
            cell: (row) =>
                row.refund ? (
                    <MoneyAmount amount={row.refund} direction="credit" />
                ) : (
                    <span className="text-muted-foreground text-xs">—</span>
                ),
        },
        {
            key: 'updated',
            header: t('cases.columns.updated'),
            priority: 'secondary',
            cell: (row) => (
                <span className="text-sm whitespace-nowrap">
                    {new Date(row.updated_at).toLocaleDateString(locale)}
                </span>
            ),
        },
    ];

    return (
        <>
            <Head title={t('cases.partner.title')} />

            <PageContainer>
                <PageHeader
                    title={t('cases.partner.title')}
                    description={t('cases.partner.description')}
                />

                <DataTable
                    columns={columns}
                    paginator={cases}
                    rowKey={(row) => row.id}
                    caption={t('cases.partner.caption')}
                    searchPlaceholder={t('cases.partner.search')}
                    onlyReload={['cases', 'filters']}
                    filters={
                        <NativeSelect
                            size="sm"
                            aria-label={t('cases.filters.type')}
                            value={filters.type ?? ''}
                            onChange={(event) =>
                                setFilter(
                                    'type',
                                    event.target.value || undefined,
                                )
                            }
                            className="sm:w-44"
                        >
                            <option value="">
                                {t('cases.filters.all_types')}
                            </option>
                            <option value="return">
                                {t('cases.kinds.return')}
                            </option>
                            <option value="exchange">
                                {t('cases.kinds.exchange')}
                            </option>
                        </NativeSelect>
                    }
                    renderCard={(row) => (
                        <div className="space-y-2">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <Link
                                        href={show(row.id)}
                                        className="block truncate font-mono text-sm font-medium underline-offset-4 hover:underline"
                                    >
                                        {row.reference}
                                    </Link>
                                    <div className="text-muted-foreground truncate text-xs">
                                        {row.kind_label} · {row.order.reference}
                                        {row.exchange_order
                                            ? ` → ${row.exchange_order.reference}`
                                            : ''}
                                    </div>
                                </div>
                                <StatusPill
                                    tone={row.status_tone}
                                    label={row.status_label}
                                />
                            </div>
                            {row.product && (
                                <div className="text-sm break-words">
                                    {row.product.name}
                                    {row.product.variant
                                        ? ` · ${row.product.variant}`
                                        : ''}{' '}
                                    × {row.quantity}
                                </div>
                            )}
                            <div className="flex flex-wrap items-center justify-between gap-2 text-xs">
                                {charge(row)}
                                {row.refund && (
                                    <MoneyAmount
                                        amount={row.refund}
                                        direction="credit"
                                    />
                                )}
                            </div>
                        </div>
                    )}
                    emptyState={
                        <EmptyState
                            icon={Undo2}
                            title={t(
                                filtered
                                    ? 'cases.no_matches'
                                    : 'cases.partner.empty',
                            )}
                            description={t(
                                filtered
                                    ? 'cases.no_matches_help'
                                    : 'cases.partner.empty_help',
                            )}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

PartnerCases.layout = {
    breadcrumbs: [{ title: 'nav.returns_exchanges', href: index() }],
};
