import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import { index } from '@/routes/supplier/withdrawals';

type Withdrawal = {
    id: string;
    reference: string;
    amount: Money;
    status: string;
    status_label: string;
    status_tone: string;
    payout_snapshot: {
        type_label: string | null;
        label: string | null;
        masked_number: string | null;
    };
    failure_reason: string | null;
    external_reference: string | null;
    requested_at: string;
    decided_at: string | null;
    paid_at: string | null;
    history: {
        previous_status: string | null;
        new_status: string;
        reason: string | null;
        changed_at: string;
    }[];
};

export default function SupplierWithdrawalShow({
    withdrawal,
}: {
    withdrawal: Withdrawal;
}) {
    const { t, locale } = useTranslation();

    return (
        <>
            <Head title={withdrawal.reference} />

            <PageContainer width="narrow">
                <PageHeader
                    title={withdrawal.reference}
                    description={`${withdrawal.payout_snapshot.type_label} · ${withdrawal.payout_snapshot.masked_number}`}
                    actions={
                        <>
                            <StatusPill
                                tone={withdrawal.status_tone as never}
                                label={withdrawal.status_label}
                            />
                            <Button variant="outline" size="sm" asChild>
                                <Link href={index()}>
                                    <ArrowLeft
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t('supplier.withdrawals.back')}
                                </Link>
                            </Button>
                        </>
                    }
                />

                <SectionCard title={t('supplier.withdrawals.amount')}>
                    <dl className="grid gap-4 text-sm sm:grid-cols-3">
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('supplier.withdrawals.amount')}
                            </dt>
                            <dd>
                                <MoneyAmount
                                    amount={withdrawal.amount}
                                    size="large"
                                />
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('supplier.withdrawals.requested_at')}
                            </dt>
                            <dd>
                                {new Date(
                                    withdrawal.requested_at,
                                ).toLocaleString(locale)}
                            </dd>
                        </div>
                        {withdrawal.paid_at && (
                            <div>
                                <dt className="text-muted-foreground text-xs">
                                    {t('supplier.withdrawals.paid_at')}
                                </dt>
                                <dd>
                                    {new Date(
                                        withdrawal.paid_at,
                                    ).toLocaleString(locale)}
                                </dd>
                            </div>
                        )}
                    </dl>

                    {withdrawal.external_reference && (
                        <p className="text-muted-foreground mt-4 text-xs">
                            {t('supplier.withdrawals.external_reference')}:{' '}
                            <span className="font-mono">
                                {withdrawal.external_reference}
                            </span>
                        </p>
                    )}

                    {withdrawal.failure_reason && (
                        <p
                            className="text-warning-foreground mt-4 text-sm font-medium"
                            role="status"
                        >
                            {t('supplier.withdrawals.failure_reason')}:{' '}
                            {withdrawal.failure_reason}
                        </p>
                    )}
                </SectionCard>

                <SectionCard title={t('supplier.withdrawals.history')}>
                    <ol className="divide-border divide-y text-sm">
                        {withdrawal.history.map((change, position) => (
                            <li
                                key={position}
                                className="py-2 first:pt-0 last:pb-0"
                            >
                                <div className="flex flex-wrap justify-between gap-2">
                                    <span>{change.new_status}</span>
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
