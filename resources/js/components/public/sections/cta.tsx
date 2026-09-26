import { Link } from '@inertiajs/react';
import SectionContainer from '@/components/public/section-container';
import SectionMedia from '@/components/public/sections/section-media';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { CmsCta, CmsMedia, CmsMediaFit, CmsMediaPosition } from '@/types';

type CtaContent = {
    heading: string;
    body?: string | null;
    primary_cta: CmsCta;
    secondary_cta?: CmsCta | null;
    media?: CmsMedia | null;
    media_position?: CmsMediaPosition;
    media_fit?: CmsMediaFit;
};

export default function Cta({ content }: { content: CtaContent }) {
    const position: CmsMediaPosition =
        content.media_position ?? (content.media ? 'right' : null);

    const copy = (
        <div
            className={cn(
                'text-center',
                content.media && position !== 'background' && 'lg:text-left',
            )}
        >
            <h2 className="text-3xl font-semibold tracking-tight text-balance">
                {content.heading}
            </h2>

            {content.body && (
                <p className="text-muted-foreground mx-auto mt-3 max-w-xl text-balance">
                    {content.body}
                </p>
            )}

            <div
                className={cn(
                    'mt-6 flex flex-wrap items-center justify-center gap-3',
                    content.media &&
                        position !== 'background' &&
                        'lg:justify-start',
                )}
            >
                <Button asChild size="lg">
                    <Link href={content.primary_cta.href}>
                        {content.primary_cta.label}
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
            <SectionContainer>
                <div className="relative overflow-hidden rounded-2xl px-8 py-12 sm:px-12">
                    <div className="absolute inset-0 -z-10">
                        <SectionMedia
                            media={content.media}
                            fit={content.media_fit ?? 'cover'}
                            decorative
                        />
                        <div
                            className="bg-background/75 absolute inset-0"
                            aria-hidden="true"
                        />
                    </div>
                    {copy}
                </div>
            </SectionContainer>
        );
    }

    if (content.media) {
        const mediaBlock = (
            <div className="aspect-[4/3] overflow-hidden rounded-2xl">
                <SectionMedia media={content.media} fit={content.media_fit} />
            </div>
        );

        return (
            <SectionContainer>
                <div className="bg-brand-subtle grid gap-10 rounded-2xl p-8 lg:grid-cols-2 lg:items-center lg:p-12">
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
        <SectionContainer>
            <div className="bg-brand-subtle rounded-2xl px-8 py-12 text-center sm:px-12">
                {copy}
            </div>
        </SectionContainer>
    );
}
