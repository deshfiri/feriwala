import { Form, Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    Building2,
    Download,
    Eye,
    FileText,
    Lock,
} from 'lucide-react';
import { useState } from 'react';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import { show as accountDossier } from '@/routes/admin/accounts';
import { decide, index } from '@/routes/admin/kyc';
import { download, show as documentShow } from '@/routes/kyc/documents';
import type {
    KycApplicant,
    KycDecisionOutcome,
    KycReviewDocument,
    KycReviewField,
    KycReviewHistoryEntry,
    KycSubmissionSummary,
} from '@/types';

/**
 * One KYC submission, and the decision on it (§7.3).
 *
 * Two rules from §7.5 shape the page. Documents are never linked directly —
 * every link goes through the controller that authorises and records the
 * access. And a reviewer without `kyc.view_kyc_documents` sees that documents
 * exist, with their values masked, but no way to open them: knowing an
 * application is waiting is a different level of intrusion from reading the
 * passport inside it.
 */
export default function AdminKycShow({
    submission,
    applicant,
    documents,
    fields,
    history,
}: {
    submission: KycSubmissionSummary;
    applicant: KycApplicant;
    documents: KycReviewDocument[];
    fields: KycReviewField[];
    history: KycReviewHistoryEntry[];
}) {
    const { t, locale } = useTranslation();
    const [outcome, setOutcome] = useState<KycDecisionOutcome>('approve');

    const formatDateTime = (value: string | null) =>
        value === null ? '—' : new Date(value).toLocaleString(locale);

    const formatSize = (bytes: number) =>
        bytes < 1024
            ? `${bytes} B`
            : `${Math.round(bytes / 1024).toLocaleString(locale)} KB`;

    const needsReason = outcome === 'reject';
    const needsFeedback = outcome === 'reject' || outcome === 'resubmit';

    return (
        <>
            <Head title={t('kyc.queue.title')} />

            <PageContainer>
                <PageHeader
                    title={t('kyc.detail.title', { round: submission.round })}
                    description={applicant.name ?? undefined}
                    actions={
                        <>
                            <StatusPill
                                tone={submission.status_tone}
                                label={submission.status_label}
                            />
                            {/*
                             * The whole business behind this round (P1-79) —
                             * its other rounds, its package, its payments. Only
                             * rendered when the reader may actually open it.
                             */}
                            {applicant.account_id && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={accountDossier(
                                            applicant.account_id,
                                        )}
                                    >
                                        <Building2
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        {t('kyc.detail.view_account')}
                                    </Link>
                                </Button>
                            )}

                            <Button variant="outline" size="sm" asChild>
                                <Link href={index()}>
                                    <ArrowLeft
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t('kyc.detail.back_to_queue')}
                                </Link>
                            </Button>
                        </>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="space-y-6 lg:col-span-2">
                        <SectionCard title={t('kyc.detail.applicant')}>
                            <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                                {[
                                    ['Name', applicant.name],
                                    ['Email', applicant.email],
                                    ['Mobile', applicant.mobile],
                                    ['Country', applicant.country],
                                    ['Account status', applicant.status_label],
                                    [
                                        t('kyc.detail.submitted_at'),
                                        formatDateTime(submission.submitted_at),
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
                        </SectionCard>

                        <SectionCard title={t('kyc.detail.documents')}>
                            {documents.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    {t('kyc.detail.no_documents')}
                                </p>
                            ) : (
                                <ul className="divide-border divide-y">
                                    {documents.map((document) => (
                                        <li
                                            key={document.id}
                                            className="flex flex-wrap items-center gap-3 py-2.5 first:pt-0 last:pb-0"
                                        >
                                            <FileText
                                                className="text-muted-foreground size-4 shrink-0"
                                                aria-hidden="true"
                                            />

                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-medium">
                                                    {document.type ??
                                                        document.original_name}
                                                </p>
                                                <p className="text-muted-foreground truncate text-xs">
                                                    {document.original_name} ·{' '}
                                                    {formatSize(
                                                        document.size_bytes,
                                                    )}
                                                </p>
                                            </div>

                                            {document.can_open ? (
                                                <div className="flex items-center gap-1">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        {/* A plain anchor, not
                                                            an Inertia visit —
                                                            the response is a
                                                            file, not a page. */}
                                                        <a
                                                            href={documentShow.url(
                                                                document.id,
                                                            )}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                        >
                                                            <Eye
                                                                className="size-4"
                                                                aria-hidden="true"
                                                            />
                                                            {t(
                                                                'kyc.detail.view',
                                                            )}
                                                        </a>
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <a
                                                            href={download.url(
                                                                document.id,
                                                            )}
                                                        >
                                                            <Download
                                                                className="size-4"
                                                                aria-hidden="true"
                                                            />
                                                            {t(
                                                                'kyc.detail.download',
                                                            )}
                                                        </a>
                                                    </Button>
                                                </div>
                                            ) : (
                                                <span className="text-muted-foreground flex items-center gap-1.5 text-xs">
                                                    <Lock
                                                        className="size-3.5"
                                                        aria-hidden="true"
                                                    />
                                                    {t(
                                                        'kyc.detail.documents_hidden',
                                                    )}
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </SectionCard>

                        <SectionCard title={t('kyc.detail.fields')}>
                            {fields.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    {t('kyc.detail.no_fields')}
                                </p>
                            ) : (
                                <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                                    {fields.map((field, position) => (
                                        <div key={`${field.type}-${position}`}>
                                            <dt className="text-muted-foreground text-xs">
                                                {field.type ?? '—'}
                                            </dt>
                                            <dd className="font-medium tabular-nums">
                                                {field.value}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            )}
                        </SectionCard>

                        <SectionCard title={t('kyc.detail.history')}>
                            {history.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    {t('kyc.detail.no_history')}
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
                                                    {entry.reviewer ?? '—'} ·{' '}
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

                                            {entry.user_visible_feedback && (
                                                <p className="text-muted-foreground text-sm">
                                                    {
                                                        entry.user_visible_feedback
                                                    }
                                                </p>
                                            )}

                                            {entry.internal_note && (
                                                <p className="text-muted-foreground text-xs">
                                                    <span className="font-medium">
                                                        {t(
                                                            'kyc.detail.internal_note_label',
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
                        </SectionCard>
                    </div>

                    <aside className="lg:col-span-1">
                        <SectionCard
                            title={t('kyc.decision.title')}
                            description={t('kyc.decision.description')}
                        >
                            {!submission.can_review ? (
                                <p className="text-muted-foreground text-sm">
                                    {t('kyc.decision.not_permitted')}
                                </p>
                            ) : !submission.awaits_review ? (
                                <p className="text-muted-foreground text-sm">
                                    {t('kyc.decision.already_decided')}
                                </p>
                            ) : (
                                <Form
                                    {...decide.form(submission.id)}
                                    options={{ preserveScroll: true }}
                                    className="space-y-4"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            {/* Radios, not three buttons: the
                                                chosen outcome decides which
                                                fields are required, so it has
                                                to be visible before submitting
                                                rather than implied by which
                                                button was pressed. */}
                                            <fieldset className="grid gap-1.5">
                                                <legend className="mb-1.5 text-sm font-medium">
                                                    {t('kyc.decision.title')}
                                                </legend>

                                                {(
                                                    [
                                                        'approve',
                                                        'resubmit',
                                                        'reject',
                                                    ] as KycDecisionOutcome[]
                                                ).map((option) => (
                                                    <label
                                                        key={option}
                                                        className="border-border hover:bg-muted/50 has-checked:border-brand-border has-checked:bg-brand-subtle flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm"
                                                    >
                                                        <input
                                                            type="radio"
                                                            name="outcome"
                                                            value={option}
                                                            className="accent-brand"
                                                            checked={
                                                                outcome ===
                                                                option
                                                            }
                                                            onChange={() =>
                                                                setOutcome(
                                                                    option,
                                                                )
                                                            }
                                                        />
                                                        {t(
                                                            `kyc.decision.${option}`,
                                                        )}
                                                    </label>
                                                ))}
                                            </fieldset>

                                            {needsFeedback && (
                                                <FormField
                                                    label={t(
                                                        'kyc.decision.feedback',
                                                    )}
                                                    description={t(
                                                        'kyc.decision.feedback_help',
                                                    )}
                                                    error={errors.feedback}
                                                    required
                                                >
                                                    {(field) => (
                                                        <textarea
                                                            {...field}
                                                            name="feedback"
                                                            rows={3}
                                                            maxLength={1000}
                                                            className="border-input focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:border-destructive w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                                                        />
                                                    )}
                                                </FormField>
                                            )}

                                            {needsReason && (
                                                <FormField
                                                    label={t(
                                                        'kyc.decision.reason',
                                                    )}
                                                    description={t(
                                                        'kyc.decision.reason_help',
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
                                            )}

                                            <FormField
                                                label={t(
                                                    'kyc.decision.internal_note',
                                                )}
                                                error={errors.internal_note}
                                            >
                                                {(field) => (
                                                    <textarea
                                                        {...field}
                                                        name="internal_note"
                                                        rows={2}
                                                        maxLength={2000}
                                                        className="border-input focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:border-destructive w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                                                    />
                                                )}
                                            </FormField>

                                            <SubmitButton
                                                processing={processing}
                                                className="w-full"
                                            >
                                                {t('kyc.decision.submit')}
                                            </SubmitButton>
                                        </>
                                    )}
                                </Form>
                            )}
                        </SectionCard>
                    </aside>
                </div>
            </PageContainer>
        </>
    );
}

AdminKycShow.layout = {
    breadcrumbs: [
        {
            title: 'KYC review',
            href: index(),
        },
    ],
};
