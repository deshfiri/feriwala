import { Head } from '@inertiajs/react';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import WalletBalanceCards from '@/components/wallet/balance-cards';
import WalletStatementTable from '@/components/wallet/statement-table';
import { useTranslation } from '@/hooks/use-translation';
import { download, show } from '@/routes/wallet';
import { show as movement } from '@/routes/wallet/transactions';
import type { Paginator } from '@/types';
import type {
    WalletBalances,
    WalletFilterOptions,
    WalletMovement,
    WalletStatementFilters,
} from '@/types/wallet';

type Props = {
    balances: WalletBalances;
    transactions: Paginator<WalletMovement>;
    filters: WalletStatementFilters;
    options: WalletFilterOptions;
};

/**
 * The account's own wallet (§23, §33.7).
 *
 * Self-scoped — there is no identifier anywhere on this screen or in its URL,
 * because the wallet is reached through the membership (§31.3).
 *
 * A wallet with nothing in it is a normal state, not a broken screen. The
 * balances render as zeroes and the statement says so in words, because "no
 * transactions" looks like a missing feature to somebody who has just been
 * activated.
 */
export default function WalletShow({
    balances,
    transactions: movements,
    filters,
    options,
}: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('wallet.title')} />

            <PageContainer>
                <PageHeader
                    title={t('wallet.title')}
                    description={t('wallet.description')}
                />

                {!balances.meets_required_deposit && (
                    /*
                     * Said plainly and in words rather than by colouring a
                     * figure red (§33.9). The person reading it has to know
                     * what to do about it, and how much.
                     */
                    <div
                        className="border-warning bg-warning-subtle rounded-xl border p-4"
                        role="status"
                    >
                        <p className="text-sm font-medium">
                            {t('wallet.shortfall.title')}
                        </p>
                        <p className="text-muted-foreground mt-1 text-sm">
                            {t('wallet.shortfall.description', {
                                amount: balances.shortfall.formatted,
                            })}
                        </p>
                    </div>
                )}

                <WalletBalanceCards balances={balances} />

                <SectionCard
                    title={t('wallet.statement.title')}
                    contentClassName="px-0 py-0"
                >
                    <WalletStatementTable
                        transactions={movements}
                        filters={filters}
                        options={options}
                        filterUrl={show().url}
                        detailUrl={(row) => movement(row.id).url}
                        // The export honours the filters on screen: somebody
                        // who narrowed to March expects March in the file.
                        exportUrl={download.url({ query: { ...filters } })}
                    />
                </SectionCard>
            </PageContainer>
        </>
    );
}

WalletShow.layout = {
    breadcrumbs: [
        {
            title: 'Wallet',
            href: show(),
        },
    ],
};
