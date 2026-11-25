import MoneyAmount from '@/components/money-amount';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import type { StatusTone } from '@/lib/status';

export type StorefrontProfitSummary = {
    status: string;
    status_label: string;
    status_tone: string;
    reason: string | null;
    profit: Money | null;
    credited: Money;
    reversed: Money;
    release_at: string | null;
    released_at: string | null;
    reported_only: boolean;
};

export type PendingStorefrontProfit = {
    amount: Money;
    count: number;
    next_release_at: string | null;
};

/**
 * A Partner's profit on one Storefront order, as the server worked it out:
 * where it stands, how much, and when it reaches the withdrawable balance.
 * Nothing here adds anything up.
 */
export function StorefrontProfitCard({
    profit,
}: {
    profit: StorefrontProfitSummary | null | undefined;
}) {
    const { t, locale } = useTranslation();

    if (!profit) {
        return null;
    }

    const when = (value: string) => new Date(value).toLocaleString(locale);

    return (
        <SectionCard title={t('storefront_profit.title')}>
            <div className="space-y-3 text-sm">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <StatusPill
                        tone={profit.status_tone as StatusTone}
                        label={profit.status_label}
                    />
                    {profit.profit && (
                        <MoneyAmount
                            amount={profit.profit}
                            className="text-base font-semibold"
                        />
                    )}
                </div>

                {profit.reason && (
                    <p className="text-muted-foreground">{profit.reason}</p>
                )}

                {profit.reported_only && (
                    <p className="text-muted-foreground">
                        {t('storefront_profit.partner.reported_only')}
                    </p>
                )}

                {profit.status === 'holding' && profit.release_at && (
                    <p>
                        {t('storefront_profit.partner.releases_at', {
                            time: when(profit.release_at),
                        })}
                    </p>
                )}

                {profit.released_at && (
                    <p className="text-muted-foreground">
                        {t('storefront_profit.partner.credited', {
                            time: when(profit.released_at),
                        })}
                    </p>
                )}
            </div>
        </SectionCard>
    );
}

/**
 * Storefront profit on its way to the withdrawable balance: shown beside the
 * wallet, never added into it.
 */
export function PendingStorefrontProfitCard({
    pending,
}: {
    pending: PendingStorefrontProfit | null | undefined;
}) {
    const { t, locale } = useTranslation();

    if (!pending) {
        return null;
    }

    return (
        <SectionCard title={t('storefront_profit.partner.pending')}>
            <div className="space-y-2 text-sm">
                {pending.count === 0 ? (
                    <p className="text-muted-foreground">
                        {t('storefront_profit.partner.none')}
                    </p>
                ) : (
                    <>
                        <MoneyAmount
                            amount={pending.amount}
                            direction="credit"
                            className="text-lg font-semibold"
                        />
                        {pending.next_release_at && (
                            <p>
                                {t('storefront_profit.partner.releases_at', {
                                    time: new Date(
                                        pending.next_release_at,
                                    ).toLocaleString(locale),
                                })}
                            </p>
                        )}
                    </>
                )}
                <p className="text-muted-foreground text-xs">
                    {t('storefront_profit.partner.pending_help')}
                </p>
            </div>
        </SectionCard>
    );
}
