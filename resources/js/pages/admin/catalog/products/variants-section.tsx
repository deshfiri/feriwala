import { Form, Link, router, usePage } from '@inertiajs/react';
import { Boxes, Layers, Pencil, Plus, Power, Trash2 } from 'lucide-react';
import { useState } from 'react';
import ProductVariantController from '@/actions/App/Http/Controllers/Admin/ProductVariantController';
import AlertError from '@/components/alert-error';
import FormField from '@/components/forms/form-field';
import MoneyAmount from '@/components/money-amount';
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
import { index as attributesIndex } from '@/routes/admin/catalog/attributes';
import type {
    AttributeOption,
    CatalogAbilities,
    ProductDetail,
    VariantRow,
} from '@/types';

type Props = {
    product: ProductDetail;
    variants: VariantRow[];
    attributes: AttributeOption[];
    /** The most combinations one build may create, as the server enforces. */
    builderMax: number;
    can: CatalogAbilities;
};

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * A product's variations (§11.1).
 *
 * The attribute set of the first variation decides the rest: once one exists,
 * the dialog asks only for the attributes it uses. That is a convenience — the
 * server refuses an inconsistent set whatever the dialog offered.
 */
export default function VariantsSection({
    product,
    variants,
    attributes,
    builderMax,
    can,
}: Props) {
    const { t } = useTranslation();
    const page = usePage<{ errors: Record<string, string> }>();

    const [building, setBuilding] = useState(false);
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<VariantRow | null>(null);

    const usedAttributes =
        variants.length === 0
            ? null
            : new Set(variants[0].values.map((value) => value.attribute));

    const offered = usedAttributes
        ? attributes.filter((attribute) => usedAttributes.has(attribute.name))
        : attributes;

    const toggle = (variant: VariantRow) =>
        router.patch(
            ProductVariantController.update.url({
                product: product.id,
                variant: variant.id,
            }),
            {
                sku: variant.sku,
                barcode: variant.barcode,
                wholesale_price_minor: variant.wholesale_price_minor,
                base_cost_minor: variant.base_cost_minor,
                is_active: !variant.is_active,
            },
            { preserveScroll: true },
        );

    const remove = (variant: VariantRow) => {
        if (!window.confirm(t('catalog.variants.delete_confirm'))) {
            return;
        }

        router.delete(
            ProductVariantController.destroy.url({
                product: product.id,
                variant: variant.id,
            }),
            { preserveScroll: true },
        );
    };

    return (
        <SectionCard
            title={t('catalog.variants.title')}
            description={t('catalog.variants.description')}
            actions={
                can.create && attributes.length > 0 ? (
                    <>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setBuilding(true)}
                        >
                            <Boxes className="size-4" aria-hidden="true" />
                            {t('catalog.variants.build')}
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setAdding(true)}
                        >
                            <Plus className="size-4" aria-hidden="true" />
                            {t('catalog.variants.add')}
                        </Button>
                    </>
                ) : undefined
            }
            contentClassName="p-0"
        >
            {page.props.errors?.variant && (
                <div className="p-4">
                    <AlertError errors={[page.props.errors.variant]} />
                </div>
            )}

            {attributes.length === 0 && variants.length === 0 ? (
                <EmptyState
                    icon={Layers}
                    title={t('catalog.variants.no_attributes')}
                    description={t('catalog.variants.no_attributes_help')}
                    action={
                        <Button variant="outline" size="sm" asChild>
                            <Link href={attributesIndex()}>
                                {t('catalog.variants.manage_attributes')}
                            </Link>
                        </Button>
                    }
                />
            ) : variants.length === 0 ? (
                <EmptyState
                    icon={Layers}
                    title={t('catalog.variants.empty')}
                    description={t('catalog.variants.empty_help')}
                />
            ) : (
                <ul className="divide-border divide-y">
                    {variants.map((variant) => (
                        <li
                            key={variant.id}
                            className="flex flex-wrap items-center justify-between gap-3 px-5 py-3"
                        >
                            <div className="min-w-0 space-y-0.5">
                                <p className="font-medium">{variant.label}</p>
                                <p className="text-muted-foreground font-mono text-xs">
                                    {variant.sku}
                                    {variant.barcode
                                        ? ` · ${variant.barcode}`
                                        : ''}
                                </p>
                            </div>

                            <div className="flex flex-wrap items-center gap-3">
                                <div className="text-right">
                                    <MoneyAmount
                                        amount={variant.wholesale_price}
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        {t(
                                            variant.overrides_price
                                                ? 'catalog.variants.own_price'
                                                : 'catalog.variants.product_price',
                                        )}
                                    </p>
                                </div>

                                <StatusPill
                                    tone={
                                        variant.is_active
                                            ? 'success'
                                            : 'neutral'
                                    }
                                    label={t(
                                        variant.is_active
                                            ? 'catalog.variants.offered'
                                            : 'catalog.variants.switched_off',
                                    )}
                                />

                                {can.edit && (
                                    <>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setEditing(variant)}
                                        >
                                            <Pencil
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            <span className="sr-only sm:not-sr-only">
                                                {t('common.actions.edit')}
                                            </span>
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => toggle(variant)}
                                        >
                                            <Power
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            <span className="sr-only sm:not-sr-only">
                                                {t(
                                                    variant.is_active
                                                        ? 'catalog.variants.disable'
                                                        : 'catalog.variants.enable',
                                                )}
                                            </span>
                                        </Button>
                                    </>
                                )}

                                {can.delete && product.status === 'draft' && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => remove(variant)}
                                    >
                                        <Trash2
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        <span className="sr-only">
                                            {t('common.actions.delete')}
                                        </span>
                                    </Button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {building && (
                <BuildDialog
                    product={product}
                    variants={variants}
                    attributes={offered}
                    locked={usedAttributes !== null}
                    max={builderMax}
                    onClose={() => setBuilding(false)}
                />
            )}

            {(adding || editing !== null) && (
                <VariantDialog
                    product={product}
                    variant={editing}
                    attributes={offered}
                    requireEvery={usedAttributes !== null}
                    onClose={() => {
                        setAdding(false);
                        setEditing(null);
                    }}
                />
            )}
        </SectionCard>
    );
}

/**
 * The variation builder: tick values, and every combination not already on the
 * product is created in one request.
 *
 * The counts and the preview are a guide worked out from what the page holds;
 * the server builds the combinations itself, skips the ones that exist, makes
 * the SKUs, and refuses a build over its limit whatever this dialog showed.
 */
function BuildDialog({
    product,
    variants,
    attributes,
    locked,
    max,
    onClose,
}: {
    product: ProductDetail;
    variants: VariantRow[];
    attributes: AttributeOption[];
    /** True once existing variations fix which attributes every one uses. */
    locked: boolean;
    max: number;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const [picked, setPicked] = useState<Set<string>>(() => new Set());
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);

    const toggle = (id: string) =>
        setPicked((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });

    const groups = attributes.map((attribute) =>
        attribute.values.filter((value) => picked.has(value.id)),
    );

    const missing = locked && groups.some((values) => values.length === 0);
    const used = groups.filter((values) => values.length > 0);

    const combinations =
        used.length === 0
            ? []
            : used.reduce<string[][]>(
                  (partials, values) =>
                      partials.flatMap((partial) =>
                          values.map((value) => [...partial, value.value]),
                      ),
                  [[]],
              );

    const existing = new Set(variants.map((variant) => variant.label));
    const labels = combinations.map((combination) => combination.join(' / '));
    const fresh = labels.filter((label) => !existing.has(label)).length;

    const submit = () =>
        router.post(
            ProductVariantController.generate.url(product.id),
            { values: Array.from(picked) },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errors) => setError(Object.values(errors)[0] ?? null),
                onSuccess: onClose,
            },
        );

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {t('catalog.variants.build_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('catalog.variants.build_description')}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    {locked && (
                        <p className="bg-muted/40 rounded-md border px-3 py-2 text-sm">
                            {t('catalog.variants.build_locked', {
                                attributes: attributes
                                    .map((attribute) => attribute.name)
                                    .join(', '),
                            })}
                        </p>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        {attributes.map((attribute) => (
                            <fieldset
                                key={attribute.id}
                                className="space-y-2 rounded-lg border p-3"
                            >
                                <legend className="px-1 text-sm font-medium">
                                    {attribute.name}
                                </legend>
                                <div className="flex flex-wrap gap-x-4 gap-y-2">
                                    {attribute.values.map((value) => (
                                        <label
                                            key={value.id}
                                            className="flex items-center gap-2 text-sm"
                                        >
                                            <input
                                                type="checkbox"
                                                checked={picked.has(value.id)}
                                                onChange={() =>
                                                    toggle(value.id)
                                                }
                                                className="size-4"
                                            />
                                            {value.value}
                                        </label>
                                    ))}
                                </div>
                            </fieldset>
                        ))}
                    </div>

                    <dl className="flex flex-wrap gap-x-4 gap-y-1 text-sm tabular-nums">
                        <div>
                            {t('catalog.variants.build_combinations', {
                                count: combinations.length,
                            })}
                        </div>
                        <div>
                            {t('catalog.variants.build_new', { count: fresh })}
                        </div>
                        <div className="text-muted-foreground">
                            {t('catalog.variants.build_existing', {
                                count: combinations.length - fresh,
                            })}
                        </div>
                    </dl>

                    {combinations.length > max && (
                        <AlertError
                            errors={[
                                t('catalog.variants.build_limit', { max }),
                            ]}
                        />
                    )}

                    {labels.length > 0 && labels.length <= max && (
                        <ul className="divide-border max-h-56 divide-y overflow-y-auto rounded-md border text-sm">
                            {labels.map((label) => (
                                <li
                                    key={label}
                                    className="flex items-center justify-between gap-2 px-3 py-1.5"
                                >
                                    <span>{label}</span>
                                    {existing.has(label) && (
                                        <StatusPill
                                            tone="neutral"
                                            label={t(
                                                'catalog.variants.build_exists',
                                            )}
                                        />
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}

                    {error && <AlertError errors={[error]} />}
                </div>

                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button
                        type="button"
                        onClick={submit}
                        disabled={
                            processing ||
                            missing ||
                            combinations.length === 0 ||
                            combinations.length > max
                        }
                    >
                        {t('catalog.variants.build_submit')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function VariantDialog({
    product,
    variant,
    attributes,
    requireEvery,
    onClose,
}: {
    product: ProductDetail;
    variant: VariantRow | null;
    attributes: AttributeOption[];
    /** True once a sibling fixes which attributes every variation uses. */
    requireEvery: boolean;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const editing = variant !== null;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        {editing
                            ? t('catalog.variants.edit_title', {
                                  label: variant.label,
                              })
                            : t('catalog.variants.add_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('catalog.variants.description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...(editing
                        ? ProductVariantController.update.form({
                              product: product.id,
                              variant: variant.id,
                          })
                        : ProductVariantController.store.form(product.id))}
                    options={{ preserveScroll: true }}
                    // Attributes left as "not used" post nothing, rather than an
                    // empty id the server would have to explain away.
                    transform={(data) =>
                        editing
                            ? data
                            : {
                                  ...data,
                                  values: (
                                      (data.values as string[] | undefined) ??
                                      []
                                  ).filter((value) => value !== ''),
                              }
                    }
                    onSuccess={onClose}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            {editing ? (
                                <div className="bg-muted/40 rounded-md border px-3 py-2 text-sm">
                                    <span className="text-muted-foreground">
                                        {t('catalog.variants.combination')}
                                        :{' '}
                                    </span>
                                    {variant.label}
                                </div>
                            ) : (
                                <fieldset className="grid gap-3 sm:grid-cols-2">
                                    <legend className="mb-2 text-sm font-medium">
                                        {t('catalog.variants.combination')}
                                    </legend>
                                    {attributes.map((attribute) => (
                                        <FormField
                                            key={attribute.id}
                                            label={attribute.name}
                                            required={requireEvery}
                                        >
                                            {(field) => (
                                                <select
                                                    {...field}
                                                    name="values[]"
                                                    defaultValue=""
                                                    className={selectClass}
                                                >
                                                    <option
                                                        value=""
                                                        disabled={requireEvery}
                                                    >
                                                        {t(
                                                            'catalog.variants.not_used',
                                                        )}
                                                    </option>
                                                    {attribute.values.map(
                                                        (value) => (
                                                            <option
                                                                key={value.id}
                                                                value={value.id}
                                                            >
                                                                {value.value}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            )}
                                        </FormField>
                                    ))}
                                    {errors.values && (
                                        <div className="sm:col-span-2">
                                            <AlertError
                                                errors={[errors.values]}
                                            />
                                        </div>
                                    )}
                                </fieldset>
                            )}

                            <div className="grid gap-3 sm:grid-cols-2">
                                <FormField
                                    label={t('catalog.variants.sku')}
                                    error={errors.sku}
                                    required
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            name="sku"
                                            maxLength={64}
                                            className="font-mono uppercase"
                                            defaultValue={variant?.sku ?? ''}
                                        />
                                    )}
                                </FormField>

                                <FormField
                                    label={t('catalog.variants.barcode')}
                                    error={errors.barcode}
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            name="barcode"
                                            maxLength={64}
                                            className="font-mono"
                                            defaultValue={
                                                variant?.barcode ?? ''
                                            }
                                        />
                                    )}
                                </FormField>

                                <FormField
                                    label={t(
                                        'catalog.variants.wholesale_price',
                                    )}
                                    hint={t(
                                        'catalog.variants.wholesale_price_help',
                                    )}
                                    error={errors.wholesale_price_minor}
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
                                            defaultValue={
                                                variant?.wholesale_price_minor ??
                                                ''
                                            }
                                        />
                                    )}
                                </FormField>

                                <FormField
                                    label={t('catalog.variants.base_cost')}
                                    hint={t('catalog.variants.base_cost_help')}
                                    error={errors.base_cost_minor}
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
                                            defaultValue={
                                                variant?.base_cost_minor ?? ''
                                            }
                                        />
                                    )}
                                </FormField>

                                <FormField
                                    label={t('catalog.variants.availability')}
                                    error={errors.is_active}
                                >
                                    {(field) => (
                                        <select
                                            {...field}
                                            name="is_active"
                                            defaultValue={
                                                variant?.is_active === false
                                                    ? '0'
                                                    : '1'
                                            }
                                            className={selectClass}
                                        >
                                            <option value="1">
                                                {t('catalog.variants.offered')}
                                            </option>
                                            <option value="0">
                                                {t(
                                                    'catalog.variants.switched_off',
                                                )}
                                            </option>
                                        </select>
                                    )}
                                </FormField>
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={onClose}
                                >
                                    {t('common.actions.cancel')}
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {editing
                                        ? t('common.actions.save')
                                        : t('catalog.variants.add')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
