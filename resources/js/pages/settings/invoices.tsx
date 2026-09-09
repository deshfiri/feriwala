import { Head, Link } from '@inertiajs/react';
import { ReceiptText } from 'lucide-react';
import Heading from '@/components/heading';
import MoneyAmount from '@/components/money-amount';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { show as invoiceShow } from '@/routes/subscription/invoices';
import type { Money } from '@/lib/money';

export type InvoiceRow = {
    id: string;
    number: string;
    purpose: string;
    purpose_label: string;
    total: Money;
    issued_at: string;
    is_paid: boolean;
    payment_reference: string | null;
    payment_status: string | null;
    paid_at: string | null;
};

type Props = {
    invoices: {
        data: InvoiceRow[];
        current_page: number;
        last_page: number;
        total: number;
    };
};

/**
 * Invoices and package payment history (§8.2).
 *
 * Paid or not is the column that matters, and it is never colour alone (§33.9):
 * the pill carries its own label. Whether an invoice has been paid comes from
 * its payment on the server, so the list cannot disagree with the money.
 */
export default function Invoices({ invoices }: Props) {
    const { t, locale } = useTranslation();

    const date = (value: string) => new Date(value).toLocaleDateString(locale);

    return (
        <>
            <Head title={t('package.invoices.title')} />

            <h1 className="sr-only">{t('package.invoices.title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('package.invoices.title')}
                    description={t('package.invoices.description')}
                />

                {invoices.data.length === 0 ? (
                    <EmptyState
                        icon={ReceiptText}
                        title={t('package.invoices.empty_title')}
                        description={t('package.invoices.empty_description')}
                    />
                ) : (
                    <ul className="bg-card divide-border divide-y rounded-xl border text-sm">
                        {invoices.data.map((invoice) => (
                            <li
                                key={invoice.id}
                                className="flex flex-wrap items-center justify-between gap-3 p-4"
                            >
                                <div className="min-w-0">
                                    <p className="font-medium">
                                        {invoice.number}
                                    </p>
                                    <p className="text-muted-foreground text-xs">
                                        {invoice.purpose_label} ·{' '}
                                        {date(invoice.issued_at)}
                                    </p>
                                </div>

                                <div className="flex items-center gap-3">
                                    <MoneyAmount amount={invoice.total} />

                                    <StatusPill
                                        tone={
                                            invoice.is_paid
                                                ? 'success'
                                                : 'warning'
                                        }
                                        label={t(
                                            invoice.is_paid
                                                ? 'package.invoices.paid'
                                                : 'package.invoices.unpaid',
                                        )}
                                    />

                                    <Button asChild variant="ghost" size="sm">
                                        <Link href={invoiceShow(invoice.id)}>
                                            {t('package.invoices.view')}
                                        </Link>
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
