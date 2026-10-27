import { Paperclip, X } from 'lucide-react';
import { useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { xsrfToken } from '@/lib/xsrf';
import type { EvidenceFile } from '@/types/cases';

type Limits = { max_bytes: number; max_per_case: number; mime_types: string[] };

type Attachment =
    | { state: 'uploading'; key: string; name: string; progress: number }
    | { state: 'ready'; key: string; file: EvidenceFile }
    | { state: 'failed'; key: string; name: string; message: string };

/**
 * Attach evidence to a request that is still being written (D35).
 *
 * Each file goes up the moment it is chosen, with a progress bar, as the Partner's
 * own **draft**: private, belonging to this order and to them alone, and not yet
 * part of any case. It can be taken back until the request is sent; sending the
 * request attaches the ones that are left and from then on they are history. The
 * ids of the drafts are carried in hidden fields, so what is submitted is only
 * which drafts, never a file path.
 *
 * Type and size are checked here for a fast, clear message and again on the
 * server, which reads the type from the file's own bytes and is the check that
 * counts.
 */
export default function EvidenceDraftUploader({
    orderId,
    uploadUrl,
    deleteUrl,
    limits,
    initial = [],
    error,
}: {
    orderId: string;
    uploadUrl: string;
    /** A function giving the URL that removes one draft, from its id. */
    deleteUrl: (id: string) => string;
    limits: Limits;
    initial?: EvidenceFile[];
    error?: string;
}) {
    const { t } = useTranslation();
    const input = useRef<HTMLInputElement>(null);
    const [items, setItems] = useState<Attachment[]>(
        initial.map((file) => ({ state: 'ready', key: file.id, file })),
    );

    const megabytes = Math.max(1, Math.floor(limits.max_bytes / (1024 * 1024)));

    const upload = (file: File) => {
        const key = crypto.randomUUID();

        if (!limits.mime_types.includes(file.type)) {
            setItems((all) => [
                ...all,
                {
                    state: 'failed',
                    key,
                    name: file.name,
                    message: t('cases.evidence.errors.type'),
                },
            ]);

            return;
        }

        if (file.size > limits.max_bytes) {
            setItems((all) => [
                ...all,
                {
                    state: 'failed',
                    key,
                    name: file.name,
                    message: t('cases.evidence.errors.size', {
                        mb: String(megabytes),
                    }),
                },
            ]);

            return;
        }

        setItems((all) => [
            ...all,
            { state: 'uploading', key, name: file.name, progress: 0 },
        ]);

        const body = new FormData();
        body.append('order', orderId);
        body.append('file', file);

        const request = new XMLHttpRequest();
        request.open('POST', uploadUrl);
        request.setRequestHeader('Accept', 'application/json');
        request.setRequestHeader('X-XSRF-TOKEN', xsrfToken());
        request.upload.onprogress = (event) => {
            if (event.lengthComputable) {
                const progress = Math.round((event.loaded / event.total) * 100);
                setItems((all) =>
                    all.map((item) =>
                        item.key === key && item.state === 'uploading'
                            ? { ...item, progress }
                            : item,
                    ),
                );
            }
        };
        request.onload = () => {
            if (request.status === 201) {
                const saved = JSON.parse(request.responseText) as EvidenceFile;
                setItems((all) =>
                    all.map((item) =>
                        item.key === key
                            ? { state: 'ready', key, file: saved }
                            : item,
                    ),
                );

                return;
            }

            let message = t('cases.evidence.errors.failed');

            try {
                message =
                    (JSON.parse(request.responseText) as { message?: string })
                        .message ?? message;
            } catch {
                // The generic message stands.
            }

            setItems((all) =>
                all.map((item) =>
                    item.key === key
                        ? { state: 'failed', key, name: file.name, message }
                        : item,
                ),
            );
        };
        request.onerror = () =>
            setItems((all) =>
                all.map((item) =>
                    item.key === key
                        ? {
                              state: 'failed',
                              key,
                              name: file.name,
                              message: t('cases.evidence.errors.failed'),
                          }
                        : item,
                ),
            );
        request.send(body);
    };

    const remove = async (item: Attachment) => {
        if (item.state === 'ready') {
            await fetch(deleteUrl(item.file.id), {
                method: 'DELETE',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
            });
        }

        setItems((all) => all.filter((entry) => entry.key !== item.key));
    };

    const ready = items.filter(
        (item): item is Extract<Attachment, { state: 'ready' }> =>
            item.state === 'ready',
    );
    const full = ready.length >= limits.max_per_case;

    return (
        <div className="space-y-3">
            {ready.map((item) => (
                <input
                    key={item.key}
                    type="hidden"
                    name="evidence[]"
                    value={item.file.id}
                />
            ))}

            <ul className="space-y-2">
                {items.map((item) => (
                    <li
                        key={item.key}
                        className="border-border flex items-center gap-3 rounded-lg border p-2 text-sm"
                    >
                        <Paperclip
                            aria-hidden="true"
                            className="text-muted-foreground size-4 shrink-0"
                        />
                        <div className="min-w-0 flex-1">
                            <div className="truncate">
                                {item.state === 'ready'
                                    ? item.file.name
                                    : item.name}
                            </div>
                            {item.state === 'uploading' && (
                                <div
                                    role="progressbar"
                                    aria-valuemin={0}
                                    aria-valuemax={100}
                                    aria-valuenow={item.progress}
                                    aria-label={t('cases.evidence.uploading')}
                                    className="bg-muted mt-1 h-1.5 overflow-hidden rounded-full"
                                >
                                    <div
                                        className="bg-primary h-full transition-[width]"
                                        style={{ width: `${item.progress}%` }}
                                    />
                                </div>
                            )}
                            {item.state === 'failed' && (
                                <InputError message={item.message} />
                            )}
                        </div>
                        {item.state === 'uploading' ? (
                            <Spinner className="size-4" />
                        ) : (
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                className="size-7"
                                aria-label={t('cases.evidence.remove')}
                                onClick={() => void remove(item)}
                            >
                                <X aria-hidden="true" className="size-4" />
                            </Button>
                        )}
                    </li>
                ))}
            </ul>

            <input
                ref={input}
                type="file"
                accept={limits.mime_types.join(',')}
                multiple
                className="sr-only"
                aria-label={t('cases.evidence.choose')}
                onChange={(event) => {
                    Array.from(event.target.files ?? []).forEach(upload);
                    event.target.value = '';
                }}
                disabled={full}
            />
            <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={full}
                onClick={() => input.current?.click()}
            >
                <Paperclip aria-hidden="true" />
                {t('cases.evidence.add')}
            </Button>
            <p className="text-muted-foreground text-xs">
                {t('cases.evidence.hint', {
                    mb: String(megabytes),
                    count: String(limits.max_per_case),
                })}
            </p>
            <InputError message={error} />
        </div>
    );
}
