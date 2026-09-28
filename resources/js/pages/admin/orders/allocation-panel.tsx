import { Form } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import OrderController from '@/actions/App/Http/Controllers/Admin/OrderController';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import StatusPill from '@/components/status-pill';
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
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';

type Candidate = {
    source_type: 'warehouse' | 'supplier_offer';
    source_id: string;
    source_label: string;
    available: number;
    reserved: number;
    available_to_promise: number;
    unit_cost: Money;
    platform_rate: Money;
    expected_margin: Money;
    currency_code: string;
    is_eligible: boolean;
    ineligible_reason: string | null;
    is_currently_allocated: boolean;
    supplier_id: string | null;
    supplier_name: string | null;
    lead_time_days: number | null;
    is_preferred: boolean;
    supplier_status: string | null;
    offer_status: string | null;
};

type SortKey = 'cost_asc' | 'cost_desc' | 'margin_desc' | 'margin_asc';

export type AllocationLine = { id: string; name: string; sku: string };

const controlClass =
    'border-input bg-background focus-visible:ring-ring rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * The staff source allocation panel: Central Warehouse and every eligible
 * Supplier offer for one order line's exact variation.
 *
 * **The platform never picks.** The list is sortable and filterable, but
 * nothing here is pre-selected or defaulted to the cheapest row — a member
 * of staff reads the candidates and explicitly confirms one
 * (`ConfirmAllocation` below), which is the only thing that ever calls the
 * server (D25, the allocation batch).
 */
export default function AllocationPanel({
    open,
    onOpenChange,
    orderId,
    line,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    orderId: string;
    line: AllocationLine | null;
}) {
    const { t } = useTranslation();
    const [candidates, setCandidates] = useState<Candidate[] | null>(null);
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState<SortKey>('margin_desc');
    const [eligibleOnly, setEligibleOnly] = useState(true);
    const [chosen, setChosen] = useState<Candidate | null>(null);

    useEffect(() => {
        if (!open || line === null) {
            setCandidates(null);
            setChosen(null);

            return;
        }

        let cancelled = false;
        setCandidates(null);

        fetch(
            OrderController.allocationCandidates.url({
                order: orderId,
                item: line.id,
            }),
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        )
            .then((response) => response.json())
            .then((body: { candidates: Candidate[] }) => {
                if (!cancelled) setCandidates(body.candidates);
            })
            .catch(() => {
                if (!cancelled) setCandidates([]);
            });

        return () => {
            cancelled = true;
        };
    }, [open, line, orderId]);

    const filtered = useMemo(() => {
        if (candidates === null) return [];

        const term = search.trim().toLowerCase();
        const cost = (candidate: Candidate) =>
            Number(candidate.unit_cost.amount);
        const margin = (candidate: Candidate) =>
            Number(candidate.expected_margin.amount);

        return candidates
            .filter((candidate) => !eligibleOnly || candidate.is_eligible)
            .filter(
                (candidate) =>
                    term === '' ||
                    candidate.source_label.toLowerCase().includes(term),
            )
            .sort((a, b) => {
                switch (sort) {
                    case 'cost_asc':
                        return cost(a) - cost(b);
                    case 'cost_desc':
                        return cost(b) - cost(a);
                    case 'margin_asc':
                        return margin(a) - margin(b);
                    default:
                        return margin(b) - margin(a);
                }
            });
    }, [candidates, search, sort, eligibleOnly]);

    if (line === null) {
        return null;
    }

    return (
        <>
            <Sheet open={open} onOpenChange={onOpenChange}>
                <SheetContent side="right" className="w-full sm:max-w-xl">
                    <SheetHeader>
                        <SheetTitle>
                            {t('orders.admin.allocation.panel_title', {
                                product: line.name,
                            })}
                        </SheetTitle>
                        <SheetDescription>
                            {t('orders.admin.allocation.panel_description')}
                        </SheetDescription>
                    </SheetHeader>

                    <div className="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto px-4">
                        <div className="flex flex-wrap gap-2">
                            <Input
                                placeholder={t(
                                    'orders.admin.allocation.search',
                                )}
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                className="min-w-40 flex-1"
                            />
                            <select
                                value={sort}
                                onChange={(event) =>
                                    setSort(event.target.value as SortKey)
                                }
                                className={controlClass}
                                aria-label={t('orders.admin.allocation.sort')}
                            >
                                <option value="margin_desc">
                                    {t(
                                        'orders.admin.allocation.sort_options.margin_desc',
                                    )}
                                </option>
                                <option value="margin_asc">
                                    {t(
                                        'orders.admin.allocation.sort_options.margin_asc',
                                    )}
                                </option>
                                <option value="cost_asc">
                                    {t(
                                        'orders.admin.allocation.sort_options.cost_asc',
                                    )}
                                </option>
                                <option value="cost_desc">
                                    {t(
                                        'orders.admin.allocation.sort_options.cost_desc',
                                    )}
                                </option>
                            </select>
                        </div>

                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={eligibleOnly}
                                onChange={(event) =>
                                    setEligibleOnly(event.target.checked)
                                }
                            />
                            {t('orders.admin.allocation.eligible_only')}
                        </label>

                        {candidates === null && (
                            <div className="flex justify-center py-8">
                                <Spinner />
                            </div>
                        )}

                        {candidates !== null && filtered.length === 0 && (
                            <p className="text-muted-foreground text-sm">
                                {candidates.length === 0
                                    ? t('orders.admin.allocation.empty')
                                    : t('orders.admin.allocation.no_matches')}
                            </p>
                        )}

                        <ul className="space-y-2 pb-4">
                            {filtered.map((candidate) => (
                                <li
                                    key={`${candidate.source_type}:${candidate.source_id}`}
                                    className={`border-border rounded-lg border p-3 ${candidate.is_eligible ? '' : 'opacity-60'}`}
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <p className="truncate font-medium">
                                                {candidate.source_label}
                                                {candidate.is_preferred && (
                                                    <span className="text-muted-foreground ms-2 text-xs font-normal">
                                                        {t(
                                                            'orders.admin.allocation.preferred',
                                                        )}
                                                    </span>
                                                )}
                                            </p>
                                            <p className="text-muted-foreground text-xs">
                                                {t(
                                                    `status.allocation_source.${candidate.source_type}`,
                                                )}
                                                {' · '}
                                                {t(
                                                    'orders.admin.allocation.columns.available',
                                                )}
                                                {': '}
                                                {candidate.available_to_promise}
                                            </p>
                                        </div>
                                        {candidate.is_currently_allocated && (
                                            <StatusPill
                                                tone="success"
                                                label={t(
                                                    'orders.admin.allocation.current',
                                                )}
                                            />
                                        )}
                                    </div>

                                    <div className="mt-2 grid grid-cols-3 gap-2">
                                        <div>
                                            <p className="text-muted-foreground text-xs">
                                                {t(
                                                    'orders.admin.allocation.columns.unit_cost',
                                                )}
                                            </p>
                                            <MoneyAmount
                                                amount={candidate.unit_cost}
                                            />
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground text-xs">
                                                {t(
                                                    'orders.admin.allocation.columns.platform_rate',
                                                )}
                                            </p>
                                            <MoneyAmount
                                                amount={candidate.platform_rate}
                                            />
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground text-xs">
                                                {t(
                                                    'orders.admin.allocation.columns.margin',
                                                )}
                                            </p>
                                            <MoneyAmount
                                                amount={
                                                    candidate.expected_margin
                                                }
                                            />
                                        </div>
                                    </div>

                                    {!candidate.is_eligible &&
                                        candidate.ineligible_reason && (
                                            <p className="text-destructive mt-2 text-xs">
                                                {candidate.ineligible_reason}
                                            </p>
                                        )}

                                    <div className="mt-2">
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant={
                                                candidate.is_currently_allocated
                                                    ? 'outline'
                                                    : 'default'
                                            }
                                            disabled={!candidate.is_eligible}
                                            onClick={() => setChosen(candidate)}
                                        >
                                            {candidate.is_currently_allocated
                                                ? t(
                                                      'orders.admin.allocation.change_action',
                                                  )
                                                : t(
                                                      'orders.admin.allocation.confirm',
                                                  )}
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </div>

                    <SheetFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => onOpenChange(false)}
                        >
                            {t('orders.admin.allocation.close')}
                        </Button>
                    </SheetFooter>
                </SheetContent>
            </Sheet>

            <ConfirmAllocationDialog
                orderId={orderId}
                line={line}
                candidate={chosen}
                onOpenChange={(next) => {
                    if (!next) setChosen(null);
                }}
                onSuccess={() => {
                    setChosen(null);
                    onOpenChange(false);
                }}
            />
        </>
    );
}

/**
 * The explicit confirmation step — the one request that actually locks the
 * line, reserves the source and, for a Supplier, raises the pending payable.
 */
function ConfirmAllocationDialog({
    orderId,
    line,
    candidate,
    onOpenChange,
    onSuccess,
}: {
    orderId: string;
    line: AllocationLine;
    candidate: Candidate | null;
    onOpenChange: (open: boolean) => void;
    onSuccess: () => void;
}) {
    const { t } = useTranslation();

    if (candidate === null) {
        return null;
    }

    return (
        <Dialog open onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {t('orders.admin.allocation.confirm_title', {
                            source: candidate.source_label,
                            product: line.name,
                        })}
                    </DialogTitle>
                    <DialogDescription>
                        {t('orders.admin.allocation.confirm_description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...OrderController.allocate.form({
                        order: orderId,
                        item: line.id,
                    })}
                    options={{ preserveScroll: true }}
                    onSuccess={onSuccess}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="source_type"
                                value={candidate.source_type}
                            />
                            <input
                                type="hidden"
                                name="source_id"
                                value={candidate.source_id}
                            />

                            <div className="grid gap-2">
                                <Label htmlFor="allocation-reason">
                                    {t('orders.admin.allocation.reason')}
                                </Label>
                                <textarea
                                    id="allocation-reason"
                                    name="reason"
                                    rows={3}
                                    minLength={10}
                                    maxLength={1000}
                                    required
                                    className={`${controlClass} w-full`}
                                />
                                <p className="text-muted-foreground text-xs">
                                    {t('orders.admin.allocation.reason_help')}
                                </p>
                                <InputError
                                    message={errors.source_id ?? errors.reason}
                                />
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => onOpenChange(false)}
                                >
                                    {t('common.actions.cancel')}
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('orders.admin.allocation.confirm')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
