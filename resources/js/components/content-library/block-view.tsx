import { ExternalLink } from 'lucide-react';
import { useTranslation } from '@/hooks/use-translation';
import type { PresentedBlock } from '@/types/content-library';

/**
 * Library content as a reader sees it. Every block is rendered as data: text
 * keeps its line breaks and is never interpreted as markup, links open in a new
 * tab without handing over the opener, and a video is framed only when it is an
 * upload or a YouTube / Vimeo link the server has already turned into an
 * embeddable address — anything else is just a link.
 */
export default function BlockView({ blocks }: { blocks: PresentedBlock[] }) {
    const { t } = useTranslation();

    return (
        <div className="space-y-4">
            {blocks.map((block, index) => {
                switch (block.type) {
                    case 'text':
                        return (
                            <p
                                key={index}
                                className="text-sm leading-relaxed whitespace-pre-line"
                            >
                                {block.text}
                            </p>
                        );

                    case 'image':
                        return (
                            <figure key={index} className="space-y-1.5">
                                <img
                                    src={block.url}
                                    alt={block.alt ?? ''}
                                    loading="lazy"
                                    className="max-h-[28rem] max-w-full rounded-md border object-contain"
                                />
                                {block.caption && (
                                    <figcaption className="text-muted-foreground text-xs">
                                        {block.caption}
                                    </figcaption>
                                )}
                            </figure>
                        );

                    case 'video':
                        return (
                            <figure key={index} className="space-y-1.5">
                                {block.mime_type ? (
                                    <video
                                        src={block.url}
                                        controls
                                        preload="metadata"
                                        className="max-h-[28rem] w-full max-w-2xl rounded-md border bg-black"
                                    />
                                ) : block.embed_url ? (
                                    <iframe
                                        src={block.embed_url}
                                        title={
                                            block.caption ??
                                            t('content_library.video')
                                        }
                                        loading="lazy"
                                        allowFullScreen
                                        referrerPolicy="strict-origin-when-cross-origin"
                                        className="aspect-video w-full max-w-2xl rounded-md border"
                                    />
                                ) : (
                                    <a
                                        href={block.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-primary inline-flex items-center gap-1.5 text-sm underline"
                                    >
                                        <ExternalLink
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        {t('content_library.watch_video')}
                                    </a>
                                )}
                                {block.caption && (
                                    <figcaption className="text-muted-foreground text-xs">
                                        {block.caption}
                                    </figcaption>
                                )}
                            </figure>
                        );

                    case 'link':
                        return (
                            <a
                                key={index}
                                href={block.url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="bg-muted/50 hover:bg-muted flex items-start gap-2 rounded-md border px-3 py-2.5 text-sm"
                            >
                                <ExternalLink
                                    className="text-primary mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <span className="min-w-0">
                                    <span className="text-primary block font-medium underline">
                                        {block.label}
                                    </span>
                                    {block.description && (
                                        <span className="text-muted-foreground block text-xs">
                                            {block.description}
                                        </span>
                                    )}
                                </span>
                            </a>
                        );
                }
            })}
        </div>
    );
}
