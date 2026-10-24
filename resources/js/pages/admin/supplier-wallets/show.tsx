import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Receipt } from 'lucide-react';
import { useState } from 'react';
import SupplierWalletAdjustmentController from '@/actions/App/Http/Controllers/Admin/SupplierWalletAdjustmentController';
import DataTable from '@/components/data-table/data-table';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import EmptyState from '@/components/states/empty-state';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import AdjustWalletDialog from '@/pages/admin/wallets/adjust-wallet-dialog';
import ConvertBalanceDialog from '@/pages/admin/wallets/convert-balance-dialog';
import { index } from '@/routes/admin/supplier-wallets';
import type { Column, Paginator } from '@/types';

type Wallet = {
    id: string;
    supplier: string;
    supplier_reference: string;
    currency: string;
    total: Money;
    platform_credit: Money;
    withdrawable: Money;
    available: Money;
    available_for_withdrawal: Money;
    reserved: Money;
    recovery: Money;
    has_outstanding_recovery: boolean;
};

type Row = {
    id: string;
    reference: string;
    type_label: string;
    balance_type_label: string;
    is_credit: boolean;
    amount: Money;
    balance_after: Money;
    reserved_after: Money;
    recovery_after: Money;
    description: string;
    internal_note: string | null;
    related_payable_reference: string | null;
    related_withdrawal_reference: string | null;
    created_at: string;
};

export default function AdminSupplierWalletShow({
    wallet,
    can,
    entries,
}: {
    wallet: Wallet;
    can: { adjust: boolean; convert: boolean };
    entries: Paginator<Row>;
}) {
    const { t, locale } = useTranslation();
    const [adjusting, setAdjusting] = useState(false);
    const [converting, setConverting] = useState(false);

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
                    {row.internal_note && (
                        <div className="text-muted-foreground truncate text-xs italic">
                            {t('supplier.admin.wallets.internal_note')}:{' '}
                            {row.internal_note}
                        </div>
                    )}
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
            key: 'balance_type',
            header: t('supplier.admin.wallets.columns.balance_type'),
            priority: 'secondary',
            cell: (row) => row.balance_type_label,
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
                row.related_payable_reference ??
                row.related_withdrawal_reference ??
                '—',
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
            <Head
                title={t('supplier.admin.wallets.wallet_of', {
                    supplier: wallet.supplier,
                })}
            />

            <PageContainer>
                <PageHeader
                    title={t('supplier.admin.wallets.wallet_of', {
                        supplier: wallet.supplier,
                    })}
                    description={wallet.supplier_reference}
                    actions={
                        <>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={index()}>
                                    <ArrowLeft
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t('supplier.admin.wallets.back')}
                                </Link>
                            </Button>

                            {can.convert && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setConverting(true)}
                                >
                                    {t('wallet.admin.convert.action')}
                                </Button>
                            )}

                            {can.adjust && (
                                <Button
                                    size="sm"
                                    onClick={() => setAdjusting(true)}
                                >
                                    {t('wallet.admin.adjust.action')}
                                </Button>
                            )}
                        </>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <SectionCard
                        title={t(
                            'supplier.admin.wallets.columns.platform_credit',
                        )}
                    >
                        <MoneyAmount
                            amount={wallet.platform_credit}
                            size="large"
                        />
                    </SectionCard>
                    <SectionCard
                        title={t(
                            'supplier.admin.wallets.columns.withdrawable_part',
                        )}
                    >
                        <MoneyAmount
                            amount={wallet.withdrawable}
                            size="large"
                        />
                    </SectionCard>
                    <SectionCard
                        title={t(
                            'supplier.admin.wallets.columns.withdrawable_balance',
                        )}
                    >
                        <MoneyAmount
                            amount={wallet.available_for_withdrawal}
                            size="large"
                            direction="credit"
                        />
                    </SectionCard>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <SectionCard
                        title={t('supplier.admin.wallets.columns.total')}
                    >
                        <MoneyAmount amount={wallet.total} size="large" />
                    </SectionCard>
                    <SectionCard
                        title={t('supplier.admin.wallets.columns.available')}
                    >
                        <MoneyAmount
                            amount={wallet.available}
                            size="large"
                            direction="credit"
                        />
                    </SectionCard>
                    <SectionCard
                        title={t('supplier.admin.wallets.columns.reserved')}
                    >
                        <MoneyAmount amount={wallet.reserved} size="large" />
                    </SectionCard>
                    <SectionCard
                        title={t('supplier.admin.wallets.columns.recovery')}
                        tone={
                            wallet.has_outstanding_recovery
                                ? 'destructive'
                                : undefined
                        }
                    >
                        <MoneyAmount
                            amount={wallet.recovery}
                            size="large"
                            direction={
                                wallet.has_outstanding_recovery
                                    ? 'debit'
                                    : 'neutral'
                            }
                        />
                    </SectionCard>
                </div>

                <SectionCard
                    title={t('supplier.admin.wallets.ledger')}
                    contentClassName="px-0 py-0"
                >
                    <DataTable
                        columns={columns}
                        paginator={entries}
                        rowKey={(row) => row.id}
                        caption={t('supplier.admin.wallets.ledger')}
                        onlyReload={['entries']}
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
                </SectionCard>
            </PageContainer>

            {can.adjust && (
                <AdjustWalletDialog
                    form={SupplierWalletAdjustmentController.adjust.form(
                        wallet.id,
                    )}
                    account={wallet.supplier}
                    open={adjusting}
                    onOpenChange={setAdjusting}
                    withIdempotencyKey
                />
            )}

            {can.convert && (
                <ConvertBalanceDialog
                    form={SupplierWalletAdjustmentController.convert.form(
                        wallet.id,
                    )}
                    platformCredit={wallet.platform_credit}
                    open={converting}
                    onOpenChange={setConverting}
                />
            )}
        </>
    );
}

AdminSupplierWalletShow.layout = {
    breadcrumbs: [{ title: 'nav.supplier_wallets', href: index() }],
};
