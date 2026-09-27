import { Head, Link } from '@inertiajs/react';
import { PackageSearch } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import { index, show } from '@/routes/admin/supplier-allocations';
import type { Column, Paginator } from '@/types';

const RELOAD_PROPS = ['allocations'];

type Row = {
    id: string;
    order_reference: string;
    order_status: string;
    placed_at: string | null;
    supplier: string;
    product_name: string;
    variant: string | null;
    quantity: number;
    supplier_rate: Money;
    platform_rate: Money;
    platform_margin: Money;
};

/**
 * Every Supplier-backed order line, for staff holding `supplier_pricing.view`
 * (D25, P13-21). Every row carries the Supplier Rate, the Platform Rate and
 * the margin between them — never shown to a Client, a Partner or a Supplier.
 */
export default function AdminSupplierAllocationsIndex({
    allocations,
}: {
    allocations: Paginator<Row>;
}) {
    const { t, locale } = useTranslation();
    const { getFilter, setFilter } = useTableQuery({ only: RELOAD_PROPS });

    const columns: Column<Row>[] = [
        {
            key: 'product',
            header: t('supplier.admin.allocations.columns.product'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate font-medium">
                        {row.product_name}
                    </div>
                    <div className="text-muted-foreground truncate text-xs">
                        {row.order_reference}
                    </div>
                </div>
            ),
        },
        {
            key: 'supplier',
            header: t('supplier.admin.allocations.columns.supplier'),
            priority: 'secondary',
            cell: (row) => row.supplier,
        },
        {
            key: 'quantity',
            header: t('supplier.admin.allocations.columns.quantity'),
            align: 'end',
            cell: (row) => row.quantity.toLocaleString(locale),
        },
        {
            key: 'supplier_rate',
            header: t('supplier.admin.offers.columns.supplier_rate'),
            align: 'end',
            cell: (row) => <MoneyAmount amount={row.supplier_rate} />,
        },
        {
            key: 'platform_rate',
            header: t('supplier.admin.offers.columns.platform_rate'),
            align: 'end',
            priority: 'secondary',
            cell: (row) => <MoneyAmount amount={row.platform_rate} />,
        },
        {
            key: 'margin',
            header: t('supplier.admin.offers.columns.margin'),
            align: 'end',
            priority: 'secondary',
            cell: (row) => <MoneyAmount amount={row.platform_margin} />,
        },
        {
            key: 'actions',
            header: '',
            alwaysVisible: true,
            align: 'end',
            cell: (row) => (
                <Button variant="ghost" size="sm" asChild>
                    <Link href={show(row.id)}>
                        {t('supplier.admin.suppliers.open')}
                    </Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title={t('supplier.admin.allocations.title')} />

            <PageContainer>
                <PageHeader
                    title={t('supplier.admin.allocations.title')}
                    description={t('supplier.admin.allocations.description')}
                />

                <DataTable
                    columns={columns}
                    paginator={allocations}
                    rowKey={(row) => row.id}
                    caption={t('supplier.admin.allocations.title')}
                    searchPlaceholder={t(
                        'supplier.admin.allocations.search_placeholder',
                    )}
                    onlyReload={RELOAD_PROPS}
                    filters={
                        <div className="flex flex-wrap items-center gap-2">
                            <Input
                                className="h-8 w-40"
                                placeholder={t(
                                    'supplier.admin.allocations.supplier_filter',
                                )}
                                value={getFilter('supplier') ?? ''}
                                onChange={(event) =>
                                    setFilter(
                                        'supplier',
                                        event.target.value || undefined,
                                    )
                                }
                            />
                            <Input
                                className="h-8 w-36"
                                placeholder={t(
                                    'supplier.admin.allocations.order_filter',
                                )}
                                value={getFilter('order') ?? ''}
                                onChange={(event) =>
                                    setFilter(
                                        'order',
                                        event.target.value || undefined,
                                    )
                                }
                            />
                            <Input
                                type="date"
                                className="h-8 w-40"
                                aria-label={t(
                                    'supplier.admin.suppliers.submitted_from',
                                )}
                                value={getFilter('date_from') ?? ''}
                                onChange={(event) =>
                                    setFilter(
                                        'date_from',
                                        event.target.value || undefined,
                                    )
                                }
                            />
                            <Input
                                type="date"
                                className="h-8 w-40"
                                aria-label={t(
                                    'supplier.admin.suppliers.submitted_to',
                                )}
                                value={getFilter('date_to') ?? ''}
                                onChange={(event) =>
                                    setFilter(
                                        'date_to',
                                        event.target.value || undefined,
                                    )
                                }
                            />
                        </div>
                    }
                    emptyState={
                        <EmptyState
                            icon={PackageSearch}
                            title={t('supplier.admin.allocations.empty_title')}
                            description={t(
                                'supplier.admin.allocations.empty_description',
                            )}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

AdminSupplierAllocationsIndex.layout = {
    breadcrumbs: [{ title: 'nav.supplier_allocations', href: index() }],
};
