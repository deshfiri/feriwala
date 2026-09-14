import { Form, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
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
import type { AccountOption } from '@/types/inventory';

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Setting central stock aside for one business account (§19, P3-30).
 *
 * The accounts offered come from the server, which only offers accounts that
 * can trade; the available figure is a guide the server re-reads under its lock.
 */
export default function AllocateStockDialog({
    itemId,
    sku,
    available,
    accounts,
    open,
    onClose,
}: {
    itemId: string;
    sku: string;
    available: number;
    accounts?: AccountOption[];
    open: boolean;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState<AccountOption | null>(null);

    // Asks the server for matching accounts, debounced, once there is enough to search on.
    useEffect(() => {
        if (!open || search.trim().length < 2) {
            return;
        }

        const timer = setTimeout(() => {
            router.reload({
                only: ['accounts'],
                data: { account_search: search.trim() },
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
                        {t('inventory.allocations.allocate_title', { sku })}
                    </DialogTitle>
                    <DialogDescription>
                        {t('inventory.allocations.allocate_description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...StockAllocationController.store.form(itemId)}
                    options={{ preserveScroll: true }}
                    onSuccess={close}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="allocate-account-search">
                                    {t('inventory.allocations.account')}
                                </Label>
                                <Input
                                    id="allocate-account-search"
                                    type="search"
                                    autoComplete="off"
                                    value={search}
                                    placeholder={t(
                                        'inventory.allocations.account_search',
                                    )}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                />
                                <p className="text-muted-foreground text-xs">
                                    {t(
                                        'inventory.allocations.account_search_help',
                                    )}
                                </p>

                                {search.trim().length >= 2 &&
                                    accounts !== undefined &&
                                    (accounts.length === 0 ? (
                                        <p className="text-muted-foreground text-sm">
                                            {t(
                                                'inventory.allocations.no_accounts',
                                            )}
                                        </p>
                                    ) : (
                                        <ul className="divide-border max-h-56 divide-y overflow-y-auto rounded-lg border">
                                            {accounts.map((account) => (
                                                <li key={account.id}>
                                                    <button
                                                        type="button"
                                                        aria-pressed={
                                                            selected?.id ===
                                                            account.id
                                                        }
                                                        onClick={() =>
                                                            setSelected(account)
                                                        }
                                                        className="hover:bg-muted aria-pressed:bg-muted flex w-full items-center px-3 py-2 text-left text-sm"
                                                    >
                                                        <span className="truncate">
                                                            {account.name}
                                                        </span>
                                                    </button>
                                                </li>
                                            ))}
                                        </ul>
                                    ))}

                                {selected && (
                                    <p className="bg-muted rounded-md border px-3 py-2 text-sm">
                                        {t(
                                            'inventory.allocations.account_selected',
                                            { name: selected.name },
                                        )}
                                    </p>
                                )}

                                <input
                                    type="hidden"
                                    name="account"
                                    value={selected?.id ?? ''}
                                />
                                <InputError message={errors.account} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="allocate-quantity">
                                    {t('inventory.allocations.quantity')}
                                </Label>
                                <Input
                                    id="allocate-quantity"
                                    name="quantity"
                                    type="number"
                                    min={1}
                                    max={1000000}
                                    step={1}
                                    required
                                />
                                <p className="text-muted-foreground text-xs">
                                    {t('inventory.allocations.available_now', {
                                        count: available,
                                    })}
                                </p>
                                <InputError message={errors.quantity} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="allocate-reason">
                                    {t('inventory.allocations.reason')}
                                </Label>
                                <textarea
                                    id="allocate-reason"
                                    name="reason"
                                    rows={3}
                                    minLength={10}
                                    maxLength={1000}
                                    required
                                    className={controlClass}
                                />
                                <p className="text-muted-foreground text-xs">
                                    {t('inventory.allocations.reason_help')}
                                </p>
                                <InputError message={errors.reason} />
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
                                    {t('inventory.allocations.submit')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
