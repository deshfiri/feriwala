import { Form, Head, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, Check, X } from 'lucide-react';
import { useState } from 'react';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import PageHeader from '@/components/page-header';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import {
    approve,
    index,
    requestResubmission,
    suspend,
} from '@/routes/admin/activations';
import type {
    AccountHistoryEntry,
    ActivationAccount,
    ActivationCondition,
    ActivationOutcome,
} from '@/types';

const textareaClasses =
    'border-input focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:border-destructive w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]';

/**
 * One account at the activation gate (§5.1, §44).
 *
 * The conditions are shown with their evidence rather than as three ticks: an
 * administrator taking responsibility for letting someone onto the platform
 * should see what they are relying on.
 *
 * There is no "reject" here because §5.3 does not have one. From approval
 * pending an account can only be activated, sent back for corrections, or
 * suspended — and the last two mean genuinely different things, so they are
 * offered as different decisions rather than one button called Reject.
 */
export default function AdminActivationsShow({
    account,
    conditions,
    history,
}: {
    account: ActivationAccount;
    conditions: ActivationCondition[];
    history: AccountHistoryEntry[];
}) {
    const { t, locale } = useTranslation();

    // Only the decisions this reviewer actually holds. Offering a control that
    // will be refused wastes their time and tells them nothing about why.
    const available: ActivationOutcome[] = [
        ...(account.can_approve ? (['approve'] as const) : []),
        ...(account.can_request_resubmission ? (['corrections'] as const) : []),
        ...(account.can_suspend ? (['suspend'] as const) : []),
    ];

    const [outcome, setOutcome] = useState<ActivationOutcome>(
        available[0] ?? 'approve',
    );

    const formatDateTime = (value: string | null) =>
        value === null ? '—' : new Date(value).toLocaleString(locale);

    return (
        <>
            <Head title={t('activation.detail.title')} />

            <div className="space-y-6 p-4">
                <PageHeader
                    title={t('activation.detail.title')}
                    description={account.name}
                    actions={
                        <>
                            <StatusPill
                                tone={account.status_tone}
                                label={account.status_label}
                            />
                            <Button variant="outline" size="sm" asChild>
                                <Link href={index()}>
                                    <ArrowLeft
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t('activation.detail.back_to_queue')}
                                </Link>
                            </Button>
                        </>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="space-y-6 lg:col-span-2">
                        <section className="bg-card border-border rounded-lg border p-5 shadow-sm">
                            <h2 className="mb-3 text-sm font-semibold">
                                {t('activation.detail.account')}
                            </h2>

                            <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                                {[
                                    ['Business', account.name],
                                    ['Owner', account.owner],
                                    ['Email', account.email],
                                    ['Mobile', account.mobile],
                                    ['Country', account.country],
                                    [
                                        t('activation.detail.registered_at'),
                                        formatDateTime(account.registered_at),
                                    ],
                                ].map(([label, value]) => (
                                    <div key={label}>
                                        <dt className="text-muted-foreground text-xs">
                                            {label}
                                        </dt>
                                        <dd className="font-medium break-words">
                                            {value ?? '—'}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </section>

                        <section className="bg-card border-border rounded-lg border p-5 shadow-sm">
                            <h2 className="text-sm font-semibold">
                                {t('activation.detail.conditions')}
                            </h2>
                            <p className="text-muted-foreground mt-0.5 mb-3 text-xs">
                                {t('activation.detail.conditions_help')}
                            </p>

                            <ul className="divide-border divide-y">
                                {conditions.map((condition) => (
                                    <li
                                        key={condition.key}
                                        className="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0"
                                    >
                                        {/* Icon and wording both carry the
                                            state — never colour alone (§33.9). */}
                                        {condition.met ? (
                                            <Check
                                                className="text-success size-4 shrink-0"
                                                aria-hidden="true"
                                            />
                                        ) : (
                                            <X
                                                className="text-danger size-4 shrink-0"
                                                aria-hidden="true"
                                            />
                                        )}

                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-medium">
                                                {condition.label}
                                            </p>
                                            {condition.detail && (
                                                <p className="text-muted-foreground truncate text-xs">
                                                    {condition.detail}
                                                </p>
                                            )}
                                        </div>

                                        <span className="text-muted-foreground text-xs">
                                            {condition.met
                                                ? t('activation.detail.met')
                                                : t('activation.detail.unmet')}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </section>

                        <section className="bg-card border-border rounded-lg border p-5 shadow-sm">
                            <h2 className="mb-3 text-sm font-semibold">
                                {t('activation.detail.history')}
                            </h2>

                            {history.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    {t('activation.detail.no_history')}
                                </p>
                            ) : (
                                <ol className="divide-border divide-y">
                                    {history.map((entry, position) => (
                                        <li
                                            key={`${entry.created_at}-${position}`}
                                            className="space-y-1 py-3 first:pt-0 last:pb-0"
                                        >
                                            <div className="flex flex-wrap items-baseline justify-between gap-2">
                                                <span className="text-sm font-medium">
                                                    {entry.to_status}
                                                </span>
                                                <span className="text-muted-foreground text-xs">
                                                    {/* An automatic change has
                                                        no person behind it, and
                                                        saying so is clearer
                                                        than an em dash. */}
                                                    {entry.changed_by ??
                                                        'System'}{' '}
                                                    ·{' '}
                                                    {formatDateTime(
                                                        entry.created_at,
                                                    )}
                                                </span>
                                            </div>

                                            {entry.reason && (
                                                <p className="text-sm">
                                                    {entry.reason}
                                                </p>
                                            )}

                                            {entry.user_visible_note && (
                                                <p className="text-muted-foreground text-sm">
                                                    {entry.user_visible_note}
                                                </p>
                                            )}

                                            {entry.internal_note && (
                                                <p className="text-muted-foreground text-xs">
                                                    <span className="font-medium">
                                                        {t(
                                                            'activation.detail.internal_note_label',
                                                        )}
                                                        :
                                                    </span>{' '}
                                                    {entry.internal_note}
                                                </p>
                                            )}
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </section>
                    </div>

                    <aside className="lg:col-span-1">
                        <section className="bg-card border-border rounded-lg border p-5 shadow-sm">
                            <h2 className="text-sm font-semibold">
                                {t('activation.decision.title')}
                            </h2>
                            <p className="text-muted-foreground mt-0.5 mb-4 text-xs">
                                {t('activation.decision.description')}
                            </p>

                            {available.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    {t('activation.decision.not_permitted')}
                                </p>
                            ) : (
                                <div className="space-y-4">
                                    {/* Listing what is missing beats a disabled
                                        button that will not say why. */}
                                    {!account.is_ready && (
                                        <div
                                            className="bg-warning-subtle border-warning/30 rounded-md border p-3"
                                            role="alert"
                                        >
                                            <p className="flex items-center gap-1.5 text-sm font-medium">
                                                <AlertTriangle
                                                    className="text-warning size-4 shrink-0"
                                                    aria-hidden="true"
                                                />
                                                {t(
                                                    'activation.decision.not_ready',
                                                )}
                                            </p>
                                            <ul className="text-ink-2 mt-1.5 list-disc space-y-0.5 pl-5 text-xs">
                                                {account.unmet.map((reason) => (
                                                    <li key={reason}>
                                                        {reason}
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    )}

                                    <fieldset className="grid gap-1.5">
                                        <legend className="mb-1.5 text-sm font-medium">
                                            {t('activation.decision.title')}
                                        </legend>

                                        {available.map((option) => (
                                            <label
                                                key={option}
                                                className="border-border hover:bg-muted/50 has-checked:border-brand-border has-checked:bg-brand-subtle flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm has-disabled:cursor-not-allowed has-disabled:opacity-50"
                                            >
                                                <input
                                                    type="radio"
                                                    name="activation_outcome"
                                                    className="accent-brand"
                                                    checked={outcome === option}
                                                    // Approving an account that
                                                    // does not qualify is the
                                                    // one thing the action will
                                                    // refuse outright.
                                                    disabled={
                                                        option === 'approve' &&
                                                        !account.is_ready
                                                    }
                                                    onChange={() =>
                                                        setOutcome(option)
                                                    }
                                                />
                                                {t(
                                                    `activation.decision.${option}`,
                                                )}
                                            </label>
                                        ))}
                                    </fieldset>

                                    {outcome === 'approve' ? (
                                        <Form
                                            {...approve.form(account.id)}
                                            options={{ preserveScroll: true }}
                                            className="space-y-4"
                                        >
                                            {({ processing, errors }) => (
                                                <>
                                                    <FormField
                                                        label={t(
                                                            'activation.decision.note',
                                                        )}
                                                        error={errors.note}
                                                    >
                                                        {(field) => (
                                                            <textarea
                                                                {...field}
                                                                name="note"
                                                                rows={2}
                                                                maxLength={1000}
                                                                className={
                                                                    textareaClasses
                                                                }
                                                            />
                                                        )}
                                                    </FormField>

                                                    {/* Conditions can come
                                                        undone between this page
                                                        rendering and the submit
                                                        — the action re-checks
                                                        and reports back here. */}
                                                    {errors.activation && (
                                                        <p
                                                            role="alert"
                                                            className="text-danger text-xs font-medium"
                                                        >
                                                            {errors.activation}
                                                        </p>
                                                    )}

                                                    <SubmitButton
                                                        processing={processing}
                                                        disabled={
                                                            !account.is_ready
                                                        }
                                                        className="w-full"
                                                    >
                                                        {t(
                                                            'activation.decision.approve',
                                                        )}
                                                    </SubmitButton>
                                                </>
                                            )}
                                        </Form>
                                    ) : (
                                        <Form
                                            // Its own endpoint per outcome. A
                                            // shared one with an `outcome` field
                                            // would let a correction request and
                                            // a suspension share a permission.
                                            {...(outcome === 'suspend'
                                                ? suspend.form(account.id)
                                                : requestResubmission.form(
                                                      account.id,
                                                  ))}
                                            options={{ preserveScroll: true }}
                                            className="space-y-4"
                                        >
                                            {({ processing, errors }) => (
                                                <>
                                                    {outcome === 'suspend' && (
                                                        <p className="text-muted-foreground text-xs">
                                                            {t(
                                                                'activation.decision.suspend_warning',
                                                            )}
                                                        </p>
                                                    )}

                                                    <FormField
                                                        label={t(
                                                            'activation.decision.reason',
                                                        )}
                                                        description={t(
                                                            'activation.decision.reason_help',
                                                        )}
                                                        error={errors.reason}
                                                        required
                                                    >
                                                        {(field) => (
                                                            <Input
                                                                {...field}
                                                                name="reason"
                                                                maxLength={1000}
                                                            />
                                                        )}
                                                    </FormField>

                                                    {outcome ===
                                                        'corrections' && (
                                                        <FormField
                                                            label={t(
                                                                'activation.decision.feedback',
                                                            )}
                                                            description={t(
                                                                'activation.decision.feedback_help',
                                                            )}
                                                            error={
                                                                errors.feedback
                                                            }
                                                            required
                                                        >
                                                            {(field) => (
                                                                <textarea
                                                                    {...field}
                                                                    name="feedback"
                                                                    rows={3}
                                                                    maxLength={
                                                                        1000
                                                                    }
                                                                    className={
                                                                        textareaClasses
                                                                    }
                                                                />
                                                            )}
                                                        </FormField>
                                                    )}

                                                    <FormField
                                                        label={t(
                                                            'activation.decision.internal_note',
                                                        )}
                                                        error={
                                                            errors.internal_note
                                                        }
                                                    >
                                                        {(field) => (
                                                            <textarea
                                                                {...field}
                                                                name="internal_note"
                                                                rows={2}
                                                                maxLength={2000}
                                                                className={
                                                                    textareaClasses
                                                                }
                                                            />
                                                        )}
                                                    </FormField>

                                                    <SubmitButton
                                                        processing={processing}
                                                        variant={
                                                            outcome ===
                                                            'suspend'
                                                                ? 'destructive'
                                                                : 'default'
                                                        }
                                                        className="w-full"
                                                    >
                                                        {t(
                                                            'activation.decision.submit',
                                                        )}
                                                    </SubmitButton>
                                                </>
                                            )}
                                        </Form>
                                    )}
                                </div>
                            )}
                        </section>
                    </aside>
                </div>
            </div>
        </>
    );
}

AdminActivationsShow.layout = {
    breadcrumbs: [
        {
            title: 'Activation approvals',
            href: index(),
        },
    ],
};
