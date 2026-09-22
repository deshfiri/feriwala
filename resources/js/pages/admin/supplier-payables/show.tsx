import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import SupplierPayableController from '@/actions/App/Http/Controllers/Admin/SupplierPayableController';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import { index } from '@/routes/admin/supplier-payables';
import { confirm as confirmPassword } from '@/routes/password';

type Payable = {
    id: string;
    reference: string;
    supplier: string;
    order_reference: string;
    product_name: string;
    variant_label: string | null;
    quantity: number;
    supplier_rate: Money;
    gross_amount: Money;
    net_amount: Money;
    status: string;
    status_label: string;
    status_tone: string;
    delivered_at: string | null;
    payment_settled_at: string | null;
    eligible_at: string | null;
    settled_at: string | null;
    settlement_reference: string | null;
    created_at: string;
    reversals: {
        quantity: number;
        amount: Money;
        reason: string;
        wallet_reference: string | null;
        created_at: string;
    }[];
    history: {
        previous_status: string | null;
        new_status: string;
        reason: string | null;
        changed_by: string | null;
        changed_at: string;
    }[];
};

export default function AdminSupplierPayableShow({
    payable,
    can,
    password_confirmed: passwordConfirmed,
}: {
    payable: Payable;
    can: { settle: boolean };
    password_confirmed: boolean;
}) {
    const { t, locale } = useTranslation();

    return (
        <>
            <Head title={payable.reference} />

            <PageContainer>
                <PageHeader
                    title={`${payable.product_name}${payable.variant_label ? ` · ${payable.variant_label}` : ''}`}
                    description={`${payable.reference} · ${payable.supplier} · ${payable.order_reference}`}
                    actions={
                        <>
                            <StatusPill
                                tone={payable.status_tone as never}
                                label={payable.status_label}
                            />
                            <Button variant="outline" size="sm" asChild>
                                <Link href={index()}>
                                    <ArrowLeft
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t('supplier.admin.suppliers.back')}
                                </Link>
                            </Button>
                        </>
                    }
                />

                {payable.status === 'eligible' && can.settle && (
                    <SectionCard title={t('supplier.payables.settled_at')}>
                        {passwordConfirmed ? (
                            <Form
                                {...SupplierPayableController.settle.form(
                                    payable.id,
                                )}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <p className="text-muted-foreground mb-3 text-sm">
                                            {t(
                                                'supplier.admin.payables.settle_confirm',
                                            )}
                                        </p>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {t(
                                                'supplier.admin.payables.settle',
                                            )}
                                        </Button>
                                        {errors.settlement && (
                                            <p className="text-destructive mt-2 text-sm">
                                                {errors.settlement}
                                            </p>
                                        )}
                                    </>
                                )}
                            </Form>
                        ) : (
                            <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                <span>
                                    {t(
                                        'supplier.admin.payables.confirm_password_first',
                                    )}
                                </span>
                                <Button size="sm" variant="outline" asChild>
                                    <Link href={confirmPassword()}>
                                        {t(
                                            'supplier.admin.payables.confirm_password',
                                        )}
                                    </Link>
                                </Button>
                            </div>
                        )}
                    </SectionCard>
                )}

                {payable.settled_at && (
                    <SectionCard title={t('supplier.payables.settled_at')}>
                        <p className="text-sm">
                            {new Date(payable.settled_at).toLocaleString(
                                locale,
                            )}
                        </p>
                        <p className="text-muted-foreground font-mono text-xs">
                            {t('supplier.payables.settlement_reference')}:{' '}
                            {payable.settlement_reference}
                        </p>
                    </SectionCard>
                )}

                <SectionCard
                    title={t('supplier.admin.payables.columns.amount')}
                >
                    <dl className="grid gap-4 text-sm sm:grid-cols-4">
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t(
                                    'supplier.admin.allocations.columns.quantity',
                                )}
                            </dt>
                            <dd>{payable.quantity.toLocaleString(locale)}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t(
                                    'supplier.admin.offers.columns.supplier_rate',
                                )}
                            </dt>
                            <dd>
                                <MoneyAmount amount={payable.supplier_rate} />
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('supplier.payables.gross_amount')}
                            </dt>
                            <dd>
                                <MoneyAmount
                                    amount={payable.gross_amount}
                                    size="large"
                                />
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('supplier.payables.net_amount')}
                            </dt>
                            <dd>
                                <MoneyAmount
                                    amount={payable.net_amount}
                                    size="large"
                                />
                            </dd>
                        </div>
                    </dl>
                    <p className="text-muted-foreground mt-4 text-xs">
                        {payable.delivered_at
                            ? t('supplier.payables.delivered')
                            : t('supplier.payables.awaiting_delivery')}
                        {' · '}
                        {payable.payment_settled_at
                            ? t('supplier.payables.payment_settled')
                            : t('supplier.payables.awaiting_payment')}
                    </p>
                </SectionCard>

                {payable.reversals.length > 0 && (
                    <SectionCard title={t('supplier.payables.reversals')}>
                        <ul className="divide-border divide-y text-sm">
                            {payable.reversals.map((reversal, position) => (
                                <li
                                    key={position}
                                    className="space-y-0.5 py-2 first:pt-0 last:pb-0"
                                >
                                    <div className="flex flex-wrap justify-between gap-2">
                                        <MoneyAmount amount={reversal.amount} />
                                        <span className="text-muted-foreground text-xs">
                                            {new Date(
                                                reversal.created_at,
                                            ).toLocaleDateString(locale)}
                                        </span>
                                    </div>
                                    <p className="text-muted-foreground">
                                        {reversal.reason}
                                    </p>
                                    {reversal.wallet_reference && (
                                        <p className="text-muted-foreground font-mono text-xs">
                                            {t(
                                                'supplier.payables.wallet_reference',
                                            )}
                                            : {reversal.wallet_reference}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </SectionCard>
                )}

                <SectionCard title={t('supplier.payables.history')}>
                    <ol className="divide-border divide-y text-sm">
                        {payable.history.map((change, position) => (
                            <li
                                key={position}
                                className="py-2 first:pt-0 last:pb-0"
                            >
                                <div className="flex flex-wrap justify-between gap-2">
                                    <span>
                                        {change.new_status} ·{' '}
                                        {change.changed_by ?? '—'}
                                    </span>
                                    <span className="text-muted-foreground text-xs">
                                        {new Date(
                                            change.changed_at,
                                        ).toLocaleString(locale)}
                                    </span>
                                </div>
                                {change.reason && (
                                    <p className="text-muted-foreground">
                                        {change.reason}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ol>
                </SectionCard>
            </PageContainer>
        </>
    );
}

AdminSupplierPayableShow.layout = {
    breadcrumbs: [{ title: 'Supplier payables', href: index() }],
};
