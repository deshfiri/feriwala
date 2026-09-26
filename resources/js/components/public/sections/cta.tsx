import { Link } from '@inertiajs/react';
import SectionContainer from '@/components/public/section-container';
import { Button } from '@/components/ui/button';
import type { CmsCta } from '@/types';

type CtaContent = {
    heading: string;
    body?: string | null;
    primary_cta: CmsCta;
    secondary_cta?: CmsCta | null;
};

export default function Cta({ content }: { content: CtaContent }) {
    return (
        <SectionContainer>
            <div className="bg-brand-subtle rounded-2xl px-8 py-12 text-center sm:px-12">
                <h2 className="text-3xl font-semibold tracking-tight text-balance">
                    {content.heading}
                </h2>

                {content.body && (
                    <p className="text-muted-foreground mx-auto mt-3 max-w-xl text-balance">
                        {content.body}
                    </p>
                )}

                <div className="mt-6 flex flex-wrap items-center justify-center gap-3">
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
        </SectionContainer>
    );
}
