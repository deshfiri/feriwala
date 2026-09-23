import { Form } from '@inertiajs/react';
import { useState } from 'react';
import PayoutMethodController from '@/actions/App/Http/Controllers/Supplier/PayoutMethodController';
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

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

type TypeOption = { value: string; label: string; fields: string[] };

type Method = {
    id: string;
    type: string;
    label: string;
};

/**
 * Create or update a Supplier payout method (D25, P13-24).
 *
 * The Supplier guard has no Fortify confirm-password flow, so the change is
 * confirmed inline with the current password instead — checked by the
 * server (`current_password:supplier`), never trusted from the browser.
 * Editing an existing method never shows its saved account number back; a
 * changed number must be typed in full again.
 */
export default function PayoutMethodDialog({
    open,
    onOpenChange,
    types,
    method,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    types: TypeOption[];
    method?: Method;
}) {
    const { t } = useTranslation();
    const [selectedType, setSelectedType] = useState(
        method?.type ?? types[0]?.value ?? '',
    );

    const isEditing = method !== undefined;
    const fields =
        types.find((type) => type.value === selectedType)?.fields ?? [];

    const form = isEditing
        ? PayoutMethodController.update.form(method.id)
        : PayoutMethodController.store.form();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {isEditing
                            ? t('supplier.payout_methods.edit')
                            : t('supplier.payout_methods.add')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('supplier.payout_methods.description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="payout-type">
                                    {t('supplier.payout_methods.type')}
                                </Label>
                                <select
                                    id="payout-type"
                                    name="type"
                                    required
                                    className={controlClass}
                                    value={selectedType}
                                    onChange={(event) =>
                                        setSelectedType(event.target.value)
                                    }
                                >
                                    {types.map((type) => (
                                        <option
                                            key={type.value}
                                            value={type.value}
                                        >
                                            {type.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.type} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="payout-label">
                                    {t('supplier.payout_methods.label')}
                                </Label>
                                <Input
                                    id="payout-label"
                                    name="label"
                                    defaultValue={method?.label}
                                    required
                                    maxLength={80}
                                />
                                <InputError message={errors.label} />
                            </div>

                            {fields.map((field) => (
                                <div key={field} className="grid gap-2">
                                    <Label htmlFor={`payout-${field}`}>
                                        {t(
                                            `supplier.payout_methods.fields.${field}`,
                                        )}
                                    </Label>
                                    <Input
                                        id={`payout-${field}`}
                                        name={`details[${field}]`}
                                        required
                                        maxLength={120}
                                    />
                                    <InputError
                                        message={
                                            errors[`details.${field}`] ??
                                            errors.details
                                        }
                                    />
                                </div>
                            ))}

                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="is_default"
                                    value="1"
                                    className="accent-brand"
                                />
                                {t('supplier.payout_methods.make_default')}
                            </label>

                            <div className="grid gap-2">
                                <Label htmlFor="payout-current-password">
                                    {t(
                                        'supplier.payout_methods.current_password',
                                    )}
                                </Label>
                                <Input
                                    id="payout-current-password"
                                    name="current_password"
                                    type="password"
                                    autoComplete="current-password"
                                    required
                                />
                                <p className="text-muted-foreground text-xs">
                                    {t(
                                        'supplier.payout_methods.current_password_help',
                                    )}
                                </p>
                                <InputError message={errors.current_password} />
                            </div>

                            <DialogFooter className="gap-2 sm:gap-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => onOpenChange(false)}
                                >
                                    {t('common.actions.cancel')}
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('supplier.payout_methods.save')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
