import { Form } from '@inertiajs/react';
import ReasonTextarea from '@/components/forms/reason-textarea';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTranslation } from '@/hooks/use-translation';
import { completeManually } from '@/routes/admin/accounts';

const textareaClasses =
    'border-input focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:border-destructive w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]';

/**
 * Finishing an account's onboarding by hand and activating it.
 *
 * One required reason, because it is one decision with four recorded effects:
 * contacts confirmed, KYC approved, the activation payment waived, account
 * activated. The dialog says what will happen so nobody discovers that the
 * payment was waived from the account history afterwards.
 */
export default function CompleteAccountDialog({
    accountId,
    open,
    onOpenChange,
}: {
    accountId: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {t('account.complete_manually.title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('account.complete_manually.description')}
                    </DialogDescription>
                </DialogHeader>

                <p className="text-muted-foreground text-sm">
                    {t('account.complete_manually.what_happens')}
                </p>

                <Form
                    {...completeManually.form(accountId)}
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <FormField
                                label={t('account.complete_manually.reason')}
                                description={t(
                                    'account.complete_manually.reason_help',
                                )}
                                error={errors.reason}
                                required
                            >
                                {(field) => (
                                    <ReasonTextarea
                                        context="account"
                                        {...field}
                                        name="reason"
                                        rows={3}
                                        className={textareaClasses}
                                        required
                                    />
                                )}
                            </FormField>

                            <SubmitButton processing={processing}>
                                {t('account.complete_manually.submit')}
                            </SubmitButton>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
