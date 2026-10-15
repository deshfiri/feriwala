import { Form, Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Info, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import FileField from '@/components/forms/file-field';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import BatchKycForm from '@/components/kyc/batch-form';
import PageContainer from '@/components/page-container';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import type { StatusTone } from '@/lib/status';
import { history as kycHistory } from '@/routes/kyc';
import { show as documentShow } from '@/routes/kyc/documents';
import * as evidence from '@/routes/kyc/evidence';
import type { KycBatch } from '@/types';

type Requirement = {
    /**
     * The batch it sits in — the group the form draws a heading for. Null for a
     * requirement with none, which is simply listed without one.
     */
    batch: { key: string; name: string; description: string | null } | null;
    // No id: §34.2 keeps database ids out of the payload, and the form
    // addresses a requirement by its key — which is what it posts back.
    key: string;
    name: string;
    instructions: string | null;
    is_required: boolean;
    requires_file: boolean;
    requires_value: boolean;
    value_label: string | null;
    accepted_mime_types: string[];
    max_size_kb: number;
    uploaded: { name: string; size_bytes: number } | null;
    value_preview: string | null;
};

type Submission = {
    id: string;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    is_editable: boolean;
    deadline_at: string | null;
};

/**
 * The applicant's KYC form (§7).
 *
 * Each requirement saves on its own. A KYC form can carry six documents, and
 * making someone re-pick all of them because the last was too large is the
 * fastest way to lose them at the most fragile point of signing up.
 */
export default function KycForm({
    submission,
    requirements,
    feedback,
    batch = null,
}: {
    submission: Submission;
    requirements: Requirement[];
    feedback: string | null;
    /** Set for a round on a batch version (D30); null keeps the flat form. */
    batch?: KycBatch | null;
}) {
    const { t } = useTranslation();

    const outstanding = requirements.filter(
        (requirement) =>
            requirement.is_required &&
            !requirement.uploaded &&
            !requirement.value_preview,
    );

    return (
        <>
            <Head title={t('kyc.submission.page_title')} />

            <PageContainer width="narrow">
                <header className="space-y-2">
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="text-xl font-semibold tracking-tight">
                            {t('kyc.submission.title')}
                        </h1>
                        <StatusPill
                            tone={submission.status_tone}
                            label={submission.status_label}
                        />
                    </div>
                    <p className="text-muted-foreground text-sm">
                        {t('kyc.submission.help')}
                    </p>

                    {/*
                     * Reachable from the form rather than only from a menu:
                     * "what did they ask me last time" is a question that
                     * arises here, while the applicant is looking at a round
                     * they have been sent back from.
                     */}
                    <Button variant="link" size="sm" className="px-0" asChild>
                        <Link href={kycHistory()}>
                            {t('kyc.submission.view_history')}
                        </Link>
                    </Button>
                </header>

                {/* Why the documents are safe to send matters more here than
                    anywhere else in the product. */}
                <p className="text-muted-foreground bg-muted flex items-start gap-2 rounded-lg p-3 text-xs">
                    <ShieldCheck
                        className="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <span>{t('kyc.submission.privacy')}</span>
                </p>

                {feedback && (
                    <section
                        className="bg-warning-subtle border-warning/30 space-y-1 rounded-lg border p-4"
                        role="alert"
                    >
                        <p className="text-sm font-semibold">
                            {t('kyc.submission.feedback_title')}
                        </p>
                        <p className="text-ink-2 text-sm">{feedback}</p>
                    </section>
                )}

                {!submission.is_editable ? (
                    <section className="bg-card border-border space-y-3 rounded-xl border p-5 text-center shadow-sm">
                        <CheckCircle2
                            className="text-success mx-auto size-6"
                            aria-hidden="true"
                        />
                        <p className="text-sm font-medium">
                            {t('kyc.submission.with_team_title')}
                        </p>
                        <p className="text-muted-foreground text-sm">
                            {t('kyc.submission.with_team_description')}
                        </p>
                        <Button asChild variant="outline" size="sm">
                            <Link href="/onboarding">
                                {t('kyc.submission.back_to_status')}
                            </Link>
                        </Button>
                    </section>
                ) : batch ? (
                    <BatchKycForm
                        batch={batch}
                        endpoints={{
                            file: evidence.file.url(),
                            value: evidence.value.url(),
                            remove: evidence.remove.url(),
                            choose: evidence.choose.url(),
                            deselect: evidence.deselect.url(),
                            submit: '/kyc/submit',
                            preview: (id) => documentShow.url(id),
                        }}
                    />
                ) : (
                    <>
                        {/* Every batch that applies, on one form: the applicant
                            completes them all and submits once. */}
                        <div className="space-y-6">
                            {groupByBatch(requirements).map((group) => (
                                <BatchSection key={group.key} group={group} />
                            ))}
                        </div>

                        <Form
                            action="/kyc/submit"
                            method="post"
                            className="bg-card border-border flex flex-wrap items-center gap-4 rounded-xl border p-5 shadow-sm"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="min-w-0 flex-1 space-y-1">
                                        <p className="text-sm font-medium">
                                            {outstanding.length === 0
                                                ? t(
                                                      'kyc.submission.all_provided',
                                                  )
                                                : t(
                                                      'kyc.submission.still_needed',
                                                      {
                                                          count: outstanding.length,
                                                      },
                                                  )}
                                        </p>
                                        {errors.submission && (
                                            <p className="text-danger text-xs font-medium">
                                                {errors.submission}
                                            </p>
                                        )}
                                        {outstanding.length > 0 && (
                                            <p className="text-muted-foreground text-xs">
                                                {outstanding
                                                    .map((r) => r.name)
                                                    .join(', ')}
                                            </p>
                                        )}
                                    </div>

                                    <SubmitButton
                                        processing={processing}
                                        processingLabel={t(
                                            'kyc.submission.submitting',
                                        )}
                                        disabled={outstanding.length > 0}
                                    >
                                        {t('kyc.submission.submit')}
                                    </SubmitButton>
                                </>
                            )}
                        </Form>
                    </>
                )}
            </PageContainer>
        </>
    );
}

type BatchGroup = {
    key: string;
    name: string | null;
    description: string | null;
    requirements: Requirement[];
};

/** Requirements grouped by batch, keeping the order they arrive in. */
function groupByBatch(requirements: Requirement[]): BatchGroup[] {
    const groups = new Map<string, BatchGroup>();

    for (const requirement of requirements) {
        const key = requirement.batch?.key ?? '__none';
        const group = groups.get(key) ?? {
            key,
            name: requirement.batch?.name ?? null,
            description: requirement.batch?.description ?? null,
            requirements: [],
        };

        group.requirements.push(requirement);
        groups.set(key, group);
    }

    return [...groups.values()];
}

/**
 * One batch: its heading, how far through it the applicant is, and its
 * requirements. A requirement with no batch has no heading to sit under, so it
 * is listed bare.
 */
function BatchSection({ group }: { group: BatchGroup }) {
    const { t } = useTranslation();

    const provided = group.requirements.filter(
        (requirement) =>
            requirement.uploaded !== null || requirement.value_preview !== null,
    ).length;

    return (
        <section className="space-y-3" aria-label={group.name ?? undefined}>
            {group.name !== null && (
                <header className="space-y-0.5">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 className="text-base font-semibold">
                            {group.name}
                        </h2>
                        <span className="text-muted-foreground text-xs tabular-nums">
                            {t('kyc.submission.batch_progress', {
                                provided,
                                total: group.requirements.length,
                            })}
                        </span>
                    </div>
                    {group.description && (
                        <p className="text-muted-foreground text-sm">
                            {group.description}
                        </p>
                    )}
                </header>
            )}

            <div className="space-y-3">
                {group.requirements.map((requirement) => (
                    <RequirementCard
                        key={requirement.key}
                        requirement={requirement}
                        grouped={group.name !== null}
                    />
                ))}
            </div>
        </section>
    );
}

function RequirementCard({
    requirement,
    grouped,
}: {
    requirement: Requirement;
    /** Under a batch heading, so one level lower. */
    grouped: boolean;
}) {
    const { t } = useTranslation();
    const [progress, setProgress] = useState<number | null>(null);
    const isSupplied =
        requirement.uploaded !== null || requirement.value_preview !== null;

    const upload = (file: File | null) => {
        if (!file) return;

        router.post(
            '/kyc/documents',
            { document_type: requirement.key, file },
            {
                forceFormData: true,
                preserveScroll: true,
                // percentage is absent until the upload actually starts.
                onProgress: (event) =>
                    setProgress(
                        event?.percentage === undefined
                            ? null
                            : Math.round(event.percentage),
                    ),
                onFinish: () => setProgress(null),
            },
        );
    };

    return (
        <section className="bg-card border-border space-y-3 rounded-xl border p-4 shadow-sm">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 space-y-0.5">
                    <Heading
                        level={grouped ? 3 : 2}
                        className="flex items-center gap-1.5 text-sm font-semibold"
                    >
                        {requirement.name}
                        {!requirement.is_required && (
                            <span className="text-muted-foreground text-xs font-normal">
                                {t('kyc.submission.optional')}
                            </span>
                        )}
                    </Heading>
                    {requirement.instructions && (
                        <p className="text-muted-foreground flex items-start gap-1.5 text-xs">
                            <Info
                                className="mt-0.5 size-3 shrink-0"
                                aria-hidden="true"
                            />
                            {requirement.instructions}
                        </p>
                    )}
                </div>

                {isSupplied && (
                    <StatusPill
                        tone="success"
                        label={t('kyc.submission.provided')}
                    />
                )}
            </div>

            {requirement.requires_file && (
                <FileField
                    label={
                        requirement.uploaded
                            ? t('kyc.submission.replace', {
                                  name: requirement.uploaded.name,
                              })
                            : t('kyc.submission.upload')
                    }
                    name="file"
                    accept={requirement.accepted_mime_types.join(',')}
                    maxSizeMb={Math.round(requirement.max_size_kb / 1024)}
                    progress={progress}
                    onChange={upload}
                />
            )}

            {requirement.requires_value && (
                <Form
                    action="/kyc/documents"
                    method="post"
                    options={{ preserveScroll: true }}
                    className="flex items-end gap-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="document_type"
                                value={requirement.key}
                            />
                            <FormField
                                label={
                                    requirement.value_label ??
                                    t('kyc.submission.number')
                                }
                                required={requirement.is_required}
                                error={errors.value}
                                hint={
                                    requirement.value_preview
                                        ? t('kyc.submission.saved', {
                                              value: requirement.value_preview,
                                          })
                                        : undefined
                                }
                                className="flex-1"
                            >
                                {(field) => <Input {...field} name="value" />}
                            </FormField>
                            <SubmitButton
                                processing={processing}
                                variant="outline"
                                className="mb-0.5"
                            >
                                {t('common.actions.save')}
                            </SubmitButton>
                        </>
                    )}
                </Form>
            )}
        </section>
    );
}

function Heading({
    level,
    className,
    children,
}: {
    level: 2 | 3;
    className?: string;
    children: React.ReactNode;
}) {
    return level === 3 ? (
        <h3 className={className}>{children}</h3>
    ) : (
        <h2 className={className}>{children}</h2>
    );
}
