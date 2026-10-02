import { Form, Head } from '@inertiajs/react';
import { FileText, Lock } from 'lucide-react';
import FileField from '@/components/forms/file-field';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import type { StatusTone } from '@/lib/status';
import { submit } from '@/routes/supplier/kyc';
import { store } from '@/routes/supplier/kyc/documents';

type Round = {
    id: string;
    round: number;
    status: string;
    status_label: string;
    is_editable: boolean;
    decision_note: string | null;
    submitted_at: string | null;
    documents: {
        id: string;
        type: string;
        label: string;
        original_name: string;
        size_bytes: number;
        mime_type: string;
    }[];
};

type Requirement = {
    key: string;
    name: string;
    instructions: string | null;
    is_required: boolean;
    requires_file: boolean;
    requires_value: boolean;
    value_label: string | null;
    accepted_mime_types: string[];
    max_size_kb: number;
    uploaded: boolean;
    value_preview: string | null;
};

const TONES: Record<string, StatusTone> = {
    approved: 'success',
    rejected: 'danger',
    correction_required: 'warning',
    under_review: 'info',
    submitted: 'info',
    draft: 'neutral',
};

export default function SupplierKyc({
    round,
    history,
    requirements,
}: {
    supplier_status: string;
    round: Round;
    history: {
        round: number;
        status: string;
        status_label: string;
        reviewed_at: string | null;
        decision_note: string | null;
    }[];
    requirements: Requirement[];
}) {
    const { t, locale } = useTranslation();

    const formatSize = (bytes: number) =>
        bytes < 1024
            ? `${bytes} B`
            : `${Math.round(bytes / 1024).toLocaleString(locale)} KB`;

    return (
        <>
            <Head title={t('supplier.kyc.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('supplier.kyc.title')}
                    description={t('supplier.kyc.description')}
                    actions={
                        <>
                            <span className="text-muted-foreground text-sm">
                                {t('supplier.kyc.round', {
                                    round: round.round,
                                })}
                            </span>
                            <StatusPill
                                tone={TONES[round.status] ?? 'neutral'}
                                label={round.status_label}
                            />
                        </>
                    }
                />

                {round.decision_note && (
                    <SectionCard title={t('supplier.kyc.feedback')}>
                        <p className="text-sm">{round.decision_note}</p>
                    </SectionCard>
                )}

                <SectionCard title={t('supplier.kyc.documents')}>
                    {round.documents.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {t('supplier.kyc.no_documents')}
                        </p>
                    ) : (
                        <ul className="divide-border divide-y">
                            {round.documents.map((document) => (
                                <li
                                    key={document.id}
                                    className="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0"
                                >
                                    <FileText
                                        className="text-muted-foreground size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium">
                                            {document.label}
                                        </p>
                                        <p className="text-muted-foreground truncate text-xs">
                                            {document.original_name} Â·{' '}
                                            {formatSize(document.size_bytes)}
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>

                {round.is_editable ? (
                    <>
                        <SectionCard
                            title={t('supplier.kyc.upload')}
                            description={t('supplier.kyc.accepted')}
                        >
                            <ul className="divide-border divide-y">
                                {requirements.map((requirement) => (
                                    <li
                                        key={requirement.key}
                                        className="py-4 first:pt-0 last:pb-0"
                                    >
                                        <RequirementForm
                                            requirement={requirement}
                                        />
                                    </li>
                                ))}
                            </ul>
                        </SectionCard>

                        <SectionCard
                            title={t('supplier.kyc.submit')}
                            description={t('supplier.kyc.submit_help')}
                        >
                            <Form {...submit.form()}>
                                {({ processing, errors }) => (
                                    <div className="space-y-3">
                                        {errors.submission && (
                                            <p
                                                role="alert"
                                                className="text-danger text-sm font-medium"
                                            >
                                                {errors.submission}
                                            </p>
                                        )}
                                        <SubmitButton
                                            processing={processing}
                                            disabled={
                                                round.documents.length === 0 &&
                                                !requirements.some(
                                                    (requirement) =>
                                                        requirement.value_preview,
                                                )
                                            }
                                        >
                                            {t('supplier.kyc.submit')}
                                        </SubmitButton>
                                    </div>
                                )}
                            </Form>
                        </SectionCard>
                    </>
                ) : (
                    <p className="text-muted-foreground flex items-center gap-2 text-sm">
                        <Lock className="size-4" aria-hidden="true" />
                        {t('supplier.kyc.locked')}
                    </p>
                )}

                {history.length > 1 && (
                    <SectionCard title={t('supplier.kyc.history')}>
                        <ul className="divide-border divide-y text-sm">
                            {history
                                .filter((item) => item.round !== round.round)
                                .map((item) => (
                                    <li
                                        key={item.round}
                                        className="flex flex-wrap justify-between gap-2 py-2"
                                    >
                                        <span>
                                            {t('supplier.kyc.round', {
                                                round: item.round,
                                            })}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {item.status_label}
                                        </span>
                                        {item.decision_note && (
                                            <p className="w-full">
                                                {item.decision_note}
                                            </p>
                                        )}
                                    </li>
                                ))}
                        </ul>
                    </SectionCard>
                )}
            </PageContainer>
        </>
    );
}

/**
 * One item the administrator asks Suppliers for: a file, a typed value, or
 * both, saved on its own so a failed upload never costs the others.
 */
function RequirementForm({ requirement }: { requirement: Requirement }) {
    const { t } = useTranslation();

    return (
        <Form
            {...store.form()}
            resetOnSuccess
            options={{ preserveScroll: true }}
            className="space-y-3"
        >
            {({ processing, errors, progress }) => (
                <>
                    <input
                        type="hidden"
                        name="document_type"
                        value={requirement.key}
                    />

                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-sm font-medium">
                            {requirement.name}
                        </p>
                        <span className="text-muted-foreground flex items-center gap-2 text-xs">
                            {requirement.is_required
                                ? t('supplier.kyc.required')
                                : t('supplier.kyc.optional')}
                            {(requirement.uploaded ||
                                requirement.value_preview) && (
                                <StatusPill
                                    tone="success"
                                    label={t('supplier.kyc.provided')}
                                />
                            )}
                        </span>
                    </div>

                    {requirement.instructions && (
                        <p className="text-muted-foreground text-sm">
                            {requirement.instructions}
                        </p>
                    )}

                    {requirement.requires_file && (
                        <FileField
                            label={t('supplier.kyc.file')}
                            name="file"
                            accept={requirement.accepted_mime_types.join(',')}
                            maxSizeMb={Math.max(
                                1,
                                Math.floor(requirement.max_size_kb / 1024),
                            )}
                            error={errors.file}
                            progress={progress?.percentage ?? null}
                        />
                    )}

                    {requirement.requires_value && (
                        <FormField
                            label={
                                requirement.value_label ??
                                t('supplier.kyc.value')
                            }
                            error={errors.value}
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    name="value"
                                    maxLength={255}
                                    autoComplete="off"
                                    placeholder={
                                        requirement.value_preview ?? ''
                                    }
                                />
                            )}
                        </FormField>
                    )}

                    <SubmitButton processing={processing} size="sm">
                        {t('supplier.kyc.save')}
                    </SubmitButton>
                </>
            )}
        </Form>
    );
}
