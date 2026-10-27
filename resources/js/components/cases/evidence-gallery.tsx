import { Download, ExternalLink, FileText } from 'lucide-react';
import { useTranslation } from '@/hooks/use-translation';
import type { EvidenceFile, LegacyEvidence } from '@/types/cases';

function size(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${Math.round(bytes / 1024)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * The evidence on a case (D35): the files, to open or download, and — beside
 * them, as they were written — the references the earlier way recorded.
 *
 * A file is only ever reached through the signed, short-lived links the server
 * gave this person; there is no address of its own to copy and keep. An image's
 * preview is bounded so it cannot overflow a phone, and a PDF is a plain link.
 */
export default function EvidenceGallery({
    files,
    legacy = [],
}: {
    files: EvidenceFile[];
    legacy?: LegacyEvidence[];
}) {
    const { t, locale } = useTranslation();

    if (files.length === 0 && legacy.length === 0) {
        return (
            <p className="text-muted-foreground text-sm">
                {t('cases.evidence.none')}
            </p>
        );
    }

    return (
        <div className="space-y-4">
            {files.length > 0 && (
                <ul className="grid gap-3 sm:grid-cols-2">
                    {files.map((file) => (
                        <li
                            key={file.id}
                            className="border-border flex min-w-0 gap-3 rounded-lg border p-3"
                        >
                            <a
                                href={file.preview_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                aria-label={t('cases.evidence.open', {
                                    name: file.name,
                                })}
                                className="bg-muted text-muted-foreground flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-md border"
                            >
                                {file.is_image ? (
                                    <img
                                        src={file.preview_url}
                                        alt={file.name}
                                        loading="lazy"
                                        className="size-full object-cover"
                                    />
                                ) : (
                                    <FileText
                                        aria-hidden="true"
                                        className="size-6"
                                    />
                                )}
                            </a>
                            <div className="min-w-0 flex-1 space-y-1 text-sm">
                                <div className="truncate font-medium">
                                    {file.name}
                                </div>
                                <div className="text-muted-foreground text-xs">
                                    {file.purpose_label} ·{' '}
                                    {t(`cases.actors.${file.uploaded_by}`)} ·{' '}
                                    {size(file.size_bytes)} ·{' '}
                                    {new Date(
                                        file.uploaded_at,
                                    ).toLocaleDateString(locale)}
                                </div>
                                {file.note && (
                                    <p className="text-xs break-words">
                                        {file.note}
                                    </p>
                                )}
                                <div className="flex flex-wrap gap-3 text-xs">
                                    <a
                                        href={file.preview_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex items-center gap-1 underline-offset-4 hover:underline"
                                    >
                                        <ExternalLink
                                            aria-hidden="true"
                                            className="size-3"
                                        />
                                        {t('cases.evidence.view')}
                                    </a>
                                    <a
                                        href={file.download_url}
                                        className="inline-flex items-center gap-1 underline-offset-4 hover:underline"
                                    >
                                        <Download
                                            aria-hidden="true"
                                            className="size-3"
                                        />
                                        {t('cases.evidence.download')}
                                    </a>
                                </div>
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {legacy.length > 0 && (
                <div className="space-y-1">
                    <h3 className="text-sm font-medium">
                        {t('cases.evidence.earlier_references')}
                    </h3>
                    <ul className="text-muted-foreground list-disc space-y-0.5 pl-5 text-sm">
                        {legacy.map((item) => (
                            <li key={item.reference} className="break-words">
                                {item.reference}
                                {item.note ? ` — ${item.note}` : ''}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
