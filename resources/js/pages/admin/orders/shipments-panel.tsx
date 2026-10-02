import { Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import ShipmentController from '@/actions/App/Http/Controllers/Admin/ShipmentController';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTranslation } from '@/hooks/use-translation';
import type { StatusTone } from '@/lib/status';

export type OrderShipment = {
    id: string;
    reference: string;
    provider: string;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    tracking_number: string | null;
};

export type CourierProviderOption = {
    code: string;
    label: string;
    is_available: boolean;
};

/**
 * Every shipment raised for this order, and the "Create shipment" dialog
 * that raises one (Advanced Order Management batch, Commit 5; §21).
 *
 * Only the manual provider is ever selectable -- every other provider the
 * catalogue lists is shown disabled, with the same credential-missing reason
 * {@see CreateShipmentFromAllocations} itself would refuse with, so this
 * never offers a button staff could press only to be told no on submit (D8).
 */
export default function ShipmentsPanel({
    orderId,
    orderReference,
    shipments,
    courierProviders,
}: {
    orderId: string;
    orderReference: string;
    shipments: OrderShipment[];
    courierProviders: CourierProviderOption[];
}) {
    const { t } = useTranslation();
    const [creating, setCreating] = useState(false);

    const form = useForm({
        provider: '',
        tracking_number: '',
        delivery_charge: '',
        cod_amount: '',
        weight: '',
        length: '',
        width: '',
        height: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.transform((data) => ({
            provider: data.provider,
            tracking_number: data.tracking_number || null,
            delivery_charge: data.delivery_charge,
            cod_amount: data.cod_amount || null,
            packages: [
                {
                    weight: data.weight || null,
                    length: data.length || null,
                    width: data.width || null,
                    height: data.height || null,
                },
            ],
        }));

        form.post(ShipmentController.store.url(orderId), {
            preserveScroll: true,
            onSuccess: () => {
                setCreating(false);
                form.reset();
            },
        });
    };

    return (
        <SectionCard
            title={t('courier.admin.title')}
            actions={
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => setCreating(true)}
                >
                    {t('courier.admin.create_action')}
                </Button>
            }
        >
            {shipments.length === 0 ? (
                <p className="text-muted-foreground text-sm">
                    {t('courier.admin.empty')}
                </p>
            ) : (
                <ul className="divide-border divide-y">
                    {shipments.map((shipment) => (
                        <li
                            key={shipment.id}
                            className="flex flex-wrap items-center justify-between gap-2 py-2"
                        >
                            <div className="min-w-0">
                                <Link
                                    href={ShipmentController.show(shipment.id)}
                                    className="font-mono text-sm font-medium underline-offset-4 hover:underline"
                                >
                                    {shipment.reference}
                                </Link>
                                <div className="text-muted-foreground text-xs">
                                    {shipment.provider}
                                    {shipment.tracking_number &&
                                        ` · ${shipment.tracking_number}`}
                                </div>
                            </div>
                            <StatusPill
                                tone={shipment.status_tone}
                                label={shipment.status_label}
                            />
                        </li>
                    ))}
                </ul>
            )}

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {t('courier.admin.create_title', {
                                reference: orderReference,
                            })}
                        </DialogTitle>
                        <DialogDescription>
                            {t('courier.admin.create_description')}
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submit} className="space-y-4">
                        <FormField
                            label={t('courier.admin.provider')}
                            error={form.errors.provider}
                            hint={t('courier.admin.provider_help')}
                            required
                        >
                            {(field) => (
                                <Select
                                    value={form.data.provider}
                                    onValueChange={(value) =>
                                        form.setData('provider', value)
                                    }
                                >
                                    <SelectTrigger
                                        id={field.id}
                                        aria-invalid={field['aria-invalid']}
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {courierProviders.map((provider) => (
                                            <SelectItem
                                                key={provider.code}
                                                value={provider.code}
                                                disabled={
                                                    !provider.is_available
                                                }
                                            >
                                                {provider.label}
                                                {!provider.is_available &&
                                                    ` (${t('courier.admin.provider_help')})`}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            )}
                        </FormField>

                        <FormField
                            label={t('courier.admin.tracking_number')}
                            error={form.errors.tracking_number}
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    value={form.data.tracking_number}
                                    onChange={(e) =>
                                        form.setData(
                                            'tracking_number',
                                            e.target.value,
                                        )
                                    }
                                />
                            )}
                        </FormField>

                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                label={t('courier.admin.delivery_charge')}
                                error={form.errors.delivery_charge}
                                required
                            >
                                {(field) => (
                                    <Input
                                        {...field}
                                        inputMode="decimal"
                                        placeholder="0.00"
                                        value={form.data.delivery_charge}
                                        onChange={(e) =>
                                            form.setData(
                                                'delivery_charge',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                )}
                            </FormField>

                            <FormField
                                label={t('courier.admin.cod_amount')}
                                error={form.errors.cod_amount}
                            >
                                {(field) => (
                                    <Input
                                        {...field}
                                        inputMode="decimal"
                                        placeholder="0.00"
                                        value={form.data.cod_amount}
                                        onChange={(e) =>
                                            form.setData(
                                                'cod_amount',
                                                e.target.value,
                                            )
                                        }
                                    />
                                )}
                            </FormField>
                        </div>

                        <div className="grid grid-cols-4 gap-4">
                            <FormField
                                label={t('courier.admin.package_weight')}
                                error={form.errors.weight}
                            >
                                {(field) => (
                                    <Input
                                        {...field}
                                        inputMode="decimal"
                                        value={form.data.weight}
                                        onChange={(e) =>
                                            form.setData(
                                                'weight',
                                                e.target.value,
                                            )
                                        }
                                    />
                                )}
                            </FormField>
                            <FormField
                                label={t('courier.admin.package_length')}
                                error={form.errors.length}
                            >
                                {(field) => (
                                    <Input
                                        {...field}
                                        inputMode="decimal"
                                        value={form.data.length}
                                        onChange={(e) =>
                                            form.setData(
                                                'length',
                                                e.target.value,
                                            )
                                        }
                                    />
                                )}
                            </FormField>
                            <FormField
                                label={t('courier.admin.package_width')}
                                error={form.errors.width}
                            >
                                {(field) => (
                                    <Input
                                        {...field}
                                        inputMode="decimal"
                                        value={form.data.width}
                                        onChange={(e) =>
                                            form.setData(
                                                'width',
                                                e.target.value,
                                            )
                                        }
                                    />
                                )}
                            </FormField>
                            <FormField
                                label={t('courier.admin.package_height')}
                                error={form.errors.height}
                            >
                                {(field) => (
                                    <Input
                                        {...field}
                                        inputMode="decimal"
                                        value={form.data.height}
                                        onChange={(e) =>
                                            form.setData(
                                                'height',
                                                e.target.value,
                                            )
                                        }
                                    />
                                )}
                            </FormField>
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => setCreating(false)}
                            >
                                {t('common.actions.cancel')}
                            </Button>
                            <SubmitButton processing={form.processing}>
                                {t('courier.admin.create_action')}
                            </SubmitButton>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </SectionCard>
    );
}
