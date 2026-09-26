import { Head, router } from '@inertiajs/react';
import { ArrowRightLeft, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import RedirectController from '@/actions/App/Http/Controllers/Admin/Cms/RedirectController';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import EmptyState from '@/components/states/empty-state';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { useTranslation } from '@/hooks/use-translation';
import type { CmsAdminRedirect } from '@/types';
import RedirectDialog from './redirect-dialog';

type Props = {
    redirects: CmsAdminRedirect[];
    can: { create: boolean };
};

/**
 * Public-site redirects (§34.1, Stage 7). ManageRedirect refuses anything
 * that would chain or loop — this screen only ever shows single, direct
 * hops.
 */
export default function CmsRedirectsIndex({ redirects, can }: Props) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState<CmsAdminRedirect | null>(null);
    const [creating, setCreating] = useState(false);

    const toggle = (row: CmsAdminRedirect) => {
        router.patch(
            RedirectController.setEnabled.url(row.id),
            { is_enabled: !row.is_enabled },
            { preserveScroll: true },
        );
    };

    const remove = (row: CmsAdminRedirect) => {
        if (!window.confirm(t('cms.redirects_index.remove_confirm'))) {
            return;
        }

        router.delete(RedirectController.destroy.url(row.id), {
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title={t('cms.redirects_index.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('cms.redirects_index.title')}
                    description={t('cms.redirects_index.description')}
                    actions={
                        can.create ? (
                            <Button size="sm" onClick={() => setCreating(true)}>
                                <Plus className="size-4" />
                                {t('cms.redirects_index.add')}
                            </Button>
                        ) : undefined
                    }
                />

                {redirects.length === 0 ? (
                    <EmptyState
                        icon={ArrowRightLeft}
                        title={t('cms.redirects_index.empty_title')}
                        description={t('cms.redirects_index.empty_description')}
                    />
                ) : (
                    <SectionCard>
                        <ul className="divide-border divide-y">
                            {redirects.map((row) => (
                                <li
                                    key={row.id}
                                    className="flex flex-wrap items-center justify-between gap-3 py-3"
                                >
                                    <div className="min-w-0 space-y-0.5">
                                        <p className="font-mono text-sm">
                                            {row.from_path} → {row.to_path}
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            {row.status_code}
                                        </p>
                                    </div>

                                    <div className="flex items-center gap-2">
                                        <Checkbox
                                            checked={row.is_enabled}
                                            onCheckedChange={() => toggle(row)}
                                            aria-label={t(
                                                'cms.redirects_index.is_enabled',
                                            )}
                                        />
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => setEditing(row)}
                                        >
                                            <Pencil className="size-4" />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => remove(row)}
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </SectionCard>
                )}
            </PageContainer>

            {(creating || editing !== null) && (
                <RedirectDialog
                    open
                    onOpenChange={(open) => {
                        if (!open) {
                            setCreating(false);
                            setEditing(null);
                        }
                    }}
                    row={editing}
                />
            )}
        </>
    );
}
