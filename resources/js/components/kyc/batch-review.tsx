import { router } from '@inertiajs/react';
import {
    Camera,
    Check,
    CheckCheck,
    ExternalLink,
    FileText,
    Lock,
    X,
} from 'lucide-react';
import { createContext, useContext, useState } from 'react';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import type {
    KycBatchEvidence,
    KycBatchForReview,
    KycBatchGroup,
    KycBatchOption,
    KycBatchSlot,
} from '@/types';

type Verdict = {
    requirement: string;
    slot: string;
    status: 'accepted' | 'rejected';
    note?: string;
};

/**
 * How a typed value is read in full, for those allowed to. The ordinary payload
 * carries only the masked form; revealing is a separate request that the server
 * checks and records every time, and the value is held here only until the
 * reviewer hides it or leaves the page.
 */
type RevealAccess = {
    allowed: boolean;
    url: (requirement: string, slot: string) => string;
};

const RevealContext = createContext<RevealAccess>({
    allowed: false,
    url: () => '#',
});

const formatSize = (bytes: number): string =>
    bytes < 1024 ? `${bytes} B` : `${Math.round(bytes / 1024)} KB`;

/**
 * A round on a KYC batch as a reviewer works it (D30): group by group, slot by
 * slot, each piece of evidence accepted or sent back with the instruction the
 * applicant will read.
 *
 * Nothing here decides anything. Whether a group is satisfied, what stands in the
 * way of approval and what the applicant will be told all come from the server;
 * this component shows them and posts the reviewer's verdicts. A file is only ever
 * a link to the route that authorises and records each opening (§7.5) — there is
 * no path, URL or thumbnail of a document in the payload.
 */
export default function BatchReview({
    batch,
    verdictUrl,
    documentUrl,
    revealUrl,
}: {
    batch: KycBatchForReview;
    /** Where verdicts are posted (the Partner or the Supplier endpoint). */
    verdictUrl: string;
    /** The authorised route that serves one document. */
    documentUrl: (documentId: string) => string;
    /** The route that returns one typed value in full (and records that it was read). */
    revealUrl: (requirement: string, slot: string) => string;
}) {
    const { t } = useTranslation();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const { review } = batch;

    const send = (verdicts: Verdict[], onDone?: () => void) =>
        router.post(verdictUrl, { verdicts } as never, {
            preserveScroll: true,
            onStart: () => {
                setBusy(true);
                setError(null);
            },
            onError: (errors) => setError(Object.values(errors)[0] ?? null),
            onSuccess: () => onDone?.(),
            onFinish: () => setBusy(false),
        });

    // Everything not yet judged and still current — "accept the rest".
    const pending = collectEvidence(batch).filter(
        (entry) => entry.current.verdict === null,
    );

    return (
        <RevealContext.Provider
            value={{ allowed: review.can_view_sensitive, url: revealUrl }}
        >
            <div className="space-y-6">
                <SectionCard
                    title={batch.name}
                    description={t('kyc.review_ui.subtitle', {
                        version: batch.version,
                    })}
                    actions={
                        review.can_judge && pending.length > 0 ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={busy}
                                onClick={() =>
                                    send(
                                        pending.map((entry) => ({
                                            requirement:
                                                entry.current.requirement,
                                            slot: entry.current.slot,
                                            status: 'accepted' as const,
                                        })),
                                    )
                                }
                            >
                                {busy ? (
                                    <Spinner className="size-4" />
                                ) : (
                                    <CheckCheck
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                )}
                                {t('kyc.review_ui.accept_all', {
                                    count: pending.length,
                                })}
                            </Button>
                        ) : undefined
                    }
                >
                    <div aria-live="polite" className="space-y-2">
                        {review.problems.length === 0 ? (
                            <p className="text-success flex items-center gap-2 text-sm font-medium">
                                <Check className="size-4" aria-hidden="true" />
                                {t('kyc.review_ui.ready')}
                            </p>
                        ) : (
                            <>
                                <p className="text-sm font-medium">
                                    {t('kyc.review_ui.before_approval')}
                                </p>
                                <ul className="text-muted-foreground list-disc space-y-0.5 pl-5 text-xs">
                                    {review.problems.map((line) => (
                                        <li key={line}>{line}</li>
                                    ))}
                                </ul>
                            </>
                        )}

                        {review.corrections.length > 0 && (
                            <div className="bg-warning-subtle mt-3 space-y-1 rounded-md p-3">
                                <p className="text-sm font-medium">
                                    {t('kyc.review_ui.corrections_title')}
                                </p>
                                <p className="text-muted-foreground text-xs">
                                    {t('kyc.review_ui.corrections_help')}
                                </p>
                                <ul className="list-disc space-y-0.5 pl-5 text-xs">
                                    {review.corrections.map((line) => (
                                        <li key={line}>{line}</li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {error && (
                            <p
                                role="alert"
                                className="text-danger text-sm font-medium"
                            >
                                {error}
                            </p>
                        )}
                    </div>
                </SectionCard>

                {batch.groups.map((group) => (
                    <ReviewGroup
                        key={group.key}
                        group={group}
                        canJudge={review.can_judge}
                        canOpen={review.can_open_documents}
                        busy={busy}
                        send={send}
                        documentUrl={documentUrl}
                    />
                ))}

                {batch.selfie && (
                    <SectionCard
                        title={t('kyc.review_ui.selfie')}
                        actions={
                            <StatusPill
                                tone={
                                    batch.selfie.required ? 'info' : 'neutral'
                                }
                                label={t(
                                    batch.selfie.required
                                        ? 'kyc.batch_form.required'
                                        : 'kyc.batch_form.optional',
                                )}
                            />
                        }
                    >
                        <SlotReview
                            requirement={batch.selfie.requirement}
                            slot={batch.selfie.slot}
                            canJudge={review.can_judge}
                            canOpen={review.can_open_documents}
                            busy={busy}
                            send={send}
                            documentUrl={documentUrl}
                        />
                    </SectionCard>
                )}
            </div>
        </RevealContext.Provider>
    );
}

/** Every piece of evidence on the round, flattened, for "accept the rest". */
function collectEvidence(
    batch: KycBatchForReview,
): { current: KycBatchEvidence }[] {
    const entries: { current: KycBatchEvidence }[] = [];

    for (const group of batch.groups) {
        for (const option of group.options) {
            // Only what counts: in a choosing group, the option the applicant picked.
            if (group.choose && !option.chosen) {
                continue;
            }

            for (const slot of option.slots) {
                if (slot.current) {
                    entries.push({ current: slot.current });
                }
            }
        }
    }

    const selfie = batch.selfie?.slot.current;

    if (selfie) {
        entries.push({ current: selfie });
    }

    return entries;
}

function ReviewGroup({
    group,
    canJudge,
    canOpen,
    busy,
    send,
    documentUrl,
}: {
    group: KycBatchGroup;
    canJudge: boolean;
    canOpen: boolean;
    busy: boolean;
    send: (verdicts: Verdict[], onDone?: () => void) => void;
    documentUrl: (documentId: string) => string;
}) {
    const { t } = useTranslation();

    const rule =
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
            description={rule}
            actions={
                <StatusPill
                    tone={group.satisfied ? 'success' : 'warning'}
                    label={
                        group.satisfied
                            ? t('kyc.batch_form.complete')
                            : t('kyc.batch_form.progress', {
                                  done: group.done,
                                  total: group.needed,
                              })
                    }
                />
            }
        >
            <div className="space-y-4">
                {group.options.map((option) => (
                    <ReviewOption
                        key={option.key}
                        group={group}
                        option={option}
                        canJudge={canJudge}
                        canOpen={canOpen}
                        busy={busy}
                        send={send}
                        documentUrl={documentUrl}
                    />
                ))}
            </div>
        </SectionCard>
    );
}

function ReviewOption({
    group,
    option,
    canJudge,
    canOpen,
    busy,
    send,
    documentUrl,
}: {
    group: KycBatchGroup;
    option: KycBatchOption;
    canJudge: boolean;
    canOpen: boolean;
    busy: boolean;
    send: (verdicts: Verdict[], onDone?: () => void) => void;
    documentUrl: (documentId: string) => string;
}) {
    const { t } = useTranslation();
    // An option the applicant did not choose in an "any one" group is shown, but
    // quietly: it does not count towards the group.
    const notChosen = group.choose && !option.chosen;

    return (
        <div
            className={
                notChosen
                    ? 'rounded-lg border border-dashed p-3 opacity-70'
                    : 'rounded-lg border p-3'
            }
        >
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-sm font-medium">{option.name}</h3>

                {notChosen ? (
                    <StatusPill
                        tone="neutral"
                        label={t('kyc.review_ui.not_chosen')}
                    />
                ) : (
                    <StatusPill
                        tone={option.complete ? 'success' : 'neutral'}
                        label={t('kyc.batch_form.items', {
                            provided: option.provided,
                            total: option.total,
                        })}
                    />
                )}
            </div>

            <ul className="divide-border divide-y">
                {option.slots.map((slot) => (
                    <li key={slot.key} className="py-3 first:pt-0 last:pb-0">
                        <SlotReview
                            requirement={option.key}
                            slot={slot}
                            canJudge={canJudge && !notChosen}
                            canOpen={canOpen}
                            busy={busy}
                            send={send}
                            documentUrl={documentUrl}
                        />
                    </li>
                ))}
            </ul>
        </div>
    );
}

function ValueView({
    requirement,
    slot,
    preview,
    typed,
}: {
    requirement: string;
    slot: string;
    preview: string;
    typed: boolean;
}) {
    const { t } = useTranslation();
    const access = useContext(RevealContext);
    const [value, setValue] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);

    const reveal = async () => {
        setBusy(true);
        setFailed(false);

        try {
            const response = await fetch(access.url(requirement, slot), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error('refused');
            }

            setValue((await response.json()).value as string);
        } catch {
            setFailed(true);
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="flex flex-wrap items-center gap-2">
            <p className="text-sm tabular-nums" aria-live="polite">
                {value ?? preview}
            </p>

            {typed && access.allowed && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    disabled={busy}
                    onClick={() => (value === null ? reveal() : setValue(null))}
                >
                    {value === null
                        ? t('kyc.review_ui.show_value')
                        : t('kyc.review_ui.hide_value')}
                </Button>
            )}

            {failed && (
                <span role="alert" className="text-danger text-xs">
                    {t('kyc.review_ui.reveal_failed')}
                </span>
            )}
        </div>
    );
}

function SlotReview({
    requirement,
    slot,
    canJudge,
    canOpen,
    busy,
    send,
    documentUrl,
}: {
    requirement: string;
    slot: KycBatchSlot;
    canJudge: boolean;
    canOpen: boolean;
    busy: boolean;
    send: (verdicts: Verdict[], onDone?: () => void) => void;
    documentUrl: (documentId: string) => string;
}) {
    const { t } = useTranslation();
    const [rejecting, setRejecting] = useState(false);
    const [note, setNote] = useState('');
    const current = slot.current;
    const noteId = `reject-note-${requirement}-${slot.key}`;

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap items-center gap-2">
                <p className="text-sm font-medium">{slot.label}</p>
                {!slot.required && (
                    <span className="text-muted-foreground text-xs">
                        {t('kyc.batch_form.optional')}
                    </span>
                )}
                {current?.verdict === 'accepted' && (
                    <StatusPill
                        tone="success"
                        label={t('kyc.review_ui.accepted')}
                    />
                )}
                {current?.verdict === 'rejected' && (
                    <StatusPill
                        tone="danger"
                        label={t('kyc.review_ui.rejected')}
                    />
                )}
                {current && current.verdict === null && (
                    <StatusPill
                        tone="warning"
                        label={t('kyc.review_ui.pending')}
                    />
                )}
            </div>

            {current === null ? (
                <p className="text-muted-foreground text-xs">
                    {t('kyc.review_ui.nothing_given')}
                </p>
            ) : (
                <div className="flex flex-wrap items-center gap-3">
                    {current.kind === 'file' ? (
                        <>
                            <FileText
                                className="text-muted-foreground size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm">
                                    {current.name}
                                </p>
                                <p className="text-muted-foreground flex flex-wrap items-center gap-2 text-xs tabular-nums">
                                    {current.size_bytes !== null &&
                                        formatSize(current.size_bytes)}
                                    {current.live && (
                                        <span className="inline-flex items-center gap-1">
                                            <Camera
                                                className="size-3"
                                                aria-hidden="true"
                                            />
                                            {t('kyc.batch_form.live_badge')}
                                        </span>
                                    )}
                                </p>
                            </div>

                            {canOpen && current.document ? (
                                <Button variant="ghost" size="sm" asChild>
                                    {/* A plain anchor: the response is a file,
                                        not a page, and the route authorises and
                                        records each opening. */}
                                    <a
                                        href={documentUrl(current.document)}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        <ExternalLink
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        {t('kyc.detail.view')}
                                    </a>
                                </Button>
                            ) : (
                                <span className="text-muted-foreground flex items-center gap-1.5 text-xs">
                                    <Lock
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {t('kyc.detail.documents_hidden')}
                                </span>
                            )}
                        </>
                    ) : (
                        <ValueView
                            requirement={requirement}
                            slot={slot.key}
                            preview={
                                current.value_preview ??
                                t('kyc.review_ui.declared')
                            }
                            typed={slot.evidence === 'value'}
                        />
                    )}
                </div>
            )}

            {current?.verdict === 'rejected' && current.note && (
                <p className="text-warning bg-warning-subtle rounded-md px-2.5 py-1.5 text-xs">
                    {current.note}
                </p>
            )}

            {canJudge && current !== null && !rejecting && (
                <div className="flex flex-wrap gap-2">
                    {current.verdict !== 'accepted' && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={busy}
                            onClick={() =>
                                send([
                                    {
                                        requirement,
                                        slot: slot.key,
                                        status: 'accepted',
                                    },
                                ])
                            }
                        >
                            <Check className="size-4" aria-hidden="true" />
                            {t('kyc.review_ui.accept')}
                        </Button>
                    )}
                    {current.verdict !== 'rejected' && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={busy}
                            onClick={() => setRejecting(true)}
                        >
                            <X className="size-4" aria-hidden="true" />
                            {t('kyc.review_ui.reject')}
                        </Button>
                    )}
                </div>
            )}

            {rejecting && (
                <div className="space-y-2">
                    <label htmlFor={noteId} className="text-xs font-medium">
                        {t('kyc.review_ui.note_label')}
                    </label>
                    <textarea
                        id={noteId}
                        rows={2}
                        maxLength={500}
                        value={note}
                        onChange={(event) => setNote(event.target.value)}
                        placeholder={t('kyc.review_ui.note_placeholder')}
                        className="border-input focus-visible:border-ring focus-visible:ring-ring/50 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                    />
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            size="sm"
                            disabled={busy || note.trim() === ''}
                            onClick={() =>
                                send(
                                    [
                                        {
                                            requirement,
                                            slot: slot.key,
                                            status: 'rejected',
                                            note,
                                        },
                                    ],
                                    () => {
                                        setRejecting(false);
                                        setNote('');
                                    },
                                )
                            }
                        >
                            {t('kyc.review_ui.confirm_reject')}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setRejecting(false)}
                        >
                            {t('kyc.review_ui.cancel')}
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}
