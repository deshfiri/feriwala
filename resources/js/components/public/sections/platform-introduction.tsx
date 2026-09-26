import { Link } from '@inertiajs/react';
import { Check } from 'lucide-react';
import SectionContainer from '@/components/public/section-container';
import SectionMedia from '@/components/public/sections/section-media';
import { Button } from '@/components/ui/button';
import type { CmsCta, CmsMedia, CmsMediaFit, CmsMediaPosition } from '@/types';

type PlatformIntroductionContent = {
    heading: string;
    body?: string | null;
    bullets?: string[] | null;
    cta?: CmsCta | null;
    media?: CmsMedia | null;
    media_position?: CmsMediaPosition;
    media_fit?: CmsMediaFit;
};

export default function PlatformIntroduction({
    content,
}: {
    content: PlatformIntroductionContent;
}) {
    const position: CmsMediaPosition = content.media_position ?? 'right';

    const textBlock = (
        <div>
            <h2 className="text-3xl font-semibold tracking-tight text-balance">
                {content.heading}
            </h2>
            {content.body && (
                <p className="text-muted-foreground mt-4 text-balance">
                    {content.body}
                </p>
            )}
            {content.cta && (
                <Button asChild className="mt-6">
                    <Link href={content.cta.href}>{content.cta.label}</Link>
                </Button>
            )}
        </div>
    );

    const bulletsBlock = content.bullets && content.bullets.length > 0 && (
        <ul className="space-y-3">
            {content.bullets.map((bullet, index) => (
                <li
                    key={index}
                    className="bg-card border-border flex items-start gap-3 rounded-xl border p-4"
                >
                    <Check
                        aria-hidden="true"
                        className="text-brand mt-0.5 size-5 shrink-0"
                    />
                    <span className="text-sm">{bullet}</span>
                </li>
            ))}
        </ul>
    );

    if (content.media) {
        const mediaBlock = (
            <div className="aspect-[4/3] overflow-hidden rounded-2xl">
                <SectionMedia media={content.media} fit={content.media_fit} />
            </div>
        );

        const copyBlock = (
            <div className="space-y-6">
                {textBlock}
                {bulletsBlock}
            </div>
        );

        return (
            <SectionContainer>
                <div className="grid gap-10 lg:grid-cols-2 lg:items-center">
                    {position === 'left' ? (
                        <>
                            {mediaBlock}
                            {copyBlock}
                        </>
                    ) : (
                        <>
                            {copyBlock}
                            {mediaBlock}
                        </>
                    )}
                </div>
            </SectionContainer>
        );
    }

    return (
        <SectionContainer>
            <div className="grid gap-10 lg:grid-cols-2 lg:items-center">
                {textBlock}
                {bulletsBlock}
            </div>
        </SectionContainer>
    );
}
