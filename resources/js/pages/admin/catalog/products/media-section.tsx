import { Form, router } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    Film,
    ImagePlus,
    Pencil,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import ProductMediaController from '@/actions/App/Http/Controllers/Admin/ProductMediaController';
import FormField from '@/components/forms/form-field';
import SectionCard from '@/components/section-card';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { MediaLimits, MediaRow, ProductDetail, VariantRow } from '@/types';

type Props = {
    product: ProductDetail;
    media: MediaRow[];
    limits: MediaLimits;
    variants: VariantRow[];
    canEdit: boolean;
};

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * A product's images and videos (§11.1).
 *
 * The order here is the order storefronts show, and position one is the listing
 * image — so moving a file is a real decision, sent as the whole order in one
 * request rather than a series of swaps somebody could read half-way through.
 *
 * The accepted formats and sizes are the server's own figures, never above
 * what PHP accepts, so the help text cannot promise an upload the server drops.
 */
export default function MediaSection({
    product,
    media,
    limits,
    variants,
    canEdit,
}: Props) {
    const { t } = useTranslation();
    const [describing, setDescribing] = useState<MediaRow | null>(null);

    const full = media.length >= limits.max_items;

    const move = (index: number, direction: -1 | 1) => {
        const target = index + direction;

        if (target < 0 || target >= media.length) {
            return;
        }

        const order = media.map((item) => item.id);
        [order[index], order[target]] = [order[target], order[index]];

        router.post(
            ProductMediaController.reorder.url(product.id),
            { order },
            { preserveScroll: true },
        );
    };

    const remove = (item: MediaRow) => {
        if (!window.confirm(t('catalog.media.delete_confirm'))) {
            return;
        }

        router.delete(
            ProductMediaController.destroy.url({
                product: product.id,
                media: item.id,
            }),
            { preserveScroll: true },
        );
    };

    return (
        <SectionCard
            title={t('catalog.media.title')}
            description={t('catalog.media.description')}
        >
            <div className="space-y-5">
                {media.length === 0 ? (
                    <EmptyState
                        icon={ImagePlus}
                        title={t('catalog.media.empty')}
                        description={t(
                            canEdit
                                ? 'catalog.media.empty_help'
                                : 'catalog.media.empty_help_read_only',
                        )}
                    />
                ) : (
                    <ol className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {media.map((item, index) => (
                            <li
                                key={item.id}
                                className={cn(
                                    'bg-card overflow-hidden rounded-lg border',
                                    item.position === 1 &&
                                        'ring-success/40 ring-2',
                                )}
                            >
                                <div className="bg-muted relative aspect-square">
                                    {item.type === 'image' ? (
                                        <img
                                            src={item.url}
                                            alt={item.alt_text ?? ''}
                                            width={item.width ?? undefined}
                                            height={item.height ?? undefined}
                                            className="size-full object-cover"
                                            loading="lazy"
                                            onError={(event) => {
                                                event.currentTarget.style.visibility =
                                                    'hidden';
                                            }}
                                        />
                                    ) : (
                                        <video
                                            src={item.url}
                                            controls
                                            preload="metadata"
                                            className="size-full object-cover"
                                            aria-label={item.alt_text ?? ''}
                                        />
                                    )}

                                    {item.position === 1 && (
                                        <StatusPill
                                            tone="success"
                                            label={t('catalog.media.primary')}
                                            className="absolute top-2 left-2 shadow-sm"
                                        />
                                    )}
                                </div>

                                <div className="space-y-2 p-3">
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        {item.position !== 1 && (
                                            <StatusPill
                                                tone="neutral"
                                                label={t(
                                                    'catalog.media.position',
                                                    {
                                                        position: item.position,
                                                    },
                                                )}
                                            />
                                        )}
                                        <StatusPill
                                            tone="info"
                                            label={t(
                                                item.type === 'image'
                                                    ? 'catalog.media.type_image'
                                                    : 'catalog.media.type_video',
                                            )}
                                        />
                                        {!item.alt_text && (
                                            <StatusPill
                                                tone="warning"
                                                label={t(
                                                    'catalog.media.missing_alt',
                                                )}
                                            />
                                        )}
                                    </div>

                                    {item.alt_text && (
                                        <p className="text-muted-foreground line-clamp-2 text-xs">
                                            {item.alt_text}
                                        </p>
                                    )}
                                    {item.variant_label && (
                                        <p className="text-xs">
                                            {t('catalog.media.variant')}:{' '}
                                            {item.variant_label}
                                        </p>
                                    )}

                                    {canEdit && (
                                        <div className="flex flex-wrap items-center gap-1">
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-8"
                                                disabled={index === 0}
                                                onClick={() => move(index, -1)}
                                                aria-label={t(
                                                    'catalog.media.move_earlier',
                                                )}
                                            >
                                                <ChevronLeft className="size-4" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-8"
                                                disabled={
                                                    index === media.length - 1
                                                }
                                                onClick={() => move(index, 1)}
                                                aria-label={t(
                                                    'catalog.media.move_later',
                                                )}
                                            >
                                                <ChevronRight className="size-4" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setDescribing(item)
                                                }
                                            >
                                                <Pencil
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                                {t('common.actions.edit')}
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => remove(item)}
                                            >
                                                <Trash2
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                                <span className="sr-only">
                                                    {t('common.actions.delete')}
                                                </span>
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ol>
                )}

                {canEdit &&
                    (full ? (
                        <p className="text-muted-foreground text-sm">
                            {t('catalog.media.limit_reached', {
                                max: limits.max_items,
                            })}
                        </p>
                    ) : (
                        <Form
                            {...ProductMediaController.store.form(product.id)}
                            options={{ preserveScroll: true }}
                            resetOnSuccess
                            className="grid gap-3 rounded-lg border border-dashed p-4 sm:grid-cols-2"
                        >
                            {({ errors, processing, progress }) => (
                                <>
                                    <FormField
                                        label={t('catalog.media.file')}
                                        hint={t('catalog.media.file_help', {
                                            image: limits.image_max_mb,
                                            video: limits.video_max_mb,
                                        })}
                                        error={errors.file}
                                        required
                                        className="sm:col-span-2"
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="file"
                                                type="file"
                                                accept={[
                                                    ...limits.image_types,
                                                    ...limits.video_types,
                                                ].join(',')}
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t('catalog.media.alt_text')}
                                        hint={t('catalog.media.alt_text_help')}
                                        error={errors.alt_text}
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="alt_text"
                                                maxLength={255}
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t('catalog.media.variant')}
                                        error={errors.variant_id}
                                    >
                                        {(field) => (
                                            <select
                                                {...field}
                                                name="variant_id"
                                                defaultValue=""
                                                className={selectClass}
                                                disabled={variants.length === 0}
                                            >
                                                <option value="">
                                                    {t(
                                                        'catalog.media.whole_product',
                                                    )}
                                                </option>
                                                {variants.map((variant) => (
                                                    <option
                                                        key={variant.id}
                                                        value={variant.id}
                                                    >
                                                        {variant.label}
                                                    </option>
                                                ))}
                                            </select>
                                        )}
                                    </FormField>

                                    <div className="flex items-center gap-3 sm:col-span-2">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            <ImagePlus
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            {t('catalog.media.upload')}
                                        </Button>
                                        {progress && (
                                            <progress
                                                value={progress.percentage}
                                                max={100}
                                                className="h-2 flex-1"
                                            />
                                        )}
                                        {!progress && (
                                            <Film
                                                className="text-muted-foreground size-4"
                                                aria-hidden="true"
                                            />
                                        )}
                                    </div>
                                </>
                            )}
                        </Form>
                    ))}
            </div>

            {describing && (
                <Dialog
                    open
                    onOpenChange={(open) => !open && setDescribing(null)}
                >
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>
                                {t('catalog.media.edit_title')}
                            </DialogTitle>
                            <DialogDescription>
                                {t('catalog.media.alt_text_help')}
                            </DialogDescription>
                        </DialogHeader>

                        <Form
                            {...ProductMediaController.update.form({
                                product: product.id,
                                media: describing.id,
                            })}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setDescribing(null)}
                            className="space-y-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <FormField
                                        label={t('catalog.media.alt_text')}
                                        error={errors.alt_text}
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="alt_text"
                                                maxLength={255}
                                                defaultValue={
                                                    describing.alt_text ?? ''
                                                }
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t('catalog.media.variant')}
                                        error={errors.variant_id}
                                    >
                                        {(field) => (
                                            <select
                                                {...field}
                                                name="variant_id"
                                                defaultValue={
                                                    describing.variant_id ?? ''
                                                }
                                                className={selectClass}
                                            >
                                                <option value="">
                                                    {t(
                                                        'catalog.media.whole_product',
                                                    )}
                                                </option>
                                                {variants.map((variant) => (
                                                    <option
                                                        key={variant.id}
                                                        value={variant.id}
                                                    >
                                                        {variant.label}
                                                    </option>
                                                ))}
                                            </select>
                                        )}
                                    </FormField>

                                    <DialogFooter>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() => setDescribing(null)}
                                        >
                                            {t('common.actions.cancel')}
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {t('common.actions.save')}
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            )}
        </SectionCard>
    );
}
