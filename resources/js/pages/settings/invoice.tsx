import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import MoneyAmount from '@/components/money-amount';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { index as invoiceIndex } from '@/routes/subscription/invoices';
import type { Money } from '@/lib/money';
import type { InvoiceRow } from './invoices';

type Props = {
    invoice: InvoiceRow & {
        subtotal: Money;
        lines: {
            type: string;
            label: string;
            amount: Money;
            is_deduction: boolean;
        }[];
    };
};

/**
 * One invoice, itemised (§8.2, §9).
 *
 * The lines are the ones stored at issue, labels included — a fee renamed since
 * must not retitle a line on a document somebody already has. Nothing is
 * computed here; every figure arrives calculated (§36.1).
 *
 * An unpaid invoice says so plainly. §8.2 keeps a pending invoice from granting
 * anything, and a document that looks settled when it is not would be the
 * clearest possible way to mislead somebody about what they have.
 */
export default function Invoice({ invoice }: Props) {
    const { t, locale } = useTranslation();

    const date = (value: string | null) =>
        value === null ? '—' : new Date(value).toLocaleDateString(locale);

    return (
        <>
            <Head title={invoice.number} />

            <h1 className="sr-only">{invoice.number}</h1>

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title={invoice.number}
                        description={invoice.purpose_label}
                    />

                    <Button asChild variant="ghost" size="sm">
                        <Link href={invoiceIndex()}>
                            {t('package.invoices.back')}
                        </Link>
                    </Button>
                </div>

                <section className="bg-card space-y-3 rounded-xl border p-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <dl className="grid gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="text-muted-foreground text-xs">
                                    {t('package.invoices.issued')}
                                </dt>
                                <dd>{date(invoice.issued_at)}</dd>
                            </div>

                            {invoice.payment_reference && (
                                <div>
                                    <dt className="text-muted-foreground text-xs">
                                        {t('package.invoices.reference')}
                                    </dt>
                                    <dd className="font-mono text-xs">
                                        {invoice.payment_reference}
                                    </dd>
                                </div>
                            )}
                        </dl>

                        <StatusPill
                            tone={invoice.is_paid ? 'success' : 'warning'}
                            label={t(
                                invoice.is_paid
                                    ? 'package.invoices.paid'
                                    : 'package.invoices.unpaid',
                            )}
                        />
                    </div>

                    {!invoice.is_paid && (
                        <p className="text-muted-foreground text-sm">
                            {t('package.invoices.unpaid_note')}
                        </p>
                    )}
                </section>

                <section className="bg-card border-border overflow-hidden rounded-xl border">
                    <dl className="divide-border divide-y">
                        {invoice.lines.map((line) => (
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

                        <div className="flex items-center justify-between gap-4 px-4 py-2.5">
                            <dt className="text-muted-foreground text-sm">
                                {t('package.invoices.subtotal')}
                            </dt>
                            <dd>
                                <MoneyAmount amount={invoice.subtotal} />
                            </dd>
                        </div>

                        <div className="bg-muted flex items-center justify-between gap-4 px-4 py-3">
                            <dt className="text-sm font-semibold">
                                {t('package.invoices.total')}
                            </dt>
                            <dd>
                                <MoneyAmount
                                    amount={invoice.total}
                                    size="large"
                                />
                            </dd>
                        </div>
                    </dl>
                </section>
            </div>
        </>
    );
}
