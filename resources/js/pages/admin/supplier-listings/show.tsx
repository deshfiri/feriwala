import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { FormEvent } from 'react';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import TextArea from '@/components/forms/text-area';
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
import { index } from '@/routes/admin/supplier-listings';
import { store as correction } from '@/routes/admin/supplier-listings/correction';
import { store as decision } from '@/routes/admin/supplier-listings/decision';

type Item = {
    id: string;
    variant_label: string | null;
    supplier_sku: string;
    supplier_rate: Money | null;
    available_quantity: number;
    minimum_supply_quantity: number;
    lead_time_days: number | null;
    warranty: string | null;
    return_conditions: string | null;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    decision_note: string | null;
};

type Props = {
    listing: {
        id: string;
        reference: string;
        product_name: string;
        description: string | null;
        category: string | null;
        category_suggestion: string | null;
        brand: string | null;
        supplier_note: string | null;
        status_label: string;
        status_tone: StatusTone;
        awaits_decision: boolean;
        can_review: boolean;
        can_decide: boolean;
        may_view_pricing: boolean;
        connected_product: string | null;
        items: Item[];
    };
    supplier: { id: string; business_name: string; status_label: string };
    options: { categories: { value: string; label: string }[] };
    history: {
        new_status: string;
        changed_by: string | null;
        changed_at: string;
        reason: string | null;
        public_note: string | null;
    }[];
};

type ItemDecision = {
    item_id: string;
    decision: 'skip' | 'approve' | 'reject' | 'correction';
    variant_id: string;
    /** Whole taka typed by the reviewer; converted to minor units on submit. */
    platform_rate: string;
    wholesale_enabled: boolean;
    dropshipping_enabled: boolean;
    note: string;
};

function toMinorUnits(value: string): number {
    const [whole = '0', fraction = ''] = value.trim().split('.');

    return (
        Number.parseInt(whole || '0', 10) * 100 +
        Number.parseInt((fraction + '00').slice(0, 2), 10)
    );
}

/**
 * A Supplier's listing request, and the staff decision on it (D25).
 *
 * Deciding connects the proposal to the Central Catalogue — an existing
 * product, or a new one made through the ordinary catalogue action — and
 * prices every approved variation as its own Supplier offer. Nothing on this
 * page publishes a product by itself.
 */
export default function AdminSupplierListingShow({
    listing,
    supplier,
    options,
    history,
}: Props) {
    const { t, locale } = useTranslation();

    const correctionForm = useForm({ feedback: '', internal_reason: '' });

    const form = useForm({
        mode: 'connect' as 'connect' | 'create',
        connect_product_id: '',
        sku: '',
        category_id: '',
        reason: '',
        items: listing.items
            .filter((item) => item.status === 'pending')
            .map<ItemDecision>((item) => ({
                item_id: item.id,
                decision: 'skip',
                variant_id: '',
                platform_rate: '',
                wholesale_enabled: true,
                dropshipping_enabled: false,
                note: '',
            })),
    });

    const setItem = (index: number, patch: Partial<ItemDecision>) =>
        form.setData(
            'items',
            form.data.items.map((item, position) =>
                position === index ? { ...item, ...patch } : item,
            ),
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.transform((data) => ({
            reason: data.reason,
            connect_product_id:
                data.mode === 'connect'
                    ? data.connect_product_id || null
                    : null,
            create_product: data.mode === 'create',
            sku: data.mode === 'create' ? data.sku : null,
            category_id:
                data.mode === 'create' ? data.category_id || null : null,
            items: data.items
                .filter((item) => item.decision !== 'skip')
                .map((item) => ({
                    item_id: item.item_id,
                    decision: item.decision,
                    variant_id: item.variant_id || null,
                    platform_rate_minor:
                        item.decision === 'approve'
                            ? toMinorUnits(item.platform_rate)
                            : null,
                    wholesale_enabled: item.wholesale_enabled,
                    dropshipping_enabled: item.dropshipping_enabled,
                    note: item.note || null,
                })),
        }));

        form.post(decision(listing.id).url, { preserveScroll: true });
    };

    const errorFor = (key: string) =>
        (form.errors as Record<string, string | undefined>)[key];

    return (
        <>
            <Head title={listing.product_name} />

            <PageContainer>
                <PageHeader
                    title={listing.product_name}
                    description={`${listing.reference} · ${supplier.business_name}`}
                    actions={
                        <>
                            <StatusPill
                                tone={listing.status_tone}
                                label={listing.status_label}
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

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="space-y-6 lg:col-span-2">
                        <SectionCard title={listing.product_name}>
                            <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                                {[
                                    [
                                        t(
                                            'supplier.admin.listings.supplier_summary',
                                        ),
                                        `${supplier.business_name} (${supplier.status_label})`,
                                    ],
                                    [
                                        t('supplier.listings.category'),
                                        listing.category ??
                                            listing.category_suggestion,
                                    ],
                                    [
                                        t('supplier.listings.brand'),
                                        listing.brand,
                                    ],
                                    [
                                        t(
                                            'supplier.listings.description_field',
                                        ),
                                        listing.description,
                                    ],
                                    [
                                        t('supplier.listings.supplier_note'),
                                        listing.supplier_note,
                                    ],
                                ].map(([label, value]) => (
                                    <div key={label}>
                                        <dt className="text-muted-foreground text-xs">
                                            {label}
                                        </dt>
                                        <dd className="font-medium break-words">
                                            {value ?? '—'}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </SectionCard>

                        <SectionCard title={t('supplier.admin.listings.items')}>
                            <ul className="divide-border divide-y">
                                {listing.items.map((item) => (
                                    <li
                                        key={item.id}
                                        className="space-y-1 py-3 first:pt-0 last:pb-0"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <span className="text-sm font-medium">
                                                {item.variant_label ??
                                                    listing.product_name}{' '}
                                                · {item.supplier_sku}
                                            </span>
                                            <StatusPill
                                                tone={item.status_tone}
                                                label={item.status_label}
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
                                            )}{' '}
                                            ·{' '}
                                            {t(
                                                'supplier.listings.available_quantity',
                                            )}
                                            :{' '}
                                            {item.available_quantity.toLocaleString(
                                                locale,
                                            )}{' '}
                                            ·{' '}
                                            {t(
                                                'supplier.listings.minimum_supply_quantity',
                                            )}
                                            : {item.minimum_supply_quantity}
                                        </p>
                                        {item.decision_note && (
                                            <p className="text-sm">
                                                {item.decision_note}
                                            </p>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </SectionCard>

                        <SectionCard title={t('supplier.listings.history')}>
                            <ol className="divide-border divide-y text-sm">
                                {history.map((change, position) => (
                                    <li key={position} className="py-2">
                                        <div className="flex flex-wrap justify-between gap-2">
                                            <span className="font-medium">
                                                {change.new_status}
                                            </span>
                                            <span className="text-muted-foreground text-xs">
                                                {change.changed_by ?? '—'} ·{' '}
                                                {new Date(
                                                    change.changed_at,
                                                ).toLocaleString(locale)}
                                            </span>
                                        </div>
                                        {change.reason && (
                                            <p>{change.reason}</p>
                                        )}
                                    </li>
                                ))}
                            </ol>
                        </SectionCard>
                    </div>

                    <aside className="space-y-6 lg:col-span-1">
                        {listing.awaits_decision && listing.can_review && (
                            <SectionCard
                                title={t(
                                    'supplier.admin.listings.request_correction',
                                )}
                            >
                                <form
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        correctionForm.post(
                                            correction(listing.id).url,
                                            { preserveScroll: true },
                                        );
                                    }}
                                    className="space-y-3"
                                >
                                    <FormField
                                        label={t(
                                            'supplier.admin.listings.feedback',
                                        )}
                                        error={correctionForm.errors.feedback}
                                        required
                                    >
                                        {(field) => (
                                            <TextArea
                                                {...field}
                                                value={
                                                    correctionForm.data.feedback
                                                }
                                                onChange={(e) =>
                                                    correctionForm.setData(
                                                        'feedback',
                                                        e.target.value,
                                                    )
                                                }
                                                required
                                            />
                                        )}
                                    </FormField>
                                    <SubmitButton
                                        processing={correctionForm.processing}
                                        variant="outline"
                                        className="w-full"
                                    >
                                        {t(
                                            'supplier.admin.listings.request_correction',
                                        )}
                                    </SubmitButton>
                                </form>
                            </SectionCard>
                        )}
                    </aside>
                </div>

                {listing.awaits_decision && (
                    <SectionCard title={t('supplier.admin.listings.connect')}>
                        {!listing.can_decide ? (
                            <p className="text-muted-foreground text-sm">
                                {t('supplier.admin.listings.cannot_decide')}
                            </p>
                        ) : (
                            <form onSubmit={submit} className="space-y-6">
                                <fieldset className="grid gap-3 sm:grid-cols-2">
                                    <legend className="sr-only">
                                        {t('supplier.admin.listings.connect')}
                                    </legend>
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="radio"
                                            name="mode"
                                            checked={
                                                form.data.mode === 'connect'
                                            }
                                            onChange={() =>
                                                form.setData('mode', 'connect')
                                            }
                                        />
                                        {t(
                                            'supplier.admin.listings.connect_existing',
                                        )}
                                    </label>
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="radio"
                                            name="mode"
                                            checked={
                                                form.data.mode === 'create'
                                            }
                                            onChange={() =>
                                                form.setData('mode', 'create')
                                            }
                                        />
                                        {t(
                                            'supplier.admin.listings.create_new',
                                        )}
                                    </label>
                                </fieldset>

                                {form.data.mode === 'connect' ? (
                                    <FormField
                                        label={t(
                                            'supplier.admin.listings.product_id',
                                        )}
                                        error={errorFor('connect_product_id')}
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                value={
                                                    form.data.connect_product_id
                                                }
                                                onChange={(e) =>
                                                    form.setData(
                                                        'connect_product_id',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        )}
                                    </FormField>
                                ) : (
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <FormField
                                            label={t(
                                                'supplier.admin.listings.sku',
                                            )}
                                            error={errorFor('sku')}
                                            required
                                        >
                                            {(field) => (
                                                <Input
                                                    {...field}
                                                    value={form.data.sku}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'sku',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            )}
                                        </FormField>
                                        <FormField
                                            label={t(
                                                'supplier.admin.listings.category',
                                            )}
                                            error={errorFor('category_id')}
                                        >
                                            {(field) => (
                                                <select
                                                    {...field}
                                                    value={
                                                        form.data.category_id
                                                    }
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'category_id',
                                                            e.target.value,
                                                        )
                                                    }
                                                    className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                                                >
                                                    <option value="">—</option>
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
                                                                {option.label}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            )}
                                        </FormField>
                                    </div>
                                )}

                                {form.data.items.map((decisionItem, index) => {
                                    const source = listing.items.find(
                                        (item) =>
                                            item.id === decisionItem.item_id,
                                    );

                                    return (
                                        <div
                                            key={decisionItem.item_id}
                                            className="border-border space-y-3 rounded-lg border p-4"
                                        >
                                            <p className="text-sm font-medium">
                                                {source?.variant_label ??
                                                    listing.product_name}{' '}
                                                · {source?.supplier_sku}
                                            </p>

                                            <FormField
                                                label={t(
                                                    'supplier.admin.listings.decision_per_item',
                                                )}
                                                error={errorFor(
                                                    `items.${index}.decision`,
                                                )}
                                            >
                                                {(field) => (
                                                    <select
                                                        {...field}
                                                        value={
                                                            decisionItem.decision
                                                        }
                                                        onChange={(e) =>
                                                            setItem(index, {
                                                                decision: e
                                                                    .target
                                                                    .value as ItemDecision['decision'],
                                                            })
                                                        }
                                                        className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                                                    >
                                                        <option value="skip">
                                                            {t(
                                                                'supplier.admin.listings.skip',
                                                            )}
                                                        </option>
                                                        <option value="approve">
                                                            {t(
                                                                'supplier.admin.listings.approve',
                                                            )}
                                                        </option>
                                                        <option value="reject">
                                                            {t(
                                                                'supplier.admin.listings.reject',
                                                            )}
                                                        </option>
                                                        <option value="correction">
                                                            {t(
                                                                'supplier.admin.listings.correction',
                                                            )}
                                                        </option>
                                                    </select>
                                                )}
                                            </FormField>

                                            {decisionItem.decision ===
                                                'approve' && (
                                                <div className="grid gap-4 sm:grid-cols-2">
                                                    <FormField
                                                        label={t(
                                                            'supplier.admin.listings.platform_rate',
                                                        )}
                                                        error={errorFor(
                                                            `items.${index}.platform_rate_minor`,
                                                        )}
                                                        required
                                                    >
                                                        {(field) => (
                                                            <Input
                                                                {...field}
                                                                inputMode="decimal"
                                                                value={
                                                                    decisionItem.platform_rate
                                                                }
                                                                onChange={(e) =>
                                                                    setItem(
                                                                        index,
                                                                        {
                                                                            platform_rate:
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                    )
                                                                }
                                                                required
                                                            />
                                                        )}
                                                    </FormField>
                                                    <FormField
                                                        label={t(
                                                            'supplier.admin.listings.variant_id',
                                                        )}
                                                    >
                                                        {(field) => (
                                                            <Input
                                                                {...field}
                                                                value={
                                                                    decisionItem.variant_id
                                                                }
                                                                onChange={(e) =>
                                                                    setItem(
                                                                        index,
                                                                        {
                                                                            variant_id:
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        )}
                                                    </FormField>
                                                    <label className="flex items-center gap-2 text-sm">
                                                        <input
                                                            type="checkbox"
                                                            checked={
                                                                decisionItem.wholesale_enabled
                                                            }
                                                            onChange={(e) =>
                                                                setItem(index, {
                                                                    wholesale_enabled:
                                                                        e.target
                                                                            .checked,
                                                                })
                                                            }
                                                        />
                                                        {t(
                                                            'supplier.admin.listings.wholesale',
                                                        )}
                                                    </label>
                                                    <label className="flex items-center gap-2 text-sm">
                                                        <input
                                                            type="checkbox"
                                                            checked={
                                                                decisionItem.dropshipping_enabled
                                                            }
                                                            onChange={(e) =>
                                                                setItem(index, {
                                                                    dropshipping_enabled:
                                                                        e.target
                                                                            .checked,
                                                                })
                                                            }
                                                        />
                                                        {t(
                                                            'supplier.admin.listings.dropshipping',
                                                        )}
                                                    </label>
                                                </div>
                                            )}

                                            {decisionItem.decision !==
                                                'skip' && (
                                                <FormField
                                                    label={t(
                                                        'supplier.admin.suppliers.note',
                                                    )}
                                                >
                                                    {(field) => (
                                                        <Input
                                                            {...field}
                                                            value={
                                                                decisionItem.note
                                                            }
                                                            onChange={(e) =>
                                                                setItem(index, {
                                                                    note: e
                                                                        .target
                                                                        .value,
                                                                })
                                                            }
                                                        />
                                                    )}
                                                </FormField>
                                            )}
                                        </div>
                                    );
                                })}

                                <FormField
                                    label={t('supplier.admin.listings.reason')}
                                    error={form.errors.reason}
                                    required
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            value={form.data.reason}
                                            onChange={(e) =>
                                                form.setData(
                                                    'reason',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                        />
                                    )}
                                </FormField>

                                <SubmitButton processing={form.processing}>
                                    {t(
                                        'supplier.admin.listings.record_decision',
                                    )}
                                </SubmitButton>
                            </form>
                        )}
                    </SectionCard>
                )}
            </PageContainer>
        </>
    );
}

AdminSupplierListingShow.layout = {
    breadcrumbs: [{ title: 'Supplier listings', href: index() }],
};
