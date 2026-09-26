import { Link } from '@inertiajs/react';
import { Check } from 'lucide-react';
import SectionContainer from '@/components/public/section-container';
import { Button } from '@/components/ui/button';
import type { CmsCta } from '@/types';

export type ValuePropositionContent = {
    heading: string;
    body?: string | null;
    bullets?: string[] | null;
    cta?: CmsCta | null;
};

/**
 * The one shape shared by Dropshipping, Wholesale, Dedicated Partner
 * Websites and the Supplier opportunity sections — heading, a short
 * explanation, a couple of bullets, an optional CTA. Four kinds, one
 * renderer: the content schema
 * (App\Domain\Cms\Support\SectionContentValidator) already treats them
 * identically, so a fifth near-duplicate component would only be
 * copy-pasted drift waiting to happen.
 */
export default function ValueProposition({
    content,
}: {
    content: ValuePropositionContent;
}) {
    return (
        <SectionContainer>
            <div className="bg-card border-border rounded-2xl border p-8 sm:p-10">
                <h2 className="text-2xl font-semibold tracking-tight">
                    {content.heading}
                </h2>

                {content.body && (
                    <p className="text-muted-foreground mt-3 max-w-2xl">
                        {content.body}
                    </p>
                )}

                {content.bullets && content.bullets.length > 0 && (
                    <ul className="mt-6 grid gap-3 sm:grid-cols-2">
                        {content.bullets.map((bullet, index) => (
                            <li
                                key={index}
                                className="flex items-start gap-2 text-sm"
                            >
                                <Check
                                    aria-hidden="true"
                                    className="text-brand mt-0.5 size-4 shrink-0"
                                />
                                {bullet}
                            </li>
                        ))}
                    </ul>
                )}

                {content.cta && (
                    <Button asChild className="mt-6">
                        <Link href={content.cta.href}>{content.cta.label}</Link>
                    </Button>
                )}
            </div>
        </SectionContainer>
    );
}
