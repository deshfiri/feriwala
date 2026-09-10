import MoneyAmount from '@/components/money-amount';
import StatCard, { StatCardGrid } from '@/components/stat-card';
import { useTranslation } from '@/hooks/use-translation';
import type { WalletBalances } from '@/types/wallet';

/**
 * The buckets a wallet is made of (§33.7).
 *
 * §33.7 asks these to be told apart, and they are not synonyms: the total
 * includes money already spoken for, what can be **spent** excludes reservations
 * and holds, and what can be **withdrawn** excludes the deposit the account must
 * keep in place as well. Two figures that differ need a line each saying why,
 * which is what the hints are for.
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
        {
            key: 'total',
            amount: balances.total,
        },
        {
            key: 'usable',
            amount: balances.usable,
        },
        {
            key: 'withdrawable',
            amount: balances.available_for_withdrawal,
        },
        {
            key: 'required_deposit',
            amount: balances.required_deposit,
        },
    ];

    const claims = [
        { key: 'reserved', amount: balances.reserved },
        { key: 'pending', amount: balances.pending },
        { key: 'hold', amount: balances.hold },
        { key: 'cod_receivable', amount: balances.cod_receivable },
    ];

    return (
        <div className="space-y-4">
            <StatCardGrid>
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

            <StatCardGrid>
                {claims.map((bucket) => (
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
        </div>
    );
}
