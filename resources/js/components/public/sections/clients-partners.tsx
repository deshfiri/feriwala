import SectionContainer from '@/components/public/section-container';
import SectionMedia from '@/components/public/sections/section-media';
import type { CmsMedia } from '@/types';

type ClientsPartnersContent = {
    heading?: string | null;
    items: { name: string; media: CmsMedia }[];
};

export default function ClientsPartners({
    content,
}: {
    content: ClientsPartnersContent;
}) {
    return (
        <SectionContainer>
            {content.heading && (
                <h2 className="text-center text-3xl font-semibold tracking-tight text-balance">
                    {content.heading}
                </h2>
            )}

            <div className="mt-10 flex flex-wrap items-center justify-center gap-x-10 gap-y-6">
                {content.items.map((item, index) => (
                    <div
                        key={index}
                        title={item.name}
                        className="flex h-10 w-28 items-center justify-center grayscale"
                    >
                        <SectionMedia media={item.media} fit="contain" />
                    </div>
                ))}
            </div>
        </SectionContainer>
    );
}
