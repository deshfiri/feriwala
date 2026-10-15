import { FileText, Lock, Trash2, Upload } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import NativeSelect from '@/components/forms/native-select';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import type { KycBatchGroup, KycBatchOption, KycBatchSlot } from '@/types';
import {
    formatSize,
    useEvidenceRequest,
    type KycBatchEndpoints,
} from './use-evidence-request';

/**
 * One verification group as the applicant fills it in (D30), drawn by its rule:
 *
 * - **any one** — a single dropdown ("Select identification document"), and only
 *   the chosen option's evidence slots under it. Nothing else is shown to upload
 *   until something is chosen, and the options not chosen never count as missing.
 * - **at least N** — a checkbox for each option; only the ticked ones show their
 *   slots.
 * - **all required** — every option, with its slots.
 *
 * Whether a group is satisfied, and what each option holds, comes from the
 * server; this only draws it and sends what the applicant does.
 */
export default function GroupCard({
    group,
    endpoints,
    editable,
}: {
    group: KycBatchGroup;
    endpoints: KycBatchEndpoints;
    editable: boolean;
}) {
    const { t } = useTranslation();
    const chosen = group.options.filter((option) =>
        group.selected.includes(option.key),
    );

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
                {group.rule === 'any' && (
                    <ChooseOne
                        group={group}
                        current={chosen[0] ?? null}
                        endpoints={endpoints}
                        editable={editable}
                    />
                )}

                {group.rule === 'min' && (
                    <ChooseSome
                        group={group}
                        chosen={chosen}
                        endpoints={endpoints}
                        editable={editable}
                    />
                )}

                {group.rule === 'all' && (
                    <>
                        <GroupProgress group={group} />
                        <div className="space-y-3">
                            {group.options.map((option) => (
                                <OptionPanel
                                    key={option.key}
                                    option={option}
                                    endpoints={endpoints}
                                    editable={editable}
                                />
                            ))}
                        </div>
                    </>
                )}
            </div>
        </SectionCard>
    );
}

/** The progress of an "all required" or "at least N" group, as a bar and words. */
function GroupProgress({ group }: { group: KycBatchGroup }) {
    const { t } = useTranslation();
    const percent =
        group.needed === 0
            ? 100
            : Math.round((group.done / group.needed) * 100);

    const words =
        group.rule === 'min'
            ? t('kyc.batch_form.options_complete', {
                  done: group.done,
                  needed: group.needed,
              })
            : t('kyc.batch_form.progress', {
                  done: group.done,
                  total: group.needed,
              });

    return (
        <div className="space-y-1.5">
            <p className="text-sm font-medium">
                {group.rule === 'min'
                    ? t('kyc.batch_form.min_instruction', {
                          count: group.needed,
                          total: group.offered,
                      })
                    : t('kyc.batch_form.all_required')}
            </p>
            <div className="flex items-center gap-3">
                <div
                    className="bg-muted h-1.5 flex-1 overflow-hidden rounded-full"
                    role="progressbar"
                    aria-valuenow={percent}
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-label={`${group.name}: ${words}`}
                >
                    <div
                        className="bg-brand h-full transition-[width] duration-200"
                        style={{ width: `${percent}%` }}
                    />
                </div>
                <span
                    className="text-muted-foreground text-xs whitespace-nowrap tabular-nums"
                    aria-live="polite"
                >
                    {words}
                </span>
            </div>
        </div>
    );
}

/**
 * "Any one": a dropdown, then only the chosen option.
 *
 * Switching away from an option the applicant has already put files into asks
 * first, because the server then discards those drafts. It never discards
 * anything that has been sent or accepted — the dropdown is switched off for an
 * option in that state — and says why.
 */
function ChooseOne({
    group,
    current,
    endpoints,
    editable,
}: {
    group: KycBatchGroup;
    current: KycBatchOption | null;
    endpoints: KycBatchEndpoints;
    editable: boolean;
}) {
    const { t } = useTranslation();
    const id = useId();
    const request = useEvidenceRequest();
    const [pending, setPending] = useState<KycBatchOption | null>(null);

    const choose = (option: KycBatchOption) =>
        request.send(endpoints.choose, {
            group: group.key,
            requirement: option.key,
        });

    const onChange = (key: string) => {
        const next = group.options.find((option) => option.key === key);

        if (!next || next.key === current?.key) {
            return;
        }

        // Files already in the option being left would be thrown away: ask.
        if (current?.has_draft) {
            setPending(next);

            return;
        }

        choose(next);
    };

    const locked = current?.locked === true;

    return (
        <div className="space-y-4">
            <div className="space-y-1.5">
                <Label htmlFor={id}>
                    {group.selector_label ?? t('kyc.batch_form.select_one')}
                </Label>
                <NativeSelect
                    id={id}
                    value={current?.key ?? ''}
                    disabled={!editable || request.busy || locked}
                    onChange={(event) => onChange(event.target.value)}
                    className="max-w-sm"
                >
                    <option value="" disabled={current !== null}>
                        {t('kyc.batch_form.choose_one')}
                    </option>
                    {group.options.map((option) => (
                        <option key={option.key} value={option.key}>
                            {option.name}
                        </option>
                    ))}
                </NativeSelect>

                {locked && editable && (
                    <p className="text-muted-foreground flex items-center gap-1.5 text-xs">
                        <Lock className="size-3.5" aria-hidden="true" />
                        {t('kyc.batch_form.locked_option')}
                    </p>
                )}
                {request.error && (
                    <p role="alert" className="text-danger text-xs font-medium">
                        {request.error}
                    </p>
                )}
            </div>

            <p
                className="text-muted-foreground text-sm tabular-nums"
                aria-live="polite"
            >
                {group.satisfied
                    ? t('kyc.batch_form.group_complete', { group: group.name })
                    : current === null
                      ? t('kyc.batch_form.no_selection')
                      : t('kyc.batch_form.option_progress', {
                            option: current.name,
                            provided: current.provided,
                            total: current.total,
                        })}
            </p>

            {current === null ? (
                <p className="text-muted-foreground text-xs">
                    {t('kyc.batch_form.any_one_instruction')}
                </p>
            ) : (
                <OptionPanel
                    option={current}
                    endpoints={endpoints}
                    editable={editable}
                />
            )}

            <Dialog
                open={pending !== null}
                onOpenChange={(open) => !open && setPending(null)}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {t('kyc.batch_form.change_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('kyc.batch_form.change_body', {
                                option: current?.name ?? '',
                            })}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setPending(null)}
                        >
                            {t('kyc.batch_form.change_keep', {
                                option: current?.name ?? '',
                            })}
                        </Button>
                        <Button
                            type="button"
                            onClick={() => {
                                if (pending) {
                                    choose(pending);
                                }

                                setPending(null);
                            }}
                        >
                            {t('kyc.batch_form.change_confirm')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

/**
 * "At least N": tick the options you will provide; only ticked ones show their
 * slots. Un-ticking an option holding files asks first, as switching does.
 */
function ChooseSome({
    group,
    chosen,
    endpoints,
    editable,
}: {
    group: KycBatchGroup;
    chosen: KycBatchOption[];
    endpoints: KycBatchEndpoints;
    editable: boolean;
}) {
    const { t } = useTranslation();
    const request = useEvidenceRequest();
    const [pending, setPending] = useState<KycBatchOption | null>(null);

    const toggle = (option: KycBatchOption, on: boolean) => {
        if (on) {
            request.send(endpoints.choose, {
                group: group.key,
                requirement: option.key,
            });

            return;
        }

        if (option.has_draft) {
            setPending(option);

            return;
        }

        request.send(endpoints.deselect, {
            group: group.key,
            requirement: option.key,
        });
    };

    return (
        <div className="space-y-4">
            <GroupProgress group={group} />

            <fieldset className="space-y-2">
                <legend className="text-sm font-medium">
                    {group.selector_label ?? t('kyc.batch_form.select_some')}
                </legend>
                <div className="space-y-1.5">
                    {group.options.map((option) => (
                        <label
                            key={option.key}
                            className="flex items-center gap-2 text-sm"
                        >
                            <input
                                type="checkbox"
                                checked={group.selected.includes(option.key)}
                                disabled={
                                    !editable || request.busy || option.locked
                                }
                                onChange={(event) =>
                                    toggle(option, event.target.checked)
                                }
                                className="accent-brand size-4"
                            />
                            {option.name}
                            {option.locked && (
                                <Lock
                                    className="text-muted-foreground size-3.5"
                                    aria-label={t(
                                        'kyc.batch_form.locked_option',
                                    )}
                                />
                            )}
                        </label>
                    ))}
                </div>
                {request.error && (
                    <p role="alert" className="text-danger text-xs font-medium">
                        {request.error}
                    </p>
                )}
            </fieldset>

            {chosen.map((option) => (
                <OptionPanel
                    key={option.key}
                    option={option}
                    endpoints={endpoints}
                    editable={editable}
                />
            ))}

            <Dialog
                open={pending !== null}
                onOpenChange={(open) => !open && setPending(null)}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {t('kyc.batch_form.change_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('kyc.batch_form.change_body', {
                                option: pending?.name ?? '',
                            })}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setPending(null)}
                        >
                            {t('kyc.batch_form.change_keep', {
                                option: pending?.name ?? '',
                            })}
                        </Button>
                        <Button
                            type="button"
                            onClick={() => {
                                if (pending) {
                                    request.send(endpoints.deselect, {
                                        group: group.key,
                                        requirement: pending.key,
                                    });
                                }

                                setPending(null);
                            }}
                        >
                            {t('kyc.batch_form.change_confirm')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

/** One option: its name, how far through it the applicant is, and its slots. */
function OptionPanel({
    option,
    endpoints,
    editable,
}: {
    option: KycBatchOption;
    endpoints: KycBatchEndpoints;
    editable: boolean;
}) {
    const { t } = useTranslation();

    return (
        <div className="rounded-lg border p-3">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-sm font-medium">{option.name}</h3>
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
            </div>

            {option.instructions && (
                <p className="text-muted-foreground mb-3 text-xs">
                    {option.instructions}
                </p>
            )}

            <div className="space-y-4">
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
    // A picture of what was just chosen, held in this browser only: shown at once,
    // without asking the server for the file back.
    const [localPreview, setLocalPreview] = useState<string | null>(null);
    const [showStored, setShowStored] = useState(false);
    const current = slot.current;
    const accepted = current?.verdict === 'accepted';
    const rejected = current?.verdict === 'rejected';
    const locked = !editable || accepted;
    const id = `slot-${requirement}-${slot.key}`;

    useEffect(
        () => () => {
            if (localPreview) {
                URL.revokeObjectURL(localPreview);
            }
        },
        [localPreview],
    );

    // The file is gone (removed, replaced by the server's copy): drop the picture.
    useEffect(() => {
        if (current === null) {
            setLocalPreview(null);
            setShowStored(false);
        }
    }, [current]);

    const upload = (file: File | null) => {
        if (!file) {
            return;
        }

        setLocalPreview(
            file.type.startsWith('image/') ? URL.createObjectURL(file) : null,
        );
        setShowStored(false);
        request.send(
            endpoints.file,
            { requirement, slot: slot.key, file },
            true,
        );
    };

    const clear = () => {
        setLocalPreview(null);
        setShowStored(false);
        request.send(endpoints.remove, { requirement, slot: slot.key });
    };

    const storedUrl =
        current?.document && endpoints.preview
            ? endpoints.preview(current.document)
            : null;
    const thumbnail = localPreview ?? (showStored ? storedUrl : null);

    return (
        <div className="space-y-1.5">
            <div className="flex flex-wrap items-center gap-2">
                <label htmlFor={id} className="text-sm font-medium">
                    {slot.label}
                </label>
                <span className="text-muted-foreground text-xs">
                    {t(
                        slot.required
                            ? 'kyc.batch_form.required'
                            : 'kyc.batch_form.optional',
                    )}
                </span>
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
                            {thumbnail ? (
                                <img
                                    src={thumbnail}
                                    alt={`${slot.label}: ${current.name ?? ''}`}
                                    className="h-14 w-20 shrink-0 rounded border object-cover"
                                />
                            ) : (
                                <FileText
                                    className="text-muted-foreground size-4 shrink-0"
                                    aria-hidden="true"
                                />
                            )}
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

                            {!thumbnail && storedUrl && current.image && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setShowStored(true)}
                                >
                                    {t('kyc.batch_form.preview')}
                                </Button>
                            )}
                            {storedUrl && !current.image && (
                                <Button variant="ghost" size="sm" asChild>
                                    {/* Opened on purpose, in its own tab: the
                                        route authorises, and the file is not
                                        fetched until the applicant asks. */}
                                    <a
                                        href={storedUrl}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        {t('kyc.batch_form.preview_open')}
                                    </a>
                                </Button>
                            )}
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
