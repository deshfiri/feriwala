import { Head, Link } from '@inertiajs/react';
import { Receipt } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
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
import { show as showPayable } from '@/routes/supplier/payables';
import { show as showWithdrawal } from '@/routes/supplier/withdrawals';
import type { Column, Paginator } from '@/types';

const ALL = 'all';
const RELOAD_PROPS = ['entries'];

type Row = {
    id: string;
    reference: string;
    type_label: string;
    is_credit: boolean;
    amount: Money;
    balance_after: Money;
    description: string;
    related_payable_id: string | null;
    related_payable_reference: string | null;
    related_withdrawal_id: string | null;
    related_withdrawal_reference: string | null;
    created_at: string;
};

export default function SupplierWalletTransactions({
    entries,
    types,
}: {
    entries: Paginator<Row>;
    types: { value: string; label: string }[];
}) {
    const { t, locale } = useTranslation();
    const { getFilter, setFilter } = useTableQuery({ only: RELOAD_PROPS });

    const columns: Column<Row>[] = [
        {
            key: 'description',
            header: t('supplier.transactions.description_column'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate font-medium">{row.type_label}</div>
                    <div className="text-muted-foreground truncate text-xs">
                        {row.reference} — {row.description}
                    </div>
                </div>
            ),
        },
        {
            key: 'amount',
            header: t('supplier.transactions.amount'),
            align: 'end',
            cell: (row) => (
                <MoneyAmount
                    amount={row.amount}
                    direction={row.is_credit ? 'credit' : 'debit'}
                    showSign
                />
            ),
        },
        {
            key: 'balance_after',
            header: t('supplier.transactions.balance_after'),
            align: 'end',
            priority: 'secondary',
            cell: (row) => <MoneyAmount amount={row.balance_after} />,
        },
        {
            key: 'related',
            header: t('supplier.transactions.related'),
            priority: 'secondary',
            cell: (row) =>
                row.related_payable_id ? (
                    <Link
                        href={showPayable(row.related_payable_id)}
                        className="underline-offset-4 hover:underline"
                    >
                        {row.related_payable_reference}
                    </Link>
                ) : row.related_withdrawal_id ? (
                    <Link
                        href={showWithdrawal(row.related_withdrawal_id)}
                        className="underline-offset-4 hover:underline"
                    >
                        {row.related_withdrawal_reference}
                    </Link>
                ) : (
                    '—'
                ),
        },
        {
            key: 'created_at',
            header: t('supplier.transactions.date'),
            priority: 'secondary',
            cell: (row) => new Date(row.created_at).toLocaleDateString(locale),
        },
    ];

    return (
        <>
            <Head title={t('supplier.transactions.title')} />

            <PageContainer>
                <PageHeader
                    title={t('supplier.transactions.title')}
                    description={t('supplier.transactions.description')}
                />

                <DataTable
                    columns={columns}
                    paginator={entries}
                    rowKey={(row) => row.id}
                    caption={t('supplier.transactions.title')}
                    onlyReload={RELOAD_PROPS}
                    filters={
                        <Select
                            value={getFilter('type') ?? ALL}
                            onValueChange={(value) =>
                                setFilter(
                                    'type',
                                    value === ALL ? undefined : value,
                                )
                            }
                        >
                            <SelectTrigger
                                size="sm"
                                className="w-48"
                                aria-label={t('supplier.transactions.type')}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>
                                    {t('supplier.transactions.all_types')}
                                </SelectItem>
                                {types.map((type) => (
                                    <SelectItem
                                        key={type.value}
                                        value={type.value}
                                    >
                                        {type.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    }
                    emptyState={
                        <EmptyState
                            icon={Receipt}
                            title={t('supplier.transactions.empty_title')}
                            description={t(
                                'supplier.transactions.empty_description',
                            )}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}
