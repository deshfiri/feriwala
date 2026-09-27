import { Form, Head } from '@inertiajs/react';
import { FileText, Lock } from 'lucide-react';
import FileField from '@/components/forms/file-field';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
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
        original_name: string;
        size_bytes: number;
        mime_type: string;
    }[];
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
    document_types,
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
    document_types: string[];
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
                                            {t(
                                                `supplier.kyc.types.${document.type}`,
                                            )}
                                        </p>
                                        <p className="text-muted-foreground truncate text-xs">
                                            {document.original_name} ·{' '}
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
                            <Form
                                {...store.form()}
                                resetOnSuccess
                                options={{ preserveScroll: true }}
                                className="space-y-4"
                            >
                                {({ processing, errors, progress }) => (
                                    <>
                                        <FormField
                                            label={t(
                                                'supplier.kyc.document_type',
                                            )}
                                            error={errors.document_type}
                                            required
                                        >
                                            {(field) => (
                                                <select
                                                    {...field}
                                                    name="document_type"
                                                    required
                                                    className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                                                >
                                                    {document_types.map(
                                                        (type) => (
                                                            <option
                                                                key={type}
                                                                value={type}
                                                            >
                                                                {t(
                                                                    `supplier.kyc.types.${type}`,
                                                                )}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            )}
                                        </FormField>

                                        <FileField
                                            label={t('supplier.kyc.file')}
                                            name="file"
                                            accept="image/jpeg,image/png,application/pdf"
                                            maxSizeMb={5}
                                            required
                                            error={errors.file}
                                            progress={
                                                progress?.percentage ?? null
                                            }
                                        />

                                        <SubmitButton processing={processing}>
                                            {t('supplier.kyc.upload')}
                                        </SubmitButton>
                                    </>
                                )}
                            </Form>
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
                                                round.documents.length === 0
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
