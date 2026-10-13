import { Form } from '@inertiajs/react';
import StockController from '@/actions/App/Http/Controllers/Admin/StockController';
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
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import type { StockRow } from '@/types/inventory';

/**
 * Deleting a stock row that was never used (§19).
 *
 * The server decides: a row with any movement, reservation, allocation or order
 * is part of the ledger and is refused, however this dialog was reached.
 */
export default function DeleteStockDialog({
    item,
    open,
    onClose,
}: {
    item: StockRow;
    open: boolean;
    onClose: () => void;
}) {
    const { t } = useTranslation();

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {t('inventory.stock.delete_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('inventory.stock.delete_description', {
                            sku: item.sku,
                            warehouse: item.warehouse.name,
                        })}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...StockController.destroy.form(item.id)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <InputError message={errors.item} />

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
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    {t('inventory.stock.delete_submit')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
