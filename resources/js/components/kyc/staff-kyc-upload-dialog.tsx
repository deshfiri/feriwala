import { Form } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import FormField from '@/components/forms/form-field';
import ReasonTextarea from '@/components/forms/reason-textarea';
import SubmitButton from '@/components/forms/submit-button';
import InputError from '@/components/input-error';
import Notice from '@/components/notice';
import StatusPill from '@/components/status-pill';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';

type Target = {
    value: string;
    /** A file, a typed value (a TIN), or a declaration (a tick). */
    kind: 'file' | 'value' | 'declaration';
    label: string;
    /** The batch group it belongs to; null for a flat list of documents. */
    group: string | null;
    group_rule: string | null;
    required: boolean;
    uploaded: boolean;
};

const textareaClasses =
    'border-input focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:border-destructive w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]';

/**
 * Staff uploading a round's documents on an applicant's behalf, for a partner or
 * a Supplier alike — the whole checklist at once.
 *
 * What the round asks for depends on the round the applicant would be filling
 * in, so the list is asked of the server when the dialog opens rather than
 * guessed here: every document of the batch, grouped as the applicant sees it,
 * with what is owed, what is already there, and a file field for each. Opening
 * it is what opens the round, exactly as the applicant's own KYC screen does on
 * first arrival.
 *
 * Several files go in one request. Each is judged by the round on its own, so a
 * refusal appears beside the document it is about.
 */
export default function StaffKycUploadDialog({
    targetsUrl,
    storeForm,
    open,
    onOpenChange,
    canVerify = false,
}: {
    targetsUrl: string;
    /** The wayfinder `.form()` for the store route: `{ action, method }`. */
    storeForm: { action: string; method: 'post' };
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Whether this person may also approve the round as they upload. */
    canVerify?: boolean;
}) {
    const { t } = useTranslation();
    const [targets, setTargets] = useState<Target[] | null>(null);
    const [message, setMessage] = useState<string | null>(null);
    const [maxKb, setMaxKb] = useState<number | null>(null);
    const [chosen, setChosen] = useState<Record<string, boolean>>({});
    const [tooLarge, setTooLarge] = useState<Record<string, boolean>>({});

    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();

        setTargets(null);
        setMessage(null);
        setChosen({});
        setTooLarge({});

        fetch(targetsUrl, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then((response) => response.json())
            .then(
                (body: {
                    targets: Target[];
                    message?: string;
                    max_upload_kb?: number;
                }) => {
                    setTargets(body.targets);
                    setMessage(body.message ?? null);
                    setMaxKb(body.max_upload_kb ?? null);
                },
            )
            .catch(() => {
                if (!controller.signal.aborted) {
                    setTargets([]);
                }
            });

        return () => controller.abort();
    }, [open, targetsUrl]);

    const limit =
        maxKb === null
            ? ''
            : maxKb >= 1024
              ? `${Math.floor(maxKb / 1024)} MB`
              : `${maxKb} KB`;

    // The checklist, in the order the round gives it, one block per group.
    const groups = useMemo(() => {
        const blocks: {
            name: string | null;
            rule: string | null;
            items: Target[];
        }[] = [];

        for (const target of targets ?? []) {
            const block = blocks.find((entry) => entry.name === target.group);

            if (block) {
                block.items.push(target);
            } else {
                blocks.push({
                    name: target.group,
                    rule: target.group_rule,
                    items: [target],
                });
            }
        }

        return blocks;
    }, [targets]);

    const owed = (targets ?? []).filter((target) => target.required);
    const inPlace = owed.filter(
        (target) => target.uploaded || chosen[target.value],
    ).length;
    const anyTooLarge = Object.values(tooLarge).some(Boolean);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{t('kyc.upload_on_behalf.title')}</DialogTitle>
                    <DialogDescription>
                        {t('kyc.upload_on_behalf.description')}
                    </DialogDescription>
                </DialogHeader>

                {targets === null ? (
                    <p className="text-muted-foreground text-sm">
                        {t('kyc.upload_on_behalf.loading')}
                    </p>
                ) : targets.length === 0 ? (
                    <Notice tone="warning" inset>
                        {message ?? t('kyc.upload_on_behalf.none')}
                    </Notice>
                ) : (
                    <Form
                        {...storeForm}
                        onSuccess={() => onOpenChange(false)}
                        className="space-y-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="space-y-1">
                                    {owed.length > 0 && (
                                        <p
                                            className="text-sm font-medium tabular-nums"
                                            data-test="kyc-upload-progress"
                                        >
                                            {t(
                                                'kyc.upload_on_behalf.in_place',
                                                {
                                                    done: inPlace,
                                                    total: owed.length,
                                                },
                                            )}
                                        </p>
                                    )}
                                    {limit !== '' && (
                                        <p className="text-muted-foreground text-xs">
                                            {t(
                                                'kyc.upload_on_behalf.max_size',
                                                { limit },
                                            )}
                                        </p>
                                    )}
                                    <InputError message={errors.files} />
                                </div>

                                {groups.map((group) => (
                                    <fieldset
                                        key={group.name ?? 'documents'}
                                        className="space-y-3 rounded-lg border p-3"
                                    >
                                        {group.name !== null && (
                                            <legend className="px-1 text-sm font-medium">
                                                {group.name}
                                                {group.rule !== null &&
                                                    group.rule !== 'all' && (
                                                        <span className="text-muted-foreground ml-2 text-xs font-normal">
                                                            {t(
                                                                'kyc.upload_on_behalf.any_one',
                                                            )}
                                                        </span>
                                                    )}
                                            </legend>
                                        )}

                                        {group.items.map((target) => (
                                            <FormField
                                                key={target.value}
                                                label={target.label}
                                                error={
                                                    tooLarge[target.value]
                                                        ? t(
                                                              'kyc.upload_on_behalf.too_large',
                                                              { limit },
                                                          )
                                                        : errors[
                                                              `files.${target.value}`
                                                          ]
                                                }
                                                hint={
                                                    target.required
                                                        ? t(
                                                              'kyc.upload_on_behalf.required',
                                                          )
                                                        : t(
                                                              'kyc.upload_on_behalf.optional',
                                                          )
                                                }
                                            >
                                                {(field) => (
                                                    <div className="space-y-1.5">
                                                        {target.uploaded && (
                                                            <StatusPill
                                                                tone="success"
                                                                label={t(
                                                                    'kyc.upload_on_behalf.already',
                                                                )}
                                                            />
                                                        )}
                                                        {target.kind ===
                                                        'file' ? (
                                                            <Input
                                                                {...field}
                                                                type="file"
                                                                name={`files[${target.value}]`}
                                                                onChange={(
                                                                    event,
                                                                ) => {
                                                                    const file =
                                                                        event
                                                                            .target
                                                                            .files?.[0];

                                                                    setChosen(
                                                                        (
                                                                            current,
                                                                        ) => ({
                                                                            ...current,
                                                                            [target.value]:
                                                                                file !==
                                                                                undefined,
                                                                        }),
                                                                    );
                                                                    setTooLarge(
                                                                        (
                                                                            current,
                                                                        ) => ({
                                                                            ...current,
                                                                            [target.value]:
                                                                                maxKb !==
                                                                                    null &&
                                                                                (file?.size ??
                                                                                    0) >
                                                                                    maxKb *
                                                                                        1024,
                                                                        }),
                                                                    );
                                                                }}
                                                            />
                                                        ) : target.kind ===
                                                          'declaration' ? (
                                                            <input
                                                                id={field.id}
                                                                type="checkbox"
                                                                name={`values[${target.value}]`}
                                                                value="1"
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    setChosen(
                                                                        (
                                                                            current,
                                                                        ) => ({
                                                                            ...current,
                                                                            [target.value]:
                                                                                event
                                                                                    .target
                                                                                    .checked,
                                                                        }),
                                                                    )
                                                                }
                                                            />
                                                        ) : (
                                                            <Input
                                                                {...field}
                                                                type="text"
                                                                name={`values[${target.value}]`}
                                                                maxLength={255}
                                                                autoComplete="off"
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    setChosen(
                                                                        (
                                                                            current,
                                                                        ) => ({
                                                                            ...current,
                                                                            [target.value]:
                                                                                event.target.value.trim() !==
                                                                                '',
                                                                        }),
                                                                    )
                                                                }
                                                            />
                                                        )}
                                                    </div>
                                                )}
                                            </FormField>
                                        ))}
                                    </fieldset>
                                ))}

                                <FormField
                                    label={t('kyc.upload_on_behalf.reason')}
                                    description={t(
                                        'kyc.upload_on_behalf.reason_help',
                                    )}
                                    error={errors.reason}
                                    required
                                >
                                    {(field) => (
                                        <ReasonTextarea
                                            context="account"
                                            {...field}
                                            name="reason"
                                            rows={2}
                                            className={textareaClasses}
                                            required
                                        />
                                    )}
                                </FormField>

                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        name="submit"
                                        value="1"
                                    />
                                    {t('kyc.upload_on_behalf.submit_after')}
                                </label>

                                {canVerify && (
                                    <label className="flex items-start gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            name="verify"
                                            value="1"
                                            className="mt-1"
                                        />
                                        <span>
                                            <span className="font-medium">
                                                {t(
                                                    'kyc.upload_on_behalf.verify',
                                                )}
                                            </span>
                                            <span className="text-muted-foreground block text-xs">
                                                {t(
                                                    'kyc.upload_on_behalf.verify_help',
                                                )}
                                            </span>
                                        </span>
                                    </label>
                                )}

                                <SubmitButton
                                    processing={processing}
                                    disabled={processing || anyTooLarge}
                                >
                                    {t('kyc.upload_on_behalf.submit')}
                                </SubmitButton>
                            </>
                        )}
                    </Form>
                )}
            </DialogContent>
        </Dialog>
    );
}
