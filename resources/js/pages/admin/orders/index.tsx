import { Head, Link } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import type { StatusTone } from '@/lib/status';
import { index, show } from '@/routes/admin/orders';
import type { Column, Paginator } from '@/types';

type OrderRow = {
    id: string;
    reference: string;
    account: string;
    source: string;
    website: string | null;
    storefront_reference: string | null;
    /** Who the order is for, as the order recorded them. */
    customer: string | null;
    customer_phone: string | null;
    status: string;
    status_tone: StatusTone;
    payment_status: string | null;
    needs_attention: boolean;
    total: Money;
    placed_at: string;
};

type Props = {
    orders: Paginator<OrderRow>;
    filters: {
        search: string | null;
        status: string | null;
        source: string | null;
        /** One storefront's public id, from a website's own admin page. */
        website?: string | null;
        /** One Partner's public id. */
        partner?: string | null;
    };
    statuses: string[];
    sources: string[];
    /** Partners and storefronts, for narrowing storefront orders. */
    partners: Option[];
    storefronts: Option[];
};

type Option = { value: string; label: string };

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-lg border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Every order on the platform, for the staff who review them (§18.4) — storefront
 * orders included, so there is one list to work from rather than two.
 *
 * Held orders come first, then orders waiting for payment, because those are the
 * ones somebody may have to act on. "Needs attention" is said in words beside the
 * status, never by colour alone. With storefront orders chosen, they can be
 * narrowed to one Partner or one storefront, as the old Store Orders list could.
 */
export default function AdminOrders({
    orders,
    filters,
    statuses,
    sources,
    partners,
    storefronts,
}: Props) {
    const { t, locale } = useTranslation();
    const { setFilter } = useTableQuery({ only: ['orders', 'filters'] });
    const filtered = Boolean(
        filters.search ||
        filters.status ||
        filters.source ||
        filters.website ||
        filters.partner,
    );
    const storefrontOrders = filters.source === 'website';

    const status = (row: OrderRow) => (
        <div className="min-w-0 space-y-1">
            <StatusPill
                tone={row.status_tone}
                label={t(`orders.statuses.${row.status}`)}
            />
            {row.needs_attention && (
                <div className="text-warning-foreground text-xs font-medium">
                    {t('orders.admin.needs_attention')}
                </div>
            )}
            {row.payment_status && (
                <div className="text-muted-foreground text-xs">
                    {t(`orders.admin.payment_statuses.${row.payment_status}`)}
                </div>
            )}
        </div>
    );

    const columns: Column<OrderRow>[] = [
        {
            key: 'reference',
            header: t('orders.admin.columns.reference'),
            cell: (row) => (
                <Link
                    href={show(row.id)}
                    className="font-mono text-sm font-medium underline-offset-4 hover:underline"
                >
                    {row.reference}
                </Link>
            ),
        },
        {
            key: 'customer',
            header: t('orders.customer_card.title'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate text-sm">
                        {row.customer ?? '—'}
                    </div>
                    {row.customer_phone && (
                        <div className="text-muted-foreground truncate font-mono text-xs">
                            {row.customer_phone}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'account',
            header: t('orders.admin.columns.account'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate text-sm">{row.account}</div>
                    <div className="text-muted-foreground truncate text-xs">
                        {t(`orders.admin.sources.${row.source}`)}
                        {row.website ? ` · ${row.website}` : ''}
                    </div>
                    {row.storefront_reference && (
                        <div className="text-muted-foreground truncate font-mono text-xs">
                            {row.storefront_reference}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'status',
            header: t('orders.admin.columns.status'),
            cell: status,
        },
        {
            key: 'total',
            header: t('orders.admin.columns.total'),
            align: 'end',
            cell: (row) => <MoneyAmount amount={row.total} />,
        },
        {
            key: 'placed',
            header: t('orders.admin.columns.placed'),
            priority: 'secondary',
            cell: (row) => (
                <span className="text-sm">
                    {new Date(row.placed_at).toLocaleString(locale)}
                </span>
            ),
        },
    ];

    return (
        <>
            <Head title={t('orders.admin.title')} />

            <PageContainer>
                <PageHeader
                    title={t('orders.admin.title')}
                    description={t('orders.admin.description')}
                />

                <DataTable
                    columns={columns}
                    paginator={orders}
                    rowKey={(row) => row.id}
                    caption={t('orders.admin.caption')}
                    searchPlaceholder={t('orders.admin.search')}
                    onlyReload={['orders', 'filters']}
                    filters={
                        <>
                            <select
                                aria-label={t('orders.admin.filter_status')}
                                value={filters.status ?? ''}
                                onChange={(event) =>
                                    setFilter(
                                        'status',
                                        event.target.value || undefined,
                                    )
                                }
                                className={selectClass}
                            >
                                <option value="">
                                    {t('orders.admin.all_statuses')}
                                </option>
                                {statuses.map((value) => (
                                    <option key={value} value={value}>
                                        {t(`orders.statuses.${value}`)}
                                    </option>
                                ))}
                            </select>
                            <select
                                aria-label={t('orders.admin.filter_source')}
                                value={filters.source ?? ''}
                                onChange={(event) =>
                                    setFilter(
                                        'source',
                                        event.target.value || undefined,
                                    )
                                }
                                className={selectClass}
                            >
                                <option value="">
                                    {t('orders.admin.all_sources')}
                                </option>
                                {sources.map((value) => (
                                    <option key={value} value={value}>
                                        {t(`orders.admin.sources.${value}`)}
                                    </option>
                                ))}
                            </select>
                            {storefrontOrders && (
                                <>
                                    <select
                                        aria-label={t(
                                            'orders.admin.filter_partner',
                                        )}
                                        value={filters.partner ?? ''}
                                        onChange={(event) =>
                                            setFilter(
                                                'partner',
                                                event.target.value || undefined,
                                            )
                                        }
                                        className={selectClass}
                                    >
                                        <option value="">
                                            {t('orders.admin.all_partners')}
                                        </option>
                                        {partners.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                    <select
                                        aria-label={t(
                                            'orders.admin.filter_storefront',
                                        )}
                                        value={filters.website ?? ''}
                                        onChange={(event) =>
                                            setFilter(
                                                'website',
                                                event.target.value || undefined,
                                            )
                                        }
                                        className={selectClass}
                                    >
                                        <option value="">
                                            {t('orders.admin.all_storefronts')}
                                        </option>
                                        {storefronts.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                </>
                            )}
                        </>
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
                                        {row.customer
                                            ? `${row.customer}${row.customer_phone ? ` · ${row.customer_phone}` : ''}`
                                            : row.account}
                                    </div>
                                </div>
                                <MoneyAmount amount={row.total} />
                            </div>
                            {status(row)}
                        </div>
                    )}
                    emptyState={
                        <EmptyState
                            icon={ClipboardList}
                            title={t(
                                filtered
                                    ? 'orders.admin.no_matches'
                                    : 'orders.admin.empty',
                            )}
                            description={t(
                                filtered
                                    ? 'orders.admin.no_matches_help'
                                    : 'orders.admin.empty_help',
                            )}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

AdminOrders.layout = {
    breadcrumbs: [
        {
            title: 'nav.orders',
            href: index(),
        },
    ],
};
