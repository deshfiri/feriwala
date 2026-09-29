import { Form } from '@inertiajs/react';
import { useState } from 'react';
import PayoutMethodController from '@/actions/App/Http/Controllers/Erp/PayoutMethodController';
import BankFields from '@/components/payout/bank-fields';
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
import { branches as bankBranches, index as bankIndex } from '@/routes/banks';
import {
    children as locationChildren,
    divisions as locationDivisions,
} from '@/routes/locations';

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

type TypeOption = { value: string; label: string; fields: string[] };

type Method = {
    id: string;
    type: string;
    label: string;
};

/**
 * Create or update a `BusinessAccount`'s payout method (D25, P13-24; Shared
 * Payout Methods batch — the Client/Partner side of the same architecture
 * {@see \App\Http\Controllers\Supplier\PayoutMethodController} uses).
 *
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
                            ? t('payout.index.edit')
                            : t('payout.index.add')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('payout.index.description')}
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
                                    {t('payout.index.type')}
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
                                    {t('payout.index.label')}
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

                            {selectedType === 'bank_account' && (
                                <BankFields
                                    banksUrl={bankIndex.url()}
                                    divisionsUrl={locationDivisions.url()}
                                    districtsUrlFor={(divisionSourceId) =>
                                        locationChildren.url([
                                            'division',
                                            divisionSourceId,
                                        ])
                                    }
                                    branchesUrlFor={(bankCode, districtId) =>
                                        bankBranches.url(bankCode, {
                                            query: { district_id: districtId },
                                        })
                                    }
                                    errors={errors}
                                />
                            )}

                            {fields.map((field) => (
                                <div key={field} className="grid gap-2">
                                    <Label htmlFor={`payout-${field}`}>
                                        {t(`payout.form.fields.${field}`)}
                                    </Label>
                                    {field === 'account_type' ? (
                                        <select
                                            id={`payout-${field}`}
                                            name={`details[${field}]`}
                                            required
                                            className={controlClass}
                                            defaultValue=""
                                        >
                                            <option value="" disabled>
                                                {t('common.actions.select')}
                                            </option>
                                            <option value="savings">
                                                {t(
                                                    'payout.form.account_types.savings',
                                                )}
                                            </option>
                                            <option value="current">
                                                {t(
                                                    'payout.form.account_types.current',
                                                )}
                                            </option>
                                        </select>
                                    ) : (
                                        <Input
                                            id={`payout-${field}`}
                                            name={`details[${field}]`}
                                            required
                                            maxLength={120}
                                        />
                                    )}
                                    <InputError
                                        message={
                                            errors[`details.${field}`] ??
                                            errors.details
                                        }
                                    />
                                </div>
                            ))}

                            {fields.includes('account_number') ? (
                                <div className="grid gap-2">
                                    <Label htmlFor="payout-confirm-account-number">
                                        {t(
                                            'payout.form.fields.confirm_account_number',
                                        )}
                                    </Label>
                                    <Input
                                        id="payout-confirm-account-number"
                                        name="details[confirm_account_number]"
                                        required
                                        maxLength={120}
                                    />
                                </div>
                            ) : null}

                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="is_default"
                                    value="1"
                                    className="accent-brand"
                                />
                                {t('payout.index.make_default')}
                            </label>

                            <div className="grid gap-2">
                                <Label htmlFor="payout-current-password">
                                    {t('payout.form.current_password')}
                                </Label>
                                <Input
                                    id="payout-current-password"
                                    name="current_password"
                                    type="password"
                                    autoComplete="current-password"
                                    required
                                />
                                <p className="text-muted-foreground text-xs">
                                    {t('payout.form.current_password_help')}
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
                                    {t('payout.form.save')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
