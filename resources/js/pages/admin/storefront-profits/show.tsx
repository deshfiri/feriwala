import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';
import ConfirmButton from '@/components/confirm/confirm-button';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import type { StatusTone } from '@/lib/status';
import { show as showOrder } from '@/routes/admin/orders';
import {
    correct,
    holdRelease,
    index,
    retry,
    show,
} from '@/routes/admin/storefront-profits';
import type { StorefrontProfitRow } from './index';

type Lot = {
    id: string;
    purchased_at: string;
    quantity: number;
    unit_cost: Money | null;
    source: string | null;
};

type Line = {
    id: string;
    name: string;
    sku: string;
    basis: string;
    quantity: number;
    returned: number;
    selling: Money;
    cost: Money | null;
    profit: Money | null;
    unproven_reason: string | null;
    lots: Lot[];
};

type Props = {
    profit: StorefrontProfitRow & {
        collection_channel: string | null;
        collected_amount: Money | null;
        released_at: string | null;
        legacy_credited: Money;
        attempts: number;
        failure_message: string | null;
        lines: Line[];
        reversals: {
            id: string;
            amount: Money;
            status: string;
            cause: string;
            posted_at: string | null;
            created_at: string;
        }[];
        corrections: {
            id: string;
            cost: Money;
            reason: string;
            reference: string;
            actor: string;
            created_at: string;
        }[];
    };
    can_retry: boolean;
    /** A reason a person must clear before anything is credited. */
    review_hold: string | null;
    can_clear_hold: boolean;
    can_correct: boolean;
    password_confirmed: boolean;
    preview: {
        line: string;
        cost?: Money;
        line_profit?: Money;
        error: string | null;
    } | null;
};

/**
 * One Storefront profit: the two facts, each line's cost and how it was proven,
 * what reached the Wallet and what was taken back. Staff may retry a technical
 * failure; finance may decide a cost that cannot be proven, from the Partner's
 * own purchase lots, with the figures worked out on the server.
 */
export default function StorefrontProfitShow({
    profit,
    can_retry,
    review_hold,
    can_clear_hold,
    can_correct,
    password_confirmed,
    preview,
}: Props) {
    const { t, locale } = useTranslation();
    const when = (value: string | null) =>
        value === null ? '—' : new Date(value).toLocaleString(locale);
    const retryForm = useForm({});
    const holdForm = useForm({ reason: '' });

    const facts: [string, ReactNode][] = [
        [t('storefront_profit.fields.delivered_at'), when(profit.delivered_at)],
        [t('storefront_profit.fields.collected_at'), when(profit.collected_at)],
        [
            t('storefront_profit.fields.channel'),
            profit.collection_channel
                ? t(`storefront_profit.channels.${profit.collection_channel}`)
                : '—',
        ],
        [t('storefront_profit.fields.release_at'), when(profit.release_at)],
        [
            t('storefront_profit.fields.selling'),
            profit.selling ? <MoneyAmount amount={profit.selling} /> : '—',
        ],
        [
            t('storefront_profit.fields.cost'),
            profit.cost ? <MoneyAmount amount={profit.cost} /> : '—',
        ],
        [
            t('storefront_profit.fields.profit'),
            profit.profit ? <MoneyAmount amount={profit.profit} /> : '—',
        ],
        [
            t('storefront_profit.fields.released'),
            <MoneyAmount
                key="released"
                amount={profit.released}
                direction="credit"
            />,
        ],
        [
            t('storefront_profit.fields.reversed'),
            <MoneyAmount
                key="reversed"
                amount={profit.reversed}
                direction="debit"
            />,
        ],
    ];

    return (
        <>
            <Head
                title={`${t('storefront_profit.title')} · ${profit.order.reference}`}
            />

            <PageContainer>
                <PageHeader
                    title={profit.order.reference}
                    description={`${profit.partner.name}${profit.partner.number ? ` · ${profit.partner.number}` : ''}`}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button asChild variant="outline" size="sm">
                                <Link href={index()}>
                                    <ArrowLeft aria-hidden="true" />
                                    {t('storefront_profit.admin_title')}
                                </Link>
                            </Button>
                            <Button asChild variant="outline" size="sm">
                                <Link href={showOrder(profit.order.id)}>
                                    {t('storefront_profit.fields.order')}
                                </Link>
                            </Button>
                        </div>
                    }
                />

                <div className="grid gap-4 lg:grid-cols-[2fr_1fr]">
                    <div className="space-y-4">
                        <SectionCard title={t('storefront_profit.title')}>
                            <div className="mb-3 flex flex-wrap items-center gap-2">
                                <StatusPill
                                    tone={profit.status_tone as StatusTone}
                                    label={profit.status_label}
                                />
                                {profit.status_reason && (
                                    <span className="text-muted-foreground text-sm">
                                        {t(
                                            `storefront_profit.reasons.${profit.status_reason}`,
                                        )}
                                    </span>
                                )}
                            </div>
                            <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                                {facts.map(([label, value]) => (
                                    <div
                                        key={label}
                                        className="flex justify-between gap-3"
                                    >
                                        <dt className="text-muted-foreground">
                                            {label}
                                        </dt>
                                        <dd className="text-end tabular-nums">
                                            {value}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </SectionCard>

                        {profit.lines.map((line) => (
                            <SectionCard key={line.id} title={line.name}>
                                <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                                    <div className="flex justify-between gap-3">
                                        <dt className="text-muted-foreground">
                                            {t(
                                                'storefront_profit.fields.basis',
                                            )}
                                        </dt>
                                        <dd>
                                            {t(
                                                `storefront_profit.basis.${line.basis}`,
                                            )}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between gap-3">
                                        <dt className="text-muted-foreground">
                                            {t(
                                                'storefront_profit.fields.quantity',
                                            )}
                                        </dt>
                                        <dd className="tabular-nums">
                                            {line.quantity}
                                            {line.returned > 0 &&
                                                ` (${t('storefront_profit.fields.returned')}: ${line.returned})`}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between gap-3">
                                        <dt className="text-muted-foreground">
                                            {t(
                                                'storefront_profit.fields.selling',
                                            )}
                                        </dt>
                                        <dd>
                                            <MoneyAmount
                                                amount={line.selling}
                                            />
                                        </dd>
                                    </div>
                                    <div className="flex justify-between gap-3">
                                        <dt className="text-muted-foreground">
                                            {t('storefront_profit.fields.cost')}
                                        </dt>
                                        <dd>
                                            {line.cost ? (
                                                <MoneyAmount
                                                    amount={line.cost}
                                                />
                                            ) : (
                                                '—'
                                            )}
                                        </dd>
                                    </div>
                                </dl>

                                {line.unproven_reason && (
                                    <p className="text-muted-foreground mt-3 text-sm">
                                        {t(
                                            `storefront_profit.unproven.${line.unproven_reason}`,
                                        )}
                                    </p>
                                )}

                                {can_correct && line.unproven_reason && (
                                    <CorrectionForm
                                        profitId={profit.id}
                                        line={line}
                                        preview={
                                            preview?.line === line.id
                                                ? preview
                                                : null
                                        }
                                        passwordConfirmed={password_confirmed}
                                    />
                                )}
                            </SectionCard>
                        ))}
                    </div>

                    <div className="space-y-4">
                        {review_hold && (
                            <SectionCard
                                title={t('storefront_profit.hold.title')}
                            >
                                <p className="text-muted-foreground mb-3 text-sm">
                                    {t(
                                        `storefront_profit.reasons.${review_hold}`,
                                    )}
                                </p>
                                {can_clear_hold && (
                                    <form
                                        className="space-y-2"
                                        onSubmit={(event) => {
                                            event.preventDefault();
                                            holdForm.post(
                                                holdRelease.url(profit.id),
                                                {
                                                    preserveScroll: true,
                                                },
                                            );
                                        }}
                                    >
                                        <Label htmlFor="hold-reason">
                                            {t('storefront_profit.hold.reason')}
                                        </Label>
                                        <Input
                                            id="hold-reason"
                                            value={holdForm.data.reason}
                                            onChange={(event) =>
                                                holdForm.setData(
                                                    'reason',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={holdForm.errors.reason}
                                        />
                                        <ConfirmButton
                                            type="submit"
                                            size="sm"
                                            disabled={holdForm.processing}
                                            confirm={{
                                                title: t(
                                                    'storefront_profit.hold.confirm_title',
                                                ),
                                                description: t(
                                                    'storefront_profit.hold.confirm_body',
                                                ),
                                            }}
                                        >
                                            {t('storefront_profit.hold.clear')}
                                        </ConfirmButton>
                                    </form>
                                )}
                            </SectionCard>
                        )}

                        {can_retry && (
                            <SectionCard
                                title={t('storefront_profit.actions.retry')}
                            >
                                {profit.failure_message && (
                                    <p className="text-muted-foreground mb-3 text-sm break-words">
                                        {profit.failure_message}
                                    </p>
                                )}
                                <form
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        retryForm.post(retry.url(profit.id), {
                                            preserveScroll: true,
                                        });
                                    }}
                                >
                                    <ConfirmButton
                                        type="submit"
                                        size="sm"
                                        disabled={retryForm.processing}
                                        confirm={{
                                            title: t(
                                                'storefront_profit.confirm.retry_title',
                                            ),
                                            description: t(
                                                'storefront_profit.confirm.retry_body',
                                            ),
                                            destructive: false,
                                        }}
                                    >
                                        {t('storefront_profit.actions.retry')}
                                    </ConfirmButton>
                                </form>
                            </SectionCard>
                        )}

                        {profit.reversals.length > 0 && (
                            <SectionCard
                                title={t('storefront_profit.fields.reversed')}
                            >
                                <ul className="divide-y text-sm">
                                    {profit.reversals.map((reversal) => (
                                        <li
                                            key={reversal.id}
                                            className="flex justify-between gap-2 py-2"
                                        >
                                            <span>
                                                {reversal.status} ·{' '}
                                                {when(
                                                    reversal.posted_at ??
                                                        reversal.created_at,
                                                )}
                                            </span>
                                            <MoneyAmount
                                                amount={reversal.amount}
                                                direction="debit"
                                            />
                                        </li>
                                    ))}
                                </ul>
                            </SectionCard>
                        )}

                        {profit.corrections.length > 0 && (
                            <SectionCard
                                title={t('storefront_profit.correction.title')}
                            >
                                <ul className="divide-y text-sm">
                                    {profit.corrections.map((correction) => (
                                        <li
                                            key={correction.id}
                                            className="space-y-1 py-2"
                                        >
                                            <div className="flex justify-between gap-2">
                                                <span className="font-medium">
                                                    {correction.actor}
                                                </span>
                                                <MoneyAmount
                                                    amount={correction.cost}
                                                />
                                            </div>
                                            <p className="text-muted-foreground">
                                                {correction.reason}
                                            </p>
                                            <p className="text-muted-foreground text-xs">
                                                {correction.reference}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            </SectionCard>
                        )}
                    </div>
                </div>
            </PageContainer>
        </>
    );
}

function CorrectionForm({
    profitId,
    line,
    preview,
    passwordConfirmed,
}: {
    profitId: string;
    line: Line;
    preview: Props['preview'];
    passwordConfirmed: boolean;
}) {
    const { t, locale } = useTranslation();
    const [units, setUnits] = useState<Record<string, string>>({});
    const form = useForm<{
        line: string;
        allocations: Record<string, number>;
        reason: string;
        reference: string;
    }>({ line: line.id, allocations: {}, reason: '', reference: '' });

    const allocations = () =>
        Object.fromEntries(
            Object.entries(units)
                .map(([lot, value]) => [lot, Number.parseInt(value, 10)])
                .filter(
                    ([, value]) => Number.isInteger(value) && Number(value) > 0,
                ),
        ) as Record<string, number>;

    const askPreview = () => {
        router.get(
            show.url(profitId),
            { preview_line: line.id, preview_allocations: allocations() },
            { only: ['preview'], preserveState: true, preserveScroll: true },
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, allocations: allocations() }));
        form.post(correct.url(profitId), { preserveScroll: true });
    };

    if (line.lots.length === 0) {
        return (
            <p className="text-muted-foreground mt-3 text-sm">
                {t('storefront_profit.unproven.no_purchase_record')}
            </p>
        );
    }

    return (
        <form onSubmit={submit} className="mt-4 space-y-3 border-t pt-4">
            <h3 className="text-sm font-semibold">
                {t('storefront_profit.correction.title')}
            </h3>

            <div className="space-y-2">
                {line.lots.map((lot) => (
                    <div
                        key={lot.id}
                        className="grid grid-cols-[1fr_6rem] items-center gap-3 text-sm"
                    >
                        <Label
                            htmlFor={`lot-${lot.id}`}
                            className="font-normal"
                        >
                            {lot.source ?? lot.id} ·{' '}
                            {new Date(lot.purchased_at).toLocaleDateString(
                                locale,
                            )}{' '}
                            · {lot.quantity} ×{' '}
                            {lot.unit_cost ? lot.unit_cost.formatted : '—'}
                        </Label>
                        <Input
                            id={`lot-${lot.id}`}
                            inputMode="numeric"
                            aria-label={t(
                                'storefront_profit.correction.quantity',
                            )}
                            value={units[lot.id] ?? ''}
                            onChange={(event) =>
                                setUnits((current) => ({
                                    ...current,
                                    [lot.id]: event.target.value.replace(
                                        /[^0-9]/g,
                                        '',
                                    ),
                                }))
                            }
                        />
                    </div>
                ))}
            </div>

            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={askPreview}
            >
                {t('storefront_profit.correction.preview')}
            </Button>

            {preview && (
                <div className="bg-muted rounded-lg p-3 text-sm">
                    {preview.error ? (
                        <p className="text-destructive">{preview.error}</p>
                    ) : (
                        <dl className="space-y-1">
                            <div className="flex justify-between">
                                <dt>
                                    {t(
                                        'storefront_profit.correction.preview_cost',
                                    )}
                                </dt>
                                <dd>
                                    {preview.cost && (
                                        <MoneyAmount amount={preview.cost} />
                                    )}
                                </dd>
                            </div>
                            <div className="flex justify-between">
                                <dt>
                                    {t(
                                        'storefront_profit.correction.preview_profit',
                                    )}
                                </dt>
                                <dd>
                                    {preview.line_profit && (
                                        <MoneyAmount
                                            amount={preview.line_profit}
                                        />
                                    )}
                                </dd>
                            </div>
                        </dl>
                    )}
                </div>
            )}

            <div className="space-y-1">
                <Label htmlFor={`reason-${line.id}`}>
                    {t('storefront_profit.correction.reason')}
                </Label>
                <Input
                    id={`reason-${line.id}`}
                    value={form.data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                />
                <InputError message={form.errors.reason} />
            </div>

            <div className="space-y-1">
                <Label htmlFor={`reference-${line.id}`}>
                    {t('storefront_profit.correction.reference')}
                </Label>
                <Input
                    id={`reference-${line.id}`}
                    value={form.data.reference}
                    onChange={(event) =>
                        form.setData('reference', event.target.value)
                    }
                />
                <InputError message={form.errors.reference} />
            </div>

            <InputError message={form.errors.allocations} />

            <ConfirmButton
                type="submit"
                size="sm"
                disabled={form.processing || !preview || preview.error !== null}
                confirm={{
                    title: t('storefront_profit.confirm.correct_title'),
                    description: t('storefront_profit.confirm.correct_body'),
                }}
            >
                {t('storefront_profit.actions.correct')}
            </ConfirmButton>

            {!passwordConfirmed && (
                <p className="text-muted-foreground text-xs">
                    {t('common.confirm.description')}
                </p>
            )}
        </form>
    );
}
