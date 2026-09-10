import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/admin/payments';
import type { PaymentRow } from './index';

type LogEntry = {
    id: string;
    direction: string;
    event: string;
    outcome: string | null;
    http_status: number | null;
    gateway_reference: string | null;
    ip_address: string | null;
    at: string;
    context: Record<string, unknown>;
};

type Props = {
    payment: PaymentRow & {
        purpose_label: string;
        settled_amount_minor: number | null;
        settled_currency_code: string | null;
        initiated_at: string | null;
        expires_at: string | null;
        failed_at: string | null;
        cancelled_at: string | null;
        failure_reason: string | null;
        reconciliation_reason: string | null;
        reconciliation_required_at: string | null;
    };
    logs: LogEntry[];
};

/**
 * One payment, and everything that was said about it (§42, §26.4).
 *
 * The reconciliation panel comes first when there is one. It is the only state
 * on this screen where money is sitting unresolved, and it says plainly what was
 * and was not done — nothing activated, nothing refunded — so the next person
 * knows what they are picking up.
 *
 * The trail below is rendered exactly as stored, and it was redacted on the way
 * in. There is nothing here to leak.
 */
export default function AdminPaymentShow({ payment, logs }: Props) {
    const { t, locale } = useTranslation();
    const [open, setOpen] = useState<string | null>(null);

    const at = (value: string | null) =>
        value === null
            ? t('payments.detail.none')
            : new Date(value).toLocaleString(locale);

    const field = (label: string, value: string) => (
        <div>
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="text-sm">{value}</dd>
        </div>
    );

    return (
        <>
            <Head title={payment.reference} />

            <PageContainer width="narrow">
                <PageHeader
                    title={payment.reference}
                    description={payment.account ?? undefined}
                    actions={
                        <Button asChild variant="ghost" size="sm">
                            <Link href={index()}>{t('payments.back')}</Link>
                        </Button>
                    }
                />

                {payment.needs_reconciliation && (
                    <SectionCard
                        title={t('payments.reconciliation.title')}
                        description={t('payments.reconciliation.help')}
                    >
                        <p className="text-sm">
                            {payment.reconciliation_reason}
                        </p>
                        <p className="text-muted-foreground mt-2 text-xs">
                            {t('payments.reconciliation.flagged', {
                                date: at(payment.reconciliation_required_at),
                            })}
                        </p>
                    </SectionCard>
                )}

                <SectionCard title={t('payments.detail.summary')}>
                    <dl className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('payments.columns.status')}
                            </dt>
                            <dd className="mt-1">
                                <StatusPill
                                    tone={payment.status_tone}
                                    label={payment.status_label}
                                />
                            </dd>
                        </div>

                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('payments.columns.amount')}
                            </dt>
                            <dd>
                                <MoneyAmount amount={payment.amount} />
                            </dd>
                        </div>

                        {field(
                            t('payments.detail.purpose'),
                            payment.purpose_label,
                        )}
                        {field(
                            t('payments.columns.gateway'),
                            payment.gateway ?? t('payments.detail.none'),
                        )}
                        {field(
                            t('payments.columns.gateway_reference'),
                            payment.gateway_reference ??
                                t('payments.detail.none'),
                        )}
                        {field(
                            t('payments.detail.invoice'),
                            payment.invoice_number ?? t('payments.detail.none'),
                        )}
                        {field(
                            t('payments.detail.settled'),
                            payment.settled_amount_minor === null
                                ? t('payments.detail.none')
                                : `${payment.settled_amount_minor} ${payment.settled_currency_code ?? ''}`.trim(),
                        )}
                        {field(
                            t('payments.columns.created'),
                            at(payment.created_at),
                        )}
                        {field(
                            t('payments.detail.initiated'),
                            at(payment.initiated_at),
                        )}
                        {field(
                            t('payments.detail.expires'),
                            at(payment.expires_at),
                        )}
                        {field(
                            t('payments.columns.completed'),
                            at(payment.completed_at),
                        )}
                        {field(
                            t('payments.detail.cancelled'),
                            at(payment.cancelled_at),
                        )}
                        {field(
                            t('payments.detail.failed'),
                            at(payment.failed_at),
                        )}
                        {payment.failure_reason !== null &&
                            field(
                                t('payments.detail.failure_reason'),
                                payment.failure_reason,
                            )}
                    </dl>
                </SectionCard>

                <SectionCard
                    title={t('payments.log.title')}
                    description={t('payments.log.description')}
                    contentClassName="p-0"
                >
                    {logs.length === 0 ? (
                        <p className="text-muted-foreground px-5 py-4 text-sm">
                            {t('payments.log.empty')}
                        </p>
                    ) : (
                        <ul className="divide-border divide-y text-sm">
                            {logs.map((entry) => (
                                <li key={entry.id} className="px-5 py-3">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="font-medium">
                                                {t(
                                                    entry.direction ===
                                                        'inbound'
                                                        ? 'payments.log.inbound'
                                                        : 'payments.log.outbound',
                                                )}
                                                {' · '}
                                                {entry.event}
                                                {entry.outcome !== null && (
                                                    <span className="text-muted-foreground">
                                                        {' · '}
                                                        {entry.outcome}
                                                    </span>
                                                )}
                                            </p>
                                            <p className="text-muted-foreground text-xs">
                                                {new Date(
                                                    entry.at,
                                                ).toLocaleString(locale)}
                                                {entry.http_status !== null &&
                                                    ` · HTTP ${entry.http_status}`}
                                                {entry.ip_address !== null &&
                                                    ` · ${t('payments.log.from', { ip: entry.ip_address })}`}
                                            </p>
                                        </div>

                                        {Object.keys(entry.context).length >
                                            0 && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setOpen(
                                                        open === entry.id
                                                            ? null
                                                            : entry.id,
                                                    )
                                                }
                                            >
                                                {t(
                                                    open === entry.id
                                                        ? 'payments.log.hide_payload'
                                                        : 'payments.log.show_payload',
                                                )}
                                            </Button>
                                        )}
                                    </div>

                                    {open === entry.id && (
                                        <pre className="bg-muted mt-3 overflow-x-auto rounded-lg p-3 text-xs">
                                            {JSON.stringify(
                                                entry.context,
                                                null,
                                                2,
                                            )}
                                        </pre>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </PageContainer>
        </>
    );
}

AdminPaymentShow.layout = {
    breadcrumbs: [
        {
            title: 'Payments',
            href: index(),
        },
    ],
};
