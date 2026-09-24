import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import MoneyAmount from '@/components/money-amount';
import MoneyInput from '@/components/money-input';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import { index } from '@/routes/admin/supplier-offers';
import { store as activation } from '@/routes/admin/supplier-offers/activation';
import { store as preferred } from '@/routes/admin/supplier-offers/preferred';
import { store as rates } from '@/routes/admin/supplier-offers/rates';
import { store as stockAdjustment } from '@/routes/admin/supplier-offers/stock-adjustment';
import { store as suspension } from '@/routes/admin/supplier-offers/suspension';

type Offer = {
    id: string;
    reference: string;
    supplier: string;
    product_name: string;
    status: string;
    is_preferred: boolean;
    supplier_rate: Money;
    platform_rate: Money;
    platform_margin: Money;
    available_quantity: number;
    can_edit: boolean;
    rate_history: {
        supplier_rate: Money;
        platform_rate: Money;
        effective_from: string;
        changed_by: string | null;
        reason: string;
    }[];
};

export default function AdminSupplierOfferShow({ offer }: { offer: Offer }) {
    const { t, locale } = useTranslation();

    return (
        <>
            <Head title={offer.product_name} />

            <PageContainer>
                <PageHeader
                    title={offer.product_name}
                    description={`${offer.reference} · ${offer.supplier}`}
                    actions={
                        <>
                            <StatusPill
                                tone={
                                    offer.status === 'active'
                                        ? 'success'
                                        : 'danger'
                                }
                                label={
                                    offer.status === 'active'
                                        ? t('supplier.offers.state_active')
                                        : t('supplier.offers.state_suspended')
                                }
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
                        <SectionCard title={t('supplier.admin.offers.rates')}>
                            <dl className="grid gap-4 text-sm sm:grid-cols-3">
                                <div>
                                    <dt className="text-muted-foreground text-xs">
                                        {t(
                                            'supplier.admin.offers.columns.supplier_rate',
                                        )}
                                    </dt>
                                    <dd>
                                        <MoneyAmount
                                            amount={offer.supplier_rate}
                                            size="large"
                                        />
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">
                                        {t(
                                            'supplier.admin.offers.columns.platform_rate',
                                        )}
                                    </dt>
                                    <dd>
                                        <MoneyAmount
                                            amount={offer.platform_rate}
                                            size="large"
                                        />
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">
                                        {t(
                                            'supplier.admin.offers.columns.margin',
                                        )}
                                    </dt>
                                    <dd>
                                        <MoneyAmount
                                            amount={offer.platform_margin}
                                            size="large"
                                        />
                                    </dd>
                                </div>
                            </dl>
                        </SectionCard>

                        <SectionCard title={t('supplier.admin.offers.history')}>
                            <ol className="divide-border divide-y text-sm">
                                {offer.rate_history.map((change, position) => (
                                    <li
                                        key={position}
                                        className="space-y-0.5 py-3 first:pt-0 last:pb-0"
                                    >
                                        <div className="flex flex-wrap justify-between gap-2">
                                            <span>
                                                <MoneyAmount
                                                    amount={
                                                        change.supplier_rate
                                                    }
                                                />{' '}
                                                →{' '}
                                                <MoneyAmount
                                                    amount={
                                                        change.platform_rate
                                                    }
                                                />
                                            </span>
                                            <span className="text-muted-foreground text-xs">
                                                {change.changed_by ?? '—'} ·{' '}
                                                {new Date(
                                                    change.effective_from,
                                                ).toLocaleString(locale)}
                                            </span>
                                        </div>
                                        <p className="text-muted-foreground">
                                            {change.reason}
                                        </p>
                                    </li>
                                ))}
                            </ol>
                        </SectionCard>
                    </div>

                    {offer.can_edit && (
                        <aside className="space-y-6 lg:col-span-1">
                            <SectionCard
                                title={t('supplier.admin.offers.rates')}
                            >
                                <Form
                                    {...rates.form(offer.id)}
                                    options={{ preserveScroll: true }}
                                    className="space-y-3"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <MoneyInput
                                                id="supplier-rate"
                                                name="supplier_rate"
                                                label={t(
                                                    'supplier.admin.offers.supplier_rate',
                                                )}
                                                defaultValue={
                                                    offer.supplier_rate.amount
                                                }
                                                required
                                                error={errors.supplier_rate}
                                            />
                                            <MoneyInput
                                                id="platform-rate"
                                                name="platform_rate"
                                                label={t(
                                                    'supplier.admin.offers.platform_rate',
                                                )}
                                                defaultValue={
                                                    offer.platform_rate.amount
                                                }
                                                required
                                                error={errors.platform_rate}
                                            />
                                            <FormField
                                                label={t(
                                                    'supplier.admin.offers.reason',
                                                )}
                                                error={errors.reason}
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
                                                className="w-full"
                                            >
                                                {t(
                                                    'supplier.admin.offers.save_rates',
                                                )}
                                            </SubmitButton>
                                        </>
                                    )}
                                </Form>
                            </SectionCard>

                            <SectionCard title={t('supplier.offers.status')}>
                                <div className="space-y-3">
                                    {offer.status === 'active' ? (
                                        <Form
                                            {...suspension.form(offer.id)}
                                            options={{ preserveScroll: true }}
                                            className="space-y-3"
                                        >
                                            {({ processing }) => (
                                                <>
                                                    <FormField
                                                        label={t(
                                                            'supplier.admin.offers.reason',
                                                        )}
                                                    >
                                                        {(field) => (
                                                            <Input
                                                                {...field}
                                                                name="reason"
                                                            />
                                                        )}
                                                    </FormField>
                                                    <SubmitButton
                                                        processing={processing}
                                                        variant="destructive"
                                                        className="w-full"
                                                    >
                                                        {t(
                                                            'supplier.admin.offers.suspend',
                                                        )}
                                                    </SubmitButton>
                                                </>
                                            )}
                                        </Form>
                                    ) : (
                                        <Form
                                            {...activation.form(offer.id)}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing }) => (
                                                <SubmitButton
                                                    processing={processing}
                                                    className="w-full"
                                                >
                                                    {t(
                                                        'supplier.admin.offers.activate',
                                                    )}
                                                </SubmitButton>
                                            )}
                                        </Form>
                                    )}

                                    {offer.status === 'active' &&
                                        !offer.is_preferred && (
                                            <Form
                                                {...preferred.form(offer.id)}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                            >
                                                {({ processing }) => (
                                                    <SubmitButton
                                                        processing={processing}
                                                        variant="outline"
                                                        className="w-full"
                                                    >
                                                        {t(
                                                            'supplier.admin.offers.set_preferred',
                                                        )}
                                                    </SubmitButton>
                                                )}
                                            </Form>
                                        )}
                                </div>
                            </SectionCard>

                            <SectionCard
                                title={t('supplier.admin.offers.adjust_stock')}
                                description={`${offer.available_quantity.toLocaleString(locale)}`}
                            >
                                <Form
                                    {...stockAdjustment.form(offer.id)}
                                    options={{ preserveScroll: true }}
                                    className="space-y-3"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <FormField
                                                label={t(
                                                    'supplier.admin.offers.quantity',
                                                )}
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
                                                label={t(
                                                    'supplier.admin.offers.reason',
                                                )}
                                                error={errors.reason}
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
                                                className="w-full"
                                            >
                                                {t(
                                                    'supplier.admin.offers.adjust_stock',
                                                )}
                                            </SubmitButton>
                                        </>
                                    )}
                                </Form>
                            </SectionCard>
                        </aside>
                    )}
                </div>
            </PageContainer>
        </>
    );
}

AdminSupplierOfferShow.layout = {
    breadcrumbs: [{ title: 'Supplier offers', href: index() }],
};
