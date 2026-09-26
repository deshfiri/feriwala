import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import SectionContainer from '@/components/public/section-container';
import SectionMedia from '@/components/public/sections/section-media';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { CmsCta, CmsMedia, CmsMediaFit, CmsMediaPosition } from '@/types';

type HeroContent = {
    heading: string;
    subheading?: string | null;
    body?: string | null;
    primary_cta: CmsCta;
    secondary_cta?: CmsCta | null;
    media?: CmsMedia | null;
    mobile_media?: CmsMedia | null;
    media_position?: CmsMediaPosition;
    media_fit?: CmsMediaFit;
};

/**
 * The landing page's opening statement (§34). A split hero was the
 * `banij_landing` reference's own structure, but with no real explainer
 * video or trust-signal numbers to show yet, this stays a single centered
 * column rather than inventing a placeholder for the empty half.
 *
 * A hero image (Stage 7 addendum) is the one media reference on the whole
 * page allowed to load eagerly — it is always the first thing above the
 * fold — and is the only section that supports `background` positioning,
 * where the image fills the section behind a scrim rather than sitting
 * beside the copy.
 */
export default function Hero({ content }: { content: HeroContent }) {
    const position: CmsMediaPosition =
        content.media_position ?? (content.media ? 'right' : null);

    const copy = (
        <div
            className={cn(
                'mx-auto max-w-3xl text-center',
                content.media &&
                    position !== 'background' &&
                    'lg:mx-0 lg:max-w-none lg:text-left',
            )}
        >
            <h1 className="text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
                {content.heading}
            </h1>

            {content.subheading && (
                <p className="text-muted-foreground mt-4 text-lg text-balance">
                    {content.subheading}
                </p>
            )}

            {content.body && (
                <p className="text-muted-foreground mt-3 text-balance">
                    {content.body}
                </p>
            )}

            <div
                className={cn(
                    'mt-8 flex flex-wrap items-center justify-center gap-3',
                    content.media &&
                        position !== 'background' &&
                        'lg:justify-start',
                )}
            >
                <Button asChild size="lg">
                    <Link href={content.primary_cta.href}>
                        {content.primary_cta.label}
                        <ArrowRight aria-hidden="true" className="size-4" />
                    </Link>
                </Button>

                {content.secondary_cta && (
                    <Button asChild variant="outline" size="lg">
                        <Link href={content.secondary_cta.href}>
                            {content.secondary_cta.label}
                        </Link>
                    </Button>
                )}
            </div>
        </div>
    );

    if (content.media && position === 'background') {
        return (
            <SectionContainer className="relative overflow-hidden pt-16 sm:pt-24">
                <div className="absolute inset-0 -z-10">
                    <SectionMedia
                        media={content.media}
                        mobileMedia={content.mobile_media}
                        fit={content.media_fit ?? 'cover'}
                        priority
                    />
                    <div
                        className="bg-background/75 absolute inset-0"
                        aria-hidden="true"
                    />
                </div>
                {copy}
            </SectionContainer>
        );
    }

    if (content.media) {
        const mediaBlock = (
            <div className="aspect-[4/3] overflow-hidden rounded-2xl">
                <SectionMedia
                    media={content.media}
                    mobileMedia={content.mobile_media}
                    fit={content.media_fit}
                    priority
                />
            </div>
        );

        return (
            <SectionContainer className="pt-16 sm:pt-24">
                <div className="grid items-center gap-10 lg:grid-cols-2">
                    {position === 'left' ? (
                        <>
                            {mediaBlock}
                            {copy}
                        </>
                    ) : (
                        <>
                            {copy}
                            {mediaBlock}
                        </>
                    )}
                </div>
            </SectionContainer>
        );
    }

    return (
        <SectionContainer className="pt-16 sm:pt-24">{copy}</SectionContainer>
    );
}
