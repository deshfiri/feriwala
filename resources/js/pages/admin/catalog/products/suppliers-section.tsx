import { Link, router } from '@inertiajs/react';
import { ExternalLink, Trash2, UserPlus } from 'lucide-react';
import { useState } from 'react';
import ProductSupplierController from '@/actions/App/Http/Controllers/Admin/ProductSupplierController';
import AlertError from '@/components/alert-error';
import FormField from '@/components/forms/form-field';
import ReasonTextarea from '@/components/forms/reason-textarea';
import SupplierPickerSheet from '@/components/product-suppliers/supplier-picker-sheet';
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
import { useTranslation } from '@/hooks/use-translation';
import { show } from '@/routes/admin/suppliers';
import type {
    AssignableSupplier,
    ProductDetail,
    ProductSuppliersState,
} from '@/types';

type Props = {
    product: ProductDetail;
    suppliers: ProductSuppliersState;
};

const textareaClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Which Suppliers actually have this Product (Admin Product workspace).
 *
 * A Product can have many Suppliers. Only direct assignments are listed as the
 * Product's own and can be removed; Suppliers of other Products in its Same
 * Product group are shown below for reference. No price is set here.
 */
export default function SuppliersSection({ product, suppliers }: Props) {
    const { t, locale } = useTranslation();
    const [picking, setPicking] = useState(false);
    const [selected, setSelected] = useState<AssignableSupplier[]>([]);
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);
    const [removing, setRemoving] = useState<
        ProductSuppliersState['assigned'][number] | null
    >(null);

    const assign = () => {
        router.post(
            ProductSupplierController.store.url(),
            {
                product_ids: [product.id],
                supplier_ids: selected.map((supplier) => supplier.id),
                reason: reason || null,
            },
            {
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setError(undefined);
                },
                onSuccess: () => {
                    setSelected([]);
                    setReason('');
                    setPicking(false);
                },
                onError: (errors) =>
                    setError(errors.supplier_ids ?? errors.product_ids),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <SectionCard
            title={t('product_suppliers.section.title')}
            description={t('product_suppliers.section.description')}
            actions={
                suppliers.can.assign && (
                    <Button
                        type="button"
                        size="sm"
                        onClick={() => setPicking(true)}
                    >
                        <UserPlus className="size-4" aria-hidden="true" />
                        {t('product_suppliers.section.assign')}
                    </Button>
                )
            }
        >
            <div className="space-y-6">
                {suppliers.assigned.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        {t('product_suppliers.section.none')}
                    </p>
                ) : (
                    <ul className="divide-border divide-y rounded-md border">
                        {suppliers.assigned.map((row) => (
                            <li
                                key={row.id}
                                className="flex flex-wrap items-center justify-between gap-3 p-3"
                            >
                                <div className="min-w-0 space-y-0.5">
                                    <p className="text-sm font-medium">
                                        {row.supplier.name}
                                    </p>
                                    <p className="text-muted-foreground text-xs">
                                        {row.supplier.reference} ·{' '}
                                        {t(
                                            'product_suppliers.section.assigned_by',
                                            {
                                                name: row.assigned_by,
                                                date: new Date(
                                                    row.assigned_at,
                                                ).toLocaleDateString(locale),
                                            },
                                        )}
                                        {row.reason ? ` · ${row.reason}` : ''}
                                    </p>
                                </div>
                                <div className="flex items-center gap-1">
                                    <StatusPill
                                        tone={row.supplier.status_tone}
                                        label={row.supplier.status_label}
                                    />
                                    <Button variant="ghost" size="sm" asChild>
                                        <Link href={show.url(row.supplier.id)}>
                                            <ExternalLink
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            {t(
                                                'product_suppliers.section.view',
                                            )}
                                        </Link>
                                    </Button>
                                    {suppliers.can.remove && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setRemoving(row)}
                                        >
                                            <Trash2
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            {t(
                                                'product_suppliers.section.remove',
                                            )}
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <SupplierPickerSheet
                open={picking}
                onOpenChange={setPicking}
                selected={selected}
                onChange={setSelected}
                searchUrl={ProductSupplierController.browse.url()}
                excludeIds={suppliers.assigned.map((row) => row.supplier.id)}
                confirmLabel={t('product_suppliers.confirm_assign.confirm')}
                processing={processing}
                onConfirm={assign}
            >
                {error && <AlertError errors={[error]} />}
                <FormField
                    label={t('product_suppliers.confirm_assign.reason_label')}
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
            </SupplierPickerSheet>

            <RemoveDialog row={removing} onClose={() => setRemoving(null)} />
        </SectionCard>
    );
}

function RemoveDialog({
    row,
    onClose,
}: {
    row: ProductSuppliersState['assigned'][number] | null;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);

    const confirm = () => {
        if (row === null) {
            return;
        }

        router.delete(ProductSupplierController.destroy.url(row.id), {
            data: { reason: reason || null },
            preserveScroll: true,
            onStart: () => {
                setProcessing(true);
                setError(undefined);
            },
            onSuccess: () => {
                setReason('');
                onClose();
            },
            onError: (errors) => setError(errors.assignment),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <Dialog
            open={row !== null}
            onOpenChange={(open) => {
                if (!open && !processing) {
                    setError(undefined);
                    onClose();
                }
            }}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {t('product_suppliers.confirm_remove.title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('product_suppliers.confirm_remove.description')}
                    </DialogDescription>
                </DialogHeader>

                {row && (
                    <p className="rounded-md border p-3 text-sm font-medium">
                        {row.supplier.name}
                    </p>
                )}

                {error && <AlertError errors={[error]} />}

                <FormField
                    label={t('product_suppliers.confirm_assign.reason_label')}
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
                        variant="destructive"
                        onClick={confirm}
                        disabled={processing}
                    >
                        {t('product_suppliers.confirm_remove.confirm')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
