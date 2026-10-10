import {
    ArrowDown,
    ArrowUp,
    Image as ImageIcon,
    Link2,
    Trash2,
    Type,
    Video,
} from 'lucide-react';
import { useState } from 'react';
import ContentLibraryController from '@/actions/App/Http/Controllers/Admin/ContentLibraryController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import type { EditorBlock, LibraryLimits } from '@/types/content-library';

type Props = {
    blocks: EditorBlock[];
    onChange: (next: EditorBlock[]) => void;
    limits: LibraryLimits;
    error?: string;
};

const textareaClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

const newKey = () => Math.random().toString(36).slice(2, 10);

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * The content editor: an ordered stack of blocks — text, image, video, link —
 * added from one toolbar, reordered with the arrows and removed in place.
 *
 * Blocks are data, not HTML. What is typed here is stored and shown as plain
 * text, an image or video is an uploaded managed file (stored the moment it is
 * chosen, so it previews straight away), and a link is a full http(s) address.
 */
export default function BlockEditor({
    blocks,
    onChange,
    limits,
    error,
}: Props) {
    const { t } = useTranslation();
    const [uploading, setUploading] = useState<Record<string, boolean>>({});
    const [uploadErrors, setUploadErrors] = useState<Record<string, string>>(
        {},
    );

    const full = blocks.length >= limits.max_blocks;

    const add = (type: EditorBlock['type']) => {
        const key = newKey();

        const block: EditorBlock =
            type === 'text'
                ? { key, type, text: '' }
                : type === 'image'
                  ? { key, type, file_id: '', url: '', alt: '', caption: '' }
                  : type === 'video'
                    ? {
                          key,
                          type,
                          file_id: '',
                          url: '',
                          source: 'upload',
                          caption: '',
                      }
                    : { key, type, url: '', label: '', description: '' };

        onChange([...blocks, block]);
    };

    const patch = (key: string, changes: Partial<EditorBlock>) =>
        onChange(
            blocks.map((block) =>
                block.key === key
                    ? ({ ...block, ...changes } as EditorBlock)
                    : block,
            ),
        );

    const move = (index: number, direction: -1 | 1) => {
        const target = index + direction;

        if (target < 0 || target >= blocks.length) {
            return;
        }

        const next = [...blocks];
        [next[index], next[target]] = [next[target], next[index]];
        onChange(next);
    };

    const upload = async (
        key: string,
        kind: 'image' | 'video',
        file: File | undefined,
    ) => {
        if (!file) {
            return;
        }

        setUploading((current) => ({ ...current, [key]: true }));
        setUploadErrors(({ [key]: _dropped, ...rest }) => rest);

        const body = new FormData();
        body.append('kind', kind);
        body.append('file', file);

        try {
            const response = await fetch(
                ContentLibraryController.upload.url(),
                {
                    method: 'POST',
                    body,
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': xsrfToken(),
                    },
                },
            );

            const json = (await response.json()) as {
                id?: string;
                url?: string;
                message?: string;
                errors?: Record<string, string[]>;
            };

            if (!response.ok || !json.id || !json.url) {
                throw new Error(
                    json.errors?.file?.[0] ??
                        json.message ??
                        t('content_library.editor.upload_failed'),
                );
            }

            patch(key, { file_id: json.id, url: json.url });
        } catch (failure) {
            setUploadErrors((current) => ({
                ...current,
                [key]:
                    failure instanceof Error
                        ? failure.message
                        : t('content_library.editor.upload_failed'),
            }));
        } finally {
            setUploading((current) => ({ ...current, [key]: false }));
        }
    };

    return (
        <div className="space-y-3">
            <div
                role="toolbar"
                aria-label={t('content_library.editor.toolbar')}
                className="bg-muted/40 sticky top-0 z-10 flex flex-wrap items-center gap-2 rounded-lg border p-2"
            >
                <span className="text-muted-foreground px-1 text-xs font-medium">
                    {t('content_library.editor.add')}
                </span>
                {(
                    [
                        ['text', Type],
                        ['image', ImageIcon],
                        ['video', Video],
                        ['link', Link2],
                    ] as const
                ).map(([type, Icon]) => (
                    <Button
                        key={type}
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={full}
                        onClick={() => add(type)}
                    >
                        <Icon className="size-4" aria-hidden="true" />
                        {t(`content_library.editor.type.${type}`)}
                    </Button>
                ))}
                <span className="text-muted-foreground ms-auto px-1 text-xs tabular-nums">
                    {blocks.length}/{limits.max_blocks}
                </span>
            </div>

            {error && (
                <p role="alert" className="text-danger text-sm">
                    {error}
                </p>
            )}

            {blocks.length === 0 && (
                <p className="text-muted-foreground rounded-lg border border-dashed p-6 text-center text-sm">
                    {t('content_library.editor.empty')}
                </p>
            )}

            <ol className="space-y-3">
                {blocks.map((block, index) => (
                    <li
                        key={block.key}
                        className="bg-card space-y-3 rounded-lg border p-3"
                    >
                        <div className="flex items-center justify-between gap-2">
                            <span className="text-sm font-medium">
                                {index + 1}.{' '}
                                {t(`content_library.editor.type.${block.type}`)}
                            </span>
                            <span className="flex items-center gap-1">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-7"
                                    disabled={index === 0}
                                    onClick={() => move(index, -1)}
                                    aria-label={t(
                                        'content_library.editor.move_up',
                                    )}
                                >
                                    <ArrowUp className="size-4" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-7"
                                    disabled={index === blocks.length - 1}
                                    onClick={() => move(index, 1)}
                                    aria-label={t(
                                        'content_library.editor.move_down',
                                    )}
                                >
                                    <ArrowDown className="size-4" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-7"
                                    onClick={() =>
                                        onChange(
                                            blocks.filter(
                                                (candidate) =>
                                                    candidate.key !== block.key,
                                            ),
                                        )
                                    }
                                    aria-label={t(
                                        'content_library.editor.remove',
                                    )}
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            </span>
                        </div>

                        {block.type === 'text' && (
                            <div className="grid gap-1.5">
                                <textarea
                                    value={block.text}
                                    onChange={(event) =>
                                        patch(block.key, {
                                            text: event.target.value,
                                        })
                                    }
                                    rows={5}
                                    maxLength={limits.text_max}
                                    placeholder={t(
                                        'content_library.editor.text_placeholder',
                                    )}
                                    aria-label={t(
                                        'content_library.editor.type.text',
                                    )}
                                    className={textareaClass}
                                />
                                <p className="text-muted-foreground text-end text-xs tabular-nums">
                                    {block.text.length}/{limits.text_max}
                                </p>
                            </div>
                        )}

                        {block.type === 'image' && (
                            <div className="space-y-3">
                                <MediaPicker
                                    kind="image"
                                    url={block.url}
                                    busy={uploading[block.key] === true}
                                    error={uploadErrors[block.key]}
                                    accept={limits.image_types.join(',')}
                                    hint={t(
                                        'content_library.editor.image_hint',
                                        {
                                            mb: limits.image_max_mb,
                                        },
                                    )}
                                    onFile={(file) =>
                                        void upload(block.key, 'image', file)
                                    }
                                />
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor={`alt-${block.key}`}>
                                            {t('content_library.editor.alt')}
                                        </Label>
                                        <Input
                                            id={`alt-${block.key}`}
                                            value={block.alt}
                                            maxLength={255}
                                            onChange={(event) =>
                                                patch(block.key, {
                                                    alt: event.target.value,
                                                })
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor={`cap-${block.key}`}>
                                            {t(
                                                'content_library.editor.caption',
                                            )}
                                        </Label>
                                        <Input
                                            id={`cap-${block.key}`}
                                            value={block.caption}
                                            maxLength={500}
                                            onChange={(event) =>
                                                patch(block.key, {
                                                    caption: event.target.value,
                                                })
                                            }
                                        />
                                    </div>
                                </div>
                            </div>
                        )}

                        {block.type === 'video' && (
                            <div className="space-y-3">
                                <div
                                    role="radiogroup"
                                    className="flex flex-wrap gap-4 text-sm"
                                >
                                    {(['upload', 'link'] as const).map(
                                        (source) => (
                                            <label
                                                key={source}
                                                className="flex items-center gap-2"
                                            >
                                                <input
                                                    type="radio"
                                                    name={`source-${block.key}`}
                                                    checked={
                                                        block.source === source
                                                    }
                                                    onChange={() =>
                                                        patch(block.key, {
                                                            source,
                                                            file_id: '',
                                                            url: '',
                                                        })
                                                    }
                                                    className="accent-brand"
                                                />
                                                {t(
                                                    `content_library.editor.video_${source}`,
                                                )}
                                            </label>
                                        ),
                                    )}
                                </div>

                                {block.source === 'upload' ? (
                                    <MediaPicker
                                        kind="video"
                                        url={block.url}
                                        busy={uploading[block.key] === true}
                                        error={uploadErrors[block.key]}
                                        accept={limits.video_types.join(',')}
                                        hint={t(
                                            'content_library.editor.video_hint',
                                            { mb: limits.video_max_mb },
                                        )}
                                        onFile={(file) =>
                                            void upload(
                                                block.key,
                                                'video',
                                                file,
                                            )
                                        }
                                    />
                                ) : (
                                    <div className="grid gap-1.5">
                                        <Label htmlFor={`vurl-${block.key}`}>
                                            {t(
                                                'content_library.editor.video_url',
                                            )}
                                        </Label>
                                        <Input
                                            id={`vurl-${block.key}`}
                                            type="url"
                                            inputMode="url"
                                            placeholder="https://www.youtube.com/watch?v=…"
                                            value={block.url}
                                            maxLength={2000}
                                            onChange={(event) =>
                                                patch(block.key, {
                                                    url: event.target.value,
                                                })
                                            }
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            {t(
                                                'content_library.editor.video_url_hint',
                                            )}
                                        </p>
                                    </div>
                                )}

                                <div className="grid gap-1.5">
                                    <Label htmlFor={`vcap-${block.key}`}>
                                        {t('content_library.editor.caption')}
                                    </Label>
                                    <Input
                                        id={`vcap-${block.key}`}
                                        value={block.caption}
                                        maxLength={500}
                                        onChange={(event) =>
                                            patch(block.key, {
                                                caption: event.target.value,
                                            })
                                        }
                                    />
                                </div>
                            </div>
                        )}

                        {block.type === 'link' && (
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="grid gap-1.5 sm:col-span-2">
                                    <Label htmlFor={`url-${block.key}`}>
                                        {t('content_library.editor.link_url')}
                                    </Label>
                                    <Input
                                        id={`url-${block.key}`}
                                        type="url"
                                        inputMode="url"
                                        placeholder="https://"
                                        value={block.url}
                                        maxLength={2000}
                                        onChange={(event) =>
                                            patch(block.key, {
                                                url: event.target.value,
                                            })
                                        }
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor={`label-${block.key}`}>
                                        {t('content_library.editor.link_label')}
                                    </Label>
                                    <Input
                                        id={`label-${block.key}`}
                                        value={block.label}
                                        maxLength={255}
                                        onChange={(event) =>
                                            patch(block.key, {
                                                label: event.target.value,
                                            })
                                        }
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor={`desc-${block.key}`}>
                                        {t(
                                            'content_library.editor.link_description',
                                        )}
                                    </Label>
                                    <Input
                                        id={`desc-${block.key}`}
                                        value={block.description}
                                        maxLength={500}
                                        onChange={(event) =>
                                            patch(block.key, {
                                                description: event.target.value,
                                            })
                                        }
                                    />
                                </div>
                            </div>
                        )}
                    </li>
                ))}
            </ol>
        </div>
    );
}

function MediaPicker({
    kind,
    url,
    busy,
    error,
    accept,
    hint,
    onFile,
}: {
    kind: 'image' | 'video';
    url: string;
    busy: boolean;
    error?: string;
    accept: string;
    hint: string;
    onFile: (file: File | undefined) => void;
}) {
    const { t } = useTranslation();

    return (
        <div className="space-y-2">
            {url !== '' &&
                (kind === 'image' ? (
                    <img
                        src={url}
                        alt=""
                        className="max-h-56 rounded-md border object-contain"
                    />
                ) : (
                    <video
                        src={url}
                        controls
                        preload="metadata"
                        className="max-h-56 rounded-md border bg-black"
                    />
                ))}

            <Input
                type="file"
                accept={accept}
                disabled={busy}
                aria-label={t(`content_library.editor.choose_${kind}`)}
                onChange={(event) => {
                    onFile(event.target.files?.[0]);
                    event.target.value = '';
                }}
            />

            <p className="text-muted-foreground text-xs" aria-live="polite">
                {busy ? t('content_library.editor.uploading') : hint}
            </p>

            {error && (
                <p role="alert" className="text-danger text-sm">
                    {error}
                </p>
            )}
        </div>
    );
}
