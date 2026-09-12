import { Head, Link } from '@inertiajs/react';
import EmptyState from '@/components/states/empty-state';
import Heading from '@/components/heading';
import MoneyAmount from '@/components/money-amount';
import StatusPill from '@/components/status-pill';
import { useTranslation } from '@/hooks/use-translation';
import { show as receiptShow } from '@/routes/subscription/receipts';
import type { Money } from '@/lib/money';

export type ReceiptRow = {
    id: string;
    number: string;
    purpose: string;
    amount: Money;
    paid_at: string;
    is_sandbox: boolean;
    refunded_total: Money | null;
};

type Props = {
    receipts: {
        data: ReceiptRow[];
        current_page: number;
        last_page: number;
        total: number;
    };
};

/**
 * Proof of what this account has paid (§26.4).
 *
 * Not the invoice list. An invoice says what was owed and exists before anybody
 * pays; a receipt exists only because money arrived, which is why nothing
 * unsettled appears here at all.
 */
export default function Receipts({ receipts }: Props) {
    const { t, locale } = useTranslation();

    const date = (value: string) => new Date(value).toLocaleDateString(locale);

    return (
        <>
            <Head title={t('package.receipts.title')} />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('package.receipts.title')}
                    description={t('package.receipts.description')}
                />

                {receipts.data.length === 0 ? (
                    <EmptyState
                        title={t('package.receipts.empty')}
                        description={t('package.receipts.empty_help')}
                    />
                ) : (
                    <ul className="bg-card divide-border divide-y overflow-hidden rounded-xl border">
                        {receipts.data.map((receipt) => (
                            <li key={receipt.id}>
                                <Link
                                    href={receiptShow(receipt.id)}
                                    className="hover:bg-muted/50 flex flex-wrap items-center justify-between gap-3 px-4 py-3 transition-colors"
                                >
                                    <div className="min-w-0 space-y-0.5">
                                        <p className="font-mono text-xs">
                                            {receipt.number}
                                        </p>
                                        <p className="text-muted-foreground text-sm">
                                            {receipt.purpose} ·{' '}
                                            {date(receipt.paid_at)}
                                        </p>
                                    </div>

                                    <div className="flex items-center gap-3">
                                        {/*
                                            A sandbox payment must never look
                                            like proof of real money, so it says
                                            so wherever it appears.
                                        */}
                                        {receipt.is_sandbox && (
                                            <StatusPill
                                                tone="warning"
                                                label={t(
                                                    'package.receipts.sandbox',
                                                )}
                                            />
                                        )}

                                        {receipt.refunded_total && (
                                            <StatusPill
                                                tone="neutral"
                                                label={t(
                                                    'package.receipts.refunded',
                                                )}
                                            />
                                        )}

                                        <MoneyAmount amount={receipt.amount} />
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
