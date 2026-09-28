import { Form } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import KycReverificationCancellationController from '@/actions/App/Http/Controllers/Admin/KycReverificationCancellationController';
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

type Props = {
    /** The round being withdrawn; null keeps the dialog closed. */
    round: { id: string; round: number } | null;
    onOpenChange: (open: boolean) => void;
};

/**
 * Withdraw a re-verification nobody has answered (§7.2).
 *
 * The round is not deleted — it is evidence that we asked, and the business
 * was notified and possibly restricted while it stood. What this collects is
 * the reason, which stays in the account history beside the request it
 * cancels.
 *
 * The reason is required by the server as well. A requirement that appeared
 * and then vanished with no explanation is worse than one never made.
 */
export default function WithdrawKycRequestDialog({
    round,
    onOpenChange,
}: Props) {
    const { t } = useTranslation();

    return (
        <Dialog open={round !== null} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('account.kyc_withdraw.title')}</DialogTitle>
                    <DialogDescription>
                        {round
                            ? t('account.kyc_withdraw.description', {
                                  number: round.round,
                              })
                            : null}
                    </DialogDescription>
                </DialogHeader>

                {round && (
                    <Form
                        {...KycReverificationCancellationController.form(
                            round.id,
                        )}
                        onSuccess={() => onOpenChange(false)}
                        className="space-y-5"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="space-y-1.5">
                                    <Label htmlFor="withdraw-reason">
                                        {t('account.kyc_withdraw.reason')}
                                    </Label>
                                    <textarea
                                        id="withdraw-reason"
                                        name="reason"
                                        rows={3}
                                        required
                                        maxLength={1000}
                                        className="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        {t('account.kyc_withdraw.reason_help')}
                                    </p>
                                    <InputError message={errors.reason} />
                                </div>

                                <p
                                    className="bg-warning-subtle border-warning/30 flex items-start gap-2 rounded-lg border p-3 text-sm"
                                    role="status"
                                >
                                    <AlertTriangle
                                        aria-hidden="true"
                                        className="text-warning mt-0.5 size-4 shrink-0"
                                    />
                                    {t('account.kyc_withdraw.confirm')}
                                </p>

                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => onOpenChange(false)}
                                    >
                                        {t('account.kyc_withdraw.cancel')}
                                    </Button>

                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        {processing
                                            ? t(
                                                  'account.kyc_withdraw.withdrawing',
                                              )
                                            : t('account.kyc_withdraw.submit')}
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                )}
            </DialogContent>
        </Dialog>
    );
}
