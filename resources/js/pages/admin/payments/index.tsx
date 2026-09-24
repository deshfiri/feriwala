import { Head, Link, router } from '@inertiajs/react';
import { CreditCard } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { index, show } from '@/routes/admin/payments';
import type { Money } from '@/lib/money';
import type { Column, Paginator } from '@/types';

export type PaymentRow = {
    id: string;
    reference: string;
    account: string | null;
    gateway: string | null;
    gateway_reference: string | null;
    amount: Money;
    currency: string;
    status: string;
    status_label: string;
    status_tone: 'success' | 'warning' | 'danger' | 'info' | 'neutral';
    needs_reconciliation: boolean;
    invoice_number: string | null;
    created_at: string | null;
    completed_at: string | null;
};

type Props = {
    payments: Paginator<PaymentRow>;
    statuses: { value: string; label: string }[];
    gateways: string[];
    filters: { status: string; gateway: string };
    needs_reconciliation: number;
};

const selectClass =
    'border-input bg-background focus-visible:ring-ring rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Payments, and what state each one is in (§42, §26.4).
 *
 * The count of payments needing reconciliation sits above the table rather than
 * inside it. They are the only rows where money is unresolved, and a filter you
 * have to know to apply is a filter nobody applies — so the screen says how many
 * there are before anybody scrolls.
 *
 * State is carried by a labelled pill, never by colour alone (§33.9).
 */
export default function AdminPaymentsIndex({
    payments,
    statuses,
    gateways,
    filters,
    needs_reconciliation: needsReconciliation,
}: Props) {
    const { t, locale } = useTranslation();

    const date = (value: string | null) =>
        value === null
            ? '—'
            : new Date(value).toLocaleDateString(locale, {
                  day: 'numeric',
                  month: 'short',
                  year: 'numeric',
              });

    const filter = (key: 'status' | 'gateway', value: string) =>
        router.get(
            index().url,
            { ...filters, [key]: value },
            {
                preserveState: true,
                replace: true,
                only: ['payments', 'filters'],
            },
        );

    const columns: Column<PaymentRow>[] = [
        {
            key: 'reference',
            header: t('payments.columns.reference'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate font-mono text-xs font-medium">
                        {row.reference}
                    </div>
                    <div className="text-muted-foreground truncate text-xs">
                        {row.account ?? '—'}
                    </div>
                </div>
            ),
        },
        {
            key: 'gateway',
            header: t('payments.columns.gateway'),
            priority: 'secondary',
            cell: (row) => (
                <div className="min-w-0">
                    <div>{row.gateway ?? '—'}</div>
                    {row.gateway_reference !== null && (
                        <div className="text-muted-foreground truncate font-mono text-xs">
                            {row.gateway_reference}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'amount',
            header: t('payments.columns.amount'),
            sortable: true,
            align: 'end',
            cell: (row) => <MoneyAmount amount={row.amount} />,
        },
        {
            key: 'status',
            header: t('payments.columns.status'),
            sortable: true,
            cell: (row) => (
                <StatusPill tone={row.status_tone} label={row.status_label} />
            ),
        },
        {
            key: 'created_at',
            header: t('payments.columns.created'),
            sortable: true,
            priority: 'secondary',
            cell: (row) => date(row.created_at),
        },
        {
            key: 'actions',
            header: '',
            alwaysVisible: true,
            align: 'end',
            cell: (row) => (
                <Button variant="ghost" size="sm" asChild>
                    <Link href={show(row.id)}>{t('payments.open')}</Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title={t('payments.title')} />

            <PageContainer>
                <PageHeader
                    title={t('payments.title')}
                    description={t('payments.description')}
                />

                {needsReconciliation > 0 && (
                    <div
                        className="border-warning bg-warning-subtle rounded-xl border p-4"
                        role="status"
                    >
                        <p className="text-sm font-medium">
                            {t('payments.needs_attention', {
                                count: needsReconciliation,
                            })}
                        </p>
                        <p className="text-muted-foreground mt-1 text-sm">
                            {t('payments.needs_attention_help')}
                        </p>
                        <Button
                            variant="secondary"
                            size="sm"
                            className="mt-3"
                            onClick={() => filter('status', 'reconciliation')}
                        >
                            {t('payments.only_reconciliation')}
                        </Button>
                    </div>
                )}

                <DataTable
                    columns={columns}
                    paginator={payments}
                    rowKey={(row) => row.id}
                    caption={t('payments.caption')}
                    searchPlaceholder={t('payments.search_placeholder')}
                    onlyReload={['payments', 'filters']}
                    filters={
                        <>
                            <select
                                aria-label={t('payments.columns.status')}
                                className={selectClass}
                                value={filters.status}
                                onChange={(event) =>
                                    filter('status', event.target.value)
                                }
                            >
                                <option value="">
                                    {t('payments.all_statuses')}
                                </option>
                                <option value="reconciliation">
                                    {t('payments.only_reconciliation')}
                                </option>
                                {statuses.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>

                            <select
                                aria-label={t('payments.columns.gateway')}
                                className={selectClass}
                                value={filters.gateway}
                                onChange={(event) =>
                                    filter('gateway', event.target.value)
                                }
                            >
                                <option value="">
                                    {t('payments.all_gateways')}
                                </option>
                                {gateways.map((gateway) => (
                                    <option key={gateway} value={gateway}>
                                        {gateway}
                                    </option>
                                ))}
                            </select>
                        </>
                    }
                    emptyState={
                        <EmptyState
                            icon={CreditCard}
                            title={t('payments.empty_title')}
                            description={t('payments.empty_description')}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

AdminPaymentsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Payments',
            href: index(),
        },
    ],
};
