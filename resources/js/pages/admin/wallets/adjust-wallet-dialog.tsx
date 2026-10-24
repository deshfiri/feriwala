import { Form } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
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

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

type BalanceType = 'platform_credit' | 'withdrawable' | '';

/**
 * Moving money by hand (§23.1, §32.2) — a manual top-up, or taking money back.
 *
 * The one operation where a person, rather than an event, decides a balance
 * should change — so the screen says what it is about to do before it offers the
 * button, and the button stays out of reach until that has been acknowledged.
 *
 * A credit must say what kind of money it is: platform credit, which can only be
 * spent inside Banij, or withdrawable money, which can leave it. There is no
 * default — whether money can leave the platform is the decision this form
 * exists to make — and choosing withdrawable shows a warning, because that
 * amount can be paid out. The server enforces both; this only makes them
 * visible. A debit may name the kind to take first, and is otherwise taken from
 * platform credit first.
 *
 * The amount is asked for in flat Taka and submitted exactly as typed ("25.00"
 * is BDT 25.00, D26), as every other money form in the panel does. Nothing here
 * converts or scales it: a browser doing arithmetic on money is a total the
 * ledger cannot vouch for, and §36.1 keeps that on the server.
 *
 * The reason is required by the server as well as here. It goes to the audit log
 * with the administrator's name, because a balance that changed for no recorded
 * reason is the first thing an auditor asks about.
 *
 * Used for an account's wallet and for a Supplier's: `form` says where it posts,
 * and `withIdempotencyKey` adds the once-per-attempt key a Supplier's endpoint
 * asks for, so a double submit posts once.
 */
export default function AdjustWalletDialog({
    form,
    account,
    open,
    onOpenChange,
    withIdempotencyKey = false,
}: {
    form: { action: string; method: 'post' };
    account: string | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    withIdempotencyKey?: boolean;
}) {
    const { t } = useTranslation();
    const [acknowledged, setAcknowledged] = useState(false);
    const [direction, setDirection] = useState<'credit' | 'debit'>('credit');
    const [balanceType, setBalanceType] = useState<BalanceType>('');
    const [attempt] = useState(() => crypto.randomUUID());

    const close = (next: boolean) => {
        if (!next) {
            setAcknowledged(false);
            setDirection('credit');
            setBalanceType('');
        }

        onOpenChange(next);
    };

    const isCredit = direction === 'credit';

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('wallet.admin.adjust.title')}</DialogTitle>
                    <DialogDescription>
                        {t('wallet.admin.adjust.description')}
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
                            {withIdempotencyKey && (
                                <input
                                    type="hidden"
                                    name="idempotency_key"
                                    value={attempt}
                                />
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="adjust-direction">
                                    {t('wallet.admin.adjust.direction')}
                                </Label>

                                <select
                                    id="adjust-direction"
                                    name="direction"
                                    required
                                    className={controlClass}
                                    value={direction}
                                    onChange={(event) =>
                                        setDirection(
                                            event.target.value as
                                                | 'credit'
                                                | 'debit',
                                        )
                                    }
                                >
                                    <option value="credit">
                                        {t('wallet.admin.adjust.credit')}
                                    </option>
                                    <option value="debit">
                                        {t('wallet.admin.adjust.debit')}
                                    </option>
                                </select>

                                <InputError message={errors.direction} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="adjust-balance-type">
                                    {t('wallet.admin.adjust.balance_type')}
                                </Label>

                                <select
                                    id="adjust-balance-type"
                                    name="balance_type"
                                    required={isCredit}
                                    className={controlClass}
                                    value={balanceType}
                                    onChange={(event) =>
                                        setBalanceType(
                                            event.target.value as BalanceType,
                                        )
                                    }
                                >
                                    <option value="">
                                        {isCredit
                                            ? t(
                                                  'wallet.admin.adjust.balance_type_placeholder',
                                              )
                                            : '—'}
                                    </option>
                                    <option value="platform_credit">
                                        {t(
                                            'wallet.admin.adjust.balance_type_platform_credit',
                                        )}
                                    </option>
                                    <option value="withdrawable">
                                        {t(
                                            'wallet.admin.adjust.balance_type_withdrawable',
                                        )}
                                    </option>
                                </select>

                                {!isCredit && (
                                    <p className="text-muted-foreground text-xs">
                                        {t(
                                            'wallet.admin.adjust.balance_type_debit_help',
                                        )}
                                    </p>
                                )}

                                {isCredit && balanceType === 'withdrawable' && (
                                    <p
                                        role="alert"
                                        className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-3 py-2 text-sm"
                                    >
                                        {t(
                                            'wallet.admin.adjust.withdrawable_warning',
                                        )}
                                    </p>
                                )}

                                {isCredit &&
                                    balanceType === 'platform_credit' && (
                                        <p className="text-muted-foreground text-xs">
                                            {t(
                                                'wallet.admin.adjust.platform_credit_help',
                                            )}
                                        </p>
                                    )}

                                <InputError message={errors.balance_type} />
                            </div>

                            <MoneyInput
                                id="adjust-amount"
                                name="amount"
                                label={t('wallet.admin.adjust.amount')}
                                required
                                error={errors.amount}
                            />

                            <div className="grid gap-2">
                                <Label htmlFor="adjust-reason">
                                    {t('wallet.admin.adjust.reason')}
                                </Label>

                                <ReasonTextarea
                                    context="wallet"
                                    id="adjust-reason"
                                    name="reason"
                                    rows={3}
                                    minLength={10}
                                    required
                                    className={controlClass}
                                />

                                <p className="text-muted-foreground text-xs">
                                    {t('wallet.admin.adjust.reason_help')}
                                </p>

                                <InputError message={errors.reason} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="adjust-note">
                                    {t('wallet.admin.adjust.internal_note')}
                                </Label>

                                <textarea
                                    id="adjust-note"
                                    name="internal_note"
                                    rows={2}
                                    className={controlClass}
                                />

                                <p className="text-muted-foreground text-xs">
                                    {t(
                                        'wallet.admin.adjust.internal_note_help',
                                    )}
                                </p>

                                <InputError message={errors.internal_note} />
                            </div>

                            {account !== null && (
                                <p className="bg-muted border-border rounded-md border px-3 py-2.5 text-sm">
                                    {t('wallet.admin.wallet_of', { account })}
                                </p>
                            )}

                            <label className="flex items-start gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    className="accent-brand mt-0.5"
                                    checked={acknowledged}
                                    onChange={(event) =>
                                        setAcknowledged(event.target.checked)
                                    }
                                />
                                <span>{t('wallet.admin.adjust.confirm')}</span>
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
                                    {t('wallet.admin.adjust.submit')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
