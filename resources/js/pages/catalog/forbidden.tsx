import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import PageContainer from '@/components/page-container';
import PermissionDeniedState from '@/components/states/permission-denied-state';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';

type Props = {
    /**
     * Who was refused: a partner, who selects from the catalogue rather than
     * writing it, or a member of staff whose role does not include it.
     */
    audience: 'business' | 'staff';
};

/**
 * The central catalogue, refused (§12, §33.10).
 *
 * The server renders this for every 403 on a catalogue administration route
 * (`bootstrap/app.php`), so a refusal is
 * a page in the application's own shell — light, dark, English or Bangla —
 * rather than the framework's bare error screen. It names nothing about what
 * the refused page would have held; it says why, and where to go instead.
 */
export default function CatalogForbidden({ audience }: Props) {
    const { t } = useTranslation();
    const business = audience === 'business';

    const title = t(
        business ? 'catalog.forbidden_title' : 'catalog.forbidden_staff_title',
    );

    return (
        <>
            <Head title={title} />

            <PageContainer width="narrow">
                <PermissionDeniedState
                    title={title}
                    description={t(
                        business
                            ? 'catalog.forbidden_description'
                            : 'catalog.forbidden_staff_description',
                    )}
                />

                <div className="flex justify-center">
                    <Button variant="outline" asChild>
                        <Link href={dashboard()}>
                            <ArrowLeft className="size-4" aria-hidden="true" />
                            {t('catalog.forbidden_back')}
                        </Link>
                    </Button>
                </div>
            </PageContainer>
        </>
    );
}
