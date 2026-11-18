import { Form } from '@inertiajs/react';
import ManagedAccountController from '@/actions/App/Http/Controllers/Admin/ManagedAccountController';
import NativeSelect from '@/components/forms/native-select';
import ReasonTextarea from '@/components/forms/reason-textarea';
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

type Option = { value: string; label: string };

export type EditableDetails = {
    business_name: string;
    name: string;
    email: string;
    mobile: string | null;
    date_of_birth: string | null;
    gender: string | null;
    country: string | null;
    nationality: string | null;
    countries: Option[];
    genders: Option[];
};

/**
 * Staff correcting a partner's business name and the owner's details.
 *
 * Every field is pre-filled with what is stored now; the server validates,
 * applies and audits the change with the reason.
 */
export default function EditDetailsDialog({
    accountId,
    details,
    open,
    onOpenChange,
}: {
    accountId: string;
    details: EditableDetails;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    const text = (
        field: keyof EditableDetails,
        type: string,
        error: string | undefined,
        required = false,
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={`details-${field}`}>
                {t(`account.edit_details.fields.${field}`)}
            </Label>
            <Input
                id={`details-${field}`}
                name={field}
                type={type}
                required={required}
                defaultValue={(details[field] as string | null) ?? ''}
            />
            <InputError message={error} />
        </div>
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{t('account.edit_details.title')}</DialogTitle>
                    <DialogDescription>
                        {t('account.edit_details.description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...ManagedAccountController.updateDetails.form(accountId)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                {text(
                                    'business_name',
                                    'text',
                                    errors.business_name,
                                    true,
                                )}
                                {text('name', 'text', errors.name, true)}
                                {text('email', 'email', errors.email, true)}
                                {text('mobile', 'tel', errors.mobile, true)}
                                {text(
                                    'date_of_birth',
                                    'date',
                                    errors.date_of_birth,
                                )}

                                <div className="grid gap-2">
                                    <Label htmlFor="details-gender">
                                        {t(
                                            'account.edit_details.fields.gender',
                                        )}
                                    </Label>
                                    <NativeSelect
                                        id="details-gender"
                                        name="gender"
                                        defaultValue={details.gender ?? ''}
                                    >
                                        <option value="">
                                            {t('account.edit_details.not_set')}
                                        </option>
                                        {details.genders.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                    <InputError message={errors.gender} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="details-country">
                                        {t(
                                            'account.edit_details.fields.country',
                                        )}
                                    </Label>
                                    <NativeSelect
                                        id="details-country"
                                        name="country"
                                        required
                                        defaultValue={details.country ?? ''}
                                    >
                                        {details.countries.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                    <InputError message={errors.country} />
                                </div>

                                {text(
                                    'nationality',
                                    'text',
                                    errors.nationality,
                                )}
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="details-reason">
                                    {t('account.edit_details.reason')}
                                </Label>
                                <ReasonTextarea
                                    context="account"
                                    id="details-reason"
                                    name="reason"
                                    rows={3}
                                    required
                                />
                                <p className="text-muted-foreground text-xs">
                                    {t('account.edit_details.reason_help')}
                                </p>
                                <InputError message={errors.reason} />
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
                                    {t('account.edit_details.submit')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
