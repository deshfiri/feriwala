import { Check } from 'lucide-react';
import SectionContainer from '@/components/public/section-container';

type PlatformIntroductionContent = {
    heading: string;
    body?: string | null;
    bullets?: string[] | null;
};

export default function PlatformIntroduction({
    content,
}: {
    content: PlatformIntroductionContent;
}) {
    return (
        <SectionContainer>
            <div className="grid gap-10 lg:grid-cols-2 lg:items-center">
                <div>
                    <h2 className="text-3xl font-semibold tracking-tight text-balance">
                        {content.heading}
                    </h2>
                    {content.body && (
                        <p className="text-muted-foreground mt-4 text-balance">
                            {content.body}
                        </p>
                    )}
                </div>

                {content.bullets && content.bullets.length > 0 && (
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
                )}
            </div>
        </SectionContainer>
    );
}
