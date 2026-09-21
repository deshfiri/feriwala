import { Form, Head, Link } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import SubmitButton from '@/components/forms/submit-button';
import MoneyAmount from '@/components/money-amount';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import type { StatusTone } from '@/lib/status';
import { archive, edit } from '@/routes/supplier/listings';
import { store as submission } from '@/routes/supplier/listings/submission';

type Listing = {
    id: string;
    reference: string;
    product_name: string;
    description: string | null;
    category_suggestion: string | null;
    brand_suggestion: string | null;
    supplier_note: string | null;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    is_editable: boolean;
    decision_note: string | null;
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
        status_label: string;
        status_tone: StatusTone;
        decision_note: string | null;
    }[];
    status_history: {
        previous_status: string | null;
        new_status: string;
        reason: string | null;
        public_note: string | null;
        changed_at: string;
    }[];
};

export default function SupplierListingShow({ listing }: { listing: Listing }) {
    const { t, locale } = useTranslation();

    return (
        <>
            <Head title={listing.product_name} />

            <div className="space-y-6">
                <PageHeader
                    title={listing.product_name}
                    description={listing.reference}
                    actions={
                        <>
                            <StatusPill
                                tone={listing.status_tone}
                                label={listing.status_label}
                            />
                            {listing.is_editable && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={edit(listing.id)}>
                                        {t('supplier.listings.edit')}
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                {listing.decision_note && (
                    <SectionCard title={t('supplier.listings.reviewer_note')}>
                        <p className="text-sm">{listing.decision_note}</p>
                    </SectionCard>
                )}

                <SectionCard title={t('supplier.listings.variations')}>
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
                                    {t('supplier.listings.supplier_rate')}:{' '}
                                    <MoneyAmount amount={item.supplier_rate} />{' '}
                                    ·{' '}
                                    {t('supplier.listings.available_quantity')}:{' '}
                                    {item.available_quantity.toLocaleString(
                                        locale,
                                    )}
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

                {listing.is_editable ? (
                    <div className="flex flex-wrap gap-3">
                        <Form {...submission.form(listing.id)}>
                            {({ processing, errors }) => (
                                <div className="space-y-2">
                                    <SubmitButton processing={processing}>
                                        {t('supplier.listings.submit')}
                                    </SubmitButton>
                                    {errors.listing && (
                                        <p
                                            role="alert"
                                            className="text-danger text-sm font-medium"
                                        >
                                            {errors.listing}
                                        </p>
                                    )}
                                </div>
                            )}
                        </Form>
                        {listing.status === 'draft' && (
                            <Form {...archive.form(listing.id)}>
                                {({ processing }) => (
                                    <SubmitButton
                                        processing={processing}
                                        variant="outline"
                                    >
                                        {t('supplier.listings.archive')}
                                    </SubmitButton>
                                )}
                            </Form>
                        )}
                    </div>
                ) : (
                    <p className="text-muted-foreground flex items-center gap-2 text-sm">
                        <Lock className="size-4" aria-hidden="true" />
                        {t('supplier.listings.locked')}
                    </p>
                )}

                {listing.status_history.length > 0 && (
                    <SectionCard title={t('supplier.listings.history')}>
                        <ol className="divide-border divide-y text-sm">
                            {listing.status_history.map((change, position) => (
                                <li key={position} className="py-2">
                                    <div className="flex flex-wrap justify-between gap-2">
                                        <span className="font-medium">
                                            {change.new_status}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {new Date(
                                                change.changed_at,
                                            ).toLocaleString(locale)}
                                        </span>
                                    </div>
                                    {change.public_note && (
                                        <p className="text-muted-foreground">
                                            {change.public_note}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ol>
                    </SectionCard>
                )}
            </div>
        </>
    );
}
