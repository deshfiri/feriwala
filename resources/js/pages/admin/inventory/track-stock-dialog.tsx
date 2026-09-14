import { Form, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import type { StockUnit, WarehouseOption } from '@/types/inventory';

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Start holding a SKU in a warehouse (§19).
 *
 * The SKUs offered come from the server, which never offers a product that has
 * variations — those are held per variation. Nothing is typed as a quantity
 * here: the item opens at zero, and stock arrives through an adjustment that
 * records who received it.
 */
export default function TrackStockDialog({
    open,
    onClose,
    warehouses,
    units,
}: {
    open: boolean;
    onClose: () => void;
    warehouses: WarehouseOption[];
    units?: StockUnit[];
}) {
    const { t } = useTranslation();
    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState<StockUnit | null>(null);

    // Asks the server for matching SKUs, debounced, and only once there is
    // enough to search on.
    useEffect(() => {
        if (!open || search.trim().length < 2) {
            return;
        }

        const timer = setTimeout(() => {
            router.reload({
                only: ['units'],
                data: { unit_search: search.trim() },
            });
        }, 300);

        return () => clearTimeout(timer);
    }, [open, search]);

    const close = () => {
        setSearch('');
        setSelected(null);
        onClose();
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !next && close()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {t('inventory.stock.track_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('inventory.stock.track_description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...StockController.store.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={close}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="track-warehouse">
                                    {t('inventory.stock.warehouse')}
                                </Label>
                                <select
                                    id="track-warehouse"
                                    name="warehouse"
                                    required
                                    className={controlClass}
                                    defaultValue={warehouses[0]?.id}
                                >
                                    {warehouses.map((warehouse) => (
                                        <option
                                            key={warehouse.id}
                                            value={warehouse.id}
                                        >
                                            {warehouse.name} ({warehouse.code})
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.warehouse} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="track-unit-search">
                                    {t('inventory.stock.unit')}
                                </Label>
                                <Input
                                    id="track-unit-search"
                                    type="search"
                                    autoComplete="off"
                                    value={search}
                                    placeholder={t(
                                        'inventory.stock.unit_search',
                                    )}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                />
                                <p className="text-muted-foreground text-xs">
                                    {t('inventory.stock.unit_search_help')}
                                </p>

                                {search.trim().length >= 2 &&
                                    units !== undefined &&
                                    (units.length === 0 ? (
                                        <p className="text-muted-foreground text-sm">
                                            {t('inventory.stock.no_units')}
                                        </p>
                                    ) : (
                                        <ul className="divide-border max-h-56 divide-y overflow-y-auto rounded-lg border">
                                            {units.map((unit) => {
                                                const chosen =
                                                    selected?.sku === unit.sku;

                                                return (
                                                    <li
                                                        key={`${unit.product}:${unit.variant ?? ''}`}
                                                    >
                                                        <button
                                                            type="button"
                                                            aria-pressed={
                                                                chosen
                                                            }
                                                            onClick={() =>
                                                                setSelected(
                                                                    unit,
                                                                )
                                                            }
                                                            className="hover:bg-muted aria-pressed:bg-muted flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm"
                                                        >
                                                            <span className="min-w-0">
                                                                <span className="block truncate font-mono">
                                                                    {unit.sku}
                                                                </span>
                                                                <span className="text-muted-foreground block truncate text-xs">
                                                                    {unit.name}
                                                                </span>
                                                            </span>
                                                        </button>
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    ))}

                                {selected && (
                                    <p className="bg-muted rounded-md border px-3 py-2 text-sm">
                                        {t('inventory.stock.unit_selected', {
                                            sku: selected.sku,
                                        })}
                                    </p>
                                )}

                                <input
                                    type="hidden"
                                    name="product"
                                    value={selected?.product ?? ''}
                                />
                                <input
                                    type="hidden"
                                    name="variant"
                                    value={selected?.variant ?? ''}
                                />
                                <InputError
                                    message={errors.product ?? errors.variant}
                                />
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={close}
                                >
                                    {t('common.actions.cancel')}
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={processing || selected === null}
                                >
                                    {processing && <Spinner />}
                                    {t('inventory.stock.track')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
