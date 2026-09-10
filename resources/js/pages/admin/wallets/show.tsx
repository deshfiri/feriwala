import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import WalletBalanceCards from '@/components/wallet/balance-cards';
import WalletStatementTable from '@/components/wallet/statement-table';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { download, index, show } from '@/routes/admin/wallets';
import { show as movement } from '@/routes/admin/wallets/transactions';
import AdjustWalletDialog from '@/pages/admin/wallets/adjust-wallet-dialog';
import type { Paginator } from '@/types';
import type {
    WalletBalances,
    WalletFilterOptions,
    WalletMovement,
    WalletStatementFilters,
    WalletSummary,
} from '@/types/wallet';

type Props = {
    wallet: WalletSummary;
    balances: WalletBalances;
    transactions: Paginator<WalletMovement>;
    filters: WalletStatementFilters;
    options: WalletFilterOptions;
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
    can,
}: Props) {
    const { t } = useTranslation();
    const [adjusting, setAdjusting] = useState(false);

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
