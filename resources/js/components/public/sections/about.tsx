import SectionContainer from '@/components/public/section-container';
import SectionMedia from '@/components/public/sections/section-media';
import type { CmsMedia, CmsMediaFit, CmsMediaPosition } from '@/types';

type AboutContent = {
    heading: string;
    body: string;
    media?: CmsMedia | null;
    media_position?: CmsMediaPosition;
    media_fit?: CmsMediaFit;
};

export default function About({ content }: { content: AboutContent }) {
    const position: CmsMediaPosition = content.media_position ?? 'right';

    const copy = (
        <div>
            <h2 className="text-3xl font-semibold tracking-tight text-balance">
                {content.heading}
            </h2>
            <p className="text-muted-foreground mt-4 text-balance">
                {content.body}
            </p>
        </div>
    );

    if (!content.media) {
        return (
            <SectionContainer>
                <div className="mx-auto max-w-3xl text-center">{copy}</div>
            </SectionContainer>
        );
    }

    const mediaBlock = (
        <div className="aspect-[4/3] overflow-hidden rounded-2xl">
            <SectionMedia media={content.media} fit={content.media_fit} />
        </div>
    );

    return (
        <SectionContainer>
            <div className="grid gap-10 lg:grid-cols-2 lg:items-center">
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
