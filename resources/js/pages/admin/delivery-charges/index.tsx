import { Form, Head, router } from '@inertiajs/react';
import { Truck } from 'lucide-react';
import { useState } from 'react';
import DeliveryChargeController from '@/actions/App/Http/Controllers/Admin/DeliveryChargeController';
import FormField from '@/components/forms/form-field';
import MoneyField from '@/components/forms/money-field';
import SubmitButton from '@/components/forms/submit-button';
import MoneyAmount from '@/components/money-amount';
import Notice from '@/components/notice';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
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
import type { Money } from '@/lib/money';

type Preview = {
    subtotal: Money;
    delivery: Money;
    is_free: boolean;
    delivery_tax: {
        amount: Money;
        label: string;
        is_inclusive: boolean;
    } | null;
    total: Money;
};

export type DeliveryRate = {
    key: 'inside_dhaka' | 'outside_dhaka' | 'wholesale';
    group: 'customer' | 'wholesale';
    rule: {
        id: string;
        amount: Money;
        is_free: boolean;
        since: string;
    } | null;
    preview: Preview | null;
};

type Props = {
    rates: DeliveryRate[];
    customer_checkout_ready: boolean;
    can_manage: boolean;
};

const GUIDE = [
    'areas',
    'dhaka',
    'per_order',
    'everyone',
    'free',
    'missing',
    'frozen',
    'tax',
    'wholesale',
] as const;

/**
 * Admin → Orders → Delivery Charges (D37): the Inside Dhaka and Outside Dhaka
 * customer rates and the separate wholesale charge. Every figure on this page
 * — the preview included — is the server's; nothing here adds money up.
 */
export default function DeliveryChargesIndex({
    rates,
    customer_checkout_ready: customerReady,
    can_manage: canManage,
}: Props) {
    const { t, locale } = useTranslation();
    const [editing, setEditing] = useState<DeliveryRate | null>(null);

    const since = (value: string) =>
        t('delivery_charges.since', {
            date: new Date(value).toLocaleDateString(locale, {
                timeZone: 'Asia/Dhaka',
            }),
        });

    const deactivate = (rate: DeliveryRate) => {
        if (
            rate.rule &&
            window.confirm(t('delivery_charges.actions.deactivate_confirm'))
        ) {
            router.delete(DeliveryChargeController.destroy.url(rate.rule.id), {
                preserveScroll: true,
            });
        }
    };

    const card = (rate: DeliveryRate) => (
        <SectionCard
            key={rate.key}
            title={t(`delivery_charges.rates.${rate.key}.title`)}
            description={t(`delivery_charges.rates.${rate.key}.description`)}
            actions={
                rate.rule ? (
                    rate.rule.is_free ? (
                        <StatusPill
                            tone="success"
                            label={t('delivery_charges.free')}
                        />
                    ) : (
                        <StatusPill
                            tone="info"
                            label={t('delivery_charges.active')}
                        />
                    )
                ) : (
                    <StatusPill
                        tone="warning"
                        label={t('delivery_charges.not_set')}
                    />
                )
            }
        >
            <div className="space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="space-y-1">
                        {rate.rule ? (
                            <>
                                <p className="text-lg font-semibold">
                                    {rate.rule.is_free ? (
                                        t('delivery_charges.free')
                                    ) : (
                                        <>
                                            <MoneyAmount
                                                amount={rate.rule.amount}
                                            />{' '}
                                            <span className="text-muted-foreground text-xs font-normal">
                                                {t(
                                                    'delivery_charges.per_order',
                                                )}
                                            </span>
                                        </>
                                    )}
                                </p>
                                <p className="text-muted-foreground text-xs">
                                    {since(rate.rule.since)}
                                </p>
                            </>
                        ) : (
                            <p className="text-muted-foreground text-sm">
                                {t('delivery_charges.not_set')}
                            </p>
                        )}
                    </div>

                    {canManage && (
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                size="sm"
                                onClick={() => setEditing(rate)}
                            >
                                {rate.rule
                                    ? t('delivery_charges.actions.change')
                                    : t('delivery_charges.actions.set')}
                            </Button>
                            {rate.rule && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => deactivate(rate)}
                                >
                                    {t('delivery_charges.actions.deactivate')}
                                </Button>
                            )}
                        </div>
                    )}
                </div>

                {rate.preview && (
                    <div className="rounded-lg border p-4">
                        <p className="mb-2 text-sm font-medium">
                            {t('delivery_charges.preview.title')}
                        </p>
                        <dl className="grid max-w-sm grid-cols-[1fr_auto] gap-x-6 gap-y-1 text-sm">
                            <dt className="text-muted-foreground">
                                {t('delivery_charges.preview.subtotal')}
                            </dt>
                            <dd className="text-end">
                                <MoneyAmount amount={rate.preview.subtotal} />
                            </dd>
                            <dt className="text-muted-foreground">
                                {t('delivery_charges.preview.delivery')}
                            </dt>
                            <dd className="text-end">
                                {rate.preview.is_free ? (
                                    t('delivery_charges.free')
                                ) : (
                                    <MoneyAmount
                                        amount={rate.preview.delivery}
                                    />
                                )}
                            </dd>
                            {rate.preview.delivery_tax && (
                                <>
                                    <dt className="text-muted-foreground">
                                        {t(
                                            'delivery_charges.preview.delivery_tax',
                                            {
                                                label: rate.preview.delivery_tax
                                                    .label,
                                            },
                                        )}
                                    </dt>
                                    <dd className="text-end">
                                        {rate.preview.delivery_tax
                                            .is_inclusive ? (
                                            t(
                                                'delivery_charges.preview.delivery_tax_included',
                                            )
                                        ) : (
                                            <MoneyAmount
                                                amount={
                                                    rate.preview.delivery_tax
                                                        .amount
                                                }
                                            />
                                        )}
                                    </dd>
                                </>
                            )}
                            <dt className="border-t pt-1 font-medium">
                                {t('delivery_charges.preview.total')}
                            </dt>
                            <dd className="border-t pt-1 text-end font-medium">
                                <MoneyAmount amount={rate.preview.total} />
                            </dd>
                        </dl>
                    </div>
                )}
            </div>
        </SectionCard>
    );

    const customer = rates.filter((rate) => rate.group === 'customer');
    const wholesale = rates.filter((rate) => rate.group === 'wholesale');

    return (
        <>
            <Head title={t('delivery_charges.title')} />

            <PageContainer>
                <PageHeader
                    title={t('delivery_charges.title')}
                    description={t('delivery_charges.description')}
                />

                <Notice
                    tone="info"
                    icon={Truck}
                    title={t('delivery_charges.guide.title')}
                >
                    <ul className="list-disc space-y-1 ps-5">
                        {GUIDE.map((key) => (
                            <li key={key}>
                                {t(`delivery_charges.guide.${key}`)}
                            </li>
                        ))}
                    </ul>
                </Notice>

                <h2 className="text-base font-semibold">
                    {t('delivery_charges.groups.customer')}
                </h2>

                {!customerReady && (
                    <Notice tone="warning">
                        {t('delivery_charges.customer_not_ready')}
                    </Notice>
                )}

                <div className="grid gap-6 lg:grid-cols-2">
                    {customer.map(card)}
                </div>

                <h2 className="text-base font-semibold">
                    {t('delivery_charges.groups.wholesale')}
                </h2>

                <div className="grid gap-6 lg:grid-cols-2">
                    {wholesale.map(card)}
                </div>
            </PageContainer>

            <Dialog
                open={editing !== null}
                onOpenChange={(open) => !open && setEditing(null)}
            >
                {editing && (
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>
                                {t(
                                    `delivery_charges.rates.${editing.key}.title`,
                                )}
                            </DialogTitle>
                            <DialogDescription>
                                {t(
                                    `delivery_charges.rates.${editing.key}.description`,
                                )}
                            </DialogDescription>
                        </DialogHeader>

                        <Form
                            {...DeliveryChargeController.store.form()}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setEditing(null)}
                            className="space-y-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="rate"
                                        value={editing.key}
                                    />

                                    <FormField
                                        label={t(
                                            'delivery_charges.form.amount',
                                        )}
                                        hint={t(
                                            'delivery_charges.form.amount_help',
                                        )}
                                        error={errors.amount ?? errors.rate}
                                        required
                                    >
                                        {(field) => (
                                            <MoneyField
                                                {...field}
                                                name="amount"
                                                defaultValue={
                                                    editing.rule?.amount
                                                        .amount ?? ''
                                                }
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t('delivery_charges.form.note')}
                                        hint={t(
                                            'delivery_charges.form.note_help',
                                        )}
                                        error={errors.note}
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="note"
                                                maxLength={500}
                                            />
                                        )}
                                    </FormField>

                                    <DialogFooter>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() => setEditing(null)}
                                        >
                                            {t('common.actions.cancel')}
                                        </Button>
                                        <SubmitButton processing={processing}>
                                            {t('delivery_charges.form.save')}
                                        </SubmitButton>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                )}
            </Dialog>
        </>
    );
}
