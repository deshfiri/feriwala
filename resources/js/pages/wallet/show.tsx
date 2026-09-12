import { Head, Link } from '@inertiajs/react';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import WalletBalanceCards from '@/components/wallet/balance-cards';
import WalletStatementTable from '@/components/wallet/statement-table';
import { useTranslation } from '@/hooks/use-translation';
import { download, show } from '@/routes/wallet';
import { create as topUp } from '@/routes/wallet/top-up';
import { show as movement } from '@/routes/wallet/transactions';
import type { Paginator } from '@/types';
import type {
    WalletBalances,
    WalletFilterOptions,
    WalletMovement,
    WalletRestrictionRow,
    WalletStatementFilters,
} from '@/types/wallet';

type Props = {
    balances: WalletBalances;
    transactions: Paginator<WalletMovement>;
    filters: WalletStatementFilters;
    options: WalletFilterOptions;
    restrictions: WalletRestrictionRow[];
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
    restrictions,
}: Props) {
    const { t, locale } = useTranslation();

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
            <Head title={t('wallet.title')} />

            <PageContainer>
                <PageHeader
                    title={t('wallet.title')}
                    description={t('wallet.description')}
                    actions={
                        <>
                            <StatusPill
                                tone={balances.state_tone}
                                label={balances.state_label}
                            />

                            <Button size="sm" asChild>
                                <Link href={topUp()}>
                                    {t('wallet.top_up.title')}
                                </Link>
                            </Button>
                        </>
                    }
                />

                {!balances.meets_obligation && (
                    /*
                     * Said plainly and in words rather than by colouring a
                     * figure red (§33.9). The person reading it has to know
                     * what to do about it, how much, and by when.
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
                                amount: balances.obligation_shortfall.formatted,
                            })}
                        </p>

                        <p className="text-muted-foreground mt-1 text-sm">
                            {balances.grace_ends_at === null
                                ? t('wallet.state.grace_over')
                                : t('wallet.state.grace_until', {
                                      date: when(balances.grace_ends_at),
                                  })}
                        </p>

                        <Button
                            variant="secondary"
                            size="sm"
                            className="mt-3"
                            asChild
                        >
                            <Link href={topUp()}>
                                {t('wallet.state.top_up_required', {
                                    amount: balances.obligation_shortfall
                                        .formatted,
                                })}
                            </Link>
                        </Button>
                    </div>
                )}

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
