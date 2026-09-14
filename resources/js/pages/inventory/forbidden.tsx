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
     * Who was refused: a partner, who sees what is available but never changes
     * central stock; a member of staff who may view stock but not change it; or
     * a member of staff whose role does not include inventory at all.
     */
    audience: Audience;
};

const LINES: Record<Audience, { title: string; description: string }> = {
    business: {
        title: 'inventory.forbidden_title',
        description: 'inventory.forbidden_description',
    },
    viewer: {
        title: 'inventory.forbidden_viewer_title',
        description: 'inventory.forbidden_viewer_description',
    },
    staff: {
        title: 'inventory.forbidden_staff_title',
        description: 'inventory.forbidden_staff_description',
    },
};

/**
 * Central stock, refused (§19, §33.10).
 *
 * The server renders this for every 403 on an inventory administration route
 * (`bootstrap/app.php`), so a refusal is a page in the application's own shell —
 * light, dark, English or Bangla — rather than the framework's bare error
 * screen. It says why, and where to go instead.
 */
export default function InventoryForbidden({ audience }: Props) {
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
                            {t('inventory.forbidden_back')}
                        </Link>
                    </Button>
                </div>
            </PageContainer>
        </>
    );
}
