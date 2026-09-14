import { Form } from '@inertiajs/react';
import StockAllocationController from '@/actions/App/Http/Controllers/Admin/StockAllocationController';
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
import type { StockAllocationRow } from '@/types/inventory';

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Giving an account's allocated stock back to everyone (§19, P3-30).
 *
 * The figure shown is a guide; the server re-reads the allocation under the
 * item's row lock and refuses more than it holds.
 */
export default function ReleaseAllocationDialog({
    allocation,
    sku,
    onClose,
}: {
    allocation: StockAllocationRow | null;
    sku: string;
    onClose: () => void;
}) {
    const { t } = useTranslation();

    return (
        <Dialog
            open={allocation !== null}
            onOpenChange={(next) => !next && onClose()}
        >
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                {allocation && (
                    <>
                        <DialogHeader>
                            <DialogTitle>
                                {t('inventory.allocations.release_title', {
                                    account: allocation.account.name,
                                })}
                            </DialogTitle>
                            <DialogDescription>
                                {t(
                                    'inventory.allocations.release_description',
                                    { sku, count: allocation.quantity },
                                )}
                            </DialogDescription>
                        </DialogHeader>

                        <Form
                            {...StockAllocationController.release.form(
                                allocation.id,
                            )}
                            options={{ preserveScroll: true }}
                            onSuccess={onClose}
                            className="space-y-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="release-quantity">
                                            {t(
                                                'inventory.allocations.quantity',
                                            )}
                                        </Label>
                                        <Input
                                            id="release-quantity"
                                            name="quantity"
                                            type="number"
                                            min={1}
                                            max={allocation.quantity}
                                            step={1}
                                            defaultValue={allocation.quantity}
                                            required
                                        />
                                        <InputError message={errors.quantity} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="release-reason">
                                            {t('inventory.allocations.reason')}
                                        </Label>
                                        <textarea
                                            id="release-reason"
                                            name="reason"
                                            rows={3}
                                            minLength={10}
                                            maxLength={1000}
                                            required
                                            className={controlClass}
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            {t(
                                                'inventory.allocations.reason_help',
                                            )}
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
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {t(
                                                'inventory.allocations.release_submit',
                                            )}
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
