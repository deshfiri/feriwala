import { Form, router } from '@inertiajs/react';
import { ImagePlus, Star, Trash2 } from 'lucide-react';
import FormField from '@/components/forms/form-field';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import * as media from '@/routes/supplier/listings/media';
import type { LotEntryMedia } from './types';

/**
 * A lot's product entry's own images (Supplier Bulk Product Listing batch).
 *
 * A product entry drafted through the lot workspace is an ordinary
 * `SupplierProductListing` row, so this reuses the same
 * `supplier.listings.media.*` routes {@see ListingMediaController} already
 * exposes for the single-listing form -- no parallel route tree.
 */
export default function ListingLotMediaManager({
    listingId,
    items,
}: {
    listingId: string;
    items: LotEntryMedia[];
}) {
    const { t } = useTranslation();

    const remove = (item: LotEntryMedia) => {
        if (!window.confirm(t('supplier.listing_lots.media.remove'))) {
            return;
        }

        router.delete(
            media.destroy.url({ listing: listingId, media: item.id }),
            {
                preserveScroll: true,
            },
        );
    };

    const makePrimary = (item: LotEntryMedia) =>
        router.patch(
            media.update.url({ listing: listingId, media: item.id }),
            { role: 'primary' },
            { preserveScroll: true },
        );

    return (
        <div className="space-y-4">
            {items.length === 0 ? (
                <EmptyState
                    icon={ImagePlus}
                    title={t('supplier.listing_lots.media.empty_title')}
                    description={t(
                        'supplier.listing_lots.media.empty_description',
                    )}
                />
            ) : (
                <ul className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {items.map((item) => (
                        <li
                            key={item.id}
                            className="bg-card space-y-2 overflow-hidden rounded-lg border p-2"
                        >
                            <div className="bg-muted aspect-square overflow-hidden rounded-md">
                                <img
                                    src={item.download_url}
                                    alt={item.alt_text}
                                    className="size-full object-cover"
                                    loading="lazy"
                                />
                            </div>
                            <StatusPill
                                tone={
                                    item.role === 'primary'
                                        ? 'success'
                                        : 'neutral'
                                }
                                label={t(
                                    item.role === 'primary'
                                        ? 'supplier.listing_lots.media.primary'
                                        : 'supplier.listing_lots.media.gallery',
                                )}
                            />
                            <div className="flex items-center gap-1">
                                {item.role !== 'primary' && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => makePrimary(item)}
                                    >
                                        <Star
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        <span className="sr-only sm:not-sr-only">
                                            {t(
                                                'supplier.listing_lots.media.make_primary',
                                            )}
                                        </span>
                                    </Button>
                                )}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => remove(item)}
                                >
                                    <Trash2
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    <span className="sr-only">
                                        {t(
                                            'supplier.listing_lots.media.remove',
                                        )}
                                    </span>
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            <Form
                {...media.store.form(listingId)}
                options={{ preserveScroll: true }}
                resetOnSuccess
                className="grid gap-3 rounded-lg border border-dashed p-4 sm:grid-cols-3"
            >
                {({ errors, processing, progress }) => (
                    <>
                        <FormField
                            label={t('supplier.listing_lots.media.add')}
                            error={errors.file}
                            required
                            className="sm:col-span-2"
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    name="file"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                />
                            )}
                        </FormField>

                        <FormField
                            label={t('supplier.listing_lots.media.alt_text')}
                            error={errors.alt_text}
                            required
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    name="alt_text"
                                    maxLength={255}
                                />
                            )}
                        </FormField>

                        <input
                            type="hidden"
                            name="role"
                            value={items.length === 0 ? 'primary' : 'gallery'}
                        />

                        <div className="flex items-center gap-3 sm:col-span-3">
                            <Button
                                type="submit"
                                disabled={processing}
                                size="sm"
                            >
                                <ImagePlus
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                {t('supplier.listing_lots.media.add')}
                            </Button>
                            {progress && (
                                <progress
                                    value={progress.percentage}
                                    max={100}
                                    className="h-2 flex-1"
                                />
                            )}
                        </div>
                    </>
                )}
            </Form>
        </div>
    );
}
