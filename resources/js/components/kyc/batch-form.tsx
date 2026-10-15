import { router } from '@inertiajs/react';
import { CheckCircle2, Lock, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import SelfieCapture from '@/components/forms/selfie-capture';
import SubmitButton from '@/components/forms/submit-button';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import GroupCard from './batch-group';
import {
    useEvidenceRequest,
    type KycBatchEndpoints,
} from './use-evidence-request';
import type { KycBatch } from '@/types';

export type { KycBatchEndpoints };

/**
 * A KYC batch as the applicant fills it in (D30): groups, each saying how it is
 * satisfied, options to choose between, the files and values each needs, an
 * optional live selfie, and one confirmed submission.
 *
 * Every figure and every verdict comes from the server. Nothing here decides
 * whether a group is satisfied or whether a file is acceptable — the form only
 * shows what the server says and sends what the applicant gives.
 */
export default function BatchKycForm({
    batch,
    endpoints,
}: {
    batch: KycBatch;
    endpoints: KycBatchEndpoints;
}) {
    const { t } = useTranslation();
    const requiredGroups = batch.groups.filter((group) => group.required);
    const doneGroups = requiredGroups.filter((group) => group.satisfied);

    return (
        <div className="space-y-6">
            <header className="space-y-1">
                <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                    {t('kyc.batch_form.batch_label')}
                </p>
                <h2 className="text-lg font-semibold">{batch.name}</h2>
                {batch.description && (
                    <p className="text-muted-foreground text-sm">
                        {batch.description}
                    </p>
                )}
                <p
                    className="text-muted-foreground text-sm tabular-nums"
                    aria-live="polite"
                >
                    {t('kyc.batch_form.summary', {
                        done: doneGroups.length,
                        total: requiredGroups.length,
                    })}
                </p>
            </header>

            {!batch.editable && (
                <p className="text-muted-foreground flex items-center gap-2 text-sm">
                    <Lock className="size-4" aria-hidden="true" />
                    {t('kyc.batch_form.locked')}
                </p>
            )}

            {batch.groups.map((group) => (
                <GroupCard
                    key={group.key}
                    group={group}
                    endpoints={endpoints}
                    editable={batch.editable}
                />
            ))}

            {batch.selfie && (
                <SelfieCard
                    selfie={batch.selfie}
                    endpoints={endpoints}
                    editable={batch.editable}
                />
            )}

            {batch.editable && (
                <SubmitPanel batch={batch} endpoints={endpoints} />
            )}
        </div>
    );
}

function SelfieCard({
    selfie,
    endpoints,
    editable,
}: {
    selfie: NonNullable<KycBatch['selfie']>;
    endpoints: KycBatchEndpoints;
    editable: boolean;
}) {
    const { t } = useTranslation();
    const request = useEvidenceRequest();
    const input = useRef<HTMLInputElement>(null);
    const current = selfie.slot.current;
    const accepted = current?.verdict === 'accepted';
    const rejected = current?.verdict === 'rejected';
    const locked = !editable || accepted;

    return (
        <SectionCard
            title={t('kyc.batch_form.selfie_title')}
            description={selfie.slot.instructions ?? undefined}
            actions={
                <>
                    <StatusPill
                        tone={selfie.required ? 'info' : 'neutral'}
                        label={t(
                            selfie.required
                                ? 'kyc.batch_form.selfie_required'
                                : 'kyc.batch_form.optional',
                        )}
                    />
                    {current && !rejected && (
                        <StatusPill
                            tone="success"
                            label={t('kyc.batch_form.live_badge')}
                        />
                    )}
                </>
            }
        >
            <div className="space-y-3">
                {rejected && (
                    <p
                        role="alert"
                        className="text-warning bg-warning-subtle rounded-md px-2.5 py-1.5 text-xs font-medium"
                    >
                        {t('kyc.batch_form.needs_replacing')}
                        {current?.note ? `: ${current.note}` : ''}
                    </p>
                )}

                {current && !rejected && (
                    <p className="text-success flex items-center gap-1.5 text-sm font-medium">
                        <CheckCircle2 className="size-4" aria-hidden="true" />
                        {t('kyc.batch_form.selfie_saved')}
                    </p>
                )}

                {!locked && (
                    <SelfieCapture
                        saving={request.busy}
                        onCapture={(file, meta) =>
                            request.send(
                                endpoints.file,
                                {
                                    requirement: selfie.requirement,
                                    slot: selfie.slot.key,
                                    file,
                                    captured_live: 1,
                                    capture_meta: meta,
                                },
                                true,
                            )
                        }
                    />
                )}

                {!locked && selfie.allows_upload && (
                    <div className="space-y-1.5">
                        <p className="text-muted-foreground text-xs">
                            {t('kyc.batch_form.selfie_fallback')}
                        </p>
                        <input
                            ref={input}
                            type="file"
                            accept={selfie.slot.mimes.join(',')}
                            className="sr-only"
                            aria-label={t('kyc.batch_form.selfie_upload')}
                            onChange={(event) => {
                                const file = event.target.files?.[0];

                                if (file) {
                                    request.send(
                                        endpoints.file,
                                        {
                                            requirement: selfie.requirement,
                                            slot: selfie.slot.key,
                                            file,
                                        },
                                        true,
                                    );
                                }

                                event.target.value = '';
                            }}
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={request.busy}
                            onClick={() => input.current?.click()}
                        >
                            <Upload className="size-4" aria-hidden="true" />
                            {t('kyc.batch_form.selfie_upload')}
                        </Button>
                    </div>
                )}

                {request.error && (
                    <p role="alert" className="text-danger text-xs font-medium">
                        {request.error}
                    </p>
                )}
            </div>
        </SectionCard>
    );
}

function SubmitPanel({
    batch,
    endpoints,
}: {
    batch: KycBatch;
    endpoints: KycBatchEndpoints;
}) {
    const { t } = useTranslation();
    const [confirmed, setConfirmed] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    return (
        <SectionCard
            title={t('kyc.batch_form.submit_title')}
            description={t('kyc.batch_form.submit_help')}
        >
            <form
                className="space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();

                    router.post(
                        endpoints.submit,
                        { confirm: confirmed ? 1 : 0 },
                        {
                            onStart: () => {
                                setProcessing(true);
                                setErrors({});
                            },
                            onError: (failed) => setErrors(failed),
                            onFinish: () => setProcessing(false),
                        },
                    );
                }}
            >
                {!batch.complete && batch.missing.length > 0 && (
                    <div aria-live="polite" className="space-y-1">
                        <p className="text-sm font-medium">
                            {t('kyc.batch_form.still_needed')}
                        </p>
                        <ul className="text-muted-foreground list-disc space-y-0.5 pl-5 text-xs">
                            {batch.missing.map((line) => (
                                <li key={line}>{line}</li>
                            ))}
                        </ul>
                    </div>
                )}

                <label className="flex items-start gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={confirmed}
                        onChange={(event) => setConfirmed(event.target.checked)}
                        disabled={!batch.complete}
                        className="accent-brand mt-0.5 size-4"
                    />
                    {t('kyc.batch_form.confirm')}
                </label>

                {(errors.submission || errors.confirm) && (
                    <p role="alert" className="text-danger text-sm font-medium">
                        {errors.submission ?? errors.confirm}
                    </p>
                )}

                <SubmitButton
                    processing={processing}
                    disabled={!batch.complete || !confirmed}
                >
                    {t('kyc.batch_form.submit_button')}
                </SubmitButton>
            </form>
        </SectionCard>
    );
}
