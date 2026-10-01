import { Head, Link } from '@inertiajs/react';
import { Layers, Plus } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { StatusTone } from '@/lib/status';
import { create, show } from '@/routes/supplier/listing-lots';
import type { Column, Paginator } from '@/types';

type Row = {
    id: string;
    reference: string;
    title: string | null;
    item_count: number;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    updated_at: string;
};

export default function SupplierListingLotsIndex({
    lots,
}: {
    lots: Paginator<Row>;
}) {
    const { t, locale } = useTranslation();

    const columns: Column<Row>[] = [
        {
            key: 'reference',
            header: t('supplier.listing_lots.columns.reference'),
            cell: (row) => row.reference,
        },
        {
            key: 'title',
            header: t('supplier.listing_lots.columns.title'),
            cell: (row) => (
                <span className="font-medium">
                    {row.title ?? row.reference}
                </span>
            ),
        },
        {
            key: 'items',
            header: t('supplier.listing_lots.columns.items'),
            priority: 'secondary',
            cell: (row) => row.item_count,
        },
        {
            key: 'status',
            header: t('supplier.listing_lots.columns.status'),
            cell: (row) => (
                <StatusPill tone={row.status_tone} label={row.status_label} />
            ),
        },
        {
            key: 'updated',
            header: t('supplier.listing_lots.columns.updated'),
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
                        {t('supplier.listing_lots.open')}
                    </Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title={t('supplier.listing_lots.title')} />

            <PageContainer>
                <PageHeader
                    title={t('supplier.listing_lots.title')}
                    description={t('supplier.listing_lots.description')}
                    actions={
                        <Button asChild>
                            <Link href={create()}>
                                <Plus className="size-4" aria-hidden="true" />
                                {t('supplier.listing_lots.new')}
                            </Link>
                        </Button>
                    }
                />

                <DataTable
                    columns={columns}
                    paginator={lots}
                    rowKey={(row) => row.id}
                    caption={t('supplier.listing_lots.title')}
                    onlyReload={['lots']}
                    emptyState={
                        <EmptyState
                            icon={Layers}
                            title={t('supplier.listing_lots.empty_title')}
                            description={t(
                                'supplier.listing_lots.empty_description',
                            )}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}
