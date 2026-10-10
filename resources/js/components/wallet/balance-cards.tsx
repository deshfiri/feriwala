import MoneyAmount from '@/components/money-amount';
import SectionCard from '@/components/section-card';
import StatCard, { StatCardGrid } from '@/components/stat-card';
import { useTranslation } from '@/hooks/use-translation';
import type { WalletBalances } from '@/types/wallet';

/**
 * A wallet at a glance (§33.7): how much there is, how much can be used, and
 * how much can be withdrawn.
 *
 * Those three are not synonyms — the total includes money already spoken for,
 * what can be **used** excludes reservations and holds, and what can be
 * **withdrawn** excludes the deposit the account must keep in place as well —
 * so each keeps its own card. Everything else that explains the gap between
 * them sits in a compact Balance Details list below, where it does not compete
 * with the three.
 *
 * Written once and used by both the account's own wallet page and the
 * administrator's view of the same wallet, so a support call cannot end up with
 * two descriptions of one balance.
 *
 * Every figure arrives formatted from the server. Nothing here computes.
 */
export default function WalletBalanceCards({
    balances,
}: {
    balances: WalletBalances;
}) {
    const { t } = useTranslation();

    const primary = [
        { key: 'total', amount: balances.total },
        { key: 'usable', amount: balances.usable },
        { key: 'withdrawable', amount: balances.available_for_withdrawal },
    ];

    const details = [
        { key: 'required_deposit', amount: balances.required_deposit },
        // §24.2 lists this beside the deposit, not instead of it.
        { key: 'minimum_balance', amount: balances.minimum_balance },
        { key: 'reserved', amount: balances.reserved },
        { key: 'pending', amount: balances.pending },
        { key: 'hold', amount: balances.hold },
        { key: 'cod_receivable', amount: balances.cod_receivable },
    ];

    return (
        <div className="space-y-4">
            <StatCardGrid className="lg:grid-cols-3">
                {primary.map((bucket) => (
                    <StatCard
                        key={bucket.key}
                        label={t(`wallet.balances.${bucket.key}`)}
                        hint={t(`wallet.balances.${bucket.key}_help`)}
                        value={
                            <MoneyAmount amount={bucket.amount} size="large" />
                        }
                    />
                ))}
            </StatCardGrid>

            <SectionCard
                title={t('wallet.balances.details_title')}
                description={t('wallet.balances.details_description')}
                headingLevel="h2"
            >
                <dl className="grid gap-x-8 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
                    {details.map((bucket) => (
                        <div
                            key={bucket.key}
                            className="flex items-baseline justify-between gap-3"
                            title={t(`wallet.balances.${bucket.key}_help`)}
                        >
                            <dt className="text-muted-foreground text-sm">
                                {t(`wallet.balances.${bucket.key}`)}
                            </dt>
                            <dd>
                                <MoneyAmount amount={bucket.amount} />
                            </dd>
                        </div>
                    ))}
                </dl>
            </SectionCard>
        </div>
    );
}
