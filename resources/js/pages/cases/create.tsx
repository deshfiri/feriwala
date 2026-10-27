import { Form, Head, Link } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import EvidenceDraftUploader from '@/components/cases/evidence-draft-uploader';
import FormField from '@/components/forms/form-field';
import NativeSelect from '@/components/forms/native-select';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { isZero } from '@/lib/money';
import { cn } from '@/lib/utils';
import { xsrfToken } from '@/lib/xsrf';
import {
    destroy as discardEvidence,
    store as uploadEvidence,
} from '@/routes/cases/evidence';
import {
    estimate as estimateRoute,
    index as casesIndex,
    store as storeRoute,
} from '@/routes/cases';
import type { EstimatePreview, Eligibility, EvidenceFile } from '@/types/cases';

type Props = {
    order: { id: string; reference: string; url: string };
    eligibility: Eligibility;
    reasons: { value: string; label: string }[];
    replacements: { sku: string; label: string }[];
    estimate: EstimatePreview;
    drafts: EvidenceFile[];
    evidence_limits: {
        max_bytes: number;
        max_per_case: number;
        mime_types: string[];
    };
    can_request: boolean;
};

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Ask for a return or an exchange on one order (D35).
 *
 * Only what can still be asked for is offered: the lines and quantities the
 * server says may come back, within the window, on an order that was delivered.
 * The estimate beside the form is **the server's** — worked out from the order's
 * own frozen delivery charge and the delivery-charge service, recalculated each
 * time the request changes — and is only an estimate: who finally bears the cost,
 * and so what is charged, is decided by staff after the goods are inspected. The
 * Partner never says who is responsible, and never sends a figure.
 */
export default function CreateCase({
    order,
    eligibility,
    reasons,
    replacements,
    estimate: initialEstimate,
    drafts,
    evidence_limits,
    can_request,
}: Props) {
    const { t, locale } = useTranslation();
    const [type, setType] = useState<'return' | 'exchange'>('return');
    const [lines, setLines] = useState<Record<string, string>>({});
    const [swaps, setSwaps] = useState<Record<string, string>>({});
    const [estimate, setEstimate] = useState<EstimatePreview>(initialEstimate);
    const [estimating, setEstimating] = useState(false);
    const [estimateError, setEstimateError] = useState<string | null>(null);
    // One key per form opened: a double submit is the same request, once.
    const [key] = useState(() => crypto.randomUUID());

    const chosen = useMemo(
        () =>
            Object.fromEntries(
                Object.entries(lines).filter(
                    ([, quantity]) => Number(quantity) > 0,
                ),
            ),
        [lines],
    );
    const chosenKey = JSON.stringify(chosen);
    const swapsKey = JSON.stringify(swaps);
    const hasLines = Object.keys(chosen).length > 0;

    // The server works the estimate out again whenever the request changes.
    useEffect(() => {
        if (!can_request) {
            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(async () => {
            setEstimating(true);
            setEstimateError(null);

            try {
                const response = await fetch(estimateRoute(order.id).url, {
                    method: 'POST',
                    signal: controller.signal,
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': xsrfToken(),
                    },
                    body: JSON.stringify({
                        type,
                        lines: JSON.parse(chosenKey) as Record<string, string>,
                        replacements: JSON.parse(swapsKey) as Record<
                            string,
                            string
                        >,
                    }),
                });

                if (response.ok) {
                    setEstimate((await response.json()) as EstimatePreview);
                } else {
                    const body = (await response.json().catch(() => ({}))) as {
                        message?: string;
                    };
                    setEstimateError(
                        body.message ?? t('cases.create.estimate_failed'),
                    );
                }
            } catch (error) {
                if ((error as Error).name !== 'AbortError') {
                    setEstimateError(t('cases.create.estimate_failed'));
                }
            } finally {
                setEstimating(false);
            }
        }, 300);

        return () => {
            controller.abort();
            clearTimeout(timer);
        };
    }, [type, chosenKey, swapsKey, order.id, can_request, t]);

    if (!can_request) {
        return (
            <>
                <Head title={t('cases.create.title')} />
                <PageContainer>
                    <PageHeader
                        title={t('cases.create.title')}
                        description={order.reference}
                        back={{
                            href: order.url,
                            label: t('cases.create.back_to_order'),
                        }}
                    />
                    <SectionCard>
                        <div className="flex gap-3 text-sm" role="alert">
                            <AlertTriangle
                                aria-hidden="true"
                                className="text-warning mt-0.5 size-4 shrink-0"
                            />
                            <div className="space-y-1">
                                <p className="font-medium">
                                    {t('cases.create.cannot_request')}
                                </p>
                                <p className="text-muted-foreground">
                                    {eligibility.eligible
                                        ? t('cases.create.cannot_manage')
                                        : t(
                                              `cases.create.refusals.${eligibility.refusal ?? 'order_not_returnable'}`,
                                          )}
                                </p>
                            </div>
                        </div>
                    </SectionCard>
                </PageContainer>
            </>
        );
    }

    return (
        <>
            <Head title={t('cases.create.title')} />

            <PageContainer>
                <PageHeader
                    title={t('cases.create.title')}
                    description={t('cases.create.description', {
                        order: order.reference,
                    })}
                    back={{
                        href: order.url,
                        label: t('cases.create.back_to_order'),
                    }}
                />

                <div className="grid gap-4 lg:grid-cols-[2fr_1fr]">
                    <Form
                        {...storeRoute.form(order.id)}
                        options={{ preserveScroll: true }}
                        className="space-y-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="idempotency_key"
                                    value={key}
                                />
                                <input type="hidden" name="type" value={type} />

                                <SectionCard title={t('cases.create.what')}>
                                    <div className="space-y-4">
                                        <fieldset className="grid gap-2 sm:grid-cols-2">
                                            <legend className="sr-only">
                                                {t('cases.create.type')}
                                            </legend>
                                            {(
                                                ['return', 'exchange'] as const
                                            ).map((option) => (
                                                <label
                                                    key={option}
                                                    className={cn(
                                                        'flex cursor-pointer gap-3 rounded-lg border p-3 text-sm',
                                                        type === option
                                                            ? 'border-primary bg-surface-subtle'
                                                            : 'border-border',
                                                    )}
                                                >
                                                    <input
                                                        type="radio"
                                                        name="type_choice"
                                                        value={option}
                                                        checked={
                                                            type === option
                                                        }
                                                        onChange={() =>
                                                            setType(option)
                                                        }
                                                        className="mt-1"
                                                    />
                                                    <span>
                                                        <span className="block font-medium">
                                                            {t(
                                                                `cases.kinds.${option}`,
                                                            )}
                                                        </span>
                                                        <span className="text-muted-foreground block text-xs">
                                                            {t(
                                                                `cases.create.type_help.${option}`,
                                                            )}
                                                        </span>
                                                    </span>
                                                </label>
                                            ))}
                                        </fieldset>

                                        {!eligibility.eligible ? (
                                            <p className="text-sm" role="alert">
                                                {t(
                                                    `cases.create.refusals.${eligibility.refusal ?? 'order_not_returnable'}`,
                                                )}
                                            </p>
                                        ) : (
                                            <fieldset className="space-y-3">
                                                <legend className="text-sm font-medium">
                                                    {t('cases.create.items')}
                                                </legend>
                                                {eligibility.window_closes_at && (
                                                    <p className="text-muted-foreground text-xs">
                                                        {t(
                                                            'cases.create.window',
                                                            {
                                                                time: new Date(
                                                                    eligibility.window_closes_at,
                                                                ).toLocaleString(
                                                                    locale,
                                                                ),
                                                            },
                                                        )}
                                                    </p>
                                                )}
                                                {eligibility.lines.map(
                                                    (line) => (
                                                        <div
                                                            key={line.id}
                                                            className="border-border space-y-2 rounded-lg border p-3"
                                                        >
                                                            <FormField
                                                                label={`${line.name}${line.variant ? ` · ${line.variant}` : ''} (${line.sku})`}
                                                                hint={t(
                                                                    'cases.create.up_to',
                                                                    {
                                                                        count: String(
                                                                            line.returnable,
                                                                        ),
                                                                    },
                                                                )}
                                                                error={
                                                                    errors[
                                                                        `lines.${line.sku}`
                                                                    ]
                                                                }
                                                            >
                                                                {(field) => (
                                                                    <Input
                                                                        {...field}
                                                                        type="number"
                                                                        inputMode="numeric"
                                                                        min={0}
                                                                        max={
                                                                            line.returnable
                                                                        }
                                                                        name={`lines[${line.sku}]`}
                                                                        value={
                                                                            lines[
                                                                                line
                                                                                    .sku
                                                                            ] ??
                                                                            '0'
                                                                        }
                                                                        onChange={(
                                                                            event,
                                                                        ) =>
                                                                            setLines(
                                                                                (
                                                                                    all,
                                                                                ) => ({
                                                                                    ...all,
                                                                                    [line.sku]:
                                                                                        event
                                                                                            .target
                                                                                            .value,
                                                                                }),
                                                                            )
                                                                        }
                                                                        disabled={
                                                                            line.returnable ===
                                                                            0
                                                                        }
                                                                        className="w-28"
                                                                    />
                                                                )}
                                                            </FormField>

                                                            {type ===
                                                                'exchange' &&
                                                                Number(
                                                                    lines[
                                                                        line.sku
                                                                    ] ?? 0,
                                                                ) > 0 && (
                                                                    <FormField
                                                                        label={t(
                                                                            'cases.create.replacement',
                                                                        )}
                                                                        hint={t(
                                                                            'cases.create.replacement_help',
                                                                        )}
                                                                    >
                                                                        {(
                                                                            field,
                                                                        ) => (
                                                                            <NativeSelect
                                                                                {...field}
                                                                                name={`replacements[${line.sku}]`}
                                                                                value={
                                                                                    swaps[
                                                                                        line
                                                                                            .sku
                                                                                    ] ??
                                                                                    ''
                                                                                }
                                                                                onChange={(
                                                                                    event,
                                                                                ) =>
                                                                                    setSwaps(
                                                                                        (
                                                                                            all,
                                                                                        ) => ({
                                                                                            ...all,
                                                                                            [line.sku]:
                                                                                                event
                                                                                                    .target
                                                                                                    .value,
                                                                                        }),
                                                                                    )
                                                                                }
                                                                            >
                                                                                <option value="">
                                                                                    {t(
                                                                                        'cases.create.same_product',
                                                                                    )}
                                                                                </option>
                                                                                {replacements
                                                                                    .filter(
                                                                                        (
                                                                                            option,
                                                                                        ) =>
                                                                                            option.sku !==
                                                                                            line.sku,
                                                                                    )
                                                                                    .map(
                                                                                        (
                                                                                            option,
                                                                                        ) => (
                                                                                            <option
                                                                                                key={
                                                                                                    option.sku
                                                                                                }
                                                                                                value={
                                                                                                    option.sku
                                                                                                }
                                                                                            >
                                                                                                {
                                                                                                    option.label
                                                                                                }
                                                                                            </option>
                                                                                        ),
                                                                                    )}
                                                                            </NativeSelect>
                                                                        )}
                                                                    </FormField>
                                                                )}
                                                        </div>
                                                    ),
                                                )}
                                                <InputError
                                                    message={errors.lines}
                                                />
                                            </fieldset>
                                        )}
                                    </div>
                                </SectionCard>

                                <SectionCard title={t('cases.create.why')}>
                                    <div className="grid gap-4">
                                        <FormField
                                            label={t('cases.create.reason')}
                                            error={errors.reason}
                                            required
                                        >
                                            {(field) => (
                                                <NativeSelect
                                                    {...field}
                                                    name="reason"
                                                    required
                                                    defaultValue=""
                                                >
                                                    <option value="" disabled>
                                                        {t(
                                                            'cases.create.choose_reason',
                                                        )}
                                                    </option>
                                                    {reasons.map((reason) => (
                                                        <option
                                                            key={reason.value}
                                                            value={reason.value}
                                                        >
                                                            {reason.label}
                                                        </option>
                                                    ))}
                                                </NativeSelect>
                                            )}
                                        </FormField>
                                        <FormField
                                            label={t(
                                                'cases.create.description_label',
                                            )}
                                            error={errors.description}
                                        >
                                            {(field) => (
                                                <textarea
                                                    {...field}
                                                    name="description"
                                                    rows={4}
                                                    maxLength={2000}
                                                    className={controlClass}
                                                />
                                            )}
                                        </FormField>
                                    </div>
                                </SectionCard>

                                <SectionCard
                                    title={t('cases.create.evidence')}
                                    description={t(
                                        'cases.create.evidence_help',
                                    )}
                                >
                                    <EvidenceDraftUploader
                                        orderId={order.id}
                                        uploadUrl={uploadEvidence().url}
                                        deleteUrl={(id) =>
                                            discardEvidence(id).url
                                        }
                                        limits={evidence_limits}
                                        initial={drafts}
                                        error={errors.evidence}
                                    />
                                </SectionCard>

                                <SectionCard title={t('cases.create.confirm')}>
                                    <div className="space-y-3 text-sm">
                                        <p>
                                            {t(
                                                `cases.create.confirm_text.${type}`,
                                            )}
                                        </p>
                                        <label className="flex items-start gap-2">
                                            <input
                                                type="checkbox"
                                                name="confirmed"
                                                value="1"
                                                required
                                                className="mt-1"
                                            />
                                            <span>
                                                {t(
                                                    'cases.create.confirm_label',
                                                )}
                                            </span>
                                        </label>
                                        <InputError
                                            message={errors.confirmed}
                                        />
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                type="submit"
                                                disabled={
                                                    processing ||
                                                    !hasLines ||
                                                    !eligibility.eligible
                                                }
                                            >
                                                {processing && <Spinner />}
                                                {t(
                                                    `cases.create.submit.${type}`,
                                                )}
                                            </Button>
                                            <Button asChild variant="ghost">
                                                <Link href={casesIndex()}>
                                                    {t('common.actions.cancel')}
                                                </Link>
                                            </Button>
                                        </div>
                                    </div>
                                </SectionCard>
                            </>
                        )}
                    </Form>

                    <aside
                        aria-label={t('cases.create.estimate')}
                        className="lg:sticky lg:top-4 lg:self-start"
                    >
                        <SectionCard
                            title={t('cases.create.estimate')}
                            description={t('cases.create.estimate_help')}
                        >
                            <div
                                aria-busy={estimating}
                                className="space-y-3 text-sm"
                            >
                                <Row
                                    label={t(
                                        'cases.financials.rows.original_delivery',
                                    )}
                                    note={t(
                                        'cases.financials.original_delivery_note',
                                    )}
                                >
                                    <MoneyAmount
                                        amount={estimate.original_delivery}
                                    />
                                </Row>
                                <Row
                                    label={t(
                                        'cases.financials.rows.return_charge',
                                    )}
                                >
                                    <MoneyAmount
                                        amount={estimate.return_charge}
                                    />
                                </Row>
                                {type === 'exchange' && (
                                    <Row
                                        label={t(
                                            'cases.financials.rows.exchange_delivery',
                                        )}
                                    >
                                        {estimate.exchange_delivery ? (
                                            <MoneyAmount
                                                amount={
                                                    estimate.exchange_delivery
                                                }
                                            />
                                        ) : (
                                            <span className="text-muted-foreground text-xs">
                                                {t(
                                                    'cases.create.choose_items_first',
                                                )}
                                            </span>
                                        )}
                                    </Row>
                                )}
                                {type === 'exchange' && estimate.exchange && (
                                    <>
                                        {!isZero(
                                            estimate.exchange.additional_due,
                                        ) && (
                                            <Row
                                                label={t(
                                                    'cases.financials.rows.exchange_additional_due',
                                                )}
                                                note={t(
                                                    'cases.financials.collected_from_customer',
                                                )}
                                            >
                                                <MoneyAmount
                                                    amount={
                                                        estimate.exchange
                                                            .additional_due
                                                    }
                                                />
                                            </Row>
                                        )}
                                        {!isZero(
                                            estimate.exchange.refund_due,
                                        ) && (
                                            <Row
                                                label={t(
                                                    'cases.financials.rows.exchange_refund_due',
                                                )}
                                                note={t(
                                                    'cases.financials.owed_to_customer',
                                                )}
                                            >
                                                <MoneyAmount
                                                    amount={
                                                        estimate.exchange
                                                            .refund_due
                                                    }
                                                />
                                            </Row>
                                        )}
                                    </>
                                )}
                                <div className="bg-surface-subtle flex items-center justify-between gap-3 rounded-lg border p-3">
                                    <span className="font-medium">
                                        {t('cases.create.estimated_total')}
                                    </span>
                                    {estimating ? (
                                        <Spinner className="size-4" />
                                    ) : (
                                        <MoneyAmount
                                            amount={estimate.estimated_total}
                                        />
                                    )}
                                </div>
                                {estimateError && (
                                    <InputError message={estimateError} />
                                )}
                                <p className="text-muted-foreground text-xs">
                                    {t(`cases.create.estimate_notes.${type}`)}
                                </p>
                                {type === 'return' && (
                                    <p className="text-muted-foreground text-xs">
                                        {t(
                                            'cases.create.refund_after_inspection',
                                        )}
                                    </p>
                                )}
                            </div>
                        </SectionCard>
                    </aside>
                </div>
            </PageContainer>
        </>
    );
}

CreateCase.layout = {
    breadcrumbs: [{ title: 'nav.returns_exchanges', href: casesIndex() }],
};

function Row({
    label,
    note,
    children,
}: {
    label: string;
    note?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
                <div>{label}</div>
                {note && (
                    <div className="text-muted-foreground text-xs">{note}</div>
                )}
            </div>
            <div>{children}</div>
        </div>
    );
}
