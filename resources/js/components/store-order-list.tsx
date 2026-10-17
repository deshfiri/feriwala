import { Link } from '@inertiajs/react';
import { Store } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import MoneyAmount from '@/components/money-amount';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import type { Column, Paginator } from '@/types';

export type StoreOrderRow = {
    id: string;
    reference: string;
    storefront_reference: string | null;
    storefront: { id: string | null; name: string | null };
    partner: { id: string; name: string } | null;
    customer: string | null;
    step: string | null;
    step_label: string;
    status: string;
    fulfilment: string;
    courier: string;
    delivery: string;
    payment: string | null;
    total: Money;
    placed_at: string;
};

export type StoreOrderFilters = {
    partner?: string | null;
    storefront?: string | null;
    step?: string | null;
    payment?: string | null;
    from?: string | null;
    to?: string | null;
    search?: string | null;
};

type Option = { value: string; label: string };

type Props = {
    orders: Paginator<StoreOrderRow>;
    filters: StoreOrderFilters;
    steps: Option[];
    storefronts: Option[];
    partners?: Option[];
    payments?: string[];
    /** Staff see four separate badges; a Partner a simpler list. */
    forStaff: boolean;
    showUrl: (id: string) => string;
};

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-lg border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * The Store Orders table, for staff (every Partner, four status badges) and for a
 * Partner (their own storefronts only). One component so the two lists cannot
 * drift apart; what each may see is decided by the server's query, not here.
 */
export default function StoreOrderList({
    orders,
    filters,
    steps,
    storefronts,
    partners,
    payments,
    forStaff,
    showUrl,
}: Props) {
    const { t, locale } = useTranslation();
    const { setFilter } = useTableQuery({ only: ['orders', 'filters'] });
    const filtered = Object.values(filters).some(Boolean);

    const select = (
        key: keyof StoreOrderFilters,
        label: string,
        options: Option[],
    ) => (
        <select
            aria-label={label}
            value={filters[key] ?? ''}
            onChange={(event) =>
                setFilter(key as string, event.target.value || undefined)
            }
            className={selectClass}
        >
            <option value="">{label}</option>
            {options.map((option) => (
                <option key={option.value} value={option.value}>
                    {option.label}
                </option>
            ))}
        </select>
    );

    const badges = (row: StoreOrderRow) => (
        <div className="flex flex-wrap gap-1">
            <StatusPill
                tone={
                    row.step === 'delivered' || row.step === 'completed'
                        ? 'success'
                        : row.step
                          ? 'info'
                          : 'danger'
                }
                label={row.step_label}
            />
            {forStaff && (
                <>
                    <StatusPill
                        tone="neutral"
                        label={`${t('store_orders.fulfilment')}: ${t(`store_orders.axes.${row.fulfilment}`)}`}
                    />
                    <StatusPill
                        tone="neutral"
                        label={`${t('store_orders.courier')}: ${t(`store_orders.axes.${row.courier}`)}`}
                    />
                </>
            )}
            <StatusPill
                tone={row.payment === 'paid' ? 'success' : 'neutral'}
                label={`${t('store_orders.payment')}: ${row.payment ? t(`orders.admin.payment_statuses.${row.payment}`) : '—'}`}
            />
        </div>
    );

    const columns: Column<StoreOrderRow>[] = [
        {
            key: 'reference',
            header: t('store_orders.columns.order'),
            cell: (row) => (
                <div className="min-w-0">
                    <Link
                        href={showUrl(row.id)}
                        className="font-mono text-sm font-medium underline-offset-4 hover:underline"
                    >
                        {row.reference}
                    </Link>
                    {row.storefront_reference && (
                        <div className="text-muted-foreground truncate font-mono text-xs">
                            {row.storefront_reference}
                        </div>
                    )}
                </div>
            ),
        },
        ...(forStaff
            ? [
                  {
                      key: 'partner',
                      header: t('store_orders.columns.partner'),
                      cell: (row: StoreOrderRow) => (
                          <span className="text-sm">{row.partner?.name}</span>
                      ),
                  },
              ]
            : []),
        {
            key: 'storefront',
            header: t('store_orders.columns.storefront'),
            cell: (row) => (
                <span className="text-sm">{row.storefront.name}</span>
            ),
        },
        {
            key: 'status',
            header: t('store_orders.columns.status'),
            cell: badges,
        },
        {
            key: 'total',
            header: t('store_orders.columns.total'),
            align: 'end',
            cell: (row) => <MoneyAmount amount={row.total} />,
        },
        {
            key: 'placed',
            header: t('store_orders.columns.placed'),
            priority: 'secondary',
            cell: (row) => (
                <span className="text-sm">
                    {new Date(row.placed_at).toLocaleString(locale)}
                </span>
            ),
        },
    ];

    return (
        <DataTable
            columns={columns}
            paginator={orders}
            rowKey={(row) => row.id}
            caption={t('store_orders.title')}
            searchPlaceholder={t('store_orders.search')}
            onlyReload={['orders', 'filters']}
            filters={
                <>
                    {forStaff &&
                        partners &&
                        select(
                            'partner',
                            t('store_orders.filters.partner'),
                            partners,
                        )}
                    {select(
                        'storefront',
                        t('store_orders.filters.storefront'),
                        storefronts,
                    )}
                    {select('step', t('store_orders.filters.status'), steps)}
                    {forStaff &&
                        payments &&
                        select(
                            'payment',
                            t('store_orders.filters.payment'),
                            payments.map((value) => ({
                                value,
                                label: t(
                                    `orders.admin.payment_statuses.${value}`,
                                ),
                            })),
                        )}
                    <input
                        type="date"
                        aria-label={t('store_orders.filters.from')}
                        value={filters.from ?? ''}
                        onChange={(event) =>
                            setFilter('from', event.target.value || undefined)
                        }
                        className={selectClass}
                    />
                    <input
                        type="date"
                        aria-label={t('store_orders.filters.to')}
                        value={filters.to ?? ''}
                        onChange={(event) =>
                            setFilter('to', event.target.value || undefined)
                        }
                        className={selectClass}
                    />
                </>
            }
            renderCard={(row) => (
                <div className="space-y-2">
                    <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <Link
                                href={showUrl(row.id)}
                                className="block truncate font-mono text-sm font-medium underline-offset-4 hover:underline"
                            >
                                {row.reference}
                            </Link>
                            <div className="text-muted-foreground truncate text-xs">
                                {forStaff ? `${row.partner?.name} · ` : ''}
                                {row.storefront.name}
                            </div>
                        </div>
                        <MoneyAmount amount={row.total} />
                    </div>
                    {badges(row)}
                </div>
            )}
            emptyState={
                <EmptyState
                    icon={Store}
                    title={t(
                        filtered
                            ? 'store_orders.no_matches'
                            : 'store_orders.empty',
                    )}
                    description={t(
                        filtered
                            ? 'store_orders.no_matches_help'
                            : 'store_orders.empty_help',
                    )}
                />
            }
        />
    );
}
