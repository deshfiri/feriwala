import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Eye } from 'lucide-react';
import BatchKycForm from '@/components/kyc/batch-form';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { edit, index, preview } from '@/routes/admin/kyc/batch-builder';
import type { KycBatch } from '@/types';

/** Endpoints that go nowhere: the preview round refuses to store anything. */
const NOWHERE = {
    file: '#',
    value: '#',
    remove: '#',
    choose: '#',
    deselect: '#',
    submit: '#',
};

/**
 * A batch template as an applicant would meet it (D30): the applicant's own form,
 * drawn from the batch's live structure, with every control switched off.
 *
 * It uses the same component and the same server presenter as the real form, so
 * what an administrator checks here cannot differ from what an applicant gets.
 * Nothing posts from this page and the server has no submission behind it.
 */
export default function BatchPreview({
    batch,
    form,
    as,
    problems,
}: {
    batch: { id: string; name: string; audience: string };
    form: KycBatch | null;
    as: 'en' | 'bn';
    problems: string[];
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('kyc.builder.preview_title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('kyc.builder.preview_title')}
                    description={batch.name}
                    actions={
                        <>
                            <StatusPill
                                tone="info"
                                icon={Eye}
                                label={t('kyc.builder.preview_badge')}
                            />
                            <Button variant="outline" size="sm" asChild>
                                <Link href={edit(batch.id)}>
                                    <ArrowLeft
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t('kyc.builder.back_to_builder')}
                                </Link>
                            </Button>
                        </>
                    }
                />

                <div
                    role="group"
                    aria-label={t('kyc.builder.preview_language')}
                    className="flex gap-2"
                >
                    {(['en', 'bn'] as const).map((locale) => (
                        <Button
                            key={locale}
                            variant={as === locale ? 'default' : 'outline'}
                            size="sm"
                            asChild
                        >
                            <Link
                                href={preview(batch.id, {
                                    query: { as: locale },
                                })}
                                aria-current={
                                    as === locale ? 'true' : undefined
                                }
                            >
                                {locale === 'en' ? 'English' : 'বাংলা'}
                            </Link>
                        </Button>
                    ))}
                </div>

                <p className="text-muted-foreground text-sm">
                    {t('kyc.builder.preview_help')}
                </p>

                {problems.length > 0 && (
                    <section
                        role="alert"
                        className="bg-warning-subtle border-warning/30 space-y-1 rounded-lg border p-4"
                    >
                        <p className="text-sm font-semibold">
                            {t('kyc.builder.problems_title')}
                        </p>
                        <ul className="list-disc space-y-0.5 pl-5 text-sm">
                            {problems.map((problem) => (
                                <li key={problem}>{problem}</li>
                            ))}
                        </ul>
                    </section>
                )}

                {form === null ? null : (
                    // Disabled as a whole: no input, button or camera can be used.
                    <fieldset disabled className="m-0 min-w-0 border-0 p-0">
                        <BatchKycForm batch={form} endpoints={NOWHERE} />
                    </fieldset>
                )}
            </PageContainer>
        </>
    );
}

BatchPreview.layout = {
    breadcrumbs: [
        {
            title: 'nav.kyc_batches',
            href: index(),
        },
    ],
};
