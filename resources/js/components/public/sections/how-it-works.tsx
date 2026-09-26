import SectionContainer from '@/components/public/section-container';
import SectionMedia from '@/components/public/sections/section-media';
import type { CmsMedia } from '@/types';

type HowItWorksContent = {
    heading: string;
    steps: {
        step_number: number;
        heading: string;
        body?: string | null;
        media?: CmsMedia | null;
    }[];
};

/**
 * A numbered vertical sequence, not a card grid — the `banij_landing`
 * reference's own "process" section reads well as a step-by-step story for
 * exactly this reason, and Feriwala's own onboarding funnel (register, KYC,
 * package, approval) is genuinely sequential. No scroll-driven fill bar or
 * decorative orbit graphic: those were purely cosmetic in the reference and
 * add nothing a numbered list doesn't already say.
 */
export default function HowItWorks({
    content,
}: {
    content: HowItWorksContent;
}) {
    return (
        <SectionContainer>
            <h2 className="text-center text-3xl font-semibold tracking-tight text-balance">
                {content.heading}
            </h2>

            <ol className="mx-auto mt-10 max-w-2xl space-y-6">
                {content.steps.map((step) => (
                    <li key={step.step_number} className="flex gap-4">
                        <span
                            className="bg-brand text-brand-foreground flex size-9 shrink-0 items-center justify-center rounded-full text-sm font-semibold"
                            aria-hidden="true"
                        >
                            {step.step_number}
                        </span>
                        <div className="flex flex-1 items-start gap-4">
                            <div>
                                <h3 className="font-semibold">
                                    {step.heading}
                                </h3>
                                {step.body && (
                                    <p className="text-muted-foreground mt-1 text-sm">
                                        {step.body}
                                    </p>
                                )}
                            </div>
                            {step.media && (
                                <div className="aspect-square w-20 shrink-0 overflow-hidden rounded-lg sm:w-24">
                                    <SectionMedia media={step.media} />
                                </div>
                            )}
                        </div>
                    </li>
                ))}
            </ol>
        </SectionContainer>
    );
}
