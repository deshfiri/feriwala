import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import TextArea from '@/components/forms/text-area';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import { store, update } from '@/routes/supplier/listing-lots/items';
import type { AttributeOption, LotEntry } from './types';

type ItemInput = {
    id: string;
    variant_label: string;
    supplier_sku: string;
    /** Flat-Taka decimal typed by the Supplier, submitted exactly as typed. */
    supplier_rate: string;
    supply_mode: 'ready_stock' | 'on_demand' | 'pre_order';
    available_quantity: string;
    minimum_supply_quantity: string;
    lead_time_days: string;
    fulfilment_capacity: string;
    expected_availability_at: string;
    warranty: string;
    return_conditions: string;
    attribute_value_ids: string[];
};

const emptyItem = (): ItemInput => ({
    id: '',
    variant_label: '',
    supplier_sku: '',
    supplier_rate: '',
    supply_mode: 'ready_stock',
    available_quantity: '',
    minimum_supply_quantity: '1',
    lead_time_days: '',
    fulfilment_capacity: '',
    expected_availability_at: '',
    warranty: '',
    return_conditions: '',
    attribute_value_ids: [],
});

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Adds or edits one product entry inside a Supplier's listing batch.
 *
 * The whole product -- its fields, every variant row, and each variant's
 * attribute values -- is one submission to {@see SaveSupplierListingDraft},
 * mirroring the single-listing form this batch's workspace sits alongside.
 * Never a `platform_rate` field: a Supplier enters only their own rate here.
 */
export default function ListingLotEntryDialog({
    lotId,
    entry,
    options,
    onClose,
}: {
    lotId: string;
    entry: LotEntry | null;
    options: {
        categories: { value: string; label: string }[];
        brands: { value: string; label: string }[];
        attributes: AttributeOption[];
    };
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const editing = entry !== null;

    const form = useForm({
        product_name: entry?.product_name ?? '',
        description: entry?.description ?? '',
        category_id: entry?.category_id ?? '',
        category_suggestion: entry?.category_suggestion ?? '',
        brand_id: entry?.brand_id ?? '',
        brand_suggestion: entry?.brand_suggestion ?? '',
        supplier_note: entry?.supplier_note ?? '',
        items: (entry?.items.map((item) => ({
            id: item.id,
            variant_label: item.variant_label ?? '',
            supplier_sku: item.supplier_sku,
            supplier_rate: item.supplier_rate.amount,
            supply_mode: item.supply_mode,
            available_quantity:
                item.available_quantity === null
                    ? ''
                    : String(item.available_quantity),
            minimum_supply_quantity: String(item.minimum_supply_quantity),
            lead_time_days:
                item.lead_time_days === null ? '' : String(item.lead_time_days),
            fulfilment_capacity:
                item.fulfilment_capacity === null
                    ? ''
                    : String(item.fulfilment_capacity),
            expected_availability_at: item.expected_availability_at ?? '',
            warranty: item.warranty ?? '',
            return_conditions: item.return_conditions ?? '',
            attribute_value_ids: item.attribute_value_ids,
        })) ?? [emptyItem()]) as ItemInput[],
    });

    const setItem = <K extends keyof ItemInput>(
        index: number,
        key: K,
        value: ItemInput[K],
    ) =>
        form.setData(
            'items',
            form.data.items.map((item, position) =>
                position === index ? { ...item, [key]: value } : item,
            ),
        );

    const setAttributeValue = (
        index: number,
        attributeValues: string[],
        chosen: string | null,
    ) => {
        const item = form.data.items[index];
        const withoutGroup = item.attribute_value_ids.filter(
            (id) => !attributeValues.includes(id),
        );

        setItem(
            index,
            'attribute_value_ids',
            chosen === null ? withoutGroup : [...withoutGroup, chosen],
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.transform((data) => ({
            ...data,
            items: data.items.map((item) => ({
                id: item.id || null,
                variant_label: item.variant_label || null,
                supplier_sku: item.supplier_sku,
                supplier_rate: item.supplier_rate,
                currency_code: 'BDT',
                supply_mode: item.supply_mode,
                available_quantity:
                    item.available_quantity === ''
                        ? null
                        : Number.parseInt(item.available_quantity, 10),
                minimum_supply_quantity: Number.parseInt(
                    item.minimum_supply_quantity || '1',
                    10,
                ),
                lead_time_days:
                    item.lead_time_days === ''
                        ? null
                        : Number.parseInt(item.lead_time_days, 10),
                fulfilment_capacity:
                    item.fulfilment_capacity === ''
                        ? null
                        : Number.parseInt(item.fulfilment_capacity, 10),
                expected_availability_at: item.expected_availability_at || null,
                warranty: item.warranty || null,
                return_conditions: item.return_conditions || null,
                attribute_value_ids: item.attribute_value_ids,
            })),
        }));

        if (editing) {
            form.patch(update([lotId, entry.id]).url, { onSuccess: onClose });
        } else {
            form.post(store(lotId).url, { onSuccess: onClose });
        }
    };

    const errorFor = (key: string) =>
        (form.errors as Record<string, string | undefined>)[key];

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {editing
                            ? entry.product_name
                            : t('supplier.listing_lots.workspace.new_entry')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('supplier.listings.proposal_notice')}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField
                            label={t('supplier.listings.product_name')}
                            error={form.errors.product_name}
                            required
                            className="sm:col-span-2"
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    value={form.data.product_name}
                                    onChange={(e) =>
                                        form.setData(
                                            'product_name',
                                            e.target.value,
                                        )
                                    }
                                    required
                                />
                            )}
                        </FormField>

                        <FormField
                            label={t('supplier.listings.description_field')}
                            error={form.errors.description}
                            className="sm:col-span-2"
                        >
                            {(field) => (
                                <TextArea
                                    {...field}
                                    value={form.data.description}
                                    onChange={(e) =>
                                        form.setData(
                                            'description',
                                            e.target.value,
                                        )
                                    }
                                />
                            )}
                        </FormField>

                        <FormField
                            label={t('supplier.listings.category')}
                            error={form.errors.category_id}
                        >
                            {(field) => (
                                <select
                                    {...field}
                                    value={form.data.category_id}
                                    onChange={(e) =>
                                        form.setData(
                                            'category_id',
                                            e.target.value,
                                        )
                                    }
                                    className={selectClass}
                                >
                                    <option value="">
                                        {t('supplier.listings.none')}
                                    </option>
                                    {options.categories.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            )}
                        </FormField>

                        <FormField
                            label={t('supplier.listings.category_suggestion')}
                            error={form.errors.category_suggestion}
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    value={form.data.category_suggestion}
                                    onChange={(e) =>
                                        form.setData(
                                            'category_suggestion',
                                            e.target.value,
                                        )
                                    }
                                />
                            )}
                        </FormField>

                        <FormField
                            label={t('supplier.listings.brand')}
                            error={form.errors.brand_id}
                        >
                            {(field) => (
                                <select
                                    {...field}
                                    value={form.data.brand_id}
                                    onChange={(e) =>
                                        form.setData('brand_id', e.target.value)
                                    }
                                    className={selectClass}
                                >
                                    <option value="">
                                        {t('supplier.listings.none')}
                                    </option>
                                    {options.brands.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            )}
                        </FormField>

                        <FormField
                            label={t('supplier.listings.brand_suggestion')}
                            error={form.errors.brand_suggestion}
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    value={form.data.brand_suggestion}
                                    onChange={(e) =>
                                        form.setData(
                                            'brand_suggestion',
                                            e.target.value,
                                        )
                                    }
                                />
                            )}
                        </FormField>

                        <FormField
                            label={t('supplier.listings.supplier_note')}
                            error={form.errors.supplier_note}
                            className="sm:col-span-2"
                        >
                            {(field) => (
                                <TextArea
                                    {...field}
                                    value={form.data.supplier_note}
                                    onChange={(e) =>
                                        form.setData(
                                            'supplier_note',
                                            e.target.value,
                                        )
                                    }
                                />
                            )}
                        </FormField>
                    </div>

                    <div className="space-y-4">
                        {form.data.items.map((item, index) => (
                            <div
                                key={index}
                                className="space-y-4 rounded-lg border p-4"
                            >
                                <div className="flex items-center justify-between">
                                    <h4 className="text-sm font-semibold">
                                        {t('supplier.listings.variations')}{' '}
                                        {index + 1}
                                    </h4>
                                    {form.data.items.length > 1 && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                form.setData(
                                                    'items',
                                                    form.data.items.filter(
                                                        (_, position) =>
                                                            position !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            {t(
                                                'supplier.listings.remove_variation',
                                            )}
                                        </Button>
                                    )}
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FormField
                                        label={t(
                                            'supplier.listings.variant_label',
                                        )}
                                        error={errorFor(
                                            `items.${index}.variant_label`,
                                        )}
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                value={item.variant_label}
                                                onChange={(e) =>
                                                    setItem(
                                                        index,
                                                        'variant_label',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        )}
                                    </FormField>
                                    <FormField
                                        label={t(
                                            'supplier.listings.supplier_sku',
                                        )}
                                        error={errorFor(
                                            `items.${index}.supplier_sku`,
                                        )}
                                        required
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                value={item.supplier_sku}
                                                onChange={(e) =>
                                                    setItem(
                                                        index,
                                                        'supplier_sku',
                                                        e.target.value,
                                                    )
                                                }
                                                required
                                            />
                                        )}
                                    </FormField>
                                    <FormField
                                        label={t(
                                            'supplier.listings.supplier_rate',
                                        )}
                                        error={errorFor(
                                            `items.${index}.supplier_rate`,
                                        )}
                                        required
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                inputMode="decimal"
                                                value={item.supplier_rate}
                                                onChange={(e) =>
                                                    setItem(
                                                        index,
                                                        'supplier_rate',
                                                        e.target.value,
                                                    )
                                                }
                                                required
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t(
                                            'supplier.listing_lots.supply_mode.label',
                                        )}
                                        error={errorFor(
                                            `items.${index}.supply_mode`,
                                        )}
                                    >
                                        {(field) => (
                                            <select
                                                {...field}
                                                value={item.supply_mode}
                                                onChange={(e) =>
                                                    setItem(
                                                        index,
                                                        'supply_mode',
                                                        e.target
                                                            .value as ItemInput['supply_mode'],
                                                    )
                                                }
                                                className={selectClass}
                                            >
                                                <option value="ready_stock">
                                                    {t(
                                                        'supplier.listing_lots.supply_mode.ready_stock',
                                                    )}
                                                </option>
                                                <option value="on_demand">
                                                    {t(
                                                        'supplier.listing_lots.supply_mode.on_demand',
                                                    )}
                                                </option>
                                                <option value="pre_order">
                                                    {t(
                                                        'supplier.listing_lots.supply_mode.pre_order',
                                                    )}
                                                </option>
                                            </select>
                                        )}
                                    </FormField>

                                    {item.supply_mode === 'ready_stock' && (
                                        <FormField
                                            label={t(
                                                'supplier.listings.available_quantity',
                                            )}
                                            error={errorFor(
                                                `items.${index}.available_quantity`,
                                            )}
                                        >
                                            {(field) => (
                                                <Input
                                                    {...field}
                                                    type="number"
                                                    min={0}
                                                    value={
                                                        item.available_quantity
                                                    }
                                                    onChange={(e) =>
                                                        setItem(
                                                            index,
                                                            'available_quantity',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            )}
                                        </FormField>
                                    )}

                                    {(item.supply_mode === 'on_demand' ||
                                        item.supply_mode === 'pre_order') && (
                                        <>
                                            <FormField
                                                label={t(
                                                    'supplier.listing_lots.supply_mode.fulfilment_capacity',
                                                )}
                                                hint={t(
                                                    'supplier.listing_lots.supply_mode.fulfilment_capacity_help',
                                                )}
                                                error={errorFor(
                                                    `items.${index}.fulfilment_capacity`,
                                                )}
                                            >
                                                {(field) => (
                                                    <Input
                                                        {...field}
                                                        type="number"
                                                        min={0}
                                                        value={
                                                            item.fulfilment_capacity
                                                        }
                                                        onChange={(e) =>
                                                            setItem(
                                                                index,
                                                                'fulfilment_capacity',
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                )}
                                            </FormField>
                                            <FormField
                                                label={t(
                                                    'supplier.listings.lead_time_days',
                                                )}
                                                error={errorFor(
                                                    `items.${index}.lead_time_days`,
                                                )}
                                            >
                                                {(field) => (
                                                    <Input
                                                        {...field}
                                                        type="number"
                                                        min={0}
                                                        value={
                                                            item.lead_time_days
                                                        }
                                                        onChange={(e) =>
                                                            setItem(
                                                                index,
                                                                'lead_time_days',
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                )}
                                            </FormField>
                                            {item.supply_mode ===
                                                'pre_order' && (
                                                <FormField
                                                    label={t(
                                                        'supplier.listing_lots.supply_mode.expected_availability_at',
                                                    )}
                                                    error={errorFor(
                                                        `items.${index}.expected_availability_at`,
                                                    )}
                                                >
                                                    {(field) => (
                                                        <Input
                                                            {...field}
                                                            type="date"
                                                            value={
                                                                item.expected_availability_at
                                                            }
                                                            onChange={(e) =>
                                                                setItem(
                                                                    index,
                                                                    'expected_availability_at',
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                        />
                                                    )}
                                                </FormField>
                                            )}
                                        </>
                                    )}

                                    <FormField
                                        label={t(
                                            'supplier.listings.minimum_supply_quantity',
                                        )}
                                        error={errorFor(
                                            `items.${index}.minimum_supply_quantity`,
                                        )}
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                type="number"
                                                min={1}
                                                value={
                                                    item.minimum_supply_quantity
                                                }
                                                onChange={(e) =>
                                                    setItem(
                                                        index,
                                                        'minimum_supply_quantity',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        )}
                                    </FormField>
                                    <FormField
                                        label={t('supplier.listings.warranty')}
                                        error={errorFor(
                                            `items.${index}.warranty`,
                                        )}
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                value={item.warranty}
                                                onChange={(e) =>
                                                    setItem(
                                                        index,
                                                        'warranty',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        )}
                                    </FormField>
                                    <FormField
                                        label={t(
                                            'supplier.listings.return_conditions',
                                        )}
                                        error={errorFor(
                                            `items.${index}.return_conditions`,
                                        )}
                                        className="sm:col-span-2"
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                value={item.return_conditions}
                                                onChange={(e) =>
                                                    setItem(
                                                        index,
                                                        'return_conditions',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        )}
                                    </FormField>
                                </div>

                                {options.attributes.length > 0 && (
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        {options.attributes.map((attribute) => {
                                            const groupIds =
                                                attribute.values.map(
                                                    (v) => v.value,
                                                );
                                            const selected =
                                                item.attribute_value_ids.find(
                                                    (id) =>
                                                        groupIds.includes(id),
                                                ) ?? '';

                                            return (
                                                <fieldset
                                                    key={attribute.id}
                                                    className="space-y-2 rounded-lg border p-3"
                                                >
                                                    <legend className="px-1 text-sm font-medium">
                                                        {attribute.name}
                                                    </legend>
                                                    <div className="flex flex-wrap gap-x-4 gap-y-2">
                                                        <label className="flex items-center gap-2 text-sm">
                                                            <input
                                                                type="radio"
                                                                checked={
                                                                    selected ===
                                                                    ''
                                                                }
                                                                onChange={() =>
                                                                    setAttributeValue(
                                                                        index,
                                                                        groupIds,
                                                                        null,
                                                                    )
                                                                }
                                                                className="size-4"
                                                            />
                                                            {t(
                                                                'supplier.listings.none',
                                                            )}
                                                        </label>
                                                        {attribute.values.map(
                                                            (value) => (
                                                                <label
                                                                    key={
                                                                        value.value
                                                                    }
                                                                    className="flex items-center gap-2 text-sm"
                                                                >
                                                                    <input
                                                                        type="radio"
                                                                        checked={
                                                                            selected ===
                                                                            value.value
                                                                        }
                                                                        onChange={() =>
                                                                            setAttributeValue(
                                                                                index,
                                                                                groupIds,
                                                                                value.value,
                                                                            )
                                                                        }
                                                                        className="size-4"
                                                                    />
                                                                    {
                                                                        value.label
                                                                    }
                                                                </label>
                                                            ),
                                                        )}
                                                    </div>
                                                </fieldset>
                                            );
                                        })}
                                    </div>
                                )}
                            </div>
                        ))}

                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                form.setData('items', [
                                    ...form.data.items,
                                    emptyItem(),
                                ])
                            }
                        >
                            <Plus className="size-4" aria-hidden="true" />
                            {t('supplier.listings.add_variation')}
                        </Button>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            {t('common.actions.cancel')}
                        </Button>
                        <SubmitButton processing={form.processing}>
                            {editing
                                ? t(
                                      'supplier.listing_lots.workspace.edit_entry',
                                  )
                                : t(
                                      'supplier.listing_lots.workspace.add_entry',
                                  )}
                        </SubmitButton>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
