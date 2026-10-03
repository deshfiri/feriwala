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
    supply_mode: 'ready_stock' | 'on_demand' | 'pre_order';
    supply_mode_label: string;
    buyer_facing_availability_label: string | null;
    fulfilment_capacity: number | null;
    requires_confirmation: boolean;
    is_related: boolean;
    source_product_name: string | null;
    source_product_sku: string | null;
    /** How this source came to be offered: exact product, sourcing group, or a confirmed link. */
    match_kind: 'exact' | 'group' | 'linked';
};

/**
 * What the order line froze about its fulfilment when it was placed: the
 * sourcing group and canonical product/variation, or unmatched for an order
 * placed before groups or without an explicit mapping.
 */
type Sourcing = {
    state: 'matched' | 'unmatched';
    group: {
        id: string;
        code: string;
        name_en: string;
        name_bn: string;
        is_active: boolean;
    } | null;
    canonical_product: { name: string; sku: string } | null;
    canonical_variant: string | null;
};

type SortKey = 'cost_asc' | 'cost_desc' | 'margin_desc' | 'margin_asc';
type Tab = 'recommended' | 'suppliers' | 'warehouses';

export type AllocationLine = {
    id: string;
    name: string;
    sku: string;
    /**
     * The specific existing active allocation this panel is reallocating,
     * when it is one (Advanced Order Management batch, Commit 3) — absent
     * when adding a brand-new split for still-unallocated units.
     */
    replacingAllocationId?: string;
    /** Defaults the quantity-to-allocate field; editable down, never up past what the line has left. */
    quantity?: number;
};

const controlClass =
    'border-input bg-background focus-visible:ring-ring rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * The staff source allocation panel.
 *
 * **Recommended / Already Related** is the historical exact-match candidate
 * list plus every source a confirmed {@see ConfirmRelationshipDialog} link
 * already covers. **All Suppliers** / **All Warehouses** search the whole
 * catalogue, server-paginated — a source found there that is not yet related
 * needs a confirmed relationship before it can be allocated at all.
 *
 * **The platform never picks.** Nothing here is pre-selected or defaulted to
 * the cheapest row — a member of staff reads the candidates and explicitly
 * confirms one.
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
    const { t, locale } = useTranslation();
    const [tab, setTab] = useState<Tab>('recommended');
    const [sourcing, setSourcing] = useState<Sourcing | null>(null);
    const [typeFilter, setTypeFilter] = useState<
        'all' | 'supplier_offer' | 'warehouse'
    >('all');
    const [recommended, setRecommended] = useState<Candidate[] | null>(null);
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState<SortKey>('margin_desc');
    const [eligibleOnly, setEligibleOnly] = useState(true);
    const [chosen, setChosen] = useState<Candidate | null>(null);
    const [linking, setLinking] = useState<Candidate | null>(null);
    const [remainingQuantity, setRemainingQuantity] = useState<number | null>(
        null,
    );

    useEffect(() => {
        if (!open || line === null) {
            setRecommended(null);
            setChosen(null);
            setLinking(null);
            setTab('recommended');
            setRemainingQuantity(null);
            setSourcing(null);
            setTypeFilter('all');

            return;
        }

        let cancelled = false;
        setRecommended(null);

        const url = new URL(
            OrderController.allocationCandidates.url({
                order: orderId,
                item: line.id,
            }),
            window.location.origin,
        );
        if (line.replacingAllocationId) {
            url.searchParams.set('replacing', line.replacingAllocationId);
        }

        fetch(url.toString(), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => response.json())
            .then(
                (body: {
                    candidates: Candidate[];
                    remaining_quantity: number;
                    sourcing: Sourcing;
                }) => {
                    if (cancelled) return;
                    setRecommended(body.candidates);
                    setSourcing(body.sourcing);
                    setRemainingQuantity(body.remaining_quantity);
                },
            )
            .catch(() => {
                if (!cancelled) setRecommended([]);
            });

        return () => {
            cancelled = true;
        };
    }, [open, line, orderId]);

    const filteredRecommended = useMemo(() => {
        if (recommended === null) return [];

        const term = search.trim().toLowerCase();
        const cost = (candidate: Candidate) =>
            Number(candidate.unit_cost.amount);
        const margin = (candidate: Candidate) =>
            Number(candidate.expected_margin.amount);

        return recommended
            .filter((candidate) => !eligibleOnly || candidate.is_eligible)
            .filter(
                (candidate) =>
                    typeFilter === 'all' ||
                    candidate.source_type === typeFilter,
            )
            .filter(
                (candidate) =>
                    term === '' ||
                    [
                        candidate.source_label,
                        candidate.supplier_name,
                        candidate.source_product_name,
                        candidate.source_product_sku,
                    ]
                        .filter(Boolean)
                        .join(' ')
                        .toLowerCase()
                        .includes(term),
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
    }, [recommended, search, sort, eligibleOnly, typeFilter]);

    function selectCandidate(candidate: Candidate) {
        if (candidate.is_related) {
            setChosen(candidate);
        } else {
            setLinking(candidate);
        }
    }

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

                    <div className="flex flex-wrap gap-1 px-4">
                        {(sourcing?.state === 'matched'
                            ? ([
                                  [
                                      'recommended',
                                      'orders.admin.allocation.tabs.recommended',
                                  ],
                              ] as const)
                            : ([
                                  [
                                      'recommended',
                                      'orders.admin.allocation.tabs.recommended',
                                  ],
                                  [
                                      'suppliers',
                                      'orders.admin.allocation.tabs.suppliers',
                                  ],
                                  [
                                      'warehouses',
                                      'orders.admin.allocation.tabs.warehouses',
                                  ],
                              ] as const)
                        ).map(([key, label]) => (
                            <Button
                                key={key}
                                type="button"
                                size="sm"
                                variant={tab === key ? 'default' : 'outline'}
                                onClick={() => setTab(key)}
                            >
                                {t(label)}
                            </Button>
                        ))}
                    </div>

                    <div className="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto px-4">
                        {tab === 'recommended' && (
                            <>
                                <SourcingBanner
                                    sourcing={sourcing}
                                    locale={locale}
                                />

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
                                        value={typeFilter}
                                        onChange={(event) =>
                                            setTypeFilter(
                                                event.target
                                                    .value as typeof typeFilter,
                                            )
                                        }
                                        className={controlClass}
                                        aria-label={t(
                                            'orders.admin.allocation.source_filter',
                                        )}
                                    >
                                        <option value="all">
                                            {t(
                                                'orders.admin.allocation.source_filter_all',
                                            )}
                                        </option>
                                        <option value="supplier_offer">
                                            {t(
                                                'status.allocation_source.supplier_offer',
                                            )}
                                        </option>
                                        <option value="warehouse">
                                            {t(
                                                'status.allocation_source.warehouse',
                                            )}
                                        </option>
                                    </select>
                                    <select
                                        value={sort}
                                        onChange={(event) =>
                                            setSort(
                                                event.target.value as SortKey,
                                            )
                                        }
                                        className={controlClass}
                                        aria-label={t(
                                            'orders.admin.allocation.sort',
                                        )}
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
                                            setEligibleOnly(
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    {t('orders.admin.allocation.eligible_only')}
                                </label>

                                {recommended === null && (
                                    <div className="flex justify-center py-8">
                                        <Spinner />
                                    </div>
                                )}

                                {recommended !== null &&
                                    filteredRecommended.length === 0 && (
                                        <p className="text-muted-foreground text-sm">
                                            {recommended.length === 0
                                                ? t(
                                                      'orders.admin.allocation.empty',
                                                  )
                                                : t(
                                                      'orders.admin.allocation.no_matches',
                                                  )}
                                        </p>
                                    )}

                                <ul className="space-y-2 pb-4">
                                    {filteredRecommended.map((candidate) => (
                                        <CandidateCard
                                            key={`${candidate.source_type}:${candidate.source_id}`}
                                            candidate={candidate}
                                            onSelect={() =>
                                                selectCandidate(candidate)
                                            }
                                        />
                                    ))}
                                </ul>
                            </>
                        )}

                        {tab !== 'recommended' && (
                            <CatalogueSearchList
                                orderId={orderId}
                                lineId={line.id}
                                sourceType={
                                    tab === 'suppliers'
                                        ? 'supplier_offer'
                                        : 'warehouse'
                                }
                                onSelect={selectCandidate}
                            />
                        )}
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

            <ConfirmRelationshipDialog
                orderId={orderId}
                line={line}
                candidate={linking}
                onOpenChange={(next) => {
                    if (!next) setLinking(null);
                }}
                onConfirmed={(candidate) => {
                    setLinking(null);
                    setChosen({ ...candidate, is_related: true });
                }}
            />

            <ConfirmAllocationDialog
                orderId={orderId}
                line={line}
                candidate={chosen}
                remainingQuantity={remainingQuantity}
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
 * One candidate row, shared by the Recommended list and the catalogue
 * search tabs — a "not yet related" candidate shows the relationship badge
 * and its confirm button reads differently, but is still the same click
 * target ({@see AllocationPanel.selectCandidate} decides what happens next).
 */
function CandidateCard({
    candidate,
    onSelect,
}: {
    candidate: Candidate;
    onSelect: () => void;
}) {
    const { t } = useTranslation();

    return (
        <li
            className={`border-border rounded-lg border p-3 ${candidate.is_eligible ? '' : 'opacity-60'}`}
        >
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="truncate font-medium">
                        {candidate.source_label}
                        {candidate.is_preferred && (
                            <span className="text-muted-foreground ms-2 text-xs font-normal">
                                {t('orders.admin.allocation.preferred')}
                            </span>
                        )}
                    </p>
                    <p className="text-muted-foreground text-xs">
                        {t(`status.allocation_source.${candidate.source_type}`)}
                        {' · '}
                        {candidate.buyer_facing_availability_label ? (
                            candidate.buyer_facing_availability_label
                        ) : (
                            <>
                                {t('orders.admin.allocation.columns.available')}
                                {': '}
                                {candidate.available_to_promise}
                            </>
                        )}
                    </p>
                    {candidate.source_type === 'supplier_offer' && (
                        <p className="text-muted-foreground text-xs">
                            {candidate.supply_mode_label}
                            {candidate.lead_time_days !== null &&
                                ` · ${t('orders.admin.allocation.lead_time', { days: candidate.lead_time_days })}`}
                            {candidate.fulfilment_capacity !== null &&
                                ` · ${t('orders.admin.allocation.capacity', { capacity: candidate.fulfilment_capacity })}`}
                        </p>
                    )}
                    {candidate.source_product_name && (
                        <p className="text-muted-foreground truncate text-xs">
                            {candidate.source_product_name}
                            {candidate.source_product_sku
                                ? ` · ${candidate.source_product_sku}`
                                : ''}
                        </p>
                    )}
                </div>
                <div className="flex shrink-0 flex-col items-end gap-1">
                    {candidate.is_currently_allocated && (
                        <StatusPill
                            tone="success"
                            label={t('orders.admin.allocation.current')}
                        />
                    )}
                    <StatusPill
                        tone={candidate.is_related ? 'success' : 'warning'}
                        label={t(
                            candidate.is_related
                                ? 'orders.admin.allocation.related'
                                : 'orders.admin.allocation.not_related',
                        )}
                    />
                    {candidate.match_kind === 'group' && (
                        <StatusPill
                            tone="info"
                            label={t('orders.admin.allocation.same_group')}
                        />
                    )}
                    {candidate.requires_confirmation && (
                        <StatusPill
                            tone="warning"
                            label={t(
                                'orders.admin.allocation.requires_confirmation',
                            )}
                        />
                    )}
                </div>
            </div>

            <div className="mt-2 grid grid-cols-3 gap-2">
                <div>
                    <p className="text-muted-foreground text-xs">
                        {t('orders.admin.allocation.columns.unit_cost')}
                    </p>
                    <MoneyAmount amount={candidate.unit_cost} />
                </div>
                <div>
                    <p className="text-muted-foreground text-xs">
                        {t('orders.admin.allocation.columns.platform_rate')}
                    </p>
                    <MoneyAmount amount={candidate.platform_rate} />
                </div>
                <div>
                    <p className="text-muted-foreground text-xs">
                        {t('orders.admin.allocation.columns.margin')}
                    </p>
                    <MoneyAmount amount={candidate.expected_margin} />
                </div>
            </div>

            {!candidate.is_eligible && candidate.ineligible_reason && (
                <p className="text-destructive mt-2 text-xs">
                    {candidate.ineligible_reason}
                </p>
            )}

            <div className="mt-2">
                <Button
                    type="button"
                    size="sm"
                    variant={
                        candidate.is_currently_allocated ? 'outline' : 'default'
                    }
                    disabled={!candidate.is_eligible}
                    onClick={onSelect}
                >
                    {candidate.is_currently_allocated
                        ? t('orders.admin.allocation.change_action')
                        : candidate.is_related
                          ? t('orders.admin.allocation.confirm')
                          : t('orders.admin.allocation.relationship_action')}
                </Button>
            </div>
        </li>
    );
}

/**
 * A server-paginated, searchable catalogue-wide list for one source type —
 * every approved active Supplier offer or every active warehouse stock
 * item, never just the ones already catalogued under this order's product.
 */
function CatalogueSearchList({
    orderId,
    lineId,
    sourceType,
    onSelect,
}: {
    orderId: string;
    lineId: string;
    sourceType: 'supplier_offer' | 'warehouse';
    onSelect: (candidate: Candidate) => void;
}) {
    const { t } = useTranslation();
    const [query, setQuery] = useState('');
    const [page, setPage] = useState(1);
    const [results, setResults] = useState<Candidate[]>([]);
    const [hasMore, setHasMore] = useState(false);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        setPage(1);
    }, [query, sourceType]);

    useEffect(() => {
        let cancelled = false;
        setLoading(true);

        const url = new URL(
            OrderController.searchAllocationSources.url({
                order: orderId,
                item: lineId,
            }),
            window.location.origin,
        );
        url.searchParams.set('source_type', sourceType);
        url.searchParams.set('page', String(page));
        if (query.trim() !== '') {
            url.searchParams.set('query', query.trim());
        }

        fetch(url.toString(), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => response.json())
            .then((body: { candidates: Candidate[]; has_more: boolean }) => {
                if (cancelled) return;
                setResults((previous) =>
                    page === 1
                        ? body.candidates
                        : [...previous, ...body.candidates],
                );
                setHasMore(body.has_more);
            })
            .catch(() => {
                if (!cancelled) {
                    setResults([]);
                    setHasMore(false);
                }
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });

        return () => {
            cancelled = true;
        };
    }, [orderId, lineId, sourceType, page, query]);

    return (
        <>
            <Input
                placeholder={t('orders.admin.allocation.catalogue_search')}
                value={query}
                onChange={(event) => setQuery(event.target.value)}
            />

            {loading && page === 1 && (
                <div className="flex justify-center py-8">
                    <Spinner />
                </div>
            )}

            {!loading && results.length === 0 && (
                <p className="text-muted-foreground text-sm">
                    {t('orders.admin.allocation.no_matches')}
                </p>
            )}

            <ul className="space-y-2 pb-4">
                {results.map((candidate) => (
                    <CandidateCard
                        key={`${candidate.source_type}:${candidate.source_id}`}
                        candidate={candidate}
                        onSelect={() => onSelect(candidate)}
                    />
                ))}
            </ul>

            {hasMore && (
                <Button
                    type="button"
                    variant="outline"
                    disabled={loading}
                    onClick={() => setPage((current) => current + 1)}
                >
                    {loading && <Spinner />}
                    {t('orders.admin.allocation.load_more')}
                </Button>
            )}
        </>
    );
}

/**
 * The explicit relationship-confirmation step for a catalogue-search result
 * that is not yet linked to this order's product — compares the ordered
 * item against the selected source item and requires a reason before
 * {@see ConfirmProductSourceLink} creates the durable link. Never allocates
 * by itself: confirming here only unlocks the normal allocate-confirm step.
 */
function ConfirmRelationshipDialog({
    orderId,
    line,
    candidate,
    onOpenChange,
    onConfirmed,
}: {
    orderId: string;
    line: AllocationLine;
    candidate: Candidate | null;
    onOpenChange: (open: boolean) => void;
    onConfirmed: (candidate: Candidate) => void;
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
                        {t('orders.admin.allocation.relationship_title', {
                            source: candidate.source_label,
                            product: line.name,
                        })}
                    </DialogTitle>
                    <DialogDescription>
                        {t('orders.admin.allocation.relationship_description')}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid grid-cols-2 gap-3 text-sm">
                    <div className="border-border rounded-lg border p-2">
                        <p className="text-muted-foreground text-xs">
                            {t(
                                'orders.admin.allocation.relationship_ordered_heading',
                            )}
                        </p>
                        <p className="font-medium">{line.name}</p>
                        <p className="text-muted-foreground text-xs">
                            {line.sku}
                        </p>
                    </div>
                    <div className="border-border rounded-lg border p-2">
                        <p className="text-muted-foreground text-xs">
                            {t(
                                'orders.admin.allocation.relationship_source_heading',
                            )}
                        </p>
                        <p className="font-medium">
                            {candidate.source_product_name ??
                                candidate.source_label}
                        </p>
                        <p className="text-muted-foreground text-xs">
                            {candidate.source_product_sku}
                        </p>
                    </div>
                </div>

                <Form
                    {...OrderController.confirmSourceLink.form({
                        order: orderId,
                        item: line.id,
                    })}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onConfirmed(candidate)}
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
                                <Label htmlFor="relationship-reason">
                                    {t(
                                        'orders.admin.allocation.relationship_reason',
                                    )}
                                </Label>
                                <textarea
                                    id="relationship-reason"
                                    name="reason"
                                    rows={3}
                                    minLength={10}
                                    maxLength={1000}
                                    required
                                    className={`${controlClass} w-full`}
                                />
                                <p className="text-muted-foreground text-xs">
                                    {t(
                                        'orders.admin.allocation.relationship_reason_help',
                                    )}
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
                                    {t(
                                        'orders.admin.allocation.relationship_confirm',
                                    )}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
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
    remainingQuantity,
    onOpenChange,
    onSuccess,
}: {
    orderId: string;
    line: AllocationLine;
    candidate: Candidate | null;
    remainingQuantity: number | null;
    onOpenChange: (open: boolean) => void;
    onSuccess: () => void;
}) {
    const { t } = useTranslation();
    const [acknowledged, setAcknowledged] = useState(false);
    const [quantity, setQuantity] = useState(line.quantity ?? 1);

    useEffect(() => {
        setAcknowledged(false);
        setQuantity(line.quantity ?? remainingQuantity ?? 1);
    }, [candidate, line.quantity, remainingQuantity]);

    if (candidate === null) {
        return null;
    }

    const needsAcknowledgement = candidate.requires_confirmation;
    const maxQuantity = remainingQuantity ?? line.quantity ?? 1;

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
                            <input
                                type="hidden"
                                name="quantity"
                                value={quantity}
                            />
                            {line.replacingAllocationId && (
                                <input
                                    type="hidden"
                                    name="replacing_allocation_id"
                                    value={line.replacingAllocationId}
                                />
                            )}

                            {maxQuantity > 1 && (
                                <div className="grid gap-2">
                                    <Label htmlFor="allocation-quantity">
                                        {t('orders.admin.allocation.quantity')}
                                    </Label>
                                    <Input
                                        id="allocation-quantity"
                                        type="number"
                                        min={1}
                                        max={maxQuantity}
                                        value={quantity}
                                        onChange={(event) =>
                                            setQuantity(
                                                Math.min(
                                                    maxQuantity,
                                                    Math.max(
                                                        1,
                                                        Number(
                                                            event.target.value,
                                                        ) || 1,
                                                    ),
                                                ),
                                            )
                                        }
                                        className="max-w-32"
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        {t(
                                            'orders.admin.allocation.quantity_help',
                                            { max: maxQuantity },
                                        )}
                                    </p>
                                    <InputError message={errors.quantity} />
                                </div>
                            )}

                            {needsAcknowledgement && (
                                <label className="bg-warning-subtle border-warning/30 flex items-start gap-2 rounded-lg border p-3 text-sm">
                                    <input
                                        type="checkbox"
                                        className="mt-0.5"
                                        checked={acknowledged}
                                        onChange={(event) =>
                                            setAcknowledged(
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    <span>
                                        {t(
                                            'orders.admin.allocation.confirmation_notice',
                                            {
                                                supply_mode:
                                                    candidate.supply_mode_label,
                                            },
                                        )}
                                    </span>
                                </label>
                            )}

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
                                <Button
                                    type="submit"
                                    disabled={
                                        processing ||
                                        (needsAcknowledgement && !acknowledged)
                                    }
                                >
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

/**
 * Why these sources are listed: the sourcing group the order line froze when
 * it was placed -- or a flag that it has none and needs manual review.
 */
function SourcingBanner({
    sourcing,
    locale,
}: {
    sourcing: Sourcing | null;
    locale: string;
}) {
    const { t } = useTranslation();

    if (sourcing === null) {
        return null;
    }

    if (sourcing.state === 'unmatched' || sourcing.group === null) {
        return (
            <div
                role="status"
                className="border-warning/40 bg-warning/10 rounded-lg border p-3 text-sm"
            >
                <p className="font-medium">
                    {t('orders.admin.allocation.unmatched_title')}
                </p>
                <p className="text-muted-foreground text-xs">
                    {t('orders.admin.allocation.unmatched_help')}
                </p>
            </div>
        );
    }

    return (
        <div role="status" className="bg-muted rounded-lg p-3 text-sm">
            <p className="font-medium">
                {t('orders.admin.allocation.group_title', {
                    group:
                        locale === 'bn'
                            ? sourcing.group.name_bn
                            : sourcing.group.name_en,
                })}
            </p>
            <p className="text-muted-foreground text-xs">
                {sourcing.canonical_product
                    ? `${sourcing.canonical_product.name} (${sourcing.canonical_product.sku})`
                    : ''}
                {sourcing.canonical_variant
                    ? ` · ${sourcing.canonical_variant}`
                    : ''}
            </p>
            <p className="text-muted-foreground text-xs">
                {t('orders.admin.allocation.group_help')}
            </p>
        </div>
    );
}
