import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { isPositive } from '@/lib/money';
import { index, show } from '@/routes/admin/wallets';
import ReverseMovementDialog from '@/pages/admin/wallets/reverse-movement-dialog';
import type { WalletMovementDetail, WalletSummary } from '@/types/wallet';

type Props = {
    wallet: WalletSummary;
    transaction: WalletMovementDetail;
    can: { reverse: boolean; view_sensitive: boolean };
};

/**
 * One movement, in full, with the controls for putting it right (§23.2, §32.2).
 *
 * The ledger entries are listed as written, including the link from a correction
 * to the entry it answers — that link is what makes a correction readable as a
 * correction rather than as a second, contradictory figure.
 *
 * The internal note appears only if the server sent one, and it only sends one
 * to a reader holding `ledger.view_sensitive_data`. It is labelled as staff-only
 * where it does appear, so nobody quotes it back to the account holder.
 */
export default function AdminWalletTransaction({
    wallet,
    transaction,
    can,
}: Props) {
    const { t, locale } = useTranslation();
    const [reversing, setReversing] = useState(false);

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
        { label: t('wallet.detail.source'), value: transaction.source },
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
                    description={wallet.account ?? wallet.id}
                    actions={
                        <>
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={show(wallet.id)}>
                                    <ArrowLeft aria-hidden="true" />
                                    {t('wallet.detail.back')}
                                </Link>
                            </Button>

                            {can.reverse && transaction.entries.length > 0 && (
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    onClick={() => setReversing(true)}
                                >
                                    {t('wallet.admin.reverse.action')}
                                </Button>
                            )}
                        </>
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

                {transaction.internal_note !== undefined &&
                    transaction.internal_note !== null && (
                        <SectionCard
                            title={t('wallet.detail.internal_note')}
                            description={t('wallet.detail.internal_note_help')}
                        >
                            <p className="text-sm break-words">
                                {transaction.internal_note}
                            </p>
                        </SectionCard>
                    )}

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
                                        {entry.corrects !== undefined &&
                                            entry.corrects !== null && (
                                                <div className="text-muted-foreground text-xs">
                                                    {t(
                                                        'wallet.detail.corrects',
                                                    )}{' '}
                                                    <span className="font-mono">
                                                        {entry.corrects}
                                                    </span>
                                                </div>
                                            )}
                                    </div>

                                    <div className="text-end">
                                        <MoneyAmount
                                            amount={
                                                isPositive(entry.credit)
                                                    ? entry.credit
                                                    : entry.debit
                                            }
                                            direction={
                                                isPositive(entry.credit)
                                                    ? 'credit'
                                                    : 'debit'
                                            }
                                            showSign
                                        />
                                        <div className="text-muted-foreground text-xs">
                                            {t('wallet.detail.balance_before')}{' '}
                                            {entry.balance_before.formatted}
                                            {' → '}
                                            {entry.balance_after.formatted}
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </PageContainer>

            {can.reverse && (
                <ReverseMovementDialog
                    walletId={wallet.id}
                    movement={transaction}
                    open={reversing}
                    onOpenChange={setReversing}
                />
            )}
        </>
    );
}

AdminWalletTransaction.layout = {
    breadcrumbs: [
        {
            title: 'Account wallets',
            href: index(),
        },
    ],
};
