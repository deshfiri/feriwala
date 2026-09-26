import { Head } from '@inertiajs/react';
import { useTranslation } from '@/hooks/use-translation';
import type { CmsSeo } from '@/types';

/**
 * Shown when nothing is live to publish yet — no page row, no published
 * revision, a revision whose schedule has not arrived, or one whose
 * schedule has passed (§34). Deliberately plain and safe rather than a
 * blank screen or a stack trace.
 */
export default function LandingUnavailable({ seo }: { seo: CmsSeo }) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={seo.title}>
                <meta
                    name="robots"
                    content={seo.robots ?? 'noindex, nofollow'}
                />
            </Head>

            <div className="bg-background text-foreground flex min-h-svh flex-col items-center justify-center px-4 text-center">
                <h1 className="text-2xl font-semibold">
                    {t('public.unavailable.title')}
                </h1>
                <p className="text-muted-foreground mt-2 max-w-md">
                    {t('public.unavailable.description')}
                </p>
            </div>
        </>
    );
}
