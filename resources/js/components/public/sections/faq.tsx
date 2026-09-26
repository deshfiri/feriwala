import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import SectionContainer from '@/components/public/section-container';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';

type FaqContent = {
    heading: string;
    items: { question: string; answer: string }[];
};

/**
 * Built on the existing `Collapsible` primitive (already a dependency, used
 * by the ERP sidebar's nav branches) rather than adding a new Radix
 * accordion package for one section.
 */
export default function Faq({ content }: { content: FaqContent }) {
    const [openIndex, setOpenIndex] = useState<number | null>(null);

    return (
        <SectionContainer>
            <h2 className="text-center text-3xl font-semibold tracking-tight text-balance">
                {content.heading}
            </h2>

            <div className="mx-auto mt-8 max-w-2xl space-y-2">
                {content.items.map((item, index) => {
                    const isOpen = openIndex === index;

                    return (
                        <Collapsible
                            key={index}
                            open={isOpen}
                            onOpenChange={(open) =>
                                setOpenIndex(open ? index : null)
                            }
                            className="border-border rounded-xl border"
                        >
                            <CollapsibleTrigger className="focus-visible:ring-ring flex w-full items-center justify-between gap-3 px-5 py-4 text-left text-sm font-semibold focus-visible:ring-2 focus-visible:outline-none">
                                {item.question}
                                <ChevronDown
                                    aria-hidden="true"
                                    className={cn(
                                        'size-4 shrink-0 transition-transform duration-200',
                                        isOpen && 'rotate-180',
                                    )}
                                />
                            </CollapsibleTrigger>
                            <CollapsibleContent className="text-muted-foreground px-5 pb-4 text-sm">
                                {item.answer}
                            </CollapsibleContent>
                        </Collapsible>
                    );
                })}
            </div>
        </SectionContainer>
    );
}
