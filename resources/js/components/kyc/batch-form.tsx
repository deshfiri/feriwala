import { router } from '@inertiajs/react';
import { CheckCircle2, FileText, Lock, Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import SelfieCapture from '@/components/forms/selfie-capture';
import SubmitButton from '@/components/forms/submit-button';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type {
    KycBatch,
    KycBatchGroup,
    KycBatchOption,
    KycBatchSlot,
} from '@/types';

/** The URLs of one audience's batch evidence endpoints. */
export type KycBatchEndpoints = {
    file: string;
    value: string;
    remove: string;
    choose: string;
    deselect: string;
    submit: string;
};

const formatSize = (bytes: number): string =>
    bytes < 1024 ? `${bytes} B` : `${Math.round(bytes / 1024)} KB`;

/**
 * One request against a slot, with its own progress and its own error.
 *
 * Each item saves alone, so a refused file never costs the others, and the
 * message appears against the item that was refused.
 */
function useEvidenceRequest() {
    const [busy, setBusy] = useState(false);
    const [progress, setProgress] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);

    const send = (
        url: string,
        data: Record<string, unknown>,
        forceFormData = false,
    ) =>
        router.post(url, data as never, {
            forceFormData,
            preserveScroll: true,
            onStart: () => {
                setBusy(true);
                setError(null);
            },
            onProgress: (event) =>
                setProgress(
                    event?.percentage === undefined
                        ? null
                        : Math.round(event.percentage),
                ),
            onError: (errors) => setError(Object.values(errors)[0] ?? null),
            onFinish: () => {
                setBusy(false);
                setProgress(null);
            },
        });

    return { busy, progress, error, send };
}

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

function GroupCard({
    group,
    endpoints,
    editable,
}: {
    group: KycBatchGroup;
    endpoints: KycBatchEndpoints;
    editable: boolean;
}) {
    const { t } = useTranslation();
    const percent =
        group.needed === 0
            ? 100
            : Math.round((group.done / group.needed) * 100);

    const instruction =
        group.rule === 'any'
            ? t('kyc.batch_form.any_one')
            : group.rule === 'min'
              ? t('kyc.batch_form.min_of', {
                    count: group.needed,
                    total: group.offered,
                })
              : t('kyc.batch_form.all_required');

    return (
        <SectionCard
            title={group.name}
            description={group.description ?? undefined}
            actions={
                <>
                    <StatusPill
                        tone={group.required ? 'info' : 'neutral'}
                        label={t(
                            group.required
                                ? 'kyc.batch_form.required'
                                : 'kyc.batch_form.optional',
                        )}
                    />
                    {group.satisfied && group.required && (
                        <StatusPill
                            tone="success"
                            label={t('kyc.batch_form.complete')}
                        />
                    )}
                </>
            }
        >
            <div className="space-y-4">
                <div className="space-y-1.5">
                    <p className="text-sm font-medium">{instruction}</p>
                    <div className="flex items-center gap-3">
                        <div
                            className="bg-muted h-1.5 flex-1 overflow-hidden rounded-full"
                            role="progressbar"
                            aria-valuenow={percent}
                            aria-valuemin={0}
                            aria-valuemax={100}
                            aria-label={`${group.name}: ${t('kyc.batch_form.progress', { done: group.done, total: group.needed })}`}
                        >
                            <div
                                className="bg-brand h-full transition-[width] duration-200"
                                style={{ width: `${percent}%` }}
                            />
                        </div>
                        <span className="text-muted-foreground text-xs whitespace-nowrap tabular-nums">
                            {t('kyc.batch_form.progress', {
                                done: group.done,
                                total: group.needed,
                            })}
                        </span>
                    </div>
                </div>

                <div
                    role={group.rule === 'any' ? 'radiogroup' : 'group'}
                    aria-label={group.name}
                    className="space-y-3"
                >
                    {group.options.map((option) => (
                        <OptionCard
                            key={option.key}
                            group={group}
                            option={option}
                            endpoints={endpoints}
                            editable={editable}
                        />
                    ))}
                </div>
            </div>
        </SectionCard>
    );
}

function OptionCard({
    group,
    option,
    endpoints,
    editable,
}: {
    group: KycBatchGroup;
    option: KycBatchOption;
    endpoints: KycBatchEndpoints;
    editable: boolean;
}) {
    const { t } = useTranslation();
    const choice = useEvidenceRequest();
    const open = !group.choose || option.chosen;

    const toggle = () => {
        if (!editable || choice.busy) return;

        // An "any one" group changes by choosing another option; a "minimum"
        // group lets an option be withdrawn as well.
        if (group.rule === 'min' && option.chosen) {
            choice.send(endpoints.deselect, {
                group: group.key,
                requirement: option.key,
            });

            return;
        }

        if (!option.chosen) {
            choice.send(endpoints.choose, {
                group: group.key,
                requirement: option.key,
            });
        }
    };

    return (
        <div
            className={cn(
                'rounded-lg border p-3',
                group.choose &&
                    option.chosen &&
                    'border-brand ring-brand/30 ring-2',
            )}
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                {group.choose ? (
                    <label className="flex cursor-pointer items-center gap-2 text-sm font-medium">
                        <input
                            type={group.rule === 'any' ? 'radio' : 'checkbox'}
                            name={`choice-${group.key}`}
                            checked={option.chosen}
                            disabled={!editable || choice.busy}
                            onChange={toggle}
                            className="accent-brand size-4"
                        />
                        {option.name}
                    </label>
                ) : (
                    <h3 className="flex items-center gap-2 text-sm font-medium">
                        {option.name}
                        {!option.required && (
                            <span className="text-muted-foreground text-xs font-normal">
                                {t('kyc.batch_form.optional')}
                            </span>
                        )}
                    </h3>
                )}

                {(open || option.complete) && (
                    <StatusPill
                        tone={option.complete ? 'success' : 'neutral'}
                        label={
                            option.complete
                                ? t('kyc.batch_form.complete')
                                : t('kyc.batch_form.items', {
                                      provided: option.provided,
                                      total: option.total,
                                  })
                        }
                    />
                )}
            </div>

            {choice.error && (
                <p
                    role="alert"
                    className="text-danger mt-2 text-xs font-medium"
                >
                    {choice.error}
                </p>
            )}

            {open && (
                <div className="mt-3 space-y-4">
                    {option.instructions && (
                        <p className="text-muted-foreground text-xs">
                            {option.instructions}
                        </p>
                    )}
                    {option.slots.map((slot) => (
                        <SlotField
                            key={slot.key}
                            requirement={option.key}
                            slot={slot}
                            endpoints={endpoints}
                            editable={editable}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

function SlotField({
    requirement,
    slot,
    endpoints,
    editable,
}: {
    requirement: string;
    slot: KycBatchSlot;
    endpoints: KycBatchEndpoints;
    editable: boolean;
}) {
    const { t } = useTranslation();
    const request = useEvidenceRequest();
    const input = useRef<HTMLInputElement>(null);
    const [value, setValue] = useState('');
    const current = slot.current;
    const accepted = current?.verdict === 'accepted';
    const rejected = current?.verdict === 'rejected';
    const locked = !editable || accepted;
    const id = `slot-${requirement}-${slot.key}`;

    const upload = (file: File | null) => {
        if (!file) return;

        request.send(
            endpoints.file,
            { requirement, slot: slot.key, file },
            true,
        );
    };

    const clear = () =>
        request.send(endpoints.remove, { requirement, slot: slot.key });

    return (
        <div className="space-y-1.5">
            <div className="flex flex-wrap items-center gap-2">
                <label htmlFor={id} className="text-sm font-medium">
                    {slot.label}
                </label>
                {!slot.required && (
                    <span className="text-muted-foreground text-xs">
                        {t('kyc.batch_form.optional')}
                    </span>
                )}
                {accepted && (
                    <StatusPill
                        tone="success"
                        label={t('kyc.batch_form.accepted')}
                    />
                )}
            </div>

            {slot.instructions && (
                <p className="text-muted-foreground text-xs">
                    {slot.instructions}
                </p>
            )}

            {rejected && (
                <p
                    role="alert"
                    className="text-warning bg-warning-subtle rounded-md px-2.5 py-1.5 text-xs font-medium"
                >
                    {t('kyc.batch_form.needs_replacing')}
                    {current?.note ? `: ${current.note}` : ''}
                </p>
            )}

            {(slot.evidence === 'file' ||
                slot.evidence === 'image' ||
                slot.evidence === 'pdf') && (
                <div className="space-y-1.5">
                    <input
                        id={id}
                        ref={input}
                        type="file"
                        accept={slot.mimes.join(',')}
                        className="sr-only"
                        disabled={locked || request.busy}
                        onChange={(event) => {
                            upload(event.target.files?.[0] ?? null);
                            event.target.value = '';
                        }}
                    />

                    {current && (
                        <div className="border-input flex items-center gap-2.5 rounded-md border p-2">
                            <FileText
                                className="text-muted-foreground size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium">
                                    {current.name}
                                </p>
                                <p className="text-muted-foreground text-xs tabular-nums">
                                    {current.size_bytes !== null
                                        ? formatSize(current.size_bytes)
                                        : ''}
                                </p>
                            </div>
                            {!locked && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-7 shrink-0"
                                    disabled={request.busy}
                                    onClick={clear}
                                    aria-label={`${t('kyc.batch_form.remove')}: ${slot.label}`}
                                >
                                    <Trash2 className="size-3.5" />
                                </Button>
                            )}
                        </div>
                    )}

                    {!locked && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={request.busy}
                            onClick={() => input.current?.click()}
                        >
                            <Upload className="size-4" aria-hidden="true" />
                            {current
                                ? t('kyc.batch_form.replace')
                                : t('kyc.batch_form.choose_file')}
                        </Button>
                    )}

                    {request.progress !== null && (
                        <div
                            className="bg-muted h-1 overflow-hidden rounded-full"
                            role="progressbar"
                            aria-valuenow={request.progress}
                            aria-valuemin={0}
                            aria-valuemax={100}
                            aria-label={`${t('kyc.batch_form.uploading')}: ${slot.label}`}
                        >
                            <div
                                className="bg-brand h-full transition-[width] duration-200"
                                style={{ width: `${request.progress}%` }}
                            />
                        </div>
                    )}

                    <p className="text-muted-foreground text-xs">
                        {t('kyc.batch_form.limits', {
                            types: slot.mimes
                                .map((mime) => mime.split('/')[1].toUpperCase())
                                .join(', '),
                            size: slot.max_kb
                                ? Math.round(slot.max_kb / 1024) || 1
                                : 5,
                        })}
                    </p>
                </div>
            )}

            {slot.evidence === 'value' && (
                <div className="space-y-1.5">
                    {current?.value_preview && (
                        <p className="text-muted-foreground text-xs">
                            {t('kyc.batch_form.value_saved', {
                                value: current.value_preview,
                            })}
                        </p>
                    )}
                    {!locked && (
                        <div className="flex items-end gap-2">
                            <Input
                                id={id}
                                value={value}
                                autoComplete="off"
                                maxLength={255}
                                placeholder={slot.value_label ?? slot.label}
                                disabled={request.busy}
                                onChange={(event) =>
                                    setValue(event.target.value)
                                }
                                className="max-w-xs"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                disabled={request.busy || value.trim() === ''}
                                onClick={() => {
                                    request.send(endpoints.value, {
                                        requirement,
                                        slot: slot.key,
                                        value,
                                    });
                                    setValue('');
                                }}
                            >
                                {t('common.actions.save')}
                            </Button>
                        </div>
                    )}
                </div>
            )}

            {slot.evidence === 'declaration' && (
                <label className="flex items-start gap-2 text-sm">
                    <input
                        id={id}
                        type="checkbox"
                        checked={current !== null}
                        disabled={locked || request.busy}
                        onChange={(event) =>
                            event.target.checked
                                ? request.send(endpoints.value, {
                                      requirement,
                                      slot: slot.key,
                                      value: 'yes',
                                  })
                                : clear()
                        }
                        className="accent-brand mt-0.5 size-4"
                    />
                    {slot.value_label ?? slot.label}
                </label>
            )}

            {request.error && (
                <p role="alert" className="text-danger text-xs font-medium">
                    {request.error}
                </p>
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
