import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import PageContainer from '@/components/page-container';
import PermissionDeniedState from '@/components/states/permission-denied-state';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';

type Audience = 'business' | 'viewer' | 'staff';

type Props = {
    /**
     * Who was refused: a partner, who selects from the catalogue rather than
     * writing it; a member of staff who may view the catalogue but not change
     * it; or a member of staff whose role does not include it at all.
     */
    audience: Audience;
};

const LINES: Record<Audience, { title: string; description: string }> = {
    business: {
        title: 'catalog.forbidden_title',
        description: 'catalog.forbidden_description',
    },
    viewer: {
        title: 'catalog.forbidden_viewer_title',
        description: 'catalog.forbidden_viewer_description',
    },
    staff: {
        title: 'catalog.forbidden_staff_title',
        description: 'catalog.forbidden_staff_description',
    },
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
    const lines = LINES[audience];
    const title = t(lines.title);

    return (
        <>
            <Head title={title} />

            <PageContainer width="narrow">
                <PermissionDeniedState
                    title={title}
                    description={t(lines.description)}
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
