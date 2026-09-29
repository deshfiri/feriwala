import { Head, Link } from '@inertiajs/react';
import { Banknote } from 'lucide-react';
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
import { index, show } from '@/routes/admin/account-withdrawals';
import type { Column, Paginator } from '@/types';

const ALL = 'all';
const RELOAD_PROPS = ['withdrawals'];

type Row = {
    id: string;
    reference: string;
    account_name: string;
    account_id: string;
    amount: Money;
    currency: string;
    status: string;
    status_label: string;
    status_tone: string;
    requested_at: string;
};

export default function AdminAccountWithdrawalsIndex({
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
            header: t('withdrawal.admin.columns.reference'),
            cell: (row) => row.reference,
        },
        {
            key: 'account',
            header: t('withdrawal.admin.columns.account'),
            cell: (row) => row.account_name,
        },
        {
            key: 'amount',
            header: t('withdrawal.admin.columns.amount'),
            align: 'end',
            cell: (row) => <MoneyAmount amount={row.amount} />,
        },
        {
            key: 'status',
            header: t('withdrawal.admin.columns.status'),
            cell: (row) => (
                <StatusPill
                    tone={row.status_tone as never}
                    label={row.status_label}
                />
            ),
        },
        {
            key: 'requested_at',
            header: t('withdrawal.admin.columns.requested'),
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
                    <Link href={show(row.id)}>
                        {t('withdrawal.admin.open')}
                    </Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title={t('withdrawal.admin.title')} />

            <PageContainer>
                <PageHeader
                    title={t('withdrawal.admin.title')}
                    description={t('withdrawal.admin.description')}
                />

                <DataTable
                    columns={columns}
                    paginator={withdrawals}
                    rowKey={(row) => row.id}
                    caption={t('withdrawal.admin.title')}
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
                                        'withdrawal.admin.columns.status',
                                    )}
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        {t('withdrawal.admin.all_statuses')}
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
                                    'withdrawal.admin.columns.account',
                                )}
                                value={getFilter('account') ?? ''}
                                onChange={(event) =>
                                    setFilter(
                                        'account',
                                        event.target.value || undefined,
                                    )
                                }
                            />
                            <Input
                                type="date"
                                className="h-8 w-40"
                                aria-label={t(
                                    'withdrawal.admin.columns.requested',
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
                                    'withdrawal.admin.columns.requested',
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
                            icon={Banknote}
                            title={t('withdrawal.admin.empty_title')}
                            description={t(
                                'withdrawal.admin.empty_description',
                            )}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

AdminAccountWithdrawalsIndex.layout = {
    breadcrumbs: [{ title: 'nav.account_withdrawals', href: index() }],
};
