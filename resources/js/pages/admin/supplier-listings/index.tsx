import { Head, Link } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
import { index, show } from '@/routes/admin/supplier-listings';
import type { Column, Paginator } from '@/types';

const ALL = 'all';
const RELOAD_PROPS = ['listings'];

type Row = {
    id: string;
    reference: string;
    product_name: string;
    supplier: string;
    category: string | null;
    items_count: number;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    submitted_at: string | null;
};

export default function AdminSupplierListingsIndex({
    listings,
    statuses,
}: {
    listings: Paginator<Row>;
    statuses: { value: string; label: string }[];
}) {
    const { t, locale } = useTranslation();
    const { getFilter, setFilter } = useTableQuery({ only: RELOAD_PROPS });

    const columns: Column<Row>[] = [
        {
            key: 'product',
            header: t('supplier.admin.listings.columns.product'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate font-medium">
                        {row.product_name}
                    </div>
                    <div className="text-muted-foreground truncate text-xs">
                        {row.reference}
                    </div>
                </div>
            ),
        },
        {
            key: 'supplier',
            header: t('supplier.admin.listings.columns.supplier'),
            priority: 'secondary',
            cell: (row) => row.supplier,
        },
        {
            key: 'items',
            header: t('supplier.admin.listings.columns.items'),
            align: 'end',
            priority: 'secondary',
            cell: (row) => row.items_count,
        },
        {
            key: 'submitted',
            header: t('supplier.admin.listings.columns.submitted'),
            cell: (row) =>
                row.submitted_at
                    ? new Date(row.submitted_at).toLocaleDateString(locale)
                    : '—',
        },
        {
            key: 'status',
            header: t('supplier.admin.listings.columns.status'),
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
            <Head title={t('supplier.admin.listings.title')} />

            <PageContainer>
                <PageHeader
                    title={t('supplier.admin.listings.title')}
                    description={t('supplier.admin.listings.description')}
                />

                <DataTable
                    columns={columns}
                    paginator={listings}
                    rowKey={(row) => row.id}
                    caption={t('supplier.admin.listings.title')}
                    onlyReload={RELOAD_PROPS}
                    filters={
                        <div className="flex flex-wrap items-center gap-2">
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
                                        'supplier.admin.listings.columns.status',
                                    )}
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        {t(
                                            'supplier.admin.suppliers.all_statuses',
                                        )}
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
                            <Input
                                type="date"
                                className="h-8 w-40"
                                aria-label={t(
                                    'supplier.admin.suppliers.submitted_from',
                                )}
                                value={getFilter('submitted_from') ?? ''}
                                onChange={(event) =>
                                    setFilter(
                                        'submitted_from',
                                        event.target.value || undefined,
                                    )
                                }
                            />
                        </div>
                    }
                    emptyState={
                        <EmptyState
                            icon={ClipboardList}
                            title={t('supplier.admin.listings.empty_title')}
                            description={t(
                                'supplier.admin.listings.empty_description',
                            )}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

AdminSupplierListingsIndex.layout = {
    breadcrumbs: [{ title: 'Supplier listings', href: index() }],
};
