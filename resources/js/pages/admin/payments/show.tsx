import { Form, Head, Link } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import PaymentManualSettlementController from '@/actions/App/Http/Controllers/Admin/PaymentManualSettlementController';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import { index } from '@/routes/admin/payments';
import { confirm as confirmPassword } from '@/routes/password';
import type { PaymentRow } from './index';
import ReasonTextarea from '@/components/forms/reason-textarea';

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
    /**
     * How many fields the server refused to send (§42).
     *
     * A count rather than the names: an administrator needs to know two fields
     * were withheld, not what a gateway calls its signature.
     */
    withheld: number;
};

type Manual = {
    available: boolean;
    password_confirmed: boolean;
    can_override: boolean;
};

type Props = {
    manual: Manual;
    payment: PaymentRow & {
        purpose_label: string;
        settled_amount: Money | null;
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
 * The trail below was redacted twice: once on the way into the database, where
 * a secret's value is destroyed but its field name survives so an investigator
 * can tell "no signature was sent" from "the signature was stripped"; and again
 * on the way here, where the field is dropped outright. A rendered page ends up
 * in tickets, screenshots and chat threads, and travels further than the row it
 * came from — so what reaches this screen is a count of what was withheld, and
 * nothing else about it (§42).
 */
export default function AdminPaymentShow({ payment, logs, manual }: Props) {
    const { t, locale } = useTranslation();
    const [open, setOpen] = useState<string | null>(null);

    const at = (value: string | null) =>
        value === null
            ? t('payments.detail.none')
            : new Date(value).toLocaleString(locale);

    const field = (label: string, value: ReactNode) => (
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
                            payment.settled_amount === null ? (
                                t('payments.detail.none')
                            ) : (
                                <MoneyAmount amount={payment.settled_amount} />
                            ),
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

                {manual.available && !manual.password_confirmed && (
                    <SectionCard
                        title={t('payments.manual.title')}
                        description={t('payments.manual.confirm_first')}
                    >
                        <Button size="sm" variant="outline" asChild>
                            <Link href={confirmPassword()}>
                                {t('payments.manual.confirm')}
                            </Link>
                        </Button>
                    </SectionCard>
                )}

                {manual.available && manual.password_confirmed && (
                    <SectionCard
                        title={t('payments.manual.title')}
                        description={t('payments.manual.help')}
                    >
                        <Form
                            {...PaymentManualSettlementController.store.form(
                                payment.id,
                            )}
                            options={{ preserveScroll: true }}
                            className="grid gap-3"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="manual-reference">
                                            {t('payments.manual.reference')}
                                        </Label>
                                        <Input
                                            id="manual-reference"
                                            name="gateway_reference"
                                            maxLength={255}
                                            required
                                            defaultValue={
                                                payment.gateway_reference ?? ''
                                            }
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            {t(
                                                'payments.manual.reference_hint',
                                            )}
                                        </p>
                                        <InputError
                                            message={errors.gateway_reference}
                                        />
                                    </div>

                                    <div className="grid gap-1.5">
                                        <Label htmlFor="manual-reason">
                                            {t('payments.manual.reason')}
                                        </Label>
                                        <ReasonTextarea
                                            context="generic"
                                            id="manual-reason"
                                            name="reason"
                                            minLength={10}
                                            maxLength={1000}
                                            required
                                        />
                                        <InputError message={errors.reason} />
                                    </div>

                                    {manual.can_override && (
                                        <div className="flex items-start gap-2">
                                            <input
                                                id="manual-override"
                                                type="checkbox"
                                                name="override"
                                                value="1"
                                                className="mt-1"
                                            />
                                            <Label htmlFor="manual-override">
                                                {t('payments.manual.override')}
                                            </Label>
                                        </div>
                                    )}

                                    <div className="flex items-start gap-2">
                                        <input
                                            id="manual-confirm"
                                            type="checkbox"
                                            name="confirm"
                                            value="1"
                                            required
                                            className="mt-1"
                                        />
                                        <Label htmlFor="manual-confirm">
                                            {t('payments.manual.confirm_box')}
                                        </Label>
                                    </div>
                                    <InputError message={errors.confirm} />

                                    <div>
                                        <Button
                                            type="submit"
                                            size="sm"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {t('payments.manual.submit')}
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </SectionCard>
                )}

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

                                        {(Object.keys(entry.context).length >
                                            0 ||
                                            entry.withheld > 0) && (
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
                                        <>
                                            <pre className="bg-muted mt-3 overflow-x-auto rounded-lg p-3 text-xs">
                                                {JSON.stringify(
                                                    entry.context,
                                                    null,
                                                    2,
                                                )}
                                            </pre>

                                            {entry.withheld > 0 && (
                                                // Said out loud: a field that is
                                                // simply missing reads as one
                                                // the gateway never sent.
                                                <p className="text-muted-foreground mt-2 text-xs">
                                                    {t(
                                                        'payments.log.withheld',
                                                        {
                                                            count: entry.withheld,
                                                        },
                                                    )}
                                                </p>
                                            )}
                                        </>
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
            title: 'nav.payments',
            href: index(),
        },
    ],
};
