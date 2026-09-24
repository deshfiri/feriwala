import { Head, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import TextArea from '@/components/forms/text-area';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import { store, update } from '@/routes/supplier/listings';

type ItemInput = {
    /** Present for a variation already saved, so it is updated in place. */
    id: string;
    variant_label: string;
    supplier_sku: string;
    /** Flat-Taka decimal typed by the Supplier, submitted exactly as typed. */
    supplier_rate: string;
    available_quantity: string;
    minimum_supply_quantity: string;
    lead_time_days: string;
    warranty: string;
    return_conditions: string;
};

type Listing = {
    id: string;
    product_name: string;
    description: string | null;
    category_id: string | null;
    category_suggestion: string | null;
    brand_id: string | null;
    brand_suggestion: string | null;
    supplier_note: string | null;
    items: {
        id: string;
        variant_label: string | null;
        supplier_sku: string;
        supplier_rate: Money;
        available_quantity: number;
        minimum_supply_quantity: number;
        lead_time_days: number | null;
        warranty: string | null;
        return_conditions: string | null;
    }[];
};

const emptyItem = (): ItemInput => ({
    id: '',
    variant_label: '',
    supplier_sku: '',
    supplier_rate: '',
    available_quantity: '0',
    minimum_supply_quantity: '1',
    lead_time_days: '',
    warranty: '',
    return_conditions: '',
});

export default function SupplierListingForm({
    listing,
    options,
}: {
    listing: Listing | null;
    options: {
        categories: { value: string; label: string }[];
        brands: { value: string; label: string }[];
    };
}) {
    const { t } = useTranslation();

    const form = useForm({
        product_name: listing?.product_name ?? '',
        description: listing?.description ?? '',
        category_id: listing?.category_id ?? '',
        category_suggestion: listing?.category_suggestion ?? '',
        brand_id: listing?.brand_id ?? '',
        brand_suggestion: listing?.brand_suggestion ?? '',
        supplier_note: listing?.supplier_note ?? '',
        items: (listing?.items.map((item) => ({
            id: item.id,
            variant_label: item.variant_label ?? '',
            supplier_sku: item.supplier_sku,
            supplier_rate: item.supplier_rate.amount,
            available_quantity: String(item.available_quantity),
            minimum_supply_quantity: String(item.minimum_supply_quantity),
            lead_time_days:
                item.lead_time_days === null ? '' : String(item.lead_time_days),
            warranty: item.warranty ?? '',
            return_conditions: item.return_conditions ?? '',
        })) ?? [emptyItem()]) as ItemInput[],
    });

    const setItem = (index: number, key: keyof ItemInput, value: string) =>
        form.setData(
            'items',
            form.data.items.map((item, position) =>
                position === index ? { ...item, [key]: value } : item,
            ),
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.transform((data) => ({
            ...data,
            items: data.items.map((item) => ({
                id: item.id || null,
                variant_label: item.variant_label || null,
                supplier_sku: item.supplier_sku,
                // The Taka string exactly as typed; the server is the only
                // place that parses it (§36.1).
                supplier_rate: item.supplier_rate,
                currency_code: 'BDT',
                available_quantity: Number.parseInt(
                    item.available_quantity || '0',
                    10,
                ),
                minimum_supply_quantity: Number.parseInt(
                    item.minimum_supply_quantity || '1',
                    10,
                ),
                lead_time_days:
                    item.lead_time_days === ''
                        ? null
                        : Number.parseInt(item.lead_time_days, 10),
                warranty: item.warranty || null,
                return_conditions: item.return_conditions || null,
            })),
        }));

        if (listing === null) {
            form.post(store().url);
        } else {
            form.patch(update(listing.id).url);
        }
    };

    const errorFor = (key: string) =>
        (form.errors as Record<string, string | undefined>)[key];

    return (
        <>
            <Head
                title={
                    listing
                        ? t('supplier.listings.edit')
                        : t('supplier.listings.new')
                }
            />

            <form onSubmit={submit} className="space-y-6">
                <PageHeader
                    title={
                        listing
                            ? t('supplier.listings.edit')
                            : t('supplier.listings.new')
                    }
                    description={t('supplier.listings.proposal_notice')}
                />

                <SectionCard title={t('supplier.listings.product_name')}>
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
                                    className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
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
                                    className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
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
                </SectionCard>

                {form.data.items.map((item, index) => (
                    <SectionCard
                        key={index}
                        title={`${t('supplier.listings.variations')} ${index + 1}`}
                        actions={
                            form.data.items.length > 1 && (
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
                                    {t('supplier.listings.remove_variation')}
                                </Button>
                            )
                        }
                    >
                        <div className="grid gap-4 sm:grid-cols-2">
                            <FormField
                                label={t('supplier.listings.variant_label')}
                                error={errorFor(`items.${index}.variant_label`)}
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
                                label={t('supplier.listings.supplier_sku')}
                                error={errorFor(`items.${index}.supplier_sku`)}
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
                                label={t('supplier.listings.supplier_rate')}
                                error={errorFor(`items.${index}.supplier_rate`)}
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
                                    'supplier.listings.available_quantity',
                                )}
                                error={errorFor(
                                    `items.${index}.available_quantity`,
                                )}
                                required
                            >
                                {(field) => (
                                    <Input
                                        {...field}
                                        type="number"
                                        min={0}
                                        value={item.available_quantity}
                                        onChange={(e) =>
                                            setItem(
                                                index,
                                                'available_quantity',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                )}
                            </FormField>
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
                                        value={item.minimum_supply_quantity}
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
                                label={t('supplier.listings.lead_time_days')}
                                error={errorFor(
                                    `items.${index}.lead_time_days`,
                                )}
                            >
                                {(field) => (
                                    <Input
                                        {...field}
                                        type="number"
                                        min={0}
                                        value={item.lead_time_days}
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
                            <FormField
                                label={t('supplier.listings.warranty')}
                                error={errorFor(`items.${index}.warranty`)}
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
                                label={t('supplier.listings.return_conditions')}
                                error={errorFor(
                                    `items.${index}.return_conditions`,
                                )}
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
                    </SectionCard>
                ))}

                <div className="flex flex-wrap gap-3">
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
                    <SubmitButton processing={form.processing}>
                        {t('supplier.listings.save_draft')}
                    </SubmitButton>
                </div>
            </form>
        </>
    );
}
