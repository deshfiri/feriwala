import { Form, Head, usePage } from '@inertiajs/react';
import {
    Boxes,
    Download,
    Image as ImageIcon,
    Info,
    Layers,
    Link2,
    Megaphone,
    Search,
    Tag,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import ProductController from '@/actions/App/Http/Controllers/Admin/ProductController';
import FormField from '@/components/forms/form-field';
import MoneyField from '@/components/forms/money-field';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import SectionCard from '@/components/section-card';
import SectionTabs, { SectionTabPanel } from '@/components/section-tabs';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import type {
    AccountMatch,
    AttributeOption,
    CatalogAbilities,
    CatalogOption,
    ContentLimits,
    ContentRow,
    MediaLimits,
    MediaRow,
    LinkedProductsState,
    MerchandisingState,
    PriceTierScope,
    ProductDetail,
    ProductEligibilityState,
    ProductStatusChangeRow,
    ProductTransition,
    RelatedProductRow,
    SeoPreview,
    VariantRow,
} from '@/types';
import SeoFields from './seo-fields';
import LinkedProductsSection from './linked-products-section';
import LogisticsFields from './logistics-fields';
import MerchandisingSection from './merchandising-section';
import EligibilitySection from './eligibility-section';
import StatusPanel from './status-panel';
import ContentSection from './content-section';
import MediaSection from './media-section';
import PriceTiersSection from './price-tiers-section';
import ProductHeader from './product-header';
import TrashReasonDialog from './trash-reason-dialog';
import VariantsSection from './variants-section';

type Props = {
    product: ProductDetail | null;
    options: { categories: CatalogOption[]; brands: CatalogOption[] };
    can: CatalogAbilities;
    /** Staff-only: Same Product links; null without `sourcing_group.view`. */
    linked_products?: LinkedProductsState | null;
    variants: VariantRow[];
    attributes: AttributeOption[];
    media: MediaRow[];
    media_limits: MediaLimits;
    variant_builder_max: number;
    price_tiers: PriceTierScope[];
    transitions: ProductTransition[];
    history: ProductStatusChangeRow[];
    eligibility: ProductEligibilityState | null;
    package_options: CatalogOption[];
    account_matches?: AccountMatch[];
    merchandising: MerchandisingState | null;
    related_matches?: RelatedProductRow[];
    seo_preview: SeoPreview | null;
    content: ContentRow[];
    content_limits: ContentLimits;
};

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none disabled:opacity-60';

const textareaClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none disabled:opacity-60';

/**
 * Which tab owns a field, for jumping there when the shared product form
 * (Basic Information, Pricing's money fields, Logistics, SEO all post to the
 * one `ProductController::update` route) comes back with an error on a tab
 * that is not the one currently open (new UI pass — the field set, the
 * route and the validation are all unchanged; only where each field is
 * drawn moved).
 */
const FIELD_TAB: Record<string, string> = {
    name: 'basic',
    slug: 'basic',
    sku: 'basic',
    barcode: 'basic',
    short_description: 'basic',
    description: 'basic',
    category_id: 'basic',
    brand_id: 'basic',
    min_order_quantity: 'basic',
    max_order_quantity: 'basic',
    featured: 'basic',
    related_ids: 'basic',
    package_scope: 'basic',
    package_ids: 'basic',
    account_scope: 'basic',
    account_ids: 'basic',
    wholesale_price: 'pricing',
    base_cost: 'pricing',
    suggested_selling_price: 'pricing',
    minimum_selling_price: 'pricing',
    maximum_selling_price: 'pricing',
    tiers: 'pricing',
    values: 'variants',
    alt_text: 'media',
    net_weight_grams: 'logistics',
    shipping_weight_grams: 'logistics',
    length_cm: 'logistics',
    width_cm: 'logistics',
    height_cm: 'logistics',
    ships_by_box: 'logistics',
    pieces_per_box: 'logistics',
    box_weight_grams: 'logistics',
    box_length_cm: 'logistics',
    box_width_cm: 'logistics',
    box_height_cm: 'logistics',
    is_fragile: 'logistics',
    title: 'content',
    body: 'content',
    image: 'content',
    file: 'media',
    meta_title: 'seo',
    meta_description: 'seo',
    meta_keywords: 'seo',
    mpn: 'seo',
    item_condition: 'seo',
    social_image_id: 'seo',
};

/**
 * Creating or editing one central product (§11.1, §12).
 *
 * A read-only viewer gets the same page with every field disabled rather than a
 * different one, so "what does this product say" has one answer for everybody —
 * and the refusal that matters is the server's, not the disabled fieldset.
 *
 * Prices are posted as flat-Taka decimal strings exactly as typed (D26), like
 * every amount this application accepts, and the saved figure is shown beneath
 * each field in the server's own formatting so a misplaced zero is visible
 * before anybody buys at it.
 *
 * The fields themselves, their routes and their validation are unchanged from
 * the earlier single long form — this organizes the same page into a compact
 * header plus named tabs instead of one continuous scroll (new UI pass).
 */
export default function ProductForm({
    product,
    options,
    can,
    linked_products,
    variants,
    attributes,
    media,
    media_limits,
    variant_builder_max,
    price_tiers,
    transitions,
    history,
    eligibility,
    package_options,
    account_matches,
    merchandising,
    related_matches,
    seo_preview,
    content,
    content_limits,
}: Props) {
    const { t } = useTranslation();
    const page = usePage<{ errors: Record<string, string> }>();
    const errors = page.props.errors ?? {};

    const editing = product !== null;
    const writable = editing ? can.edit : can.create;

    const TABS = [
        { key: 'basic', label: t('catalog.products.tabs.basic'), icon: Info },
        {
            key: 'pricing',
            label: t('catalog.products.tabs.pricing'),
            icon: Tag,
        },
        ...(editing
            ? [
                  {
                      key: 'variants',
                      label: t('catalog.products.tabs.variants'),
                      icon: Layers,
                  },
                  {
                      key: 'media',
                      label: t('catalog.products.tabs.media'),
                      icon: ImageIcon,
                  },
              ]
            : []),
        {
            key: 'logistics',
            label: t('catalog.products.tabs.logistics'),
            icon: Boxes,
        },
        ...(editing
            ? [
                  {
                      key: 'content',
                      label: t('catalog.products.tabs.content'),
                      icon: Megaphone,
                  },
              ]
            : []),
        ...(editing && linked_products
            ? [
                  {
                      key: 'links',
                      label: t('catalog.products.tabs.links'),
                      icon: Link2,
                  },
              ]
            : []),
        { key: 'seo', label: t('catalog.products.tabs.seo'), icon: Search },
    ];

    const [activeTab, setActiveTab] = useState(TABS[0].key);

    // A validation error on a tab that is not open jumps there, so an error
    // raised from the always-visible Save action is never silently hidden
    // behind a tab nobody is looking at.
    useEffect(() => {
        const keys = Object.keys(errors);

        if (keys.length === 0) {
            return;
        }

        const owning = keys
            .map((key) => FIELD_TAB[key])
            .find((tab): tab is string => Boolean(tab));

        if (owning && owning !== activeTab) {
            setActiveTab(owning);
        }
        // Only when the error set itself changes — not on every keystroke.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [errors]);

    const tabsWithErrors = new Set(
        Object.keys(errors)
            .map((key) => FIELD_TAB[key])
            .filter((tab): tab is string => Boolean(tab)),
    );

    const [trashOpen, setTrashOpen] = useState(false);

    const optionLabel = (option: CatalogOption) =>
        option.is_available
            ? option.label
            : t('catalog.products.fields.switched_off', {
                  name: option.label,
              });

    const category =
        options.categories.find(
            (option) => option.value === product?.category_id,
        )?.label ?? null;

    const primaryImage =
        media.find((item) => item.type === 'image' && item.position === 1) ??
        media.find((item) => item.type === 'image');

    return (
        <>
            <Head
                title={
                    editing ? product.name : t('catalog.products.create_title')
                }
            />

            <PageContainer className={writable ? 'pb-24 lg:pb-8' : undefined}>
                <Form
                    {...(editing
                        ? ProductController.update.form(product.id)
                        : ProductController.store.form())}
                    options={{ preserveScroll: true }}
                >
                    {({ errors: formErrors, processing }) => (
                        <div className="space-y-5 sm:space-y-6">
                            <ProductHeader
                                product={product}
                                category={category}
                                imageUrl={primaryImage?.url ?? null}
                                saveLabel={
                                    editing
                                        ? t('catalog.products.save')
                                        : t('catalog.products.create')
                                }
                                processing={processing}
                                canSave={writable}
                                canDelete={
                                    editing &&
                                    can.delete &&
                                    (product?.is_deletable ?? false)
                                }
                                onDelete={() => setTrashOpen(true)}
                                deleteError={
                                    formErrors.product ? (
                                        <InputError
                                            message={formErrors.product}
                                        />
                                    ) : undefined
                                }
                            />

                            {editing && (
                                <TrashReasonDialog
                                    open={trashOpen}
                                    onOpenChange={setTrashOpen}
                                    productName={product.name}
                                    url={ProductController.destroy.url(
                                        product.id,
                                    )}
                                />
                            )}

                            {!editing && (
                                <p className="text-muted-foreground -mt-2 text-sm">
                                    {t('catalog.products.create_description')}
                                </p>
                            )}
                            {editing && !writable && (
                                <p className="text-muted-foreground -mt-2 text-sm">
                                    {t('catalog.products.read_only')}
                                </p>
                            )}

                            <SectionTabs
                                items={TABS.map((tab) => ({
                                    ...tab,
                                    hasError: tabsWithErrors.has(tab.key),
                                }))}
                                active={activeTab}
                                onChange={setActiveTab}
                            />

                            <fieldset
                                disabled={!writable}
                                className="space-y-5 sm:space-y-6"
                            >
                                <SectionTabPanel
                                    tab="basic"
                                    active={activeTab}
                                    keepMounted
                                >
                                    {editing && (
                                        <StatusPanel
                                            product={product}
                                            transitions={transitions}
                                            history={history}
                                            can={can}
                                        />
                                    )}

                                    <SectionCard
                                        title={t(
                                            'catalog.products.sections.identity',
                                        )}
                                        description={t(
                                            'catalog.products.sections.identity_help',
                                        )}
                                    >
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <FormField
                                                label={t(
                                                    'catalog.products.fields.name',
                                                )}
                                                error={formErrors.name}
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
                                                label={t(
                                                    'catalog.products.fields.sku',
                                                )}
                                                hint={t(
                                                    'catalog.products.fields.sku_help',
                                                )}
                                                error={formErrors.sku}
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
                                                error={formErrors.barcode}
                                            >
                                                {(field) => (
                                                    <div className="flex items-center gap-2">
                                                        <Input
                                                            {...field}
                                                            name="barcode"
                                                            maxLength={13}
                                                            inputMode="numeric"
                                                            className="font-mono"
                                                            defaultValue={
                                                                product?.barcode ??
                                                                ''
                                                            }
                                                        />
                                                        {product?.barcode && (
                                                            <Button
                                                                type="button"
                                                                variant="outline"
                                                                size="sm"
                                                                asChild
                                                            >
                                                                <a
                                                                    href={ProductController.barcode.url(
                                                                        product.id,
                                                                    )}
                                                                    download
                                                                >
                                                                    <Download
                                                                        className="size-4"
                                                                        aria-hidden="true"
                                                                    />
                                                                    <span className="sr-only sm:not-sr-only">
                                                                        {t(
                                                                            'catalog.products.fields.download_barcode',
                                                                        )}
                                                                    </span>
                                                                </a>
                                                            </Button>
                                                        )}
                                                    </div>
                                                )}
                                            </FormField>

                                            <FormField
                                                label={t(
                                                    'catalog.products.fields.slug',
                                                )}
                                                hint={t(
                                                    'catalog.products.fields.slug_help',
                                                )}
                                                error={formErrors.slug}
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
                                        title={t(
                                            'catalog.products.sections.content',
                                        )}
                                        description={t(
                                            'catalog.products.sections.content_help',
                                        )}
                                    >
                                        <div className="grid gap-4">
                                            <FormField
                                                label={t(
                                                    'catalog.products.fields.short_description',
                                                )}
                                                error={
                                                    formErrors.short_description
                                                }
                                            >
                                                {(field) => (
                                                    <textarea
                                                        {...field}
                                                        name="short_description"
                                                        rows={2}
                                                        maxLength={500}
                                                        className={
                                                            textareaClass
                                                        }
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
                                                error={formErrors.description}
                                            >
                                                {(field) => (
                                                    <textarea
                                                        {...field}
                                                        name="description"
                                                        rows={8}
                                                        maxLength={20000}
                                                        className={
                                                            textareaClass
                                                        }
                                                        defaultValue={
                                                            product?.description ??
                                                            ''
                                                        }
                                                    />
                                                )}
                                            </FormField>
                                        </div>
                                    </SectionCard>

                                    <SectionCard
                                        title={t(
                                            'catalog.products.sections.placement',
                                        )}
                                        description={t(
                                            'catalog.products.sections.placement_help',
                                        )}
                                    >
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <FormField
                                                label={t(
                                                    'catalog.products.fields.category',
                                                )}
                                                error={formErrors.category_id}
                                                required
                                            >
                                                {(field) => (
                                                    <select
                                                        {...field}
                                                        name="category_id"
                                                        className={selectClass}
                                                        defaultValue={
                                                            product?.category_id ??
                                                            ''
                                                        }
                                                    >
                                                        <option
                                                            value=""
                                                            disabled
                                                        >
                                                            {t(
                                                                'catalog.products.fields.category_placeholder',
                                                            )}
                                                        </option>
                                                        {options.categories.map(
                                                            (option) => (
                                                                <option
                                                                    key={
                                                                        option.value
                                                                    }
                                                                    value={
                                                                        option.value
                                                                    }
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
                                                error={formErrors.brand_id}
                                            >
                                                {(field) => (
                                                    <select
                                                        {...field}
                                                        name="brand_id"
                                                        className={selectClass}
                                                        defaultValue={
                                                            product?.brand_id ??
                                                            ''
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
                                                                    key={
                                                                        option.value
                                                                    }
                                                                    value={
                                                                        option.value
                                                                    }
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
                                        title={t(
                                            'catalog.products.sections.bounds',
                                        )}
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
                                                error={
                                                    formErrors.min_order_quantity
                                                }
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
                                                error={
                                                    formErrors.max_order_quantity
                                                }
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
                                        </div>
                                    </SectionCard>
                                </SectionTabPanel>

                                <SectionTabPanel
                                    tab="pricing"
                                    active={activeTab}
                                    keepMounted
                                >
                                    <SectionCard
                                        title={t(
                                            'catalog.products.sections.pricing',
                                        )}
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
                                                error={
                                                    formErrors.wholesale_price
                                                }
                                                required
                                            >
                                                {(field) => (
                                                    <MoneyField
                                                        {...field}
                                                        name="wholesale_price"
                                                        defaultValue={
                                                            product
                                                                ?.wholesale_price
                                                                .amount
                                                        }
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
                                                error={formErrors.base_cost}
                                                required
                                            >
                                                {(field) => (
                                                    <MoneyField
                                                        {...field}
                                                        name="base_cost"
                                                        defaultValue={
                                                            product?.base_cost
                                                                .amount
                                                        }
                                                    />
                                                )}
                                            </FormField>
                                        </div>
                                    </SectionCard>

                                    <SectionCard
                                        title={t(
                                            'catalog.products.sections.selling_guidance',
                                        )}
                                        description={t(
                                            'catalog.products.sections.selling_guidance_help',
                                        )}
                                    >
                                        <div className="grid gap-4 sm:grid-cols-3">
                                            {(
                                                [
                                                    'suggested_selling_price',
                                                    'minimum_selling_price',
                                                    'maximum_selling_price',
                                                ] as const
                                            ).map((name) => (
                                                <FormField
                                                    key={name}
                                                    label={t(
                                                        `catalog.products.fields.${name}`,
                                                    )}
                                                    hint={
                                                        product?.[name]
                                                            ? t(
                                                                  'catalog.products.fields.saved_as',
                                                                  {
                                                                      amount: product[
                                                                          name
                                                                      ]
                                                                          .formatted,
                                                                  },
                                                              )
                                                            : t(
                                                                  'catalog.products.fields.no_bound',
                                                              )
                                                    }
                                                    error={formErrors[name]}
                                                >
                                                    {(field) => (
                                                        <MoneyField
                                                            {...field}
                                                            name={name}
                                                            defaultValue={
                                                                product?.[name]
                                                                    ?.amount
                                                            }
                                                        />
                                                    )}
                                                </FormField>
                                            ))}
                                        </div>
                                    </SectionCard>
                                </SectionTabPanel>

                                <SectionTabPanel
                                    tab="logistics"
                                    active={activeTab}
                                    keepMounted
                                >
                                    <SectionCard
                                        title={t(
                                            'catalog.products.sections.logistics',
                                        )}
                                        description={t(
                                            'catalog.products.sections.logistics_help',
                                        )}
                                    >
                                        <LogisticsFields
                                            defaultValues={product ?? {}}
                                            errors={formErrors}
                                        />
                                    </SectionCard>
                                </SectionTabPanel>

                                <SectionTabPanel
                                    tab="seo"
                                    active={activeTab}
                                    keepMounted
                                >
                                    <SeoFields
                                        product={product}
                                        media={media}
                                        preview={seo_preview}
                                        errors={formErrors}
                                    />
                                </SectionTabPanel>
                            </fieldset>

                            {writable && (
                                <div className="bg-background/95 fixed inset-x-0 bottom-0 z-40 border-t px-4 py-3 [padding-bottom:calc(0.75rem+env(safe-area-inset-bottom))] backdrop-blur lg:sticky lg:bottom-4 lg:mt-2 lg:rounded-xl lg:border lg:px-5 lg:shadow-sm">
                                    <div className="mx-auto flex max-w-[96rem] items-center justify-end gap-3">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {editing
                                                ? t('catalog.products.save')
                                                : t('catalog.products.create')}
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                </Form>

                {/*
                 * These keep their own independent form/submission and must
                 * stay outside the product-fields `<form>` above — nesting an
                 * HTML form inside another is invalid and breaks submission
                 * in both directions. Each is still gated to the tab it
                 * visually belongs to, so only one shows at a time.
                 */}
                {editing && activeTab === 'basic' && merchandising && (
                    <MerchandisingSection
                        product={product}
                        merchandising={merchandising}
                        relatedMatches={related_matches}
                        can={can}
                    />
                )}

                {editing && activeTab === 'basic' && eligibility && (
                    <EligibilitySection
                        product={product}
                        eligibility={eligibility}
                        packageOptions={package_options}
                        accountMatches={account_matches}
                        canEdit={can.edit}
                    />
                )}

                {editing && activeTab === 'pricing' && (
                    <PriceTiersSection
                        product={product}
                        scopes={price_tiers}
                        canEdit={can.edit}
                    />
                )}

                {editing && (
                    <SectionTabPanel tab="variants" active={activeTab}>
                        <VariantsSection
                            product={product}
                            variants={variants}
                            attributes={attributes}
                            builderMax={variant_builder_max}
                            can={can}
                        />
                    </SectionTabPanel>
                )}

                {editing && (
                    <SectionTabPanel tab="media" active={activeTab}>
                        <MediaSection
                            product={product}
                            media={media}
                            limits={media_limits}
                            variants={variants}
                            canEdit={can.edit}
                        />
                    </SectionTabPanel>
                )}

                {editing && linked_products && (
                    <SectionTabPanel tab="links" active={activeTab}>
                        <LinkedProductsSection
                            product={product}
                            linked={linked_products}
                        />
                    </SectionTabPanel>
                )}

                {editing && (
                    <SectionTabPanel tab="content" active={activeTab}>
                        <ContentSection
                            product={product}
                            content={content}
                            limits={content_limits}
                            canEdit={can.edit}
                        />
                    </SectionTabPanel>
                )}
            </PageContainer>
        </>
    );
}
