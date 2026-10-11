import { Head, Link } from '@inertiajs/react';
import { ImageOff, PackageCheck } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import MoneyAmount from '@/components/money-amount';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { show } from '@/routes/supplier/offers';
import type { Column, Paginator, SupplierOwnOffer } from '@/types';

/**
 * "My Products": every Product this Supplier holds, from approved listings and
 * from staff assignment alike. Shows what the Supplier agreed to be paid — its
 * Supplier Base Price — and never the platform's own prices or margin.
 */
export default function SupplierOffersIndex({
    offers,
}: {
    offers: Paginator<SupplierOwnOffer>;
}) {
    const { t, locale } = useTranslation();

    const columns: Column<SupplierOwnOffer>[] = [
        {
            key: 'product',
            header: t('supplier.offers.product'),
            cell: (row) => (
                <div className="flex min-w-0 items-center gap-3">
                    <div className="bg-muted flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-md border">
                        {row.image ? (
                            <img
                                src={row.image.url}
                                alt={row.image.alt ?? row.product_name}
                                className="size-full object-cover"
                                loading="lazy"
                            />
                        ) : (
                            <ImageOff
                                className="text-muted-foreground size-4"
                                aria-label={t('supplier.offers.no_image')}
                            />
                        )}
                    </div>
                    <div className="min-w-0">
                        <div className="truncate font-medium">
                            {row.product_name}
                        </div>
                        <div className="text-muted-foreground text-xs">
                            {t('supplier.offers.bpc')} {row.bpc}
                            {row.variant
                                ? ` · ${row.variant.label} (${row.variant.sku})`
                                : ''}
                        </div>
                    </div>
                </div>
            ),
        },
        {
            key: 'rate',
            header: t('supplier.offers.rate'),
            align: 'end',
            cell: (row) => <MoneyAmount amount={row.supplier_rate} />,
        },
        {
            key: 'supply',
            header: t('supplier.offers.supply_mode'),
            priority: 'secondary',
            cell: (row) => (
                <div className="text-sm">
                    <div>{row.supply_mode_label}</div>
                    <div className="text-muted-foreground text-xs">
                        {row.supply_mode === 'ready_stock'
                            ? t('supplier.offers.in_stock', {
                                  count: (
                                      row.available_quantity ?? 0
                                  ).toLocaleString(locale),
                              })
                            : row.fulfilment_capacity !== null
                              ? t('supplier.offers.capacity', {
                                    count: row.fulfilment_capacity.toLocaleString(
                                        locale,
                                    ),
                                })
                              : '—'}
                    </div>
                </div>
            ),
        },
        {
            key: 'lead',
            header: t('supplier.offers.lead_time'),
            align: 'end',
            priority: 'secondary',
            cell: (row) =>
                row.lead_time_days === null
                    ? '—'
                    : t('supplier.offers.days', { count: row.lead_time_days }),
        },
        {
            key: 'source',
            header: t('supplier.offers.source'),
            priority: 'secondary',
            cell: (row) => row.origin_label,
        },
        {
            key: 'updated',
            header: t('supplier.offers.updated'),
            priority: 'secondary',
            cell: (row) =>
                row.updated_at
                    ? new Date(row.updated_at).toLocaleDateString(locale)
                    : '—',
        },
        {
            key: 'status',
            header: t('supplier.offers.status'),
            cell: (row) => (
                <StatusPill
                    tone={row.status === 'active' ? 'success' : 'danger'}
                    label={
                        row.status === 'active'
                            ? t('supplier.offers.state_active')
                            : t('supplier.offers.state_suspended')
                    }
                />
            ),
        },
        {
            key: 'actions',
            header: '',
            alwaysVisible: true,
            align: 'end',
            cell: (row) => (
                <Button variant="ghost" size="sm" asChild>
                    <Link href={show(row.id)}>
                        {t('supplier.listings.open')}
                    </Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title={t('supplier.offers.title')} />

            <div className="space-y-6">
                <PageHeader
                    title={t('supplier.offers.title')}
                    description={t('supplier.offers.rates_description')}
                />

                <DataTable
                    columns={columns}
                    paginator={offers}
                    rowKey={(row) => row.id}
                    caption={t('supplier.offers.title')}
                    onlyReload={['offers']}
                    emptyState={
                        <EmptyState
                            icon={PackageCheck}
                            title={t('supplier.offers.empty_title')}
                            description={t('supplier.offers.empty_description')}
                        />
                    }
                />
            </div>
        </>
    );
}
