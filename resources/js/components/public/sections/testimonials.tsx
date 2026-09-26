import { Quote } from 'lucide-react';
import SectionContainer from '@/components/public/section-container';
import SectionMedia from '@/components/public/sections/section-media';
import type { CmsMedia } from '@/types';

type TestimonialsContent = {
    heading?: string | null;
    items: {
        quote: string;
        author_name: string;
        author_role?: string | null;
        media?: CmsMedia | null;
    }[];
};

export default function Testimonials({
    content,
}: {
    content: TestimonialsContent;
}) {
    return (
        <SectionContainer>
            {content.heading && (
                <h2 className="text-center text-3xl font-semibold tracking-tight text-balance">
                    {content.heading}
                </h2>
            )}

            <div className="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                {content.items.map((item, index) => (
                    <figure
                        key={index}
                        className="bg-card border-border flex flex-col rounded-xl border p-6"
                    >
                        <Quote
                            aria-hidden="true"
                            className="text-brand size-6"
                        />
                        <blockquote className="text-muted-foreground mt-3 flex-1 text-sm text-balance">
                            {item.quote}
                        </blockquote>
                        <figcaption className="mt-4 flex items-center gap-3">
                            {item.media ? (
                                <div className="size-10 shrink-0 overflow-hidden rounded-full">
                                    <SectionMedia media={item.media} />
                                </div>
                            ) : (
                                <div
                                    className="bg-brand-subtle text-brand flex size-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold"
                                    aria-hidden="true"
                                >
                                    {item.author_name.charAt(0).toUpperCase()}
                                </div>
                            )}
                            <div>
                                <div className="text-sm font-semibold">
                                    {item.author_name}
                                </div>
                                {item.author_role && (
                                    <div className="text-muted-foreground text-xs">
                                        {item.author_role}
                                    </div>
                                )}
                            </div>
                        </figcaption>
                    </figure>
                ))}
            </div>
        </SectionContainer>
    );
}
