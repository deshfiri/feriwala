import { Form } from '@inertiajs/react';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import { reactivate, suspend } from '@/routes/admin/accounts';

const textareaClasses =
    'border-input focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:border-destructive w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]';

/**
 * Stopping a business trading, or letting it start again (§5.3).
 *
 * One dialog for both directions because they are one decision with a sign:
 * the same authority, the same required reason, the same split between the
 * internal record and what the account holder reads.
 *
 * The two text fields are deliberately not one. `reason` is the internal
 * record that has to survive the person who decided it; `feedback` is the only
 * thing the owner is sent, and §7.3 forbids the first leaking into the second.
 * The dialog says which is which rather than leaving a reviewer to guess.
 */
export default function SuspensionDialog({
    accountId,
    mode,
    open,
    onOpenChange,
}: {
    accountId: string;
    mode: 'suspend' | 'reactivate';
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();
    const isSuspending = mode === 'suspend';

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {t(
                            isSuspending
                                ? 'account.suspension.suspend_title'
                                : 'account.suspension.reactivate_title',
                        )}
                    </DialogTitle>
                    <DialogDescription>
                        {t(
                            isSuspending
                                ? 'account.suspension.suspend_description'
                                : 'account.suspension.reactivate_description',
                        )}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...(isSuspending
                        ? suspend.form(accountId)
                        : reactivate.form(accountId))}
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <FormField
                                label={t('account.suspension.reason')}
                                description={t(
                                    'account.suspension.reason_help',
                                )}
                                error={errors.reason}
                                required
                            >
                                {(field) => (
                                    <textarea
                                        {...field}
                                        name="reason"
                                        rows={3}
                                        className={textareaClasses}
                                        required
                                    />
                                )}
                            </FormField>

                            <FormField
                                label={t('account.suspension.feedback')}
                                description={t(
                                    'account.suspension.feedback_help',
                                )}
                                error={errors.feedback}
                            >
                                {(field) => (
                                    <Input {...field} name="feedback" />
                                )}
                            </FormField>

                            <SubmitButton
                                processing={processing}
                                variant={
                                    isSuspending ? 'destructive' : 'default'
                                }
                            >
                                {t(
                                    isSuspending
                                        ? 'account.suspension.suspend_action'
                                        : 'account.suspension.reactivate_action',
                                )}
                            </SubmitButton>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
