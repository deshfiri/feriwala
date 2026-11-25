import { Form } from '@inertiajs/react';
import WarehouseController from '@/actions/App/Http/Controllers/Admin/WarehouseController';
import InputError from '@/components/input-error';
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
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import type { WarehouseRow } from '@/types/inventory';

function WarehouseCourierField({
    name,
    label,
    defaultValue,
    maxLength,
    inputMode,
    error,
}: {
    name: string;
    label: string;
    defaultValue: string | null | undefined;
    maxLength: number;
    inputMode?: 'tel';
    error?: string;
}) {
    const id = `warehouse-${name}`;

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                name={name}
                maxLength={maxLength}
                inputMode={inputMode}
                defaultValue={defaultValue ?? ''}
            />
            <InputError message={error} />
        </div>
    );
}

/**
 * Adding or editing one warehouse (§19).
 *
 * The code is asked for once, on creation, and shown read-only afterwards: it is
 * printed on labels and pick lists, and the server refuses a changed one.
 */
export default function WarehouseDialog({
    open,
    onClose,
    warehouse,
}: {
    open: boolean;
    onClose: () => void;
    warehouse: WarehouseRow | null;
}) {
    const { t } = useTranslation();
    const editing = warehouse !== null;

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {t(
                            editing
                                ? 'inventory.warehouses.edit_title'
                                : 'inventory.warehouses.create_title',
                        )}
                    </DialogTitle>
                    <DialogDescription>
                        {t('inventory.warehouses.dialog_description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    key={warehouse?.id ?? 'new'}
                    {...(editing
                        ? WarehouseController.update.form(warehouse.id)
                        : WarehouseController.store.form())}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="warehouse-code">
                                        {t('inventory.warehouses.code')}
                                    </Label>
                                    {editing ? (
                                        <p
                                            id="warehouse-code"
                                            className="bg-muted rounded-lg border px-3 py-2 font-mono text-sm"
                                        >
                                            {warehouse.code}
                                        </p>
                                    ) : (
                                        <Input
                                            id="warehouse-code"
                                            name="code"
                                            required
                                            maxLength={20}
                                            autoCapitalize="characters"
                                            className="font-mono uppercase"
                                        />
                                    )}
                                    <InputError message={errors.code} />
                                </div>

                                <div className="grid gap-2 sm:col-span-2">
                                    <Label htmlFor="warehouse-name">
                                        {t('inventory.warehouses.name')}
                                    </Label>
                                    <Input
                                        id="warehouse-name"
                                        name="name"
                                        required
                                        maxLength={120}
                                        defaultValue={warehouse?.name ?? ''}
                                    />
                                    <InputError message={errors.name} />
                                </div>
                            </div>

                            {!editing && (
                                <p className="text-muted-foreground -mt-2 text-xs">
                                    {t('inventory.warehouses.code_help')}
                                </p>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="warehouse-address">
                                    {t('inventory.warehouses.address')}
                                </Label>
                                <textarea
                                    id="warehouse-address"
                                    name="address"
                                    rows={2}
                                    maxLength={500}
                                    defaultValue={warehouse?.address ?? ''}
                                    className="border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                />
                                <InputError message={errors.address} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="warehouse-priority">
                                    {t('inventory.warehouses.priority')}
                                </Label>
                                <Input
                                    id="warehouse-priority"
                                    name="priority"
                                    type="number"
                                    min={0}
                                    max={10000}
                                    step={1}
                                    defaultValue={warehouse?.priority ?? 0}
                                />
                                <p className="text-muted-foreground text-xs">
                                    {t('inventory.warehouses.priority_help')}
                                </p>
                                <InputError message={errors.priority} />
                            </div>

                            <fieldset className="grid gap-3 rounded-lg border p-3">
                                <legend className="px-1 text-sm font-medium">
                                    {t('inventory.warehouses.courier.title')}
                                </legend>
                                <p className="text-muted-foreground text-xs">
                                    {t('inventory.warehouses.courier.help')}
                                </p>

                                <div className="grid gap-3 sm:grid-cols-2">
                                    <WarehouseCourierField
                                        name="courier_contact_name"
                                        label={t(
                                            'inventory.warehouses.courier.contact_name',
                                        )}
                                        defaultValue={
                                            warehouse?.courier_contact_name
                                        }
                                        maxLength={120}
                                        error={errors.courier_contact_name}
                                    />
                                    <WarehouseCourierField
                                        name="courier_phone"
                                        label={t(
                                            'inventory.warehouses.courier.phone',
                                        )}
                                        defaultValue={warehouse?.courier_phone}
                                        maxLength={32}
                                        inputMode="tel"
                                        error={errors.courier_phone}
                                    />
                                </div>

                                <WarehouseCourierField
                                    name="return_address_line"
                                    label={t(
                                        'inventory.warehouses.courier.address_line',
                                    )}
                                    defaultValue={
                                        warehouse?.return_address_line
                                    }
                                    maxLength={255}
                                    error={errors.return_address_line}
                                />

                                <div className="grid gap-3 sm:grid-cols-3">
                                    <WarehouseCourierField
                                        name="return_area"
                                        label={t(
                                            'inventory.warehouses.courier.area',
                                        )}
                                        defaultValue={warehouse?.return_area}
                                        maxLength={120}
                                        error={errors.return_area}
                                    />
                                    <WarehouseCourierField
                                        name="return_district"
                                        label={t(
                                            'inventory.warehouses.courier.district',
                                        )}
                                        defaultValue={
                                            warehouse?.return_district
                                        }
                                        maxLength={120}
                                        error={errors.return_district}
                                    />
                                    <WarehouseCourierField
                                        name="return_postcode"
                                        label={t(
                                            'inventory.warehouses.courier.postcode',
                                        )}
                                        defaultValue={
                                            warehouse?.return_postcode
                                        }
                                        maxLength={20}
                                        error={errors.return_postcode}
                                    />
                                </div>

                                <div className="grid gap-1">
                                    <input
                                        type="hidden"
                                        name="usable_for_courier_labels"
                                        value="0"
                                    />
                                    <label className="flex items-center gap-2 text-sm font-medium">
                                        <input
                                            type="checkbox"
                                            name="usable_for_courier_labels"
                                            value="1"
                                            defaultChecked={
                                                warehouse?.usable_for_courier_labels ??
                                                false
                                            }
                                            className="size-4"
                                        />
                                        {t(
                                            'inventory.warehouses.courier.usable',
                                        )}
                                    </label>
                                    <p className="text-muted-foreground text-xs">
                                        {t(
                                            'inventory.warehouses.courier.usable_help',
                                        )}
                                    </p>
                                    <InputError
                                        message={
                                            errors.usable_for_courier_labels
                                        }
                                    />
                                </div>
                            </fieldset>

                            <div className="grid gap-1">
                                <input
                                    type="hidden"
                                    name="is_active"
                                    value="0"
                                />
                                <label className="flex items-center gap-2 text-sm font-medium">
                                    <input
                                        type="checkbox"
                                        name="is_active"
                                        value="1"
                                        defaultChecked={
                                            warehouse?.is_active ?? true
                                        }
                                        className="size-4"
                                    />
                                    {t('inventory.warehouses.is_active')}
                                </label>
                                <p className="text-muted-foreground text-xs">
                                    {t('inventory.warehouses.is_active_help')}
                                </p>
                                <InputError message={errors.is_active} />
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={onClose}
                                >
                                    {t('common.actions.cancel')}
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t(
                                        editing
                                            ? 'common.actions.save'
                                            : 'inventory.warehouses.create',
                                    )}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
