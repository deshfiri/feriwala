import { cn } from '@/lib/utils';
import type { CmsMedia, CmsMediaFit } from '@/types';

type SectionMediaProps = {
    media: CmsMedia;
    mobileMedia?: CmsMedia | null;
    fit?: CmsMediaFit;
    /** Only the primary hero image should ever set this — everything else is below the fold. */
    priority?: boolean;
    /**
     * True only for a `background`-positioned image sitting behind copy that
     * already says everything the image shows — the library's own alt text
     * describes the picture, not "background", so announcing it here would
     * be redundant with the heading a screen reader just read. Every other
     * use is genuine content and keeps its alt text.
     */
    decorative?: boolean;
    className?: string;
};

/**
 * Renders one already-frozen, already-safe media reference (§34, Stage 7
 * addendum) — always the CMS library's own public URL, never a storage path,
 * and never a remote/unapproved host. `mobileMedia`, when the section
 * defines one, swaps in below the `lg` breakpoint via `<picture>` so the
 * browser fetches only the variant it will actually show, never both.
 */
export default function SectionMedia({
    media,
    mobileMedia,
    fit,
    priority = false,
    decorative = false,
    className,
}: SectionMediaProps) {
    const dimensions = mobileMedia ?? media;

    const img = (
        <img
            src={mobileMedia ? mobileMedia.url : media.url}
            alt={decorative ? '' : media.alt}
            aria-hidden={decorative || undefined}
            width={dimensions.width ?? undefined}
            height={dimensions.height ?? undefined}
            loading={priority ? 'eager' : 'lazy'}
            fetchPriority={priority ? 'high' : undefined}
            decoding={priority ? 'sync' : 'async'}
            className={cn(
                'h-full w-full',
                fit === 'contain' ? 'object-contain' : 'object-cover',
                className,
            )}
        />
    );

    if (!mobileMedia) {
        return img;
    }

    return (
        <picture>
            <source media="(min-width: 1024px)" srcSet={media.url} />
            {img}
        </picture>
    );
}
