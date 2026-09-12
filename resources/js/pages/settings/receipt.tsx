import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import MoneyAmount from '@/components/money-amount';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { index as receiptIndex } from '@/routes/subscription/receipts';
import type { Money } from '@/lib/money';

type Props = {
    receipt: {
        id: string;
        number: string;
        payment_reference: string;
        purpose: string;
        paid_at: string;
        lines: {
            type: string;
            label: string;
            amount: Money;
            is_deduction: boolean;
        }[];
        amount: Money;
        /** What the provider settled, when it differed from what was charged. */
        settled: { minor_units: number; currency: string } | null;
        gateway: string;
        is_sandbox: boolean;
        gateway_reference: string | null;
        refunds: { amount: Money; processed_at: string | null }[];
        refunded_total: Money | null;
    };
};

/**
 * One receipt (§26.4).
 *
 * Everything here came from a column a server-to-server verification wrote.
 * Nothing is computed in the browser and nothing arrived from a redirect
 * (§36.1) — a receipt is the document somebody keeps for their accounts, and it
 * must not be able to say something the platform never confirmed.
 *
 * What is absent is the other half of the point: no signatures, no provider
 * payloads, no internal notes. The provider's transaction reference is here
 * because a dispute needs it; nothing else about the exchange is.
 */
export default function Receipt({ receipt }: Props) {
    const { t, locale } = useTranslation();

    const date = (value: string | null) =>
        value === null ? '—' : new Date(value).toLocaleDateString(locale);

    return (
        <>
            <Head title={receipt.number} />

            <h1 className="sr-only">{receipt.number}</h1>

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title={receipt.number}
                        description={receipt.purpose}
                    />

                    <Button asChild variant="ghost" size="sm">
                        <Link href={receiptIndex()}>
                            {t('package.receipts.back')}
                        </Link>
                    </Button>
                </div>

                {/*
                    A sandbox payment moved no real money, and a document that
                    did not say so would be the clearest possible way to mislead
                    somebody about what they hold.
                */}
                {receipt.is_sandbox && (
                    <div className="border-warning/40 bg-warning-subtle text-warning rounded-lg border px-4 py-3 text-sm">
                        {t('package.receipts.sandbox_notice')}
                    </div>
                )}

                <section className="bg-card space-y-3 rounded-xl border p-4">
                    <dl className="grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('package.receipts.paid_on')}
                            </dt>
                            <dd>{date(receipt.paid_at)}</dd>
                        </div>

                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('package.receipts.method')}
                            </dt>
                            <dd>{receipt.gateway}</dd>
                        </div>

                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('package.receipts.reference')}
                            </dt>
                            <dd className="font-mono text-xs">
                                {receipt.payment_reference}
                            </dd>
                        </div>

                        {receipt.gateway_reference && (
                            <div>
                                <dt className="text-muted-foreground text-xs">
                                    {t('package.receipts.gateway_reference')}
                                </dt>
                                <dd className="font-mono text-xs">
                                    {receipt.gateway_reference}
                                </dd>
                            </div>
                        )}
                    </dl>
                </section>

                <section className="bg-card border-border overflow-hidden rounded-xl border">
                    <dl className="divide-border divide-y">
                        {receipt.lines.map((line) => (
                            <div
                                key={`${line.type}-${line.label}`}
                                className="flex items-center justify-between gap-4 px-4 py-2.5"
                            >
                                <dt className="text-sm">{line.label}</dt>
                                <dd>
                                    <MoneyAmount
                                        amount={line.amount}
                                        direction={
                                            line.is_deduction
                                                ? 'credit'
                                                : 'neutral'
                                        }
                                        showSign={line.is_deduction}
                                    />
                                </dd>
                            </div>
                        ))}

                        <div className="bg-muted flex items-center justify-between gap-4 px-4 py-3">
                            <dt className="text-sm font-semibold">
                                {t('package.receipts.paid')}
                            </dt>
                            <dd>
                                <MoneyAmount
                                    amount={receipt.amount}
                                    size="large"
                                />
                            </dd>
                        </div>
                    </dl>

                    {/*
                        Both figures, when the provider settled in a different
                        currency (D4). Recording the pair is what stops a
                        foreign settlement being silently rewritten into the
                        base ledger — and the payer is entitled to see what
                        their provider actually took.
                    */}
                    {receipt.settled && (
                        <p className="text-muted-foreground border-t px-4 py-2 text-xs">
                            {t('package.receipts.settled_as', {
                                amount: `${receipt.settled.minor_units / 100} ${receipt.settled.currency}`,
                            })}
                        </p>
                    )}
                </section>

                {receipt.refunds.length > 0 && (
                    <section className="bg-card border-border overflow-hidden rounded-xl border">
                        <h2 className="border-b px-4 py-3 text-sm font-semibold">
                            {t('package.receipts.refunds')}
                        </h2>

                        <dl className="divide-border divide-y">
                            {receipt.refunds.map((refund, index) => (
                                <div
                                    key={`${refund.processed_at}-${index}`}
                                    className="flex items-center justify-between gap-4 px-4 py-2.5"
                                >
                                    <dt className="text-sm">
                                        {date(refund.processed_at)}
                                    </dt>
                                    <dd>
                                        <MoneyAmount
                                            amount={refund.amount}
                                            direction="credit"
                                            showSign
                                        />
                                    </dd>
                                </div>
                            ))}

                            {receipt.refunded_total && (
                                <div className="bg-muted flex items-center justify-between gap-4 px-4 py-3">
                                    <dt className="text-sm font-semibold">
                                        {t('package.receipts.refunded_total')}
                                    </dt>
                                    <dd>
                                        <MoneyAmount
                                            amount={receipt.refunded_total}
                                            direction="credit"
                                            showSign
                                        />
                                    </dd>
                                </div>
                            )}
                        </dl>
                    </section>
                )}

                <div className="flex justify-end">
                    {/*
                        Printing is the browser's job. A PDF generator would be
                        a dependency this task does not call for, and the page
                        already holds every figure the document needs.
                    */}
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => window.print()}
                    >
                        {t('package.receipts.print')}
                    </Button>
                </div>
            </div>
        </>
    );
}
