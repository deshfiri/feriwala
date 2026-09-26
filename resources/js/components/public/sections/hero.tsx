import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import SectionContainer from '@/components/public/section-container';
import { Button } from '@/components/ui/button';
import type { CmsCta } from '@/types';

type HeroContent = {
    heading: string;
    subheading?: string | null;
    body?: string | null;
    primary_cta: CmsCta;
    secondary_cta?: CmsCta | null;
};

/**
 * The landing page's opening statement (§34). A split hero was the
 * `banij_landing` reference's own structure, but with no real explainer
 * video or trust-signal numbers to show yet, this stays a single centered
 * column rather than inventing a placeholder for the empty half.
 */
export default function Hero({ content }: { content: HeroContent }) {
    return (
        <SectionContainer className="pt-16 sm:pt-24">
            <div className="mx-auto max-w-3xl text-center">
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

                <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
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
        </SectionContainer>
    );
}
