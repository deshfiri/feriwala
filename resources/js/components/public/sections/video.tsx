import { Play } from 'lucide-react';
import { useState } from 'react';
import SectionContainer from '@/components/public/section-container';
import SectionMedia from '@/components/public/sections/section-media';
import { useTranslation } from '@/hooks/use-translation';
import { resolveVideoEmbedUrl, videoProviderLabel } from '@/lib/cms-video';
import type { CmsMedia } from '@/types';

type VideoContent = {
    heading?: string | null;
    body?: string | null;
    video_url: string;
    poster_media: CmsMedia;
};

/**
 * A third-party video, embedded only after a visitor asks for it (§34,
 * Stage 7 addendum). The poster image loads like any other below-the-fold
 * media; the YouTube/Vimeo player itself — a real weight and privacy cost —
 * is never fetched until the play button is pressed, and its `src` is
 * rebuilt from an extracted id ({@see resolveVideoEmbedUrl}) rather than the
 * stored URL passed straight into an iframe.
 */
export default function Video({ content }: { content: VideoContent }) {
    const { t } = useTranslation();
    const [playing, setPlaying] = useState(false);
    const embedUrl = resolveVideoEmbedUrl(content.video_url);

    return (
        <SectionContainer>
            {content.heading && (
                <h2 className="text-center text-3xl font-semibold tracking-tight text-balance">
                    {content.heading}
                </h2>
            )}

            {content.body && (
                <p className="text-muted-foreground mx-auto mt-3 max-w-2xl text-center text-balance">
                    {content.body}
                </p>
            )}

            <div className="bg-card border-border relative mx-auto mt-8 aspect-video max-w-4xl overflow-hidden rounded-2xl border">
                {playing && embedUrl ? (
                    <iframe
                        src={embedUrl}
                        title={content.heading ?? t('public.video.title')}
                        className="size-full"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                        allowFullScreen
                        loading="lazy"
                    />
                ) : (
                    <>
                        <SectionMedia media={content.poster_media} />
                        {embedUrl ? (
                            <button
                                type="button"
                                onClick={() => setPlaying(true)}
                                aria-label={t('public.video.play')}
                                className="focus-visible:ring-ring absolute inset-0 flex items-center justify-center bg-black/30 transition hover:bg-black/40 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                            >
                                <span className="flex size-16 items-center justify-center rounded-full bg-white/90 text-black shadow-lg">
                                    <Play
                                        aria-hidden="true"
                                        className="ml-1 size-7 fill-current"
                                    />
                                </span>
                            </button>
                        ) : (
                            <a
                                href={content.video_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="focus-visible:ring-ring absolute inset-0 flex items-center justify-center bg-black/30 text-sm font-medium text-white transition hover:bg-black/40 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                            >
                                {t('public.video.watch_on', {
                                    provider: videoProviderLabel(
                                        content.video_url,
                                    ),
                                })}
                            </a>
                        )}
                    </>
                )}
            </div>
        </SectionContainer>
    );
}
