import { Form } from '@inertiajs/react';
import ManagedAccountController from '@/actions/App/Http/Controllers/Admin/ManagedAccountController';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';

export type AccountTypeOption = { value: string; label: string };

export default function AccountTypeDialog({
    accountId,
    currentType,
    options,
    open,
    onOpenChange,
}: {
    accountId: string;
    currentType: string;
    options: AccountTypeOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('account.account_type.title')}</DialogTitle>
                    <DialogDescription>
                        {t('account.account_type.description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...ManagedAccountController.changeAccountType.form(
                        accountId,
                    )}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="account-type">
                                    {t('account.account_type.field')}
                                </Label>
                                <select
                                    id="account-type"
                                    name="account_type"
                                    required
                                    defaultValue={currentType}
                                    className="border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                >
                                    {options.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.account_type} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="account-type-reason">
                                    {t('account.account_type.reason')}
                                </Label>
                                <textarea
                                    id="account-type-reason"
                                    name="reason"
                                    rows={3}
                                    required
                                    className="border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                />
                                <p className="text-muted-foreground text-xs">
                                    {t('account.account_type.reason_help')}
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
                                    {t('account.account_type.submit')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
