import { FileText, Upload, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import FormField from '@/components/forms/form-field';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

function formatSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * File input with preview and upload progress (§33.5).
 *
 * Used for KYC documents, so two things matter beyond the usual. The preview is
 * built from a local object URL of the file the user just chose — it never
 * fetches from a URL, because submitted KYC documents are stored privately and
 * are only ever served through an authorised, logged endpoint (§7.5). And the
 * object URL is revoked when it is replaced or the field unmounts; leaking them
 * pins the file in memory for the life of the page.
 *
 * Size and type are checked here for a fast, clear message, and again on the
 * server, which is the check that counts.
 */
export default function FileField({
    label,
    name,
    accept,
    maxSizeMb,
    required = false,
    description,
    error,
    progress,
    onChange,
}: {
    label: string;
    name: string;
    /** e.g. "image/jpeg,image/png,application/pdf" */
    accept?: string;
    maxSizeMb?: number;
    required?: boolean;
    description?: string;
    error?: string;
    /** Upload progress 0–100 while the form is submitting. */
    progress?: number | null;
    onChange?: (file: File | null) => void;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [file, setFile] = useState<File | null>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const [localError, setLocalError] = useState<string | null>(null);

    // Release the object URL whenever it is replaced, and on unmount.
    useEffect(() => {
        if (!file || !file.type.startsWith('image/')) {
            setPreview(null);

            return;
        }

        const url = URL.createObjectURL(file);
        setPreview(url);

        return () => URL.revokeObjectURL(url);
    }, [file]);

    const accept_ = accept;

    const select = (next: File | null) => {
        setLocalError(null);

        if (next && maxSizeMb && next.size > maxSizeMb * 1024 * 1024) {
            setLocalError(
                `This file is ${formatSize(next.size)}. The limit is ${maxSizeMb} MB — try a smaller scan or photo.`,
            );
            setFile(null);
            onChange?.(null);

            return;
        }

        setFile(next);
        onChange?.(next);
    };

    const clear = () => {
        select(null);

        if (input.current) {
            input.current.value = '';
        }
    };

    const uploading = progress !== null && progress !== undefined;

    return (
        <FormField
            label={label}
            required={required}
            description={description}
            error={error ?? localError ?? undefined}
            hint={maxSizeMb ? `Up to ${maxSizeMb} MB` : undefined}
        >
            {(field) => (
                <div>
                    <input
                        {...field}
                        ref={input}
                        type="file"
                        name={name}
                        accept={accept_}
                        className="sr-only"
                        onChange={(event) =>
                            select(event.target.files?.[0] ?? null)
                        }
                    />

                    {!file ? (
                        <button
                            type="button"
                            onClick={() => input.current?.click()}
                            className={cn(
                                'border-input hover:border-brand hover:bg-brand-subtle/40 flex w-full items-center justify-center gap-2 rounded-md border border-dashed px-3 py-6',
                                'text-muted-foreground text-sm transition-colors',
                            )}
                        >
                            <Upload className="size-4" aria-hidden="true" />
                            Choose a file
                        </button>
                    ) : (
                        <div className="border-input flex items-center gap-3 rounded-md border p-2.5">
                            {preview ? (
                                <img
                                    src={preview}
                                    alt=""
                                    className="border-border size-11 shrink-0 rounded border object-cover"
                                />
                            ) : (
                                <div className="bg-muted text-muted-foreground flex size-11 shrink-0 items-center justify-center rounded">
                                    <FileText
                                        className="size-5"
                                        aria-hidden="true"
                                    />
                                </div>
                            )}

                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium">
                                    {file.name}
                                </p>
                                <p className="text-muted-foreground text-xs tabular-nums">
                                    {formatSize(file.size)}
                                </p>

                                {uploading && (
                                    <div
                                        className="bg-muted mt-1.5 h-1 overflow-hidden rounded-full"
                                        role="progressbar"
                                        aria-valuenow={progress}
                                        aria-valuemin={0}
                                        aria-valuemax={100}
                                        aria-label={`Uploading ${file.name}`}
                                    >
                                        <div
                                            className="bg-brand h-full transition-[width] duration-200"
                                            style={{ width: `${progress}%` }}
                                        />
                                    </div>
                                )}
                            </div>

                            {!uploading && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-7 shrink-0"
                                    onClick={clear}
                                    aria-label={`Remove ${file.name}`}
                                >
                                    <X className="size-3.5" />
                                </Button>
                            )}
                        </div>
                    )}
                </div>
            )}
        </FormField>
    );
}
