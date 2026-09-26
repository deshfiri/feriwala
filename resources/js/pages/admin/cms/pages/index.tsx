import { Head, Link } from '@inertiajs/react';
import { edit as cmsPageEdit } from '@/routes/admin/cms/pages';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { useTranslation } from '@/hooks/use-translation';
import type { CmsAdminPageSummary } from '@/types';

type Props = {
    pages: CmsAdminPageSummary[];
};

/**
 * The public site's pages (§4, §34, Stage 7). Today there is exactly one —
 * the landing page seeded by CmsLandingPageSeeder — but the list is real,
 * not a placeholder for a single row: a future page is another row here,
 * not a special case.
 */
export default function CmsPagesIndex({ pages }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('cms.pages_index.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('cms.pages_index.title')}
                    description={t('cms.pages_index.description')}
                />

                <SectionCard>
                    <ul className="divide-border divide-y">
                        {pages.map((page) => (
                            <li
                                key={page.id}
                                className="flex flex-wrap items-center justify-between gap-3 py-3"
                            >
                                <div className="min-w-0 space-y-0.5">
                                    <Link
                                        href={cmsPageEdit(page.id)}
                                        className="text-sm font-medium hover:underline"
                                    >
                                        /{page.slug}
                                    </Link>
                                    <p className="text-muted-foreground text-xs">
                                        {page.page_type}
                                    </p>
                                </div>

                                <StatusPill
                                    tone={page.publication_state.tone}
                                    label={page.publication_state.label}
                                />
                            </li>
                        ))}
                    </ul>
                </SectionCard>
            </PageContainer>
        </>
    );
}
