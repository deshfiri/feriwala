import { Form } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import MoneyInput from '@/components/money-input';
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
import ReasonTextarea from '@/components/forms/reason-textarea';
import type { Money } from '@/lib/money';

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Turning platform credit into withdrawable money (D-new).
 *
 * Platform credit can only be spent inside Banij; converting it makes it
 * withdrawable, so it can leave the platform — and it cannot be turned back. The
 * screen says so before it offers the button, shows how much platform credit the
 * wallet holds, and keeps the button out of reach until that is acknowledged.
 * The server refuses more than is available, and a double submit converts once:
 * the form carries one key per attempt.
 *
 * Used for an account's wallet and for a Supplier's.
 */
export default function ConvertBalanceDialog({
    form,
    platformCredit,
    open,
    onOpenChange,
}: {
    form: { action: string; method: 'post' };
    platformCredit: Money;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();
    const [acknowledged, setAcknowledged] = useState(false);
    const [attempt] = useState(() => crypto.randomUUID());

    const close = (next: boolean) => {
        if (!next) setAcknowledged(false);

        onOpenChange(next);
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('wallet.admin.convert.title')}</DialogTitle>
                    <DialogDescription>
                        {t('wallet.admin.convert.description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    onSuccess={() => close(false)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="idempotency_key"
                                value={attempt}
                            />

                            <div className="bg-muted border-border flex items-baseline justify-between gap-3 rounded-md border px-3 py-2.5 text-sm">
                                <span>
                                    {t('wallet.admin.convert.available')}
                                </span>
                                <MoneyAmount amount={platformCredit} />
                            </div>

                            <MoneyInput
                                id="convert-amount"
                                name="amount"
                                label={t('wallet.admin.convert.amount')}
                                required
                                error={errors.amount}
                            />

                            <div className="grid gap-2">
                                <Label htmlFor="convert-reason">
                                    {t('wallet.admin.convert.reason')}
                                </Label>

                                <ReasonTextarea
                                    context="wallet"
                                    id="convert-reason"
                                    name="reason"
                                    rows={3}
                                    minLength={10}
                                    required
                                    className={controlClass}
                                />

                                <p className="text-muted-foreground text-xs">
                                    {t('wallet.admin.convert.reason_help')}
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
                                <span>{t('wallet.admin.convert.confirm')}</span>
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
                                    {t('wallet.admin.convert.submit')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
