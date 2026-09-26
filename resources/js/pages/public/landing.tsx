import { Head } from '@inertiajs/react';
import type { ComponentType } from 'react';
import PublicFooter from '@/components/public/public-footer';
import PublicHeader from '@/components/public/public-header';
import About from '@/components/public/sections/about';
import Benefits from '@/components/public/sections/benefits';
import ClientsPartners from '@/components/public/sections/clients-partners';
import Cta from '@/components/public/sections/cta';
import Faq from '@/components/public/sections/faq';
import Hero from '@/components/public/sections/hero';
import HowItWorks from '@/components/public/sections/how-it-works';
import PackagePreview from '@/components/public/sections/package-preview';
import PlatformIntroduction from '@/components/public/sections/platform-introduction';
import Testimonials from '@/components/public/sections/testimonials';
import ValueProposition from '@/components/public/sections/value-proposition';
import Video from '@/components/public/sections/video';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { CmsMenus, CmsPackagePreview, CmsSection, CmsSeo } from '@/types';

type Props = {
    seo: CmsSeo;
    sections: (CmsSection & { packages?: CmsPackagePreview[] })[];
    menus: CmsMenus;
    structuredData: Record<string, unknown>[];
};

/**
 * `<script>` never breaks out of itself: a stray literal "</script>" inside
 * an FAQ answer or any other schema string field would otherwise end the
 * tag early and let the rest render as visible page markup.
 */
function safeJsonLd(schema: Record<string, unknown>): string {
    return JSON.stringify(schema).replace(/</g, '\\u003c');
}

/**
 * Every kind with a renderer today (App\Domain\Cms\Enums\SectionKind::
 * implemented()). `header_nav` and `footer` are not in this map — they are
 * rendered once, outside the section loop, by PublicHeader/PublicFooter.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const RENDERERS: Record<string, ComponentType<any>> = {
    hero: Hero,
    platform_introduction: PlatformIntroduction,
    about: About,
    benefits: Benefits,
    how_it_works: HowItWorks,
    dropshipping: ValueProposition,
    wholesale: ValueProposition,
    partner_websites: ValueProposition,
    supplier_opportunity: ValueProposition,
    package_preview: PackagePreview,
    video: Video,
    testimonials: Testimonials,
    clients_partners: ClientsPartners,
    faq: Faq,
    cta: Cta,
};

/**
 * The public landing page (§4, §34) — informational only. Every section
 * here is real, published content the server already decided was safe and
 * enabled to show; this component only lays it out, it never decides who
 * may see what.
 */
export default function Landing({
    seo,
    sections,
    menus,
    structuredData,
}: Props) {
    const { t } = useTranslation();
    const footerSection = sections.find((section) => section.kind === 'footer');
    const footerContent = (footerSection?.content ?? {}) as {
        tagline?: string;
        copyright_text: string;
    };

    return (
        <>
            <Head title={seo.title}>
                {seo.description && (
                    <meta name="description" content={seo.description} />
                )}
                <meta name="robots" content={seo.robots ?? 'index, follow'} />
                {seo.canonical_url && (
                    <link rel="canonical" href={seo.canonical_url} />
                )}
                <meta property="og:title" content={seo.title} />
                {seo.description && (
                    <meta property="og:description" content={seo.description} />
                )}
                {seo.og_image_url && (
                    <meta property="og:image" content={seo.og_image_url} />
                )}
                <meta property="og:type" content="website" />
                <meta
                    name="twitter:card"
                    content={
                        seo.og_image_url ? 'summary_large_image' : 'summary'
                    }
                />
                <meta name="twitter:title" content={seo.title} />
                {seo.description && (
                    <meta
                        name="twitter:description"
                        content={seo.description}
                    />
                )}
                {seo.og_image_url && (
                    <meta name="twitter:image" content={seo.og_image_url} />
                )}
                {seo.twitter_handle && (
                    <meta name="twitter:site" content={seo.twitter_handle} />
                )}
                {structuredData.map((schema, index) => (
                    // eslint-disable-next-line react/no-danger
                    <script
                        key={index}
                        type="application/ld+json"
                        dangerouslySetInnerHTML={{
                            __html: safeJsonLd(schema),
                        }}
                    />
                ))}
            </Head>

            <div className="bg-background text-foreground min-h-svh">
                <a
                    href="#main-content"
                    className="bg-background focus:ring-ring sr-only rounded-md px-3 py-2 focus:not-sr-only focus:absolute focus:z-50 focus:ring-2"
                >
                    {t('common.nav.skip')}
                </a>

                <PublicHeader items={menus.header} />

                <main id="main-content">
                    {sections
                        .filter(
                            (section) =>
                                section.kind !== 'header_nav' &&
                                section.kind !== 'footer',
                        )
                        .map((section) => {
                            const Renderer = RENDERERS[section.kind];

                            if (!Renderer) {
                                return null;
                            }

                            return (
                                <div
                                    key={section.key}
                                    className={cn(
                                        !section.visible_on_desktop &&
                                            'lg:hidden',
                                        !section.visible_on_mobile &&
                                            'hidden lg:block',
                                    )}
                                >
                                    <Renderer
                                        content={section.content}
                                        packages={section.packages ?? []}
                                    />
                                </div>
                            );
                        })}
                </main>

                <PublicFooter
                    tagline={footerContent.tagline}
                    copyrightText={footerContent.copyright_text ?? ''}
                    footerLinks={menus.footer}
                    legalLinks={menus.legal}
                />
            </div>
        </>
    );
}
