import { Head, Link } from '@inertiajs/react';
import { Coins } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import MoneyAmount from '@/components/money-amount';
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
import type { Money } from '@/lib/money';
import { index, show } from '@/routes/admin/supplier-payables';
import type { Column, Paginator } from '@/types';

const ALL = 'all';
const RELOAD_PROPS = ['payables'];

type Row = {
    id: string;
    reference: string;
    supplier: string;
    order_reference: string;
    quantity: number;
    net_amount: Money;
    status: string;
    status_label: string;
    status_tone: string;
    created_at: string;
};

/**
 * Every Supplier payable, for staff holding `supplier_payable.view` — a
 * permission deliberately separate from `supplier_payable.approve`, the
 * settlement right P13-23 adds (D25, P13-22).
 */
export default function AdminSupplierPayablesIndex({
    payables,
    statuses,
}: {
    payables: Paginator<Row>;
    statuses: { value: string; label: string }[];
}) {
    const { t, locale } = useTranslation();
    const { getFilter, setFilter } = useTableQuery({ only: RELOAD_PROPS });

    const columns: Column<Row>[] = [
        {
            key: 'reference',
            header: t('supplier.admin.payables.columns.reference'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate font-medium">{row.reference}</div>
                    <div className="text-muted-foreground truncate text-xs">
                        {row.order_reference}
                    </div>
                </div>
            ),
        },
        {
            key: 'supplier',
            header: t('supplier.admin.payables.columns.supplier'),
            priority: 'secondary',
            cell: (row) => row.supplier,
        },
        {
            key: 'amount',
            header: t('supplier.admin.payables.columns.amount'),
            align: 'end',
            cell: (row) => <MoneyAmount amount={row.net_amount} />,
        },
        {
            key: 'status',
            header: t('supplier.admin.payables.columns.status'),
            cell: (row) => (
                <StatusPill
                    tone={row.status_tone as never}
                    label={row.status_label}
                />
            ),
        },
        {
            key: 'created_at',
            header: t('supplier.admin.payables.columns.created'),
            priority: 'secondary',
            cell: (row) => new Date(row.created_at).toLocaleDateString(locale),
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
            <Head title={t('supplier.admin.payables.title')} />

            <PageContainer>
                <PageHeader
                    title={t('supplier.admin.payables.title')}
                    description={t('supplier.admin.payables.description')}
                />

                <DataTable
                    columns={columns}
                    paginator={payables}
                    rowKey={(row) => row.id}
                    caption={t('supplier.admin.payables.title')}
                    searchPlaceholder={t(
                        'supplier.admin.allocations.search_placeholder',
                    )}
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
                                        'supplier.admin.payables.columns.status',
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
                            icon={Coins}
                            title={t('supplier.admin.payables.empty_title')}
                            description={t(
                                'supplier.admin.payables.empty_description',
                            )}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

AdminSupplierPayablesIndex.layout = {
    breadcrumbs: [{ title: 'Supplier payables', href: index() }],
};
