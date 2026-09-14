import { Form } from '@inertiajs/react';
import { useState } from 'react';
import StockAdjustmentController from '@/actions/App/Http/Controllers/Admin/StockAdjustmentController';
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
import type { AdjustmentKindOption, StockBuckets } from '@/types/inventory';

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Changing central stock by hand (§19, P3-24).
 *
 * Each kind names the buckets it moves between, and the dialog says so before
 * anything is sent, alongside what the source bucket holds now. The figure is
 * shown as a guide only: the server re-reads it under a row lock and refuses a
 * quantity it does not hold, whatever this screen believed.
 */
export default function AdjustStockDialog({
    itemId,
    buckets,
    kinds,
    open,
    onClose,
}: {
    itemId: string;
    buckets: StockBuckets;
    kinds: AdjustmentKindOption[];
    open: boolean;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const [value, setValue] = useState(kinds[0]?.value ?? '');

    const kind = kinds.find((option) => option.value === value) ?? kinds[0];
    const label = (bucket: string) => t(`inventory.buckets.${bucket}`);

    const direction = () => {
        if (!kind) return '';

        if (kind.from === null && kind.to !== null) {
            return t('inventory.adjustments.moves_into', {
                to: label(kind.to),
            });
        }

        if (kind.from !== null && kind.to === null) {
            return t('inventory.adjustments.moves_out_of', {
                from: label(kind.from),
            });
        }

        return t('inventory.adjustments.moves_between', {
            from: label(kind.from ?? ''),
            to: label(kind.to ?? ''),
        });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {t('inventory.adjustments.title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('inventory.adjustments.description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...StockAdjustmentController.store.form(itemId)}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    resetOnSuccess
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="adjust-kind">
                                    {t('inventory.adjustments.kind')}
                                </Label>
                                <select
                                    id="adjust-kind"
                                    name="kind"
                                    required
                                    value={value}
                                    onChange={(event) =>
                                        setValue(event.target.value)
                                    }
                                    className={controlClass}
                                >
                                    {kinds.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                                <p className="text-muted-foreground text-xs">
                                    {direction()}
                                    {kind?.from
                                        ? ` ${t('inventory.adjustments.holds', {
                                              bucket: label(kind.from),
                                              count: buckets[kind.from],
                                          })}`
                                        : ''}
                                </p>
                                <InputError message={errors.kind} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="adjust-quantity">
                                    {t('inventory.adjustments.quantity')}
                                </Label>
                                <Input
                                    id="adjust-quantity"
                                    name="quantity"
                                    type="number"
                                    min={1}
                                    max={1000000}
                                    step={1}
                                    required
                                />
                                <InputError message={errors.quantity} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="adjust-reason">
                                    {t('inventory.adjustments.reason')}
                                </Label>
                                <textarea
                                    id="adjust-reason"
                                    name="reason"
                                    rows={3}
                                    minLength={10}
                                    maxLength={1000}
                                    required
                                    className={controlClass}
                                />
                                <p className="text-muted-foreground text-xs">
                                    {t('inventory.adjustments.reason_help')}
                                </p>
                                <InputError message={errors.reason} />
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={onClose}
                                >
                                    {t('common.actions.cancel')}
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('inventory.adjustments.submit')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
