import { Form, Head } from '@inertiajs/react';
import { Boxes } from 'lucide-react';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import SectionCard from '@/components/section-card';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import { store } from '@/routes/supplier/stock/updates';

type Offer = {
    id: string;
    product_name: string;
    available_quantity: number;
    pending_update: boolean;
    recent_updates: {
        id: string;
        requested_quantity: number;
        status_label: string;
        decision_note: string | null;
        created_at: string;
    }[];
};

/**
 * The Supplier's own availability. A submission only — the quantity changes
 * once staff approves it, and central warehouse stock is never shown here.
 */
export default function SupplierStock({ offers }: { offers: Offer[] }) {
    const { t, locale } = useTranslation();

    return (
        <>
            <Head title={t('supplier.stock.title')} />

            <PageContainer>
                <PageHeader
                    title={t('supplier.stock.title')}
                    description={t('supplier.stock.description')}
                />

                {offers.length === 0 && (
                    <EmptyState
                        icon={Boxes}
                        title={t('supplier.stock.empty_title')}
                        description={t('supplier.stock.empty_description')}
                    />
                )}

                {offers.map((offer) => (
                    <SectionCard key={offer.id} title={offer.product_name}>
                        <p className="text-sm">
                            {t('supplier.stock.current')}:{' '}
                            <strong>
                                {offer.available_quantity.toLocaleString(
                                    locale,
                                )}
                            </strong>
                            {offer.pending_update && (
                                <span className="text-muted-foreground">
                                    {' '}
                                    · {t('supplier.stock.pending')}
                                </span>
                            )}
                        </p>

                        <Form
                            {...store.form(offer.id)}
                            resetOnSuccess
                            options={{ preserveScroll: true }}
                            className="mt-4 grid gap-4 sm:grid-cols-3"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <FormField
                                        label={t('supplier.stock.quantity')}
                                        error={errors.quantity}
                                        required
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                type="number"
                                                min={0}
                                                name="quantity"
                                                required
                                            />
                                        )}
                                    </FormField>
                                    <FormField
                                        label={t('supplier.stock.note')}
                                        error={errors.note}
                                        className="sm:col-span-2"
                                    >
                                        {(field) => (
                                            <Input {...field} name="note" />
                                        )}
                                    </FormField>
                                    <div className="sm:col-span-3">
                                        <SubmitButton processing={processing}>
                                            {t('supplier.stock.submit')}
                                        </SubmitButton>
                                    </div>
                                </>
                            )}
                        </Form>

                        {offer.recent_updates.length > 0 && (
                            <div className="mt-4">
                                <h3 className="text-sm font-semibold">
                                    {t('supplier.stock.recent')}
                                </h3>
                                <ul className="divide-border mt-2 divide-y text-sm">
                                    {offer.recent_updates.map((update) => (
                                        <li
                                            key={update.id}
                                            className="flex flex-wrap justify-between gap-2 py-2"
                                        >
                                            <span>
                                                {update.requested_quantity.toLocaleString(
                                                    locale,
                                                )}
                                            </span>
                                            <span className="text-muted-foreground">
                                                {update.status_label} ·{' '}
                                                {new Date(
                                                    update.created_at,
                                                ).toLocaleDateString(locale)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </SectionCard>
                ))}
            </PageContainer>
        </>
    );
}
