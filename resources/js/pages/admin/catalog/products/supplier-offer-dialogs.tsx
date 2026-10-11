import { router } from '@inertiajs/react';
import { useState } from 'react';
import ProductSupplierController from '@/actions/App/Http/Controllers/Admin/ProductSupplierController';
import SupplierOfferController from '@/actions/App/Http/Controllers/Admin/SupplierOfferController';
import AlertError from '@/components/alert-error';
import FormField from '@/components/forms/form-field';
import ReasonTextarea from '@/components/forms/reason-textarea';
import SupplierPickerSheet from '@/components/product-suppliers/supplier-picker-sheet';
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
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import type {
    AssignableSupplier,
    ProductDetail,
    ProductSuppliersState,
    SupplierOfferRow,
} from '@/types';

const textareaClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none';

/** A Taka amount typed as decimal text; the server is the only one to parse it. */
function TakaInput({
    id,
    value,
    onChange,
}: {
    id: string;
    value: string;
    onChange: (next: string) => void;
}) {
    return (
        <div className="relative">
            <span
                aria-hidden="true"
                className="text-muted-foreground pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm"
            >
                ৳
            </span>
            <Input
                id={id}
                inputMode="decimal"
                autoComplete="off"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="pl-7"
            />
        </div>
    );
}

export function AssignDialog({
    open,
    onClose,
    product,
    suppliers,
}: {
    open: boolean;
    onClose: () => void;
    product: ProductDetail;
    suppliers: ProductSuppliersState;
}) {
    const { t } = useTranslation();
    const [supplier, setSupplier] = useState<AssignableSupplier | null>(null);
    const [picking, setPicking] = useState(false);
    const [variant, setVariant] = useState('');
    const [basePrice, setBasePrice] = useState('');
    const [mode, setMode] = useState('ready_stock');
    const [quantity, setQuantity] = useState('');
    const [capacity, setCapacity] = useState('');
    const [lead, setLead] = useState('');
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const reset = () => {
        setSupplier(null);
        setVariant('');
        setBasePrice('');
        setMode('ready_stock');
        setQuantity('');
        setCapacity('');
        setLead('');
        setReason('');
        setErrors({});
    };

    const submit = () => {
        router.post(
            ProductSupplierController.store.url(),
            {
                product_id: product.id,
                supplier_id: supplier?.id ?? '',
                variant_id: variant || null,
                base_price: basePrice,
                supply_mode: mode,
                quantity:
                    mode === 'ready_stock' && quantity !== '' ? quantity : null,
                fulfilment_capacity: capacity !== '' ? capacity : null,
                lead_time_days: lead !== '' ? lead : null,
                reason: reason || null,
            },
            {
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setErrors({});
                },
                onSuccess: () => {
                    reset();
                    onClose();
                },
                onError: (bag) => setErrors(bag as Record<string, string>),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const firstError = Object.values(errors)[0];

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next && !processing) {
                    onClose();
                }
            }}
        >
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {t('product_suppliers.assign_dialog.title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('product_suppliers.assign_dialog.description')}
                    </DialogDescription>
                </DialogHeader>

                {firstError && <AlertError errors={[firstError]} />}

                <div className="space-y-4">
                    <div className="grid gap-1.5">
                        <Label>{t('product_suppliers.fields.supplier')}</Label>
                        <div className="flex items-center justify-between gap-2 rounded-md border p-2">
                            <span className="truncate text-sm">
                                {supplier
                                    ? `${supplier.name} · ${supplier.reference}`
                                    : t('product_suppliers.assign_dialog.none')}
                            </span>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => setPicking(true)}
                            >
                                {t('product_suppliers.assign_dialog.choose')}
                            </Button>
                        </div>
                    </div>

                    {suppliers.variants.length > 0 && (
                        <div className="grid gap-1.5">
                            <Label htmlFor="assign-variant">
                                {t('product_suppliers.fields.variant')}
                            </Label>
                            <select
                                id="assign-variant"
                                value={variant}
                                onChange={(event) =>
                                    setVariant(event.target.value)
                                }
                                className={selectClass}
                            >
                                <option value="">
                                    {t(
                                        'product_suppliers.assign_dialog.pick_variant',
                                    )}
                                </option>
                                {suppliers.variants.map((option) => (
                                    <option key={option.id} value={option.id}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}

                    <div className="grid gap-1.5">
                        <Label htmlFor="assign-price">
                            {t('product_suppliers.fields.base_price')}
                        </Label>
                        <TakaInput
                            id="assign-price"
                            value={basePrice}
                            onChange={setBasePrice}
                        />
                        <p className="text-muted-foreground text-xs">
                            {t('product_suppliers.assign_dialog.price_help')}
                        </p>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="assign-mode">
                                {t('product_suppliers.fields.supply_mode')}
                            </Label>
                            <select
                                id="assign-mode"
                                value={mode}
                                onChange={(event) =>
                                    setMode(event.target.value)
                                }
                                className={selectClass}
                            >
                                <option value="ready_stock">
                                    {t('product_suppliers.modes.ready_stock')}
                                </option>
                                <option value="on_demand">
                                    {t('product_suppliers.modes.on_demand')}
                                </option>
                                <option value="pre_order">
                                    {t('product_suppliers.modes.pre_order')}
                                </option>
                            </select>
                        </div>
                        {mode === 'ready_stock' && (
                            <div className="grid gap-1.5">
                                <Label htmlFor="assign-qty">
                                    {t('product_suppliers.fields.quantity')}
                                </Label>
                                <Input
                                    id="assign-qty"
                                    inputMode="numeric"
                                    value={quantity}
                                    onChange={(event) =>
                                        setQuantity(event.target.value)
                                    }
                                />
                            </div>
                        )}
                        <div className="grid gap-1.5">
                            <Label htmlFor="assign-capacity">
                                {t('product_suppliers.fields.capacity_label')}
                            </Label>
                            <Input
                                id="assign-capacity"
                                inputMode="numeric"
                                value={capacity}
                                onChange={(event) =>
                                    setCapacity(event.target.value)
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="assign-lead">
                                {t('product_suppliers.fields.lead_time')}
                            </Label>
                            <Input
                                id="assign-lead"
                                inputMode="numeric"
                                value={lead}
                                onChange={(event) =>
                                    setLead(event.target.value)
                                }
                            />
                        </div>
                    </div>

                    <FormField label={t('product_suppliers.fields.reason')}>
                        {(field) => (
                            <ReasonTextarea
                                context="supplier"
                                {...field}
                                value={reason}
                                onChange={(event) =>
                                    setReason(event.target.value)
                                }
                                rows={2}
                                maxLength={1000}
                                className={textareaClass}
                            />
                        )}
                    </FormField>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={onClose}
                        disabled={processing}
                    >
                        {t('common.actions.cancel')}
                    </Button>
                    <Button
                        type="button"
                        onClick={submit}
                        disabled={
                            processing || supplier === null || basePrice === ''
                        }
                    >
                        {t('product_suppliers.assign_dialog.confirm')}
                    </Button>
                </DialogFooter>

                <SupplierPickerSheet
                    open={picking}
                    onOpenChange={setPicking}
                    selected={supplier ? [supplier] : []}
                    onChange={(next) => setSupplier(next.at(-1) ?? null)}
                    searchUrl={ProductSupplierController.browse.url()}
                    confirmLabel={t(
                        'product_suppliers.assign_dialog.use_supplier',
                    )}
                    onConfirm={() => setPicking(false)}
                />
            </DialogContent>
        </Dialog>
    );
}

export function PriceDialog({
    offer,
    onClose,
}: {
    offer: SupplierOfferRow | null;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const [price, setPrice] = useState('');
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);

    const submit = () => {
        if (offer === null) {
            return;
        }

        router.patch(
            ProductSupplierController.updatePrice.url(offer.id),
            { base_price: price, reason },
            {
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setError(undefined);
                },
                onSuccess: () => {
                    setPrice('');
                    setReason('');
                    onClose();
                },
                onError: (bag) => setError(bag.base_price ?? bag.reason),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog
            open={offer !== null}
            onOpenChange={(next) => {
                if (!next && !processing) {
                    setError(undefined);
                    onClose();
                }
            }}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {t('product_suppliers.price_dialog.title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('product_suppliers.price_dialog.description')}
                    </DialogDescription>
                </DialogHeader>

                {error && <AlertError errors={[error]} />}

                <div className="grid gap-1.5">
                    <Label htmlFor="new-base-price">
                        {t('product_suppliers.fields.base_price')}
                    </Label>
                    <TakaInput
                        id="new-base-price"
                        value={price}
                        onChange={setPrice}
                    />
                </div>

                <FormField
                    label={t('product_suppliers.fields.reason_required')}
                >
                    {(field) => (
                        <ReasonTextarea
                            context="supplier"
                            {...field}
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            rows={2}
                            maxLength={1000}
                            className={textareaClass}
                        />
                    )}
                </FormField>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={onClose}
                        disabled={processing}
                    >
                        {t('common.actions.cancel')}
                    </Button>
                    <Button
                        type="button"
                        onClick={submit}
                        disabled={processing || price === '' || reason === ''}
                    >
                        {t('product_suppliers.price_dialog.confirm')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function StatusDialog({
    offer,
    onClose,
}: {
    offer: SupplierOfferRow | null;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);
    const suspending = offer?.status === 'active';

    const submit = () => {
        if (offer === null) {
            return;
        }

        const url = suspending
            ? SupplierOfferController.suspend.url(offer.id)
            : SupplierOfferController.activate.url(offer.id);

        router.post(
            url,
            { reason: reason || null },
            {
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setError(undefined);
                },
                onSuccess: () => {
                    setReason('');
                    onClose();
                },
                onError: (bag) => setError(bag.reason),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog
            open={offer !== null}
            onOpenChange={(next) => {
                if (!next && !processing) {
                    setError(undefined);
                    onClose();
                }
            }}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {suspending
                            ? t('product_suppliers.status_dialog.suspend_title')
                            : t(
                                  'product_suppliers.status_dialog.reactivate_title',
                              )}
                    </DialogTitle>
                    <DialogDescription>
                        {suspending
                            ? t(
                                  'product_suppliers.status_dialog.suspend_description',
                              )
                            : t(
                                  'product_suppliers.status_dialog.reactivate_description',
                              )}
                    </DialogDescription>
                </DialogHeader>

                {error && <AlertError errors={[error]} />}

                <FormField label={t('product_suppliers.fields.reason')}>
                    {(field) => (
                        <ReasonTextarea
                            context="supplier"
                            {...field}
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            rows={2}
                            maxLength={1000}
                            className={textareaClass}
                        />
                    )}
                </FormField>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={onClose}
                        disabled={processing}
                    >
                        {t('common.actions.cancel')}
                    </Button>
                    <Button
                        type="button"
                        variant={suspending ? 'destructive' : 'default'}
                        onClick={submit}
                        disabled={processing}
                    >
                        {suspending
                            ? t('product_suppliers.section.suspend')
                            : t('product_suppliers.section.reactivate')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
