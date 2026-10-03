import { Form, Head, Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import SourcingGroupController from '@/actions/App/Http/Controllers/Admin/SourcingGroupController';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import TextArea from '@/components/forms/text-area';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import SectionTabs, { SectionTabPanel } from '@/components/section-tabs';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';

type VariantOption = { id: string; label: string };

type Props = {
    group: {
        id: string;
        code: string;
        name_en: string;
        name_bn: string;
        description: string | null;
        is_active: boolean;
    };
    products: {
        id: string;
        name: string;
        sku: string;
        is_canonical: boolean;
        added_reason: string;
        variants: VariantOption[];
    }[];
    canonical_variants: VariantOption[];
    mappings: {
        id: string;
        product: { id: string; name: string };
        variant: string | null;
        canonical_variant: string | null;
        reason: string;
    }[];
    offers: {
        id: string;
        supplier: string;
        product: string;
        variant: string | null;
        status: string;
        supply_mode: string;
    }[];
    stock: {
        id: string;
        warehouse: string;
        product: string;
        variant: string | null;
        available: number;
    }[];
    history: {
        id: number;
        action: string;
        actor: string | null;
        reason: string | null;
        at: string | null;
    }[];
    product_matches: { id: string; name: string; sku: string }[];
    can: { update: boolean; toggle: boolean };
};

const SELECT_CLASS =
    'border-input bg-background h-9 w-full rounded-md border px-2 text-sm';

/**
 * One Product Sourcing Group: its member products, the variant mappings that
 * make them interchangeable, the Supplier and warehouse sources they bring,
 * and the full history of who changed what and why.
 */
export default function SourcingGroupShow({
    group,
    products,
    canonical_variants,
    mappings,
    offers,
    stock,
    history,
    product_matches,
    can,
}: Props) {
    const { t, locale } = useTranslation();
    const [tab, setTab] = useState('products');

    const groupId = group.id;
    const tabs = [
        { key: 'products', label: t('sourcing.tabs.products') },
        { key: 'variants', label: t('sourcing.tabs.variants') },
        { key: 'sources', label: t('sourcing.tabs.sources') },
        { key: 'history', label: t('sourcing.tabs.history') },
    ];

    const search = (value: string) =>
        router.get(
            SourcingGroupController.show.url({ group: groupId }),
            value.length >= 2 ? { product_search: value } : {},
            {
                only: ['product_matches'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );

    return (
        <>
            <Head title={group.name_en} />

            <PageContainer>
                <PageHeader
                    title={locale === 'bn' ? group.name_bn : group.name_en}
                    description={group.code}
                    actions={
                        <div className="flex items-center gap-2">
                            <StatusPill
                                tone={group.is_active ? 'success' : 'neutral'}
                                label={
                                    group.is_active
                                        ? t('sourcing.status.active')
                                        : t('sourcing.status.inactive')
                                }
                            />
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={SourcingGroupController.index.url()}
                                >
                                    <ArrowLeft
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t('sourcing.title')}
                                </Link>
                            </Button>
                        </div>
                    }
                />

                {can.update && (
                    <SectionCard title={t('sourcing.form.save')}>
                        <Form
                            {...SourcingGroupController.update.form({
                                group: groupId,
                            })}
                            options={{ preserveScroll: true }}
                            className="grid gap-4 sm:grid-cols-2"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <FormField
                                        label={t('sourcing.form.name_en')}
                                        error={errors.name_en}
                                        required
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="name_en"
                                                defaultValue={group.name_en}
                                                required
                                            />
                                        )}
                                    </FormField>
                                    <FormField
                                        label={t('sourcing.form.name_bn')}
                                        error={errors.name_bn}
                                        required
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="name_bn"
                                                defaultValue={group.name_bn}
                                                required
                                            />
                                        )}
                                    </FormField>
                                    <FormField
                                        label={t('sourcing.form.description')}
                                        error={errors.description}
                                        className="sm:col-span-2"
                                    >
                                        {(field) => (
                                            <TextArea
                                                {...field}
                                                name="description"
                                                defaultValue={
                                                    group.description ?? ''
                                                }
                                            />
                                        )}
                                    </FormField>
                                    <div className="sm:col-span-2">
                                        <SubmitButton processing={processing}>
                                            {t('sourcing.form.save')}
                                        </SubmitButton>
                                    </div>
                                </>
                            )}
                        </Form>

                        {can.toggle && (
                            <Form
                                {...SourcingGroupController.toggle.form({
                                    group: groupId,
                                })}
                                options={{ preserveScroll: true }}
                                className="mt-4 flex flex-wrap items-end gap-2 border-t pt-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="is_active"
                                            value={group.is_active ? '0' : '1'}
                                        />
                                        <FormField
                                            label={t('sourcing.form.reason')}
                                            error={
                                                errors.reason ?? errors.group
                                            }
                                            className="min-w-64 flex-1"
                                            required
                                        >
                                            {(field) => (
                                                <Input
                                                    {...field}
                                                    name="reason"
                                                    required
                                                />
                                            )}
                                        </FormField>
                                        <SubmitButton
                                            processing={processing}
                                            variant="outline"
                                        >
                                            {group.is_active
                                                ? t('sourcing.deactivate')
                                                : t('sourcing.activate')}
                                        </SubmitButton>
                                    </>
                                )}
                            </Form>
                        )}
                    </SectionCard>
                )}

                <SectionTabs items={tabs} active={tab} onChange={setTab} />

                <SectionTabPanel tab="products" active={tab}>
                    <SectionCard title={t('sourcing.tabs.products')}>
                        {products.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                {t('sourcing.products.none')}
                            </p>
                        ) : (
                            <ul className="divide-border divide-y">
                                {products.map((product) => (
                                    <li
                                        key={product.id}
                                        className="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">
                                                {product.name}
                                                {product.is_canonical && (
                                                    <span className="ml-2">
                                                        <StatusPill
                                                            tone="info"
                                                            label={t(
                                                                'sourcing.products.canonical',
                                                            )}
                                                        />
                                                    </span>
                                                )}
                                            </p>
                                            <p className="text-muted-foreground truncate text-xs">
                                                {product.sku} ·{' '}
                                                {product.variants.length === 0
                                                    ? t(
                                                          'sourcing.products.no_variants',
                                                      )
                                                    : product.variants
                                                          .map(
                                                              (variant) =>
                                                                  variant.label,
                                                          )
                                                          .join(', ')}
                                            </p>
                                        </div>
                                        {can.update && (
                                            <Form
                                                {...SourcingGroupController.removeProduct.form(
                                                    {
                                                        group: groupId,
                                                        product: product.id,
                                                    },
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                                className="flex items-center gap-2"
                                            >
                                                {({ processing }) => (
                                                    <>
                                                        <Input
                                                            name="reason"
                                                            required
                                                            className="h-8 w-44"
                                                            placeholder={t(
                                                                'sourcing.form.reason',
                                                            )}
                                                            aria-label={t(
                                                                'sourcing.form.reason',
                                                            )}
                                                        />
                                                        <SubmitButton
                                                            processing={
                                                                processing
                                                            }
                                                            variant="outline"
                                                            size="sm"
                                                        >
                                                            {t(
                                                                'sourcing.products.remove',
                                                            )}
                                                        </SubmitButton>
                                                    </>
                                                )}
                                            </Form>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </SectionCard>

                    {can.update && group.is_active && (
                        <SectionCard
                            title={t('sourcing.products.add_heading')}
                            description={t('sourcing.products.search_hint')}
                        >
                            <div className="space-y-3">
                                <Input
                                    type="search"
                                    aria-label={t(
                                        'sourcing.products.search_label',
                                    )}
                                    placeholder={t(
                                        'sourcing.products.search_label',
                                    )}
                                    onChange={(event) =>
                                        search(event.target.value.trim())
                                    }
                                />
                                <ul className="divide-border divide-y">
                                    {product_matches.map((match) => (
                                        <li key={match.id} className="py-2">
                                            <Form
                                                {...SourcingGroupController.addProduct.form(
                                                    { group: groupId },
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                                className="flex flex-wrap items-center gap-2"
                                            >
                                                {({ processing, errors }) => (
                                                    <>
                                                        <input
                                                            type="hidden"
                                                            name="product_id"
                                                            value={match.id}
                                                        />
                                                        <span className="min-w-0 flex-1 truncate text-sm">
                                                            {match.name}{' '}
                                                            <span className="text-muted-foreground text-xs">
                                                                {match.sku}
                                                            </span>
                                                        </span>
                                                        <Input
                                                            name="reason"
                                                            required
                                                            className="h-8 w-48"
                                                            placeholder={t(
                                                                'sourcing.form.reason',
                                                            )}
                                                            aria-label={t(
                                                                'sourcing.form.reason',
                                                            )}
                                                        />
                                                        <SubmitButton
                                                            processing={
                                                                processing
                                                            }
                                                            size="sm"
                                                        >
                                                            {t(
                                                                'sourcing.products.add',
                                                            )}
                                                        </SubmitButton>
                                                        {(errors.group ??
                                                            errors.reason) && (
                                                            <p
                                                                role="alert"
                                                                className="text-danger w-full text-xs"
                                                            >
                                                                {errors.group ??
                                                                    errors.reason}
                                                            </p>
                                                        )}
                                                    </>
                                                )}
                                            </Form>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </SectionCard>
                    )}
                </SectionTabPanel>

                <SectionTabPanel tab="variants" active={tab}>
                    <SectionCard
                        title={t('sourcing.tabs.variants')}
                        description={t('sourcing.mappings.help')}
                    >
                        {mappings.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                {t('sourcing.mappings.none')}
                            </p>
                        ) : (
                            <ul className="divide-border divide-y">
                                {mappings.map((mapping) => (
                                    <li
                                        key={mapping.id}
                                        className="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0"
                                    >
                                        <p className="min-w-0 flex-1 text-sm">
                                            <span className="font-medium">
                                                {mapping.product.name}
                                            </span>
                                            {' · '}
                                            {mapping.variant ??
                                                t(
                                                    'sourcing.mappings.product_level',
                                                )}
                                            {' → '}
                                            {mapping.canonical_variant ??
                                                t(
                                                    'sourcing.mappings.product_level',
                                                )}
                                        </p>
                                        {can.update && (
                                            <Form
                                                {...SourcingGroupController.unmapVariant.form(
                                                    {
                                                        group: groupId,
                                                        mapping: mapping.id,
                                                    },
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                                className="flex items-center gap-2"
                                            >
                                                {({ processing }) => (
                                                    <>
                                                        <Input
                                                            name="reason"
                                                            required
                                                            className="h-8 w-44"
                                                            placeholder={t(
                                                                'sourcing.form.reason',
                                                            )}
                                                            aria-label={t(
                                                                'sourcing.form.reason',
                                                            )}
                                                        />
                                                        <SubmitButton
                                                            processing={
                                                                processing
                                                            }
                                                            variant="outline"
                                                            size="sm"
                                                        >
                                                            {t(
                                                                'sourcing.mappings.remove',
                                                            )}
                                                        </SubmitButton>
                                                    </>
                                                )}
                                            </Form>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </SectionCard>

                    {can.update && group.is_active && products.length > 0 && (
                        <SectionCard title={t('sourcing.mappings.add')}>
                            <MappingForm
                                groupId={groupId}
                                products={products}
                                canonicalVariants={canonical_variants}
                            />
                        </SectionCard>
                    )}
                </SectionTabPanel>

                <SectionTabPanel tab="sources" active={tab}>
                    <SectionCard title={t('sourcing.sources.offers')}>
                        {offers.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                {t('sourcing.sources.no_offers')}
                            </p>
                        ) : (
                            <ul className="divide-border divide-y text-sm">
                                {offers.map((offer) => (
                                    <li
                                        key={offer.id}
                                        className="flex flex-wrap justify-between gap-2 py-2 first:pt-0 last:pb-0"
                                    >
                                        <span>
                                            <span className="font-medium">
                                                {offer.supplier}
                                            </span>{' '}
                                            · {offer.product}
                                            {offer.variant
                                                ? ` · ${offer.variant}`
                                                : ''}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {offer.supply_mode} · {offer.status}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </SectionCard>

                    <SectionCard title={t('sourcing.sources.stock')}>
                        {stock.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                {t('sourcing.sources.no_stock')}
                            </p>
                        ) : (
                            <ul className="divide-border divide-y text-sm">
                                {stock.map((item) => (
                                    <li
                                        key={item.id}
                                        className="flex flex-wrap justify-between gap-2 py-2 first:pt-0 last:pb-0"
                                    >
                                        <span>
                                            <span className="font-medium">
                                                {item.warehouse}
                                            </span>{' '}
                                            · {item.product}
                                            {item.variant
                                                ? ` · ${item.variant}`
                                                : ''}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {t('sourcing.sources.available', {
                                                count: item.available,
                                            })}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </SectionCard>
                </SectionTabPanel>

                <SectionTabPanel tab="history" active={tab}>
                    <SectionCard title={t('sourcing.tabs.history')}>
                        {history.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                {t('sourcing.history.none')}
                            </p>
                        ) : (
                            <ol className="divide-border divide-y text-sm">
                                {history.map((entry) => (
                                    <li
                                        key={entry.id}
                                        className="space-y-0.5 py-3 first:pt-0 last:pb-0"
                                    >
                                        <div className="flex flex-wrap justify-between gap-2">
                                            <span className="font-medium">
                                                {t(
                                                    `sourcing.history.actions.${entry.action.replace('sourcing_group.', '')}`,
                                                )}
                                            </span>
                                            <span className="text-muted-foreground">
                                                {entry.at
                                                    ? new Date(
                                                          entry.at,
                                                      ).toLocaleString(locale)
                                                    : ''}
                                                {entry.actor
                                                    ? ` · ${t('sourcing.history.by', { name: entry.actor })}`
                                                    : ''}
                                            </span>
                                        </div>
                                        {entry.reason && (
                                            <p className="text-muted-foreground">
                                                {entry.reason}
                                            </p>
                                        )}
                                    </li>
                                ))}
                            </ol>
                        )}
                    </SectionCard>
                </SectionTabPanel>
            </PageContainer>
        </>
    );
}

/**
 * Pick a member product, then one of its variations (or the whole product when
 * it has none) and the canonical variation it fulfils.
 */
function MappingForm({
    groupId,
    products,
    canonicalVariants,
}: {
    groupId: string;
    products: Props['products'];
    canonicalVariants: VariantOption[];
}) {
    const { t } = useTranslation();
    const members = products.filter((product) => !product.is_canonical);
    const [productId, setProductId] = useState(members[0]?.id ?? '');
    const variants =
        members.find((product) => product.id === productId)?.variants ?? [];

    if (members.length === 0) {
        return null;
    }

    return (
        <Form
            {...SourcingGroupController.mapVariant.form({ group: groupId })}
            options={{ preserveScroll: true }}
            className="grid gap-4 sm:grid-cols-2"
        >
            {({ processing, errors }) => (
                <>
                    <FormField
                        label={t('sourcing.mappings.product')}
                        error={errors.product_id}
                    >
                        {(field) => (
                            <select
                                {...field}
                                name="product_id"
                                value={productId}
                                onChange={(event) =>
                                    setProductId(event.target.value)
                                }
                                className={SELECT_CLASS}
                            >
                                {members.map((product) => (
                                    <option key={product.id} value={product.id}>
                                        {product.name}
                                    </option>
                                ))}
                            </select>
                        )}
                    </FormField>

                    <FormField
                        label={t('sourcing.mappings.variant')}
                        error={errors.variant_id}
                    >
                        {(field) => (
                            <select
                                {...field}
                                name="variant_id"
                                className={SELECT_CLASS}
                            >
                                {variants.length === 0 && (
                                    <option value="">
                                        {t('sourcing.mappings.product_level')}
                                    </option>
                                )}
                                {variants.map((variant) => (
                                    <option key={variant.id} value={variant.id}>
                                        {variant.label}
                                    </option>
                                ))}
                            </select>
                        )}
                    </FormField>

                    <FormField
                        label={t('sourcing.mappings.canonical_variant')}
                        error={errors.canonical_variant_id}
                    >
                        {(field) => (
                            <select
                                {...field}
                                name="canonical_variant_id"
                                className={SELECT_CLASS}
                            >
                                {canonicalVariants.length === 0 && (
                                    <option value="">
                                        {t('sourcing.mappings.product_level')}
                                    </option>
                                )}
                                {canonicalVariants.map((variant) => (
                                    <option key={variant.id} value={variant.id}>
                                        {variant.label}
                                    </option>
                                ))}
                            </select>
                        )}
                    </FormField>

                    <FormField
                        label={t('sourcing.form.reason')}
                        error={errors.reason ?? errors.group}
                        required
                    >
                        {(field) => <Input {...field} name="reason" required />}
                    </FormField>

                    <div className="sm:col-span-2">
                        <SubmitButton processing={processing}>
                            {t('sourcing.mappings.add')}
                        </SubmitButton>
                    </div>
                </>
            )}
        </Form>
    );
}
