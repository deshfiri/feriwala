import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { FormEvent } from 'react';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import ProductLinkPicker from '@/components/product-links/product-link-picker';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import type { StatusTone } from '@/lib/status';
import type { ProductLinkSummary } from '@/types';
import { index } from '@/routes/admin/supplier-listing-lots';
import { store as decision } from '@/routes/admin/supplier-listing-lots/decision';

type Item = {
    id: string;
    variant_label: string | null;
    supplier_sku: string;
    supplier_rate: Money | null;
    supply_mode: 'ready_stock' | 'on_demand' | 'pre_order';
    supply_mode_label: string;
    available_quantity: number | null;
    fulfilment_capacity: number | null;
    lead_time_days: number | null;
    expected_availability_at: string | null;
    status: string;
    status_label: string;
    decision_note: string | null;
    suggested_variants: { id: string; label: string }[];
};

type Entry = {
    id: string;
    product_name: string;
    description: string | null;
    category: string | null;
    category_suggestion: string | null;
    brand: string | null;
    brand_suggestion: string | null;
    supplier_note: string | null;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    connected_product: string | null;
    primary_media_url: string | null;
    items: Item[];
};

type Props = {
    lot: {
        id: string;
        reference: string;
        title: string | null;
        status_label: string;
        status_tone: StatusTone;
        awaits_decision: boolean;
        can_decide: boolean;
        may_view_pricing: boolean;
        entries: Entry[];
    };
    supplier: { id: string; business_name: string };
    options: { categories: { value: string; label: string }[] };
    /** Staff-only: whether this reviewer may link a Product as the same as others. */
    can_link_products: boolean;
};

type ItemDecision = {
    item_id: string;
    decision: 'skip' | 'approve' | 'reject' | 'correction';
    variant_id: string;
    platform_rate: string;
    approved_quantity: string;
    note: string;
};

type EntryDecision = {
    listing_id: string;
    reason: string;
    mode: 'connect' | 'create';
    connect_product_id: string;
    sku: string;
    category_id: string;
    linked_products: ProductLinkSummary[];
    items: ItemDecision[];
};

/**
 * A Supplier's whole listing batch, decided one product entry at a time
 * (Supplier Bulk Product Listing batch).
 *
 * The submission is one request covering every entry that is decided this
 * round, but every entry -- like every listing item one level down -- is
 * decided independently: {@see DecideSupplierListingLot} reports a bad entry
 * and keeps the rest, it never rolls a whole round back over one mistake.
 */
export default function AdminSupplierListingLotShow({
    lot,
    supplier,
    options,
    can_link_products,
}: Props) {
    const { t, locale } = useTranslation();

    const pendingEntries = lot.entries.filter((entry) =>
        entry.items.some((item) => item.status === 'pending'),
    );

    const form = useForm({
        entries: pendingEntries.map<EntryDecision>((entry) => ({
            listing_id: entry.id,
            reason: '',
            mode: 'connect',
            connect_product_id: entry.connected_product ?? '',
            sku: '',
            category_id: '',
            linked_products: [] as ProductLinkSummary[],
            items: entry.items
                .filter((item) => item.status === 'pending')
                .map<ItemDecision>((item) => ({
                    item_id: item.id,
                    decision: 'skip',
                    variant_id: '',
                    platform_rate: '',
                    approved_quantity: '',
                    note: '',
                })),
        })),
    });

    const setEntry = (index: number, patch: Partial<EntryDecision>) =>
        form.setData(
            'entries',
            form.data.entries.map((entry, position) =>
                position === index ? { ...entry, ...patch } : entry,
            ),
        );

    const setItem = (
        entryIndex: number,
        itemIndex: number,
        patch: Partial<ItemDecision>,
    ) =>
        form.setData(
            'entries',
            form.data.entries.map((entry, ePos) =>
                ePos !== entryIndex
                    ? entry
                    : {
                          ...entry,
                          items: entry.items.map((item, iPos) =>
                              iPos === itemIndex ? { ...item, ...patch } : item,
                          ),
                      },
            ),
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.transform((data) => ({
            entries: data.entries
                .filter((entry) =>
                    entry.items.some((item) => item.decision !== 'skip'),
                )
                .map((entry) => ({
                    listing_id: entry.listing_id,
                    reason: entry.reason,
                    connect_product_id:
                        entry.mode === 'connect'
                            ? entry.connect_product_id || null
                            : null,
                    create_product: entry.mode === 'create',
                    sku: entry.mode === 'create' ? entry.sku : null,
                    category_id:
                        entry.mode === 'create'
                            ? entry.category_id || null
                            : null,
                    // Only what the reviewer picked; empty keeps it unique.
                    link_product_ids: entry.linked_products.map(
                        (linked) => linked.id,
                    ),
                    items: entry.items
                        .filter((item) => item.decision !== 'skip')
                        .map((item) => ({
                            item_id: item.item_id,
                            decision: item.decision,
                            variant_id: item.variant_id || null,
                            platform_rate:
                                item.decision === 'approve'
                                    ? item.platform_rate
                                    : null,
                            approved_quantity:
                                item.decision === 'approve' &&
                                item.approved_quantity !== ''
                                    ? Number.parseInt(
                                          item.approved_quantity,
                                          10,
                                      )
                                    : null,
                            note: item.note || null,
                        })),
                })),
        }));

        form.post(decision(lot.id).url, { preserveScroll: true });
    };

    const errorFor = (key: string) =>
        (form.errors as Record<string, string | undefined>)[key];

    return (
        <>
            <Head title={lot.title ?? lot.reference} />

            <PageContainer>
                <PageHeader
                    title={lot.title ?? lot.reference}
                    description={`${lot.reference} · ${supplier.business_name}`}
                    actions={
                        <>
                            <StatusPill
                                tone={lot.status_tone}
                                label={lot.status_label}
                            />
                            <Button variant="outline" size="sm" asChild>
                                <Link href={index()}>
                                    <ArrowLeft
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t('supplier.admin.suppliers.back')}
                                </Link>
                            </Button>
                        </>
                    }
                />

                <form onSubmit={submit} className="space-y-6">
                    {lot.entries.map((entry) => {
                        const entryIndex = form.data.entries.findIndex(
                            (e) => e.listing_id === entry.id,
                        );
                        const entryDecision =
                            entryIndex >= 0
                                ? form.data.entries[entryIndex]
                                : null;

                        return (
                            <SectionCard
                                key={entry.id}
                                title={entry.product_name}
                                description={
                                    entry.category ??
                                    entry.category_suggestion ??
                                    undefined
                                }
                                actions={
                                    <StatusPill
                                        tone={entry.status_tone}
                                        label={entry.status_label}
                                    />
                                }
                            >
                                <div className="space-y-4">
                                    {entry.primary_media_url ? (
                                        <img
                                            src={entry.primary_media_url}
                                            alt={entry.product_name}
                                            className="bg-muted h-32 w-32 rounded-md object-cover"
                                        />
                                    ) : (
                                        <p className="text-danger text-sm">
                                            {t(
                                                'supplier.admin.listing_lots.no_primary_image',
                                            )}
                                        </p>
                                    )}

                                    <ul className="divide-border divide-y">
                                        {entry.items.map((item) => (
                                            <li
                                                key={item.id}
                                                className="space-y-1 py-3 first:pt-0 last:pb-0"
                                            >
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <span className="text-sm font-medium">
                                                        {item.variant_label ??
                                                            entry.product_name}{' '}
                                                        · {item.supplier_sku}
                                                    </span>
                                                    <StatusPill
                                                        tone="neutral"
                                                        label={
                                                            item.supply_mode_label
                                                        }
                                                    />
                                                </div>
                                                <p className="text-muted-foreground text-sm">
                                                    {item.supplier_rate ? (
                                                        <>
                                                            {t(
                                                                'supplier.listings.supplier_rate',
                                                            )}
                                                            :{' '}
                                                            <MoneyAmount
                                                                amount={
                                                                    item.supplier_rate
                                                                }
                                                            />
                                                        </>
                                                    ) : (
                                                        t(
                                                            'supplier.admin.listings.pricing_hidden',
                                                        )
                                                    )}
                                                    {item.supply_mode ===
                                                        'ready_stock' &&
                                                        item.available_quantity !==
                                                            null && (
                                                            <>
                                                                {' '}
                                                                ·{' '}
                                                                {t(
                                                                    'supplier.listings.available_quantity',
                                                                )}
                                                                :{' '}
                                                                {item.available_quantity.toLocaleString(
                                                                    locale,
                                                                )}
                                                            </>
                                                        )}
                                                    {item.supply_mode !==
                                                        'ready_stock' &&
                                                        item.fulfilment_capacity !==
                                                            null && (
                                                            <>
                                                                {' '}
                                                                ·{' '}
                                                                {t(
                                                                    'supplier.listing_lots.supply_mode.fulfilment_capacity',
                                                                )}
                                                                :{' '}
                                                                {item.fulfilment_capacity.toLocaleString(
                                                                    locale,
                                                                )}
                                                            </>
                                                        )}
                                                </p>

                                                {entryDecision &&
                                                    item.status ===
                                                        'pending' && (
                                                        <ItemDecisionFields
                                                            item={item}
                                                            decision={entryDecision.items.find(
                                                                (d) =>
                                                                    d.item_id ===
                                                                    item.id,
                                                            )!}
                                                            locale={locale}
                                                            onChange={(patch) =>
                                                                setItem(
                                                                    entryIndex,
                                                                    entryDecision.items.findIndex(
                                                                        (d) =>
                                                                            d.item_id ===
                                                                            item.id,
                                                                    ),
                                                                    patch,
                                                                )
                                                            }
                                                            errorFor={(key) =>
                                                                errorFor(
                                                                    `entries.${entryIndex}.items.${entryDecision.items.findIndex((d) => d.item_id === item.id)}.${key}`,
                                                                )
                                                            }
                                                        />
                                                    )}

                                                {item.decision_note && (
                                                    <p className="text-sm">
                                                        {item.decision_note}
                                                    </p>
                                                )}
                                            </li>
                                        ))}
                                    </ul>

                                    {entryDecision && (
                                        <div className="grid gap-4 border-t pt-4 sm:grid-cols-2">
                                            {!entry.connected_product && (
                                                <>
                                                    <FormField
                                                        label={t(
                                                            'supplier.admin.listings.product_id',
                                                        )}
                                                        error={errorFor(
                                                            `entries.${entryIndex}.connect_product_id`,
                                                        )}
                                                    >
                                                        {(field) => (
                                                            <Input
                                                                {...field}
                                                                value={
                                                                    entryDecision.connect_product_id
                                                                }
                                                                onChange={(e) =>
                                                                    setEntry(
                                                                        entryIndex,
                                                                        {
                                                                            mode: 'connect',
                                                                            connect_product_id:
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        )}
                                                    </FormField>
                                                    <FormField
                                                        label={t(
                                                            'supplier.admin.listings.sku',
                                                        )}
                                                        error={errorFor(
                                                            `entries.${entryIndex}.sku`,
                                                        )}
                                                    >
                                                        {(field) => (
                                                            <Input
                                                                {...field}
                                                                value={
                                                                    entryDecision.sku
                                                                }
                                                                onChange={(e) =>
                                                                    setEntry(
                                                                        entryIndex,
                                                                        {
                                                                            mode: 'create',
                                                                            sku: e
                                                                                .target
                                                                                .value,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        )}
                                                    </FormField>
                                                    {entryDecision.mode ===
                                                        'create' && (
                                                        <FormField
                                                            label={t(
                                                                'supplier.admin.listings.category',
                                                            )}
                                                            error={errorFor(
                                                                `entries.${entryIndex}.category_id`,
                                                            )}
                                                        >
                                                            {(field) => (
                                                                <select
                                                                    {...field}
                                                                    value={
                                                                        entryDecision.category_id
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        setEntry(
                                                                            entryIndex,
                                                                            {
                                                                                category_id:
                                                                                    e
                                                                                        .target
                                                                                        .value,
                                                                            },
                                                                        )
                                                                    }
                                                                    className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                                                                >
                                                                    <option value="">
                                                                        —
                                                                    </option>
                                                                    {options.categories.map(
                                                                        (
                                                                            option,
                                                                        ) => (
                                                                            <option
                                                                                key={
                                                                                    option.value
                                                                                }
                                                                                value={
                                                                                    option.value
                                                                                }
                                                                            >
                                                                                {
                                                                                    option.label
                                                                                }
                                                                            </option>
                                                                        ),
                                                                    )}
                                                                </select>
                                                            )}
                                                        </FormField>
                                                    )}
                                                </>
                                            )}
                                            {can_link_products && (
                                                <div className="sm:col-span-2">
                                                    <ProductLinkPicker
                                                        value={
                                                            entryDecision.linked_products
                                                        }
                                                        onChange={(next) =>
                                                            setEntry(
                                                                entryIndex,
                                                                {
                                                                    linked_products:
                                                                        next,
                                                                },
                                                            )
                                                        }
                                                        excludeProductId={
                                                            entry.connected_product
                                                        }
                                                        error={errorFor(
                                                            `entries.${entryIndex}.link_product_ids`,
                                                        )}
                                                    />
                                                </div>
                                            )}
                                            <FormField
                                                label={t(
                                                    'supplier.admin.listings.reason',
                                                )}
                                                error={errorFor(
                                                    `entries.${entryIndex}.reason`,
                                                )}
                                                required
                                                className="sm:col-span-2"
                                            >
                                                {(field) => (
                                                    <Input
                                                        {...field}
                                                        value={
                                                            entryDecision.reason
                                                        }
                                                        onChange={(e) =>
                                                            setEntry(
                                                                entryIndex,
                                                                {
                                                                    reason: e
                                                                        .target
                                                                        .value,
                                                                },
                                                            )
                                                        }
                                                    />
                                                )}
                                            </FormField>
                                        </div>
                                    )}
                                </div>
                            </SectionCard>
                        );
                    })}

                    {lot.awaits_decision &&
                        (lot.can_decide ? (
                            <SubmitButton processing={form.processing}>
                                {t(
                                    'supplier.admin.listing_lots.record_decisions',
                                )}
                            </SubmitButton>
                        ) : (
                            <p className="text-muted-foreground text-sm">
                                {t('supplier.admin.listings.cannot_decide')}
                            </p>
                        ))}
                </form>
            </PageContainer>
        </>
    );
}

function ItemDecisionFields({
    item,
    decision,
    locale,
    onChange,
    errorFor,
}: {
    item: Item;
    decision: ItemDecision;
    locale: string;
    onChange: (patch: Partial<ItemDecision>) => void;
    errorFor: (key: string) => string | undefined;
}) {
    const { t } = useTranslation();

    return (
        <div className="space-y-3 rounded-lg border p-3">
            <FormField
                label={t('supplier.admin.listings.decision_per_item')}
                error={errorFor('decision')}
            >
                {(field) => (
                    <select
                        {...field}
                        value={decision.decision}
                        onChange={(e) =>
                            onChange({
                                decision: e.target
                                    .value as ItemDecision['decision'],
                            })
                        }
                        className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                    >
                        <option value="skip">
                            {t('supplier.admin.listings.skip')}
                        </option>
                        <option value="approve">
                            {t('supplier.admin.listings.approve')}
                        </option>
                        <option value="reject">
                            {t('supplier.admin.listings.reject')}
                        </option>
                        <option value="correction">
                            {t('supplier.admin.listings.correction')}
                        </option>
                    </select>
                )}
            </FormField>

            {decision.decision === 'approve' && (
                <div className="grid gap-3 sm:grid-cols-2">
                    <FormField
                        label={t('supplier.admin.listings.platform_rate')}
                        error={errorFor('platform_rate')}
                        required
                    >
                        {(field) => (
                            <Input
                                {...field}
                                inputMode="decimal"
                                value={decision.platform_rate}
                                onChange={(e) =>
                                    onChange({
                                        platform_rate: e.target.value,
                                    })
                                }
                                required
                            />
                        )}
                    </FormField>
                    <FormField
                        label={t('supplier.admin.listings.variant_id')}
                        error={errorFor('variant_id')}
                    >
                        {(field) => (
                            <select
                                {...field}
                                value={decision.variant_id}
                                onChange={(e) =>
                                    onChange({ variant_id: e.target.value })
                                }
                                className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                            >
                                <option value="">—</option>
                                {item.suggested_variants.map((variant) => (
                                    <option key={variant.id} value={variant.id}>
                                        {variant.label}
                                    </option>
                                ))}
                            </select>
                        )}
                    </FormField>
                    {item.supply_mode === 'ready_stock' && (
                        <FormField
                            label={t(
                                'supplier.admin.listings.approved_quantity',
                            )}
                            description={t(
                                'supplier.admin.listings.approved_quantity_hint',
                                {
                                    quantity: (
                                        item.available_quantity ?? 0
                                    ).toLocaleString(locale),
                                },
                            )}
                            error={errorFor('approved_quantity')}
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    type="number"
                                    min={0}
                                    value={decision.approved_quantity}
                                    onChange={(e) =>
                                        onChange({
                                            approved_quantity: e.target.value,
                                        })
                                    }
                                />
                            )}
                        </FormField>
                    )}
                </div>
            )}

            {decision.decision !== 'skip' && (
                <FormField label={t('supplier.admin.suppliers.note')}>
                    {(field) => (
                        <Input
                            {...field}
                            value={decision.note}
                            onChange={(e) => onChange({ note: e.target.value })}
                        />
                    )}
                </FormField>
            )}
        </div>
    );
}

AdminSupplierListingLotShow.layout = {
    breadcrumbs: [{ title: 'nav.supplier_listing_lots', href: index() }],
};
