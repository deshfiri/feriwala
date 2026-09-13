import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Trash2 } from 'lucide-react';
import ProductController from '@/actions/App/Http/Controllers/Admin/ProductController';
import FormField from '@/components/forms/form-field';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/admin/catalog/products';
import type {
    AccountMatch,
    AttributeOption,
    CatalogAbilities,
    CatalogOption,
    MediaLimits,
    MediaRow,
    MerchandisingState,
    PriceTierScope,
    ProductDetail,
    ProductEligibilityState,
    ProductStatusChangeRow,
    ProductTransition,
    RelatedProductRow,
    VariantRow,
} from '@/types';
import MerchandisingSection from './merchandising-section';
import EligibilitySection from './eligibility-section';
import StatusPanel from './status-panel';
import { ProductStatusPill } from './index';
import MediaSection from './media-section';
import PriceTiersSection from './price-tiers-section';
import VariantsSection from './variants-section';

type Props = {
    product: ProductDetail | null;
    options: { categories: CatalogOption[]; brands: CatalogOption[] };
    can: CatalogAbilities;
    variants: VariantRow[];
    attributes: AttributeOption[];
    media: MediaRow[];
    media_limits: MediaLimits;
    price_tiers: PriceTierScope[];
    transitions: ProductTransition[];
    history: ProductStatusChangeRow[];
    eligibility: ProductEligibilityState | null;
    package_options: CatalogOption[];
    account_matches?: AccountMatch[];
    merchandising: MerchandisingState | null;
    related_matches?: RelatedProductRow[];
};

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none disabled:opacity-60';

const textareaClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none disabled:opacity-60';

/**
 * Creating or editing one central product (§11.1, §12).
 *
 * A read-only viewer gets the same page with every field disabled rather than a
 * different one, so "what does this product say" has one answer for everybody —
 * and the refusal that matters is the server's, not the disabled fieldset.
 *
 * Prices are posted as integer minor units, like every amount this application
 * accepts, and the saved figure is shown beneath each field in the server's own
 * formatting so a misplaced zero is visible before anybody buys at it.
 */
export default function ProductForm({
    product,
    options,
    can,
    variants,
    attributes,
    media,
    media_limits,
    price_tiers,
    transitions,
    history,
    eligibility,
    package_options,
    account_matches,
    merchandising,
    related_matches,
}: Props) {
    const { t } = useTranslation();
    const page = usePage<{ errors: Record<string, string> }>();

    const editing = product !== null;
    const writable = editing ? can.edit : can.create;

    const remove = () => {
        if (!product || !window.confirm(t('catalog.products.delete_confirm'))) {
            return;
        }

        router.delete(ProductController.destroy.url(product.id));
    };

    const optionLabel = (option: CatalogOption) =>
        option.is_available
            ? option.label
            : t('catalog.products.fields.switched_off', {
                  name: option.label,
              });

    return (
        <>
            <Head
                title={
                    editing ? product.name : t('catalog.products.create_title')
                }
            />

            <PageContainer>
                <Link
                    href={index()}
                    className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 text-sm"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    {t('catalog.products.back')}
                </Link>

                <PageHeader
                    title={
                        editing
                            ? product.name
                            : t('catalog.products.create_title')
                    }
                    description={
                        writable
                            ? t(
                                  editing
                                      ? 'catalog.products.edit_description'
                                      : 'catalog.products.create_description',
                              )
                            : t('catalog.products.read_only')
                    }
                    actions={
                        editing ? (
                            <ProductStatusPill
                                status={product.status}
                                tone={product.status_tone}
                            />
                        ) : undefined
                    }
                />

                {editing && (
                    <StatusPanel
                        product={product}
                        transitions={transitions}
                        history={history}
                        can={can}
                    />
                )}

                <Form
                    {...(editing
                        ? ProductController.update.form(product.id)
                        : ProductController.store.form())}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ errors, processing }) => (
                        <fieldset disabled={!writable} className="space-y-6">
                            <SectionCard
                                title={t('catalog.products.sections.identity')}
                                description={t(
                                    'catalog.products.sections.identity_help',
                                )}
                            >
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FormField
                                        label={t(
                                            'catalog.products.fields.name',
                                        )}
                                        error={errors.name}
                                        required
                                        className="sm:col-span-2"
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="name"
                                                maxLength={160}
                                                defaultValue={
                                                    product?.name ?? ''
                                                }
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t('catalog.products.fields.sku')}
                                        hint={t(
                                            'catalog.products.fields.sku_help',
                                        )}
                                        error={errors.sku}
                                        required
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="sku"
                                                maxLength={64}
                                                className="font-mono uppercase"
                                                defaultValue={
                                                    product?.sku ?? ''
                                                }
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t(
                                            'catalog.products.fields.barcode',
                                        )}
                                        hint={t(
                                            'catalog.products.fields.barcode_help',
                                        )}
                                        error={errors.barcode}
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="barcode"
                                                maxLength={64}
                                                className="font-mono"
                                                defaultValue={
                                                    product?.barcode ?? ''
                                                }
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t(
                                            'catalog.products.fields.slug',
                                        )}
                                        hint={t(
                                            'catalog.products.fields.slug_help',
                                        )}
                                        error={errors.slug}
                                        className="sm:col-span-2"
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="slug"
                                                maxLength={180}
                                                placeholder={t(
                                                    'catalog.products.fields.slug_placeholder',
                                                )}
                                                defaultValue={
                                                    product?.slug ?? ''
                                                }
                                            />
                                        )}
                                    </FormField>
                                </div>
                            </SectionCard>

                            <SectionCard
                                title={t('catalog.products.sections.content')}
                                description={t(
                                    'catalog.products.sections.content_help',
                                )}
                            >
                                <div className="grid gap-4">
                                    <FormField
                                        label={t(
                                            'catalog.products.fields.short_description',
                                        )}
                                        error={errors.short_description}
                                    >
                                        {(field) => (
                                            <textarea
                                                {...field}
                                                name="short_description"
                                                rows={2}
                                                maxLength={500}
                                                className={textareaClass}
                                                defaultValue={
                                                    product?.short_description ??
                                                    ''
                                                }
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t(
                                            'catalog.products.fields.description',
                                        )}
                                        error={errors.description}
                                    >
                                        {(field) => (
                                            <textarea
                                                {...field}
                                                name="description"
                                                rows={8}
                                                maxLength={20000}
                                                className={textareaClass}
                                                defaultValue={
                                                    product?.description ?? ''
                                                }
                                            />
                                        )}
                                    </FormField>
                                </div>
                            </SectionCard>

                            <SectionCard
                                title={t('catalog.products.sections.placement')}
                                description={t(
                                    'catalog.products.sections.placement_help',
                                )}
                            >
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FormField
                                        label={t(
                                            'catalog.products.fields.category',
                                        )}
                                        error={errors.category_id}
                                        required
                                    >
                                        {(field) => (
                                            <select
                                                {...field}
                                                name="category_id"
                                                className={selectClass}
                                                defaultValue={
                                                    product?.category_id ?? ''
                                                }
                                            >
                                                <option value="" disabled>
                                                    {t(
                                                        'catalog.products.fields.category_placeholder',
                                                    )}
                                                </option>
                                                {options.categories.map(
                                                    (option) => (
                                                        <option
                                                            key={option.value}
                                                            value={option.value}
                                                        >
                                                            {optionLabel(
                                                                option,
                                                            )}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t(
                                            'catalog.products.fields.brand',
                                        )}
                                        error={errors.brand_id}
                                    >
                                        {(field) => (
                                            <select
                                                {...field}
                                                name="brand_id"
                                                className={selectClass}
                                                defaultValue={
                                                    product?.brand_id ?? ''
                                                }
                                            >
                                                <option value="">
                                                    {t(
                                                        'catalog.products.fields.no_brand',
                                                    )}
                                                </option>
                                                {options.brands.map(
                                                    (option) => (
                                                        <option
                                                            key={option.value}
                                                            value={option.value}
                                                        >
                                                            {optionLabel(
                                                                option,
                                                            )}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        )}
                                    </FormField>
                                </div>
                            </SectionCard>

                            <SectionCard
                                title={t('catalog.products.sections.pricing')}
                                description={t(
                                    'catalog.products.sections.pricing_help',
                                )}
                            >
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FormField
                                        label={t(
                                            'catalog.products.fields.wholesale_price',
                                        )}
                                        description={t(
                                            'catalog.products.fields.wholesale_price_help',
                                        )}
                                        hint={
                                            product
                                                ? t(
                                                      'catalog.products.fields.saved_as',
                                                      {
                                                          amount: product
                                                              .wholesale_price
                                                              .formatted,
                                                      },
                                                  )
                                                : undefined
                                        }
                                        error={errors.wholesale_price_minor}
                                        required
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="wholesale_price_minor"
                                                type="number"
                                                inputMode="numeric"
                                                min={0}
                                                step={1}
                                                className="tabular-nums"
                                                defaultValue={String(
                                                    product?.wholesale_price_minor ??
                                                        '',
                                                )}
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t(
                                            'catalog.products.fields.base_cost',
                                        )}
                                        description={t(
                                            'catalog.products.fields.base_cost_help',
                                        )}
                                        hint={
                                            product
                                                ? t(
                                                      'catalog.products.fields.saved_as',
                                                      {
                                                          amount: product
                                                              .base_cost
                                                              .formatted,
                                                      },
                                                  )
                                                : undefined
                                        }
                                        error={errors.base_cost_minor}
                                        required
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="base_cost_minor"
                                                type="number"
                                                inputMode="numeric"
                                                min={0}
                                                step={1}
                                                className="tabular-nums"
                                                defaultValue={String(
                                                    product?.base_cost_minor ??
                                                        '',
                                                )}
                                            />
                                        )}
                                    </FormField>
                                </div>
                            </SectionCard>

                            <SectionCard
                                title={t('catalog.products.sections.bounds')}
                                description={t(
                                    'catalog.products.sections.bounds_help',
                                )}
                            >
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FormField
                                        label={t(
                                            'catalog.products.fields.min_order_quantity',
                                        )}
                                        hint={t(
                                            'catalog.products.fields.min_order_quantity_help',
                                        )}
                                        error={errors.min_order_quantity}
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="min_order_quantity"
                                                type="number"
                                                inputMode="numeric"
                                                min={1}
                                                step={1}
                                                className="tabular-nums"
                                                defaultValue={
                                                    product?.min_order_quantity ??
                                                    ''
                                                }
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t(
                                            'catalog.products.fields.max_order_quantity',
                                        )}
                                        hint={t(
                                            'catalog.products.fields.max_order_quantity_help',
                                        )}
                                        error={errors.max_order_quantity}
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="max_order_quantity"
                                                type="number"
                                                inputMode="numeric"
                                                min={1}
                                                step={1}
                                                className="tabular-nums"
                                                defaultValue={
                                                    product?.max_order_quantity ??
                                                    ''
                                                }
                                            />
                                        )}
                                    </FormField>

                                    {(
                                        [
                                            [
                                                'suggested_selling_price_minor',
                                                'suggested_selling_price',
                                            ],
                                            [
                                                'minimum_selling_price_minor',
                                                'minimum_selling_price',
                                            ],
                                            [
                                                'maximum_selling_price_minor',
                                                'maximum_selling_price',
                                            ],
                                        ] as const
                                    ).map(([name, rendered]) => (
                                        <FormField
                                            key={name}
                                            label={t(
                                                `catalog.products.fields.${rendered}`,
                                            )}
                                            hint={
                                                product?.[rendered]
                                                    ? t(
                                                          'catalog.products.fields.saved_as',
                                                          {
                                                              amount: product[
                                                                  rendered
                                                              ].formatted,
                                                          },
                                                      )
                                                    : t(
                                                          'catalog.products.fields.no_bound',
                                                      )
                                            }
                                            error={errors[name]}
                                        >
                                            {(field) => (
                                                <Input
                                                    {...field}
                                                    name={name}
                                                    type="number"
                                                    inputMode="numeric"
                                                    min={0}
                                                    step={1}
                                                    className="tabular-nums"
                                                    defaultValue={
                                                        product?.[name] ?? ''
                                                    }
                                                />
                                            )}
                                        </FormField>
                                    ))}
                                </div>
                            </SectionCard>

                            {writable && (
                                <div className="flex justify-end">
                                    <Button type="submit" disabled={processing}>
                                        {editing
                                            ? t('catalog.products.save')
                                            : t('catalog.products.create')}
                                    </Button>
                                </div>
                            )}
                        </fieldset>
                    )}
                </Form>

                {editing && merchandising && (
                    <MerchandisingSection
                        product={product}
                        merchandising={merchandising}
                        relatedMatches={related_matches}
                        can={can}
                    />
                )}

                {editing && eligibility && (
                    <EligibilitySection
                        product={product}
                        eligibility={eligibility}
                        packageOptions={package_options}
                        accountMatches={account_matches}
                        canEdit={can.edit}
                    />
                )}

                {editing && (
                    <PriceTiersSection
                        product={product}
                        scopes={price_tiers}
                        canEdit={can.edit}
                    />
                )}

                {editing && (
                    <MediaSection
                        product={product}
                        media={media}
                        limits={media_limits}
                        variants={variants}
                        canEdit={can.edit}
                    />
                )}

                {editing && (
                    <VariantsSection
                        product={product}
                        variants={variants}
                        attributes={attributes}
                        can={can}
                    />
                )}

                {editing && can.delete && product.status === 'draft' && (
                    <SectionCard
                        tone="destructive"
                        title={t('catalog.products.danger_title')}
                        description={t('catalog.products.danger_help')}
                    >
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <InputError message={page.props.errors?.product} />
                            <Button variant="destructive" onClick={remove}>
                                <Trash2 className="size-4" aria-hidden="true" />
                                {t('catalog.products.delete')}
                            </Button>
                        </div>
                    </SectionCard>
                )}
            </PageContainer>
        </>
    );
}
