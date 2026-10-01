import { Head, Link } from '@inertiajs/react';
import { Layers } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import type { StatusTone } from '@/lib/status';
import { index, show } from '@/routes/admin/supplier-listing-lots';
import type { Column, Paginator } from '@/types';

const ALL = 'all';
const RELOAD_PROPS = ['lots'];

type Row = {
    id: string;
    reference: string;
    title: string | null;
    supplier: string;
    items_count: number;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    submitted_at: string | null;
};

export default function AdminSupplierListingLotsIndex({
    lots,
    statuses,
}: {
    lots: Paginator<Row>;
    statuses: { value: string; label: string }[];
}) {
    const { t, locale } = useTranslation();
    const { getFilter, setFilter } = useTableQuery({ only: RELOAD_PROPS });

    const columns: Column<Row>[] = [
        {
            key: 'title',
            header: t('supplier.admin.listing_lots.columns.title'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate font-medium">
                        {row.title ?? row.reference}
                    </div>
                    <div className="text-muted-foreground truncate text-xs">
                        {row.reference}
                    </div>
                </div>
            ),
        },
        {
            key: 'supplier',
            header: t('supplier.admin.listing_lots.columns.supplier'),
            priority: 'secondary',
            cell: (row) => row.supplier,
        },
        {
            key: 'items',
            header: t('supplier.admin.listing_lots.columns.items'),
            align: 'end',
            priority: 'secondary',
            cell: (row) => row.items_count,
        },
        {
            key: 'submitted',
            header: t('supplier.admin.listing_lots.columns.submitted'),
            cell: (row) =>
                row.submitted_at
                    ? new Date(row.submitted_at).toLocaleDateString(locale)
                    : '—',
        },
        {
            key: 'status',
            header: t('supplier.admin.listing_lots.columns.status'),
            cell: (row) => (
                <StatusPill tone={row.status_tone} label={row.status_label} />
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
            <Head title={t('supplier.admin.listing_lots.title')} />

            <PageContainer>
                <PageHeader
                    title={t('supplier.admin.listing_lots.title')}
                    description={t('supplier.admin.listing_lots.description')}
                />

                <DataTable
                    columns={columns}
                    paginator={lots}
                    rowKey={(row) => row.id}
                    caption={t('supplier.admin.listing_lots.title')}
                    onlyReload={RELOAD_PROPS}
                    filters={
                        <Select
                            value={getFilter('status') ?? ALL}
                            onValueChange={(value) =>
                                setFilter(
                                    'status',
                                    value === ALL ? undefined : value,
                                )
                            }
                        >
                            <SelectTrigger
                                size="sm"
                                className="w-44"
                                aria-label={t(
                                    'supplier.admin.listing_lots.columns.status',
                                )}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>
                                    {t('supplier.admin.suppliers.all_statuses')}
                                </SelectItem>
                                {statuses.map((status) => (
                                    <SelectItem
                                        key={status.value}
                                        value={status.value}
                                    >
                                        {status.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    }
                    emptyState={
                        <EmptyState
                            icon={Layers}
                            title={t('supplier.admin.listing_lots.empty_title')}
                            description={t(
                                'supplier.admin.listing_lots.empty_description',
                            )}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

AdminSupplierListingLotsIndex.layout = {
    breadcrumbs: [{ title: 'nav.supplier_listing_lots', href: index() }],
};
