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
