import { Form, Head, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft } from 'lucide-react';
import FormField from '@/components/forms/form-field';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import type { StatusTone } from '@/lib/status';
import OrderReturnController from '@/actions/App/Http/Controllers/Admin/OrderReturnController';
import PaymentRefundController from '@/actions/App/Http/Controllers/Admin/PaymentRefundController';
import { show as orderShow } from '@/routes/admin/orders';
import { index } from '@/routes/admin/returns';

type ReturnLine = {
    id: string;
    sku: string;
    name: string;
    variant: string | null;
    sold: number;
    quantity: number;
    approved_quantity: number | null;
    received_quantity: number;
    disposition: string | null;
    warehouse: string | null;
    restored: boolean;
    unit_price: Money;
    refund_amount: Money | null;
};

type AdminReturn = {
    id: string;
    reference: string;
    status: string;
    status_tone: StatusTone;
    reason: string;
    customer_note: string | null;
    evidence: string[];
    decision_note: string | null;
    source: string;
    requested_at: string;
    decided_at: string | null;
    received_at: string | null;
    order: { id: string; reference: string };
    account: string;
    website: string | null;
    cash_on_delivery: boolean;
    lines: ReturnLine[];
    refund: {
        state: string;
        tone: StatusTone;
        amount: Money | null;
        note: string | null;
        refunded_at: string | null;
        request: {
            id: string;
            status: string;
            failure_reason: string | null;
        } | null;
    };
    history: {
        previous_status: string | null;
        new_status: string;
        at: string;
        source: string;
        actor: string | null;
        reason: string | null;
        internal_note: string | null;
        public_note: string | null;
    }[];
};

type Props = {
    orderReturn: AdminReturn;
    warehouses: { id: string; label: string }[];
    dispositions: string[];
    can: {
        approve: boolean;
        reject: boolean;
        receive: boolean;
        start_refund: boolean;
        decide_refund: boolean;
        send_refund: boolean;
        settle_manually: boolean;
    };
};

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * One return as the staff who handle it see it (§18.2, §19.1, §26.3, P6-12).
 *
 * Each step is offered only when the return is at it **and** the person holds
 * the right for it — both decided on the server. Every decision asks for its
 * reason; counting goods back in says what happens to each line; sending money
 * and recording a refund settled by hand both ask for the password again.
 */
export default function AdminReturn({
    orderReturn,
    warehouses,
    dispositions,
    can,
}: Props) {
    const { t, locale } = useTranslation();
    const at = (value: string | null) =>
        value ? new Date(value).toLocaleString(locale) : '—';

    const reasonField = (error?: string) => (
        <FormField
            label={t('returns.admin.reason')}
            description={t('returns.admin.reason_help')}
            error={error}
            required
        >
            {(field) => (
                <textarea
                    {...field}
                    name="reason"
                    rows={3}
                    minLength={10}
                    maxLength={1000}
                    required
                    className={controlClass}
                />
            )}
        </FormField>
    );

    return (
        <>
            <Head title={orderReturn.reference} />

            <PageContainer>
                <Link
                    href={index()}
                    className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 text-sm"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    {t('returns.admin.back')}
                </Link>

                <PageHeader
                    title={orderReturn.reference}
                    description={`${orderReturn.account} · ${orderReturn.order.reference}`}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusPill
                                tone={orderReturn.status_tone}
                                label={t(
                                    `returns.statuses.${orderReturn.status}`,
                                )}
                            />
                            <StatusPill
                                tone={orderReturn.refund.tone}
                                label={t(
                                    `returns.refund_states.${orderReturn.refund.state}`,
                                )}
                            />
                            <Button variant="outline" size="sm" asChild>
                                <Link href={orderShow(orderReturn.order.id)}>
                                    {t('returns.admin.open_order')}
                                </Link>
                            </Button>
                        </div>
                    }
                />

                {(orderReturn.refund.state === 'manual_review' ||
                    orderReturn.refund.state === 'failed') &&
                    orderReturn.refund.note && (
                        <div
                            role="status"
                            className="border-warning bg-warning-subtle flex gap-3 rounded-xl border p-4"
                        >
                            <AlertTriangle
                                className="mt-0.5 size-5 shrink-0"
                                aria-hidden="true"
                            />
                            <div className="space-y-1">
                                <p className="font-medium">
                                    {t(
                                        `returns.refund_states.${orderReturn.refund.state}`,
                                    )}
                                </p>
                                <p className="text-sm">
                                    {orderReturn.refund.note}
                                </p>
                            </div>
                        </div>
                    )}

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="min-w-0 space-y-6">
                        <SectionCard title={t('returns.admin.sections.lines')}>
                            <ul className="divide-border divide-y">
                                {orderReturn.lines.map((line) => (
                                    <li
                                        key={line.id}
                                        className="space-y-1 py-3 first:pt-0 last:pb-0"
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                            <div className="min-w-0">
                                                <div className="font-medium break-words">
                                                    {line.name}
                                                </div>
                                                <div className="text-muted-foreground font-mono text-xs">
                                                    {line.sku}
                                                    {line.variant
                                                        ? ` · ${line.variant}`
                                                        : ''}
                                                </div>
                                            </div>
                                            <MoneyAmount
                                                amount={line.unit_price}
                                            />
                                        </div>
                                        <dl className="text-muted-foreground grid grid-cols-2 gap-x-3 gap-y-1 text-xs sm:grid-cols-4">
                                            <div>
                                                <dt>
                                                    {t(
                                                        'returns.admin.fields.sold',
                                                    )}
                                                </dt>
                                                <dd className="text-foreground tabular-nums">
                                                    {line.sold}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt>
                                                    {t(
                                                        'returns.admin.fields.asked',
                                                    )}
                                                </dt>
                                                <dd className="text-foreground tabular-nums">
                                                    {line.quantity}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt>
                                                    {t(
                                                        'returns.admin.fields.approved',
                                                    )}
                                                </dt>
                                                <dd className="text-foreground tabular-nums">
                                                    {line.approved_quantity ??
                                                        '—'}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt>
                                                    {t(
                                                        'returns.admin.fields.received',
                                                    )}
                                                </dt>
                                                <dd className="text-foreground tabular-nums">
                                                    {line.received_quantity}
                                                </dd>
                                            </div>
                                        </dl>
                                        {line.disposition && (
                                            <p className="text-xs">
                                                {t(
                                                    `returns.dispositions.${line.disposition}`,
                                                )}
                                                {line.warehouse
                                                    ? ` · ${line.warehouse}`
                                                    : ''}
                                            </p>
                                        )}
                                        {line.refund_amount && (
                                            <p className="text-xs">
                                                {t(
                                                    'returns.admin.fields.amount',
                                                )}
                                                :{' '}
                                                <MoneyAmount
                                                    amount={line.refund_amount}
                                                    direction="credit"
                                                />
                                            </p>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </SectionCard>

                        {(can.approve || can.reject) && (
                            <SectionCard
                                title={t('returns.admin.sections.decide')}
                            >
                                <div className="grid gap-6 md:grid-cols-2">
                                    {can.approve && (
                                        <Form
                                            {...OrderReturnController.approve.form(
                                                orderReturn.id,
                                            )}
                                            options={{ preserveScroll: true }}
                                            className="space-y-3"
                                        >
                                            {({ processing, errors }) => (
                                                <>
                                                    <p className="text-muted-foreground text-sm">
                                                        {t(
                                                            'returns.admin.approve_help',
                                                        )}
                                                    </p>
                                                    {orderReturn.lines.map(
                                                        (line) => (
                                                            <FormField
                                                                key={line.id}
                                                                label={`${line.sku}`}
                                                            >
                                                                {(field) => (
                                                                    <Input
                                                                        {...field}
                                                                        type="number"
                                                                        inputMode="numeric"
                                                                        name={`quantities[${line.id}]`}
                                                                        min={0}
                                                                        max={
                                                                            line.quantity
                                                                        }
                                                                        defaultValue={
                                                                            line.quantity
                                                                        }
                                                                        className="w-28"
                                                                    />
                                                                )}
                                                            </FormField>
                                                        ),
                                                    )}
                                                    {reasonField(errors.reason)}
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        disabled={processing}
                                                    >
                                                        {processing && (
                                                            <Spinner />
                                                        )}
                                                        {t(
                                                            'returns.admin.approve',
                                                        )}
                                                    </Button>
                                                </>
                                            )}
                                        </Form>
                                    )}
                                    {can.reject && (
                                        <Form
                                            {...OrderReturnController.reject.form(
                                                orderReturn.id,
                                            )}
                                            options={{ preserveScroll: true }}
                                            className="space-y-3"
                                        >
                                            {({ processing, errors }) => (
                                                <>
                                                    {reasonField(errors.reason)}
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        variant="destructive"
                                                        disabled={processing}
                                                    >
                                                        {processing && (
                                                            <Spinner />
                                                        )}
                                                        {t(
                                                            'returns.admin.reject',
                                                        )}
                                                    </Button>
                                                </>
                                            )}
                                        </Form>
                                    )}
                                </div>
                            </SectionCard>
                        )}

                        {can.receive && (
                            <SectionCard
                                title={t('returns.admin.sections.receive')}
                                description={t('returns.admin.receive_help')}
                            >
                                <Form
                                    {...OrderReturnController.receive.form(
                                        orderReturn.id,
                                    )}
                                    options={{ preserveScroll: true }}
                                    className="space-y-4"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <FormField
                                                label={t(
                                                    'returns.admin.warehouse',
                                                )}
                                                error={errors.warehouse}
                                                required
                                            >
                                                {(field) => (
                                                    <select
                                                        {...field}
                                                        name="warehouse"
                                                        className={controlClass}
                                                        required
                                                    >
                                                        {warehouses.map(
                                                            (warehouse) => (
                                                                <option
                                                                    key={
                                                                        warehouse.id
                                                                    }
                                                                    value={
                                                                        warehouse.id
                                                                    }
                                                                >
                                                                    {
                                                                        warehouse.label
                                                                    }
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                )}
                                            </FormField>

                                            {orderReturn.lines.map((line) => (
                                                <fieldset
                                                    key={line.id}
                                                    className="border-border grid gap-3 rounded-lg border p-3 sm:grid-cols-2"
                                                >
                                                    <legend className="px-1 font-mono text-xs">
                                                        {line.sku}
                                                    </legend>
                                                    <FormField
                                                        label={t(
                                                            'returns.admin.arrived',
                                                        )}
                                                    >
                                                        {(field) => (
                                                            <Input
                                                                {...field}
                                                                type="number"
                                                                inputMode="numeric"
                                                                name={`lines[${line.id}][quantity]`}
                                                                min={0}
                                                                max={
                                                                    line.approved_quantity ??
                                                                    0
                                                                }
                                                                defaultValue={
                                                                    line.approved_quantity ??
                                                                    0
                                                                }
                                                            />
                                                        )}
                                                    </FormField>
                                                    <FormField
                                                        label={t(
                                                            'returns.admin.fields.disposition',
                                                        )}
                                                        required
                                                    >
                                                        {(field) => (
                                                            <select
                                                                {...field}
                                                                name={`lines[${line.id}][disposition]`}
                                                                className={
                                                                    controlClass
                                                                }
                                                                defaultValue=""
                                                                required
                                                            >
                                                                <option
                                                                    value=""
                                                                    disabled
                                                                >
                                                                    —
                                                                </option>
                                                                {dispositions.map(
                                                                    (value) => (
                                                                        <option
                                                                            key={
                                                                                value
                                                                            }
                                                                            value={
                                                                                value
                                                                            }
                                                                        >
                                                                            {t(
                                                                                `returns.dispositions.${value}`,
                                                                            )}
                                                                        </option>
                                                                    ),
                                                                )}
                                                            </select>
                                                        )}
                                                    </FormField>
                                                </fieldset>
                                            ))}
                                            <InputError
                                                message={errors.lines}
                                            />

                                            <FormField
                                                label={t('returns.admin.note')}
                                                error={errors.note}
                                            >
                                                {(field) => (
                                                    <textarea
                                                        {...field}
                                                        name="note"
                                                        rows={2}
                                                        maxLength={1000}
                                                        className={controlClass}
                                                    />
                                                )}
                                            </FormField>

                                            <Button
                                                type="submit"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                {processing && <Spinner />}
                                                {t('returns.admin.receive')}
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </SectionCard>
                        )}

                        <SectionCard
                            title={t('returns.admin.sections.history')}
                        >
                            <ol className="space-y-3">
                                {orderReturn.history.map((entry, position) => (
                                    <li key={`${entry.new_status}-${position}`}>
                                        <p className="text-sm font-medium">
                                            {t(
                                                `returns.statuses.${entry.new_status}`,
                                            )}
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            <time dateTime={entry.at}>
                                                {at(entry.at)}
                                            </time>
                                            {' · '}
                                            {t(
                                                `returns.sources.${entry.source}`,
                                            )}
                                            {' · '}
                                            {entry.actor ??
                                                t(
                                                    'returns.admin.history.system',
                                                )}
                                        </p>
                                        {entry.reason && (
                                            <p className="mt-1 text-sm break-words">
                                                {t(
                                                    'returns.admin.history.reason',
                                                    { reason: entry.reason },
                                                )}
                                            </p>
                                        )}
                                        {entry.internal_note && (
                                            <p className="mt-1 text-sm break-words">
                                                {t(
                                                    'returns.admin.history.internal',
                                                    {
                                                        note: entry.internal_note,
                                                    },
                                                )}
                                            </p>
                                        )}
                                    </li>
                                ))}
                            </ol>
                        </SectionCard>
                    </div>

                    <div className="space-y-6">
                        <SectionCard
                            title={t('returns.admin.sections.overview')}
                        >
                            <dl className="space-y-3 text-sm">
                                <Row label={t('returns.admin.fields.order')}>
                                    <span className="font-mono text-xs">
                                        {orderReturn.order.reference}
                                    </span>
                                </Row>
                                <Row label={t('returns.admin.fields.account')}>
                                    {orderReturn.account}
                                </Row>
                                {orderReturn.website && (
                                    <Row
                                        label={t(
                                            'returns.admin.fields.website',
                                        )}
                                    >
                                        {orderReturn.website}
                                    </Row>
                                )}
                                <Row label={t('returns.admin.fields.source')}>
                                    {t(`returns.sources.${orderReturn.source}`)}
                                </Row>
                                <Row label={t('returns.admin.fields.reason')}>
                                    {t(`returns.reasons.${orderReturn.reason}`)}
                                </Row>
                                <Row label={t('returns.admin.fields.payment')}>
                                    {t(
                                        orderReturn.cash_on_delivery
                                            ? 'returns.admin.cod'
                                            : 'returns.admin.online',
                                    )}
                                </Row>
                                <Row
                                    label={t(
                                        'returns.admin.fields.requested_at',
                                    )}
                                >
                                    {at(orderReturn.requested_at)}
                                </Row>
                                {orderReturn.decided_at && (
                                    <Row
                                        label={t(
                                            'returns.admin.fields.decided_at',
                                        )}
                                    >
                                        {at(orderReturn.decided_at)}
                                    </Row>
                                )}
                                {orderReturn.received_at && (
                                    <Row
                                        label={t(
                                            'returns.admin.fields.received_at',
                                        )}
                                    >
                                        {at(orderReturn.received_at)}
                                    </Row>
                                )}
                            </dl>
                            {orderReturn.customer_note && (
                                <p className="border-border mt-4 border-t pt-3 text-sm break-words whitespace-pre-line">
                                    <span className="text-muted-foreground block text-xs">
                                        {t(
                                            'returns.admin.fields.customer_note',
                                        )}
                                    </span>
                                    {orderReturn.customer_note}
                                </p>
                            )}
                            {orderReturn.decision_note && (
                                <p className="border-border mt-4 border-t pt-3 text-sm break-words">
                                    <span className="text-muted-foreground block text-xs">
                                        {t(
                                            'returns.admin.fields.decision_note',
                                        )}
                                    </span>
                                    {orderReturn.decision_note}
                                </p>
                            )}
                            {orderReturn.evidence.length > 0 && (
                                <ul className="border-border mt-4 space-y-1 border-t pt-3 text-xs break-all">
                                    {orderReturn.evidence.map((item) => (
                                        <li key={item}>{item}</li>
                                    ))}
                                </ul>
                            )}
                        </SectionCard>

                        <SectionCard title={t('returns.admin.sections.refund')}>
                            <dl className="space-y-3 text-sm">
                                <Row label={t('returns.admin.fields.amount')}>
                                    {orderReturn.refund.amount ? (
                                        <MoneyAmount
                                            amount={orderReturn.refund.amount}
                                            direction="credit"
                                        />
                                    ) : (
                                        '—'
                                    )}
                                </Row>
                                {orderReturn.refund.request && (
                                    <Row
                                        label={t(
                                            'returns.admin.fields.refund_request',
                                        )}
                                    >
                                        {t(
                                            `returns.admin.refund_request_statuses.${orderReturn.refund.request.status}`,
                                        )}
                                    </Row>
                                )}
                            </dl>

                            <div className="mt-4 space-y-4">
                                {can.start_refund && (
                                    <Form
                                        {...OrderReturnController.refund.form(
                                            orderReturn.id,
                                        )}
                                        options={{ preserveScroll: true }}
                                        className="space-y-2"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <p className="text-muted-foreground text-xs">
                                                    {t(
                                                        'returns.admin.start_refund_help',
                                                    )}
                                                </p>
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    {processing && <Spinner />}
                                                    {t(
                                                        'returns.admin.start_refund',
                                                    )}
                                                </Button>
                                                <InputError
                                                    message={errors.refund}
                                                />
                                            </>
                                        )}
                                    </Form>
                                )}

                                {can.decide_refund &&
                                    (['approve', 'reject'] as const).map(
                                        (decision) => (
                                            <Form
                                                key={decision}
                                                {...OrderReturnController.decideRefund.form(
                                                    orderReturn.id,
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                                className="space-y-2"
                                            >
                                                {({ processing, errors }) => (
                                                    <>
                                                        <input
                                                            type="hidden"
                                                            name="decision"
                                                            value={decision}
                                                        />
                                                        <FormField
                                                            label={t(
                                                                'returns.admin.refund_note',
                                                            )}
                                                            error={errors.note}
                                                            required={
                                                                decision ===
                                                                'reject'
                                                            }
                                                        >
                                                            {(field) => (
                                                                <textarea
                                                                    {...field}
                                                                    name="note"
                                                                    rows={2}
                                                                    maxLength={
                                                                        1000
                                                                    }
                                                                    required={
                                                                        decision ===
                                                                        'reject'
                                                                    }
                                                                    className={
                                                                        controlClass
                                                                    }
                                                                />
                                                            )}
                                                        </FormField>
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            variant={
                                                                decision ===
                                                                'reject'
                                                                    ? 'destructive'
                                                                    : 'default'
                                                            }
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            {processing && (
                                                                <Spinner />
                                                            )}
                                                            {t(
                                                                decision ===
                                                                    'reject'
                                                                    ? 'returns.admin.reject_refund'
                                                                    : 'returns.admin.approve_refund',
                                                            )}
                                                        </Button>
                                                        <InputError
                                                            message={
                                                                errors.refund
                                                            }
                                                        />
                                                    </>
                                                )}
                                            </Form>
                                        ),
                                    )}

                                {can.send_refund &&
                                    orderReturn.refund.request && (
                                        <Form
                                            {...PaymentRefundController.store.form(
                                                orderReturn.refund.request.id,
                                            )}
                                            options={{ preserveScroll: true }}
                                            className="space-y-2"
                                        >
                                            {({ processing, errors }) => (
                                                <>
                                                    <p className="text-muted-foreground text-xs">
                                                        {t(
                                                            'returns.admin.send_refund_help',
                                                        )}
                                                    </p>
                                                    {orderReturn.refund.request
                                                        ?.failure_reason && (
                                                        <p className="text-danger text-xs break-words">
                                                            {
                                                                orderReturn
                                                                    .refund
                                                                    .request
                                                                    .failure_reason
                                                            }
                                                        </p>
                                                    )}
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        disabled={processing}
                                                    >
                                                        {processing && (
                                                            <Spinner />
                                                        )}
                                                        {t(
                                                            'returns.admin.send_refund',
                                                        )}
                                                    </Button>
                                                    <InputError
                                                        message={errors.refund}
                                                    />
                                                </>
                                            )}
                                        </Form>
                                    )}

                                {can.settle_manually && (
                                    <Form
                                        {...OrderReturnController.settle.form(
                                            orderReturn.id,
                                        )}
                                        options={{ preserveScroll: true }}
                                        className="space-y-2"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <p className="text-muted-foreground text-xs">
                                                    {t(
                                                        'returns.admin.settle_help',
                                                    )}
                                                </p>
                                                <FormField
                                                    label={t(
                                                        'returns.admin.how',
                                                    )}
                                                    error={errors.how}
                                                    required
                                                >
                                                    {(field) => (
                                                        <textarea
                                                            {...field}
                                                            name="how"
                                                            rows={2}
                                                            minLength={10}
                                                            maxLength={1000}
                                                            required
                                                            className={
                                                                controlClass
                                                            }
                                                        />
                                                    )}
                                                </FormField>
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    {processing && <Spinner />}
                                                    {t('returns.admin.settle')}
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                )}
                            </div>
                        </SectionCard>
                    </div>
                </div>
            </PageContainer>
        </>
    );
}

function Row({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex items-baseline justify-between gap-3">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0 text-right break-words">{children}</dd>
        </div>
    );
}

AdminReturn.layout = {
    breadcrumbs: [
        {
            title: 'nav.returns',
            href: index(),
        },
    ],
};
