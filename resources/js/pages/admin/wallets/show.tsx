import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import WalletBalanceCards from '@/components/wallet/balance-cards';
import WalletStatementTable from '@/components/wallet/statement-table';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { download, index, show } from '@/routes/admin/wallets';
import { show as movement } from '@/routes/admin/wallets/transactions';
import AdjustWalletDialog from '@/pages/admin/wallets/adjust-wallet-dialog';
import type { Money } from '@/lib/money';
import type { Paginator } from '@/types';
import type {
    WalletBalances,
    WalletFilterOptions,
    WalletMovement,
    WalletRestrictionRow,
    WalletStatementFilters,
    WalletSummary,
} from '@/types/wallet';

/** The figures this account was actually held to, as captured (§24.1). */
type CapturedObligation = {
    required_deposit: Money;
    minimum_balance: Money;
    required_top_up: Money;
    grace_period_days: number | null;
    refundability_label: string;
    refundable_percent: number | null;
    deposit_usable_for_charges: boolean;
    reserved_until_cancellation: boolean;
    withdrawable_after_liabilities: boolean;
    captured_at: string;
    deposit_due_at: string | null;
    rule_scope: string | null;
    rule_id: string | null;
};

type Props = {
    wallet: WalletSummary;
    balances: WalletBalances;
    transactions: Paginator<WalletMovement>;
    filters: WalletStatementFilters;
    options: WalletFilterOptions;
    obligation: CapturedObligation | null;
    restrictions: WalletRestrictionRow[];
    can: {
        adjust: boolean;
        reverse: boolean;
        export: boolean;
        view_sensitive: boolean;
    };
};

/**
 * One account's wallet, as an administrator sees it (§23, §32, §33.7).
 *
 * The same balances and the same statement the account holder is looking at,
 * from the same server-side definitions — so a support call cannot end up with
 * the two sides describing different money.
 *
 * What is added here is the ability to move it by hand, and that is gated: the
 * control is absent rather than disabled when the permission is missing, because
 * a button that only ever refuses teaches nothing.
 */
export default function AdminWalletShow({
    wallet,
    balances,
    transactions,
    filters,
    options,
    obligation,
    restrictions,
    can,
}: Props) {
    const { t, locale } = useTranslation();
    const [adjusting, setAdjusting] = useState(false);

    const when = (value: string | null) =>
        value === null
            ? '—'
            : new Date(value).toLocaleDateString(locale, {
                  day: 'numeric',
                  month: 'short',
                  year: 'numeric',
              });

    return (
        <>
            <Head
                title={t('wallet.admin.wallet_of', {
                    account: wallet.account ?? wallet.id,
                })}
            />

            <PageContainer>
                <PageHeader
                    title={t('wallet.admin.wallet_of', {
                        account: wallet.account ?? wallet.id,
                    })}
                    description={t('wallet.admin.description')}
                    actions={
                        <>
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={index()}>
                                    <ArrowLeft aria-hidden="true" />
                                    {t('wallet.admin.back')}
                                </Link>
                            </Button>

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

                <WalletBalanceCards balances={balances} />

                <SectionCard
                    title={t('wallet.obligation.title')}
                    description={t('wallet.obligation.description')}
                >
                    {obligation === null ? (
                        <p className="text-muted-foreground text-sm">
                            {t('wallet.obligation.none')}
                        </p>
                    ) : (
                        <dl className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1">
                                <dt className="text-muted-foreground text-xs font-medium">
                                    {t('wallet.balances.required_deposit')}
                                </dt>
                                <dd>
                                    <MoneyAmount
                                        amount={obligation.required_deposit}
                                    />
                                </dd>
                            </div>

                            <div className="space-y-1">
                                <dt className="text-muted-foreground text-xs font-medium">
                                    {t('wallet.balances.minimum_balance')}
                                </dt>
                                <dd>
                                    <MoneyAmount
                                        amount={obligation.minimum_balance}
                                    />
                                </dd>
                            </div>

                            <div className="space-y-1">
                                <dt className="text-muted-foreground text-xs font-medium">
                                    {t('wallet.rules.columns.refundability')}
                                </dt>
                                <dd className="text-sm">
                                    {obligation.refundability_label}
                                    {obligation.refundable_percent !== null &&
                                        ` · ${obligation.refundable_percent}%`}
                                </dd>
                            </div>

                            <div className="space-y-1">
                                <dt className="text-muted-foreground text-xs font-medium">
                                    {t('wallet.rules.columns.grace')}
                                </dt>
                                <dd className="text-sm">
                                    {obligation.grace_period_days === null
                                        ? '—'
                                        : t('wallet.rules.days', {
                                              count: obligation.grace_period_days,
                                          })}
                                </dd>
                            </div>

                            <div className="space-y-1 sm:col-span-2">
                                <dt className="text-muted-foreground text-xs font-medium">
                                    {t('wallet.obligation.from_rule', {
                                        scope: obligation.rule_scope ?? '—',
                                    })}
                                </dt>
                                <dd className="text-muted-foreground text-xs">
                                    {t('wallet.obligation.captured', {
                                        date: when(obligation.captured_at),
                                    })}
                                    {' · '}
                                    {obligation.deposit_usable_for_charges
                                        ? t(
                                              'wallet.obligation.usable_for_charges',
                                          )
                                        : t(
                                              'wallet.obligation.locked_for_charges',
                                          )}
                                </dd>
                            </div>
                        </dl>
                    )}
                </SectionCard>

                {restrictions.length > 0 && (
                    <SectionCard
                        title={t('wallet.restrictions.title')}
                        description={t('wallet.restrictions.description')}
                        tone="destructive"
                    >
                        <ul className="space-y-2">
                            {restrictions.map((restriction) => (
                                <li
                                    key={restriction.stage}
                                    className="flex flex-wrap items-center justify-between gap-2"
                                >
                                    <StatusPill
                                        tone={restriction.stage_tone}
                                        label={restriction.stage_label}
                                    />
                                    <span className="text-muted-foreground text-xs">
                                        {t('wallet.restrictions.since', {
                                            date: when(restriction.started_at),
                                        })}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </SectionCard>
                )}

                <SectionCard
                    title={t('wallet.statement.title')}
                    contentClassName="px-0 py-0"
                >
                    <WalletStatementTable
                        transactions={transactions}
                        filters={filters}
                        options={options}
                        filterUrl={show(wallet.id).url}
                        detailUrl={(row) => movement([wallet.id, row.id]).url}
                        exportUrl={
                            can.export
                                ? download.url(wallet.id, {
                                      query: { ...filters },
                                  })
                                : undefined
                        }
                    />
                </SectionCard>
            </PageContainer>

            {can.adjust && (
                <AdjustWalletDialog
                    walletId={wallet.id}
                    account={wallet.account}
                    open={adjusting}
                    onOpenChange={setAdjusting}
                />
            )}
        </>
    );
}

AdminWalletShow.layout = {
    breadcrumbs: [
        {
            title: 'Account wallets',
            href: index(),
        },
    ],
};
