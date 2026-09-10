import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { show } from '@/routes/wallet';
import type { WalletMovementDetail } from '@/types/wallet';

/**
 * One movement in the account's own wallet (§23.2, §33.7).
 *
 * The ledger entries are shown as what they are: written once, never changed. A
 * correction appears as a further entry rather than as a different figure here,
 * which is the whole difference between a ledger and a balance.
 *
 * Nothing on this screen is staff-only. The internal note is not withheld from
 * the render — it is never sent (§23.2).
 */
export default function WalletTransactionDetail({
    transaction,
}: {
    transaction: WalletMovementDetail;
}) {
    const { t, locale } = useTranslation();

    const when = (value: string | null) =>
        value === null
            ? '—'
            : new Date(value).toLocaleString(locale, {
                  dateStyle: 'medium',
                  timeStyle: 'short',
              });

    const rows: { label: string; value: ReactNode }[] = [
        {
            label: t('wallet.detail.reference'),
            value: (
                <span className="font-mono text-xs">
                    {transaction.reference}
                </span>
            ),
        },
        { label: t('wallet.detail.type'), value: transaction.type_label },
        {
            label: t('wallet.detail.status'),
            value: (
                <StatusPill
                    tone={transaction.status_tone}
                    label={transaction.status_label}
                />
            ),
        },
        {
            label: t('wallet.detail.amount'),
            value: (
                <MoneyAmount
                    amount={transaction.amount}
                    direction={transaction.direction}
                    showSign
                />
            ),
        },
        {
            label: t('wallet.detail.description'),
            value: transaction.description,
        },
        { label: t('wallet.detail.at'), value: when(transaction.at) },
        ...(transaction.reason === null
            ? []
            : [
                  {
                      label: t('wallet.detail.reason'),
                      value: transaction.reason,
                  },
              ]),
        ...(transaction.payment_reference === null
            ? []
            : [
                  {
                      label: t('wallet.detail.payment'),
                      value: (
                          <span className="font-mono text-xs">
                              {transaction.payment_reference}
                          </span>
                      ),
                  },
              ]),
    ];

    return (
        <>
            <Head title={transaction.reference} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('wallet.detail.title')}
                    description={transaction.description}
                    actions={
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={show()}>
                                <ArrowLeft aria-hidden="true" />
                                {t('wallet.detail.back')}
                            </Link>
                        </Button>
                    }
                />

                <SectionCard title={t('wallet.detail.title')}>
                    <dl className="grid gap-3 sm:grid-cols-2">
                        {rows.map((row) => (
                            <div key={row.label} className="min-w-0 space-y-1">
                                <dt className="text-muted-foreground text-xs font-medium">
                                    {row.label}
                                </dt>
                                <dd className="text-sm break-words">
                                    {row.value}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </SectionCard>

                <SectionCard
                    title={t('wallet.detail.entries')}
                    description={t('wallet.detail.entries_help')}
                >
                    {transaction.entries.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {t('wallet.detail.no_entries')}
                        </p>
                    ) : (
                        <ul className="divide-border divide-y">
                            {transaction.entries.map((entry) => (
                                <li
                                    key={entry.reference}
                                    className="flex flex-wrap items-baseline justify-between gap-2 py-3 first:pt-0 last:pb-0"
                                >
                                    <div className="min-w-0">
                                        <div className="font-mono text-xs">
                                            {entry.reference}
                                        </div>
                                        <div className="text-muted-foreground text-xs">
                                            {when(entry.at)}
                                        </div>
                                    </div>

                                    <div className="text-end">
                                        <MoneyAmount
                                            amount={
                                                entry.credit.minor_units > 0
                                                    ? entry.credit
                                                    : entry.debit
                                            }
                                            direction={
                                                entry.credit.minor_units > 0
                                                    ? 'credit'
                                                    : 'debit'
                                            }
                                            showSign
                                        />
                                        <div className="text-muted-foreground text-xs">
                                            {t('wallet.detail.balance_after')}{' '}
                                            {entry.balance_after.formatted}
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </PageContainer>
        </>
    );
}

WalletTransactionDetail.layout = {
    breadcrumbs: [
        {
            title: 'Wallet',
            href: show(),
        },
    ],
};
