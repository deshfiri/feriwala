import { Form } from '@inertiajs/react';
import { useState } from 'react';
import WalletAdjustmentController from '@/actions/App/Http/Controllers/Admin/WalletAdjustmentController';
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
import type { WalletMovement } from '@/types/wallet';
import ReasonTextarea from '@/components/forms/reason-textarea';

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Undoing a posting that has already happened (§23.2, §32.2).
 *
 * The wording matters as much as the control: nothing is edited and nothing is
 * deleted. The original entry stays exactly as it is and a new one of the
 * opposite direction answers it, so the wrong figure and the putting-right of it
 * are both on the record.
 *
 * The summary states what is about to be reversed, in the amount and words the
 * server sent, before the button is reachable.
 */
export default function ReverseMovementDialog({
    walletId,
    movement,
    open,
    onOpenChange,
}: {
    walletId: string;
    movement: WalletMovement;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t, locale } = useTranslation();
    const [acknowledged, setAcknowledged] = useState(false);

    const close = (next: boolean) => {
        if (!next) setAcknowledged(false);

        onOpenChange(next);
    };

    const when =
        movement.at === null
            ? '—'
            : new Date(movement.at).toLocaleString(locale, {
                  dateStyle: 'medium',
                  timeStyle: 'short',
              });

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('wallet.admin.reverse.title')}</DialogTitle>
                    <DialogDescription>
                        {t('wallet.admin.reverse.description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...WalletAdjustmentController.reverse.form([
                        walletId,
                        movement.id,
                    ])}
                    options={{ preserveScroll: true }}
                    onSuccess={() => close(false)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <p className="bg-muted border-border rounded-md border px-3 py-2.5 text-sm">
                                {t('wallet.admin.reverse.summary', {
                                    type: movement.type_label,
                                    amount: movement.amount.formatted,
                                    at: when,
                                })}
                            </p>

                            <div className="grid gap-2">
                                <Label htmlFor="reverse-reason">
                                    {t('wallet.admin.reverse.reason')}
                                </Label>

                                <ReasonTextarea
                                    context="wallet"
                                    id="reverse-reason"
                                    name="reason"
                                    rows={3}
                                    minLength={10}
                                    required
                                    className={controlClass}
                                />

                                <p className="text-muted-foreground text-xs">
                                    {t('wallet.admin.reverse.reason_help')}
                                </p>

                                <InputError message={errors.reason} />
                            </div>

                            <label className="flex items-start gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    className="accent-brand mt-0.5"
                                    checked={acknowledged}
                                    onChange={(event) =>
                                        setAcknowledged(event.target.checked)
                                    }
                                />
                                <span>{t('wallet.admin.reverse.confirm')}</span>
                            </label>

                            <DialogFooter className="gap-2 sm:gap-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => close(false)}
                                >
                                    {t('common.actions.cancel')}
                                </Button>

                                <Button
                                    type="submit"
                                    disabled={processing || !acknowledged}
                                >
                                    {processing && <Spinner />}
                                    {t('wallet.admin.reverse.submit')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
