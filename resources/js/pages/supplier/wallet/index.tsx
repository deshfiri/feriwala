import { Head, Link } from '@inertiajs/react';
import { Wallet as WalletIcon } from 'lucide-react';
import MoneyAmount from '@/components/money-amount';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import EmptyState from '@/components/states/empty-state';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import { transactions } from '@/routes/supplier/wallet';

type WalletSummary = {
    id: string;
    currency: string;
    total: Money;
    available: Money;
    reserved: Money;
    recovery: Money;
    has_outstanding_recovery: boolean;
};

type Entry = {
    id: string;
    reference: string;
    type_label: string;
    is_credit: boolean;
    amount: Money;
    description: string;
    created_at: string;
};

type Props = {
    wallet: WalletSummary | null;
    payable_totals: { pending: Money; eligible: Money; settled: Money };
    recent_entries: Entry[];
};

/**
 * A Supplier's own wallet dashboard (D25, P13-23).
 *
 * Every figure here is read straight from the server — nothing is added,
 * subtracted or recomputed in the browser.
 */
export default function SupplierWalletIndex({
    wallet,
    payable_totals: payableTotals,
    recent_entries: recentEntries,
}: Props) {
    const { t, locale } = useTranslation();

    return (
        <>
            <Head title={t('supplier.wallet.title')} />

            <div className="space-y-6">
                <PageHeader
                    title={t('supplier.wallet.title')}
                    description={t('supplier.wallet.description')}
                />

                {wallet === null ? (
                    <EmptyState
                        icon={WalletIcon}
                        title={t('supplier.wallet.no_wallet_title')}
                        description={t('supplier.wallet.no_wallet_description')}
                    />
                ) : (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <SectionCard title={t('supplier.wallet.total')}>
                                <MoneyAmount
                                    amount={wallet.total}
                                    size="large"
                                />
                            </SectionCard>
                            <SectionCard title={t('supplier.wallet.available')}>
                                <MoneyAmount
                                    amount={wallet.available}
                                    size="large"
                                    direction="credit"
                                />
                            </SectionCard>
                            <SectionCard title={t('supplier.wallet.reserved')}>
                                <MoneyAmount
                                    amount={wallet.reserved}
                                    size="large"
                                />
                            </SectionCard>
                            <SectionCard
                                title={t('supplier.wallet.recovery')}
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

                        {wallet.has_outstanding_recovery && (
                            <p
                                className="text-warning-foreground text-sm font-medium"
                                role="status"
                            >
                                {t('supplier.wallet.recovery_warning')}
                            </p>
                        )}

                        <SectionCard
                            title={t('supplier.wallet.payable_totals')}
                        >
                            <dl className="grid gap-4 text-sm sm:grid-cols-3">
                                <div>
                                    <dt className="text-muted-foreground text-xs">
                                        {t('supplier.wallet.pending_total')}
                                    </dt>
                                    <dd>
                                        <MoneyAmount
                                            amount={payableTotals.pending}
                                        />
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">
                                        {t('supplier.wallet.eligible_total')}
                                    </dt>
                                    <dd>
                                        <MoneyAmount
                                            amount={payableTotals.eligible}
                                        />
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">
                                        {t('supplier.wallet.settled_total')}
                                    </dt>
                                    <dd>
                                        <MoneyAmount
                                            amount={payableTotals.settled}
                                        />
                                    </dd>
                                </div>
                            </dl>
                        </SectionCard>

                        <SectionCard
                            title={t('supplier.wallet.recent_activity')}
                            actions={
                                <Button variant="ghost" size="sm" asChild>
                                    <Link href={transactions()}>
                                        {t(
                                            'supplier.wallet.view_all_transactions',
                                        )}
                                    </Link>
                                </Button>
                            }
                        >
                            {recentEntries.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    {t('supplier.wallet.empty_activity')}
                                </p>
                            ) : (
                                <ul className="divide-border divide-y text-sm">
                                    {recentEntries.map((entry) => (
                                        <li
                                            key={entry.id}
                                            className="flex flex-wrap items-center justify-between gap-2 py-2 first:pt-0 last:pb-0"
                                        >
                                            <div className="min-w-0">
                                                <div className="truncate font-medium">
                                                    {entry.type_label}
                                                </div>
                                                <div className="text-muted-foreground truncate text-xs">
                                                    {entry.description}
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <MoneyAmount
                                                    amount={entry.amount}
                                                    direction={
                                                        entry.is_credit
                                                            ? 'credit'
                                                            : 'debit'
                                                    }
                                                    showSign
                                                />
                                                <span className="text-muted-foreground text-xs whitespace-nowrap">
                                                    {new Date(
                                                        entry.created_at,
                                                    ).toLocaleDateString(
                                                        locale,
                                                    )}
                                                </span>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </SectionCard>
                    </>
                )}
            </div>
        </>
    );
}
