import { Form, Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Info, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import FileField from '@/components/forms/file-field';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { StatusTone } from '@/lib/status';

type Requirement = {
    id: string;
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
    round: number;
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
}: {
    submission: Submission;
    requirements: Requirement[];
    feedback: string | null;
}) {
    const outstanding = requirements.filter(
        (requirement) =>
            requirement.is_required &&
            !requirement.uploaded &&
            !requirement.value_preview,
    );

    return (
        <>
            <Head title="KYC verification" />

            <div className="mx-auto w-full max-w-2xl space-y-6 px-4 py-10">
                <header className="space-y-2">
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Identity verification
                        </h1>
                        <StatusPill
                            tone={submission.status_tone}
                            label={submission.status_label}
                        />
                    </div>
                    <p className="text-muted-foreground text-sm">
                        We need these before your account can be activated.
                        {submission.round > 1 &&
                            ` This is attempt ${submission.round}.`}
                    </p>
                </header>

                {/* Why the documents are safe to send matters more here than
                    anywhere else in the product. */}
                <p className="text-muted-foreground bg-muted flex items-start gap-2 rounded-lg p-3 text-xs">
                    <ShieldCheck
                        className="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <span>
                        Your documents are encrypted and stored privately. Only
                        authorised reviewers can open them, and every access is
                        recorded.
                    </span>
                </p>

                {feedback && (
                    <section
                        className="bg-warning-subtle border-warning/30 space-y-1 rounded-lg border p-4"
                        role="alert"
                    >
                        <p className="text-sm font-semibold">
                            Our reviewer asked for a change
                        </p>
                        <p className="text-ink-2 text-sm">{feedback}</p>
                    </section>
                )}

                {!submission.is_editable ? (
                    <section className="bg-card border-border space-y-3 rounded-lg border p-5 text-center shadow-sm">
                        <CheckCircle2
                            className="text-success mx-auto size-6"
                            aria-hidden="true"
                        />
                        <p className="text-sm font-medium">
                            Your documents are with our team.
                        </p>
                        <p className="text-muted-foreground text-sm">
                            Nothing more to do for now — we will let you know as
                            soon as they have been checked.
                        </p>
                        <Button asChild variant="outline" size="sm">
                            <Link href="/onboarding">Back to status</Link>
                        </Button>
                    </section>
                ) : (
                    <>
                        <div className="space-y-3">
                            {requirements.map((requirement) => (
                                <RequirementCard
                                    key={requirement.key}
                                    requirement={requirement}
                                />
                            ))}
                        </div>

                        <Form
                            action="/kyc/submit"
                            method="post"
                            className="bg-card border-border flex flex-wrap items-center gap-4 rounded-lg border p-5 shadow-sm"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="min-w-0 flex-1 space-y-1">
                                        <p className="text-sm font-medium">
                                            {outstanding.length === 0
                                                ? 'Everything we need is here.'
                                                : `${outstanding.length} still needed`}
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
                                        processingLabel="Submitting…"
                                        disabled={outstanding.length > 0}
                                    >
                                        Submit for review
                                    </SubmitButton>
                                </>
                            )}
                        </Form>
                    </>
                )}
            </div>
        </>
    );
}

function RequirementCard({ requirement }: { requirement: Requirement }) {
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
        <section className="bg-card border-border space-y-3 rounded-lg border p-4 shadow-sm">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 space-y-0.5">
                    <h2 className="flex items-center gap-1.5 text-sm font-semibold">
                        {requirement.name}
                        {!requirement.is_required && (
                            <span className="text-muted-foreground text-xs font-normal">
                                (optional)
                            </span>
                        )}
                    </h2>
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

                {isSupplied && <StatusPill tone="success" label="Provided" />}
            </div>

            {requirement.requires_file && (
                <FileField
                    label={
                        requirement.uploaded
                            ? `Replace ${requirement.uploaded.name}`
                            : 'Upload'
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
                                label={requirement.value_label ?? 'Number'}
                                required={requirement.is_required}
                                error={errors.value}
                                hint={
                                    requirement.value_preview
                                        ? `Saved: ${requirement.value_preview}`
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
                                Save
                            </SubmitButton>
                        </>
                    )}
                </Form>
            )}
        </section>
    );
}
