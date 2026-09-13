import FormField from '@/components/forms/form-field';
import SectionCard from '@/components/section-card';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import type { MediaRow, ProductDetail, SeoPreview } from '@/types';

type Props = {
    product: ProductDetail | null;
    media: MediaRow[];
    preview: SeoPreview | null;
    errors: Record<string, string | undefined>;
};

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none disabled:opacity-60';

/**
 * Search engine and sharing fields, inside the product form (§11.1, §34.3).
 *
 * The previews below the fields are the server's own output for the saved
 * product — the search listing a partner page would produce, and the JSON-LD it
 * would embed — so what is checked here is exactly what a website receives, not
 * a client-side imitation of it.
 */
export default function SeoFields({ product, media, preview, errors }: Props) {
    const { t } = useTranslation();

    const images = media.filter((item) => item.type === 'image');

    return (
        <SectionCard
            title={t('catalog.seo.title')}
            description={t('catalog.seo.description')}
        >
            <div className="space-y-6">
                <div className="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label={t('catalog.seo.meta_title')}
                        hint={t('catalog.seo.meta_title_help')}
                        error={errors.meta_title}
                        className="sm:col-span-2"
                    >
                        {(field) => (
                            <Input
                                {...field}
                                name="meta_title"
                                maxLength={70}
                                defaultValue={product?.meta_title ?? ''}
                            />
                        )}
                    </FormField>

                    <FormField
                        label={t('catalog.seo.meta_description')}
                        hint={t('catalog.seo.meta_description_help')}
                        error={errors.meta_description}
                        className="sm:col-span-2"
                    >
                        {(field) => (
                            <textarea
                                {...field}
                                name="meta_description"
                                rows={3}
                                maxLength={200}
                                className={controlClass}
                                defaultValue={product?.meta_description ?? ''}
                            />
                        )}
                    </FormField>

                    <FormField
                        label={t('catalog.seo.meta_keywords')}
                        error={errors.meta_keywords}
                        className="sm:col-span-2"
                    >
                        {(field) => (
                            <Input
                                {...field}
                                name="meta_keywords"
                                maxLength={255}
                                defaultValue={product?.meta_keywords ?? ''}
                            />
                        )}
                    </FormField>

                    <FormField
                        label={t('catalog.seo.mpn')}
                        hint={t('catalog.seo.mpn_help')}
                        error={errors.mpn}
                    >
                        {(field) => (
                            <Input
                                {...field}
                                name="mpn"
                                maxLength={70}
                                className="font-mono"
                                defaultValue={product?.mpn ?? ''}
                            />
                        )}
                    </FormField>

                    <FormField
                        label={t('catalog.seo.item_condition')}
                        error={errors.item_condition}
                    >
                        {(field) => (
                            <select
                                {...field}
                                name="item_condition"
                                className={controlClass}
                                defaultValue={product?.item_condition ?? 'new'}
                            >
                                {(['new', 'refurbished', 'used'] as const).map(
                                    (condition) => (
                                        <option
                                            key={condition}
                                            value={condition}
                                        >
                                            {t(
                                                `catalog.seo.conditions.${condition}`,
                                            )}
                                        </option>
                                    ),
                                )}
                            </select>
                        )}
                    </FormField>

                    {product && (
                        <FormField
                            label={t('catalog.seo.social_image')}
                            hint={t('catalog.seo.social_image_help')}
                            error={errors.social_image_id}
                            className="sm:col-span-2"
                        >
                            {(field) => (
                                <select
                                    {...field}
                                    name="social_image_id"
                                    className={controlClass}
                                    defaultValue={product.social_image_id ?? ''}
                                    disabled={images.length === 0}
                                >
                                    <option value="">
                                        {t('catalog.seo.social_image_first')}
                                    </option>
                                    {images.map((image) => (
                                        <option key={image.id} value={image.id}>
                                            {image.alt_text ??
                                                `#${image.position}`}
                                        </option>
                                    ))}
                                </select>
                            )}
                        </FormField>
                    )}
                </div>

                {preview && (
                    <>
                        <div className="space-y-1.5">
                            <h3 className="text-sm font-medium">
                                {t('catalog.seo.preview')}
                            </h3>
                            <div className="bg-muted/40 flex gap-3 rounded-md border p-3">
                                {preview.metadata.image && (
                                    <img
                                        src={preview.metadata.image.url}
                                        alt={preview.metadata.image.alt ?? ''}
                                        className="size-16 shrink-0 rounded object-cover"
                                    />
                                )}
                                <div className="min-w-0 space-y-0.5">
                                    <p className="text-primary truncate text-base font-medium">
                                        {preview.metadata.title}
                                    </p>
                                    <p className="text-muted-foreground text-sm">
                                        {preview.metadata.description ??
                                            t('catalog.seo.no_description')}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div className="space-y-1.5">
                            <h3 className="text-sm font-medium">
                                {t('catalog.seo.schema_preview')}
                            </h3>
                            <p className="text-muted-foreground text-xs">
                                {t('catalog.seo.schema_preview_help')}
                            </p>
                            <pre className="bg-muted/40 max-h-80 overflow-auto rounded-md border p-3 text-xs">
                                <code>{preview.schema}</code>
                            </pre>
                        </div>
                    </>
                )}
            </div>
        </SectionCard>
    );
}
