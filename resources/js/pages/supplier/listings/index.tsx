import { Head, Link } from '@inertiajs/react';
import { ClipboardList, Plus } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { StatusTone } from '@/lib/status';
import { create, show } from '@/routes/supplier/listings';
import type { Column, Paginator } from '@/types';

type Row = {
    id: string;
    reference: string;
    product_name: string;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    updated_at: string;
};

export default function SupplierListingsIndex({
    listings,
}: {
    listings: Paginator<Row>;
}) {
    const { t, locale } = useTranslation();

    const columns: Column<Row>[] = [
        {
            key: 'reference',
            header: t('supplier.listings.columns.reference'),
            cell: (row) => row.reference,
        },
        {
            key: 'product',
            header: t('supplier.listings.columns.product'),
            cell: (row) => (
                <span className="font-medium">{row.product_name}</span>
            ),
        },
        {
            key: 'status',
            header: t('supplier.listings.columns.status'),
            cell: (row) => (
                <StatusPill tone={row.status_tone} label={row.status_label} />
            ),
        },
        {
            key: 'updated',
            header: t('supplier.listings.columns.updated'),
            priority: 'secondary',
            cell: (row) => new Date(row.updated_at).toLocaleDateString(locale),
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
            <Head title={t('supplier.listings.title')} />

            <div className="space-y-6">
                <PageHeader
                    title={t('supplier.listings.title')}
                    description={t('supplier.listings.description')}
                    actions={
                        <Button asChild>
                            <Link href={create()}>
                                <Plus className="size-4" aria-hidden="true" />
                                {t('supplier.listings.new')}
                            </Link>
                        </Button>
                    }
                />

                <DataTable
                    columns={columns}
                    paginator={listings}
                    rowKey={(row) => row.id}
                    caption={t('supplier.listings.title')}
                    onlyReload={['listings']}
                    emptyState={
                        <EmptyState
                            icon={ClipboardList}
                            title={t('supplier.listings.empty_title')}
                            description={t(
                                'supplier.listings.empty_description',
                            )}
                        />
                    }
                />
            </div>
        </>
    );
}
