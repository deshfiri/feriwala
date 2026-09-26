import { Head, router } from '@inertiajs/react';
import { ImageIcon, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import MediaController from '@/actions/App/Http/Controllers/Admin/Cms/MediaController';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { CmsAdminMedia } from '@/types';
import MediaEditDialog from './media-edit-dialog';
import MediaUploadDialog from './media-upload-dialog';

type Props = {
    media: CmsAdminMedia[];
    can: { create: boolean };
};

/**
 * The CMS media library (§34's media-safety requirements, Stage 7). Every
 * meaningful image needs alt text in both locales before it is ready to
 * place on a published page — shown here as a plain badge, not enforced by
 * this screen (nothing wires an image into a section's content yet; see
 * the Stage 7 report).
 */
export default function CmsMediaIndex({ media, can }: Props) {
    const { t } = useTranslation();
    const [uploading, setUploading] = useState(false);
    const [editing, setEditing] = useState<CmsAdminMedia | null>(null);

    const remove = (item: CmsAdminMedia) => {
        if (!window.confirm(t('cms.media_index.remove_confirm'))) {
            return;
        }

        router.delete(MediaController.destroy.url(item.id), {
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title={t('cms.media_index.title')} />

            <PageContainer>
                <PageHeader
                    title={t('cms.media_index.title')}
                    description={t('cms.media_index.description')}
                    actions={
                        can.create ? (
                            <Button
                                size="sm"
                                onClick={() => setUploading(true)}
                            >
                                <Plus className="size-4" />
                                {t('cms.media_index.upload')}
                            </Button>
                        ) : undefined
                    }
                />

                {media.length === 0 ? (
                    <EmptyState
                        icon={ImageIcon}
                        title={t('cms.media_index.empty_title')}
                        description={t('cms.media_index.empty_description')}
                        action={
                            can.create ? (
                                <Button
                                    size="sm"
                                    onClick={() => setUploading(true)}
                                >
                                    <Plus className="size-4" />
                                    {t('cms.media_index.upload')}
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                        {media.map((item) => (
                            <div
                                key={item.id}
                                className="bg-card overflow-hidden rounded-xl border"
                            >
                                <img
                                    src={item.url}
                                    alt={item.alt_text_en ?? ''}
                                    className="aspect-video w-full object-cover"
                                />
                                <div className="space-y-1 p-3">
                                    <p className="truncate text-xs font-medium">
                                        {item.original_filename}
                                    </p>
                                    <p className="text-muted-foreground text-xs">
                                        {item.has_required_alt_text
                                            ? t('cms.media_index.alt_text_ok')
                                            : t(
                                                  'cms.media_index.alt_text_missing',
                                              )}
                                    </p>
                                    <div className="flex justify-end gap-1">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => setEditing(item)}
                                        >
                                            <Pencil className="size-4" />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => remove(item)}
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </PageContainer>

            <MediaUploadDialog open={uploading} onOpenChange={setUploading} />

            {editing !== null && (
                <MediaEditDialog
                    open
                    onOpenChange={(open) => !open && setEditing(null)}
                    media={editing}
                />
            )}
        </>
    );
}
