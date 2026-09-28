import { Form } from '@inertiajs/react';
import InputError from '@/components/input-error';
import LocationPicker from '@/components/address-book/location-picker';
import type {
    AddressFormAction,
    AddressRow,
} from '@/components/address-book/types';
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

/**
 * Create or update an address in the shared address book.
 *
 * Shared by the Client/Partner and the Supplier address screens — nothing
 * here is guard-specific. The caller resolves its own Wayfinder action
 * (`AddressController.store.form()` or `.update.form(id)`) and its own
 * lookup URLs, so this component carries no route import of its own.
 */
export default function AddressDialog({
    open,
    onOpenChange,
    types,
    address,
    form,
    divisionsUrl,
    childrenUrl,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    types: string[];
    address?: AddressRow;
    form: AddressFormAction;
    divisionsUrl: string;
    childrenUrl: (
        parentType: 'division' | 'district' | 'upazila',
        parentSourceId: string,
    ) => string;
}) {
    const { t } = useTranslation();
    const isEditing = address !== undefined;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {isEditing
                            ? t('address.actions.edit')
                            : t('address.actions.add')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('address.form_description')}
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
                                <Label htmlFor="address-type">
                                    {t('address.fields.type')}
                                </Label>
                                <select
                                    id="address-type"
                                    name="type"
                                    required
                                    className={controlClass}
                                    defaultValue={address?.type ?? types[0]}
                                >
                                    {types.map((value) => (
                                        <option key={value} value={value}>
                                            {t(`address.types.${value}`)}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.type} />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="address-contact-name">
                                        {t('address.fields.contact_name')}
                                    </Label>
                                    <Input
                                        id="address-contact-name"
                                        name="contact_name"
                                        defaultValue={address?.contact_name}
                                        required
                                        maxLength={150}
                                    />
                                    <InputError message={errors.contact_name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="address-contact-mobile">
                                        {t('address.fields.contact_mobile')}
                                    </Label>
                                    <Input
                                        id="address-contact-mobile"
                                        name="contact_mobile"
                                        type="tel"
                                        defaultValue={address?.contact_mobile}
                                        required
                                    />
                                    <InputError
                                        message={errors.contact_mobile}
                                    />
                                </div>
                            </div>

                            <LocationPicker
                                initial={address?.location}
                                errors={errors}
                                divisionsUrl={divisionsUrl}
                                childrenUrl={childrenUrl}
                            />

                            <div className="grid gap-2">
                                <Label htmlFor="address-detailed">
                                    {t('address.fields.detailed_address')}
                                </Label>
                                <textarea
                                    id="address-detailed"
                                    name="detailed_address"
                                    required
                                    maxLength={500}
                                    defaultValue={address?.detailed_address}
                                    className={`${controlClass} min-h-24`}
                                />
                                <InputError message={errors.detailed_address} />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="address-landmark">
                                        {t('address.fields.landmark')}
                                    </Label>
                                    <Input
                                        id="address-landmark"
                                        name="landmark"
                                        defaultValue={address?.landmark ?? ''}
                                        maxLength={150}
                                    />
                                    <InputError message={errors.landmark} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="address-postcode">
                                        {t('address.fields.postcode')}
                                    </Label>
                                    <Input
                                        id="address-postcode"
                                        name="postcode"
                                        defaultValue={address?.postcode ?? ''}
                                        maxLength={16}
                                    />
                                    <InputError message={errors.postcode} />
                                </div>
                            </div>

                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="is_default"
                                    value="1"
                                    className="accent-brand"
                                    defaultChecked={address?.is_default}
                                />
                                {t('address.actions.set_default')}
                            </label>

                            <DialogFooter className="gap-2 sm:gap-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => onOpenChange(false)}
                                >
                                    {t('address.actions.cancel')}
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('address.actions.save')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
