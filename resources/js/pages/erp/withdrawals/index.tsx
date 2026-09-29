import { Head, Link } from '@inertiajs/react';
import { Banknote } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import MoneyAmount from '@/components/money-amount';
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
import type { Money } from '@/lib/money';
import { create, show } from '@/routes/withdrawals';
import type { Column, Paginator } from '@/types';

const ALL = 'all';
const RELOAD_PROPS = ['withdrawals'];

type Row = {
    id: string;
    reference: string;
    amount: Money;
    status: string;
    status_label: string;
    status_tone: string;
    payout_snapshot: {
        type_label: string | null;
        masked_number: string | null;
    };
    requested_at: string;
};

export default function AccountWithdrawalsIndex({
    withdrawals,
    statuses,
}: {
    withdrawals: Paginator<Row>;
    statuses: { value: string; label: string }[];
}) {
    const { t, locale } = useTranslation();
    const { getFilter, setFilter } = useTableQuery({ only: RELOAD_PROPS });

    const columns: Column<Row>[] = [
        {
            key: 'reference',
            header: t('withdrawal.erp.title'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate font-medium">{row.reference}</div>
                    <div className="text-muted-foreground truncate text-xs">
                        {row.payout_snapshot.type_label} ·{' '}
                        {row.payout_snapshot.masked_number}
                    </div>
                </div>
            ),
        },
        {
            key: 'amount',
            header: t('withdrawal.erp.amount'),
            align: 'end',
            cell: (row) => <MoneyAmount amount={row.amount} />,
        },
        {
            key: 'status',
            header: t('withdrawal.erp.status'),
            cell: (row) => (
                <StatusPill
                    tone={row.status_tone as never}
                    label={row.status_label}
                />
            ),
        },
        {
            key: 'requested_at',
            header: t('withdrawal.erp.requested_at'),
            priority: 'secondary',
            cell: (row) =>
                new Date(row.requested_at).toLocaleDateString(locale),
        },
        {
            key: 'actions',
            header: '',
            alwaysVisible: true,
            align: 'end',
            cell: (row) => (
                <Button variant="ghost" size="sm" asChild>
                    <Link href={show(row.id)}>{t('withdrawal.erp.open')}</Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title={t('withdrawal.erp.title')} />

            <PageContainer>
                <PageHeader
                    title={t('withdrawal.erp.title')}
                    description={t('withdrawal.erp.description')}
                    actions={
                        <Button size="sm" asChild>
                            <Link href={create()}>
                                {t('withdrawal.erp.request')}
                            </Link>
                        </Button>
                    }
                />

                <DataTable
                    columns={columns}
                    paginator={withdrawals}
                    rowKey={(row) => row.id}
                    caption={t('withdrawal.erp.title')}
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
                                aria-label={t('withdrawal.erp.status')}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>
                                    {t('withdrawal.erp.all_statuses')}
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
                            icon={Banknote}
                            title={t('withdrawal.erp.empty_title')}
                            description={t('withdrawal.erp.empty_description')}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}
