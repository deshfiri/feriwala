import { Head, Link } from '@inertiajs/react';
import { Coins } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import { index, show } from '@/routes/admin/supplier-offers';
import type { Column, Paginator } from '@/types';

type Row = {
    id: string;
    reference: string;
    supplier: string;
    product_name: string;
    variant_sku: string | null;
    status: string;
    is_preferred: boolean;
    supplier_rate: Money;
    platform_rate: Money;
    platform_margin: Money;
};

export default function AdminSupplierOffersIndex({
    offers,
}: {
    offers: Paginator<Row>;
}) {
    const { t } = useTranslation();

    const columns: Column<Row>[] = [
        {
            key: 'product',
            header: t('supplier.admin.offers.columns.product'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate font-medium">
                        {row.product_name}
                    </div>
                    <div className="text-muted-foreground truncate text-xs">
                        {row.reference}
                        {row.variant_sku ? ` · ${row.variant_sku}` : ''}
                    </div>
                </div>
            ),
        },
        {
            key: 'supplier',
            header: t('supplier.admin.offers.columns.supplier'),
            priority: 'secondary',
            cell: (row) => row.supplier,
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
            key: 'status',
            header: t('supplier.admin.offers.columns.status'),
            cell: (row) => (
                <div className="flex flex-wrap gap-1">
                    <StatusPill
                        tone={row.status === 'active' ? 'success' : 'danger'}
                        label={
                            row.status === 'active'
                                ? t('supplier.offers.state_active')
                                : t('supplier.offers.state_suspended')
                        }
                    />
                    {row.is_preferred && (
                        <StatusPill
                            tone="info"
                            label={t('supplier.admin.offers.preferred')}
                        />
                    )}
                </div>
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
                        {t('supplier.admin.suppliers.open')}
                    </Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title={t('supplier.admin.offers.title')} />

            <PageContainer>
                <PageHeader
                    title={t('supplier.admin.offers.title')}
                    description={t('supplier.admin.offers.description')}
                />

                <DataTable
                    columns={columns}
                    paginator={offers}
                    rowKey={(row) => row.id}
                    caption={t('supplier.admin.offers.title')}
                    onlyReload={['offers']}
                    emptyState={
                        <EmptyState
                            icon={Coins}
                            title={t('supplier.admin.offers.empty_title')}
                            description={t(
                                'supplier.admin.offers.empty_description',
                            )}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

AdminSupplierOffersIndex.layout = {
    breadcrumbs: [{ title: 'nav.supplier_offers', href: index() }],
};
