import { Form, Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    BellRing,
    History,
    Lock,
    PackageCheck,
    SlidersHorizontal,
    Unlock,
} from 'lucide-react';
import { useState } from 'react';
import StockController from '@/actions/App/Http/Controllers/Admin/StockController';
import DataTable from '@/components/data-table/data-table';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/admin/inventory/stock';
import type { Column, Paginator } from '@/types';
import {
    STOCK_BUCKETS,
    type AccountOption,
    type AdjustmentKindOption,
    type StockAllocationRow,
    type StockBucket,
    type StockMovementRow,
    type StockReservationRow,
    type StockRow,
} from '@/types/inventory';
import AdjustStockDialog from './adjust-stock-dialog';
import AllocateStockDialog from './allocate-stock-dialog';
import ReleaseAllocationDialog from './release-allocation-dialog';
import StockStatePill from './stock-state-pill';

type Props = {
    item: StockRow;
    movements: Paginator<StockMovementRow>;
    reservations: StockReservationRow[];
    adjustment_kinds: AdjustmentKindOption[];
    allocations: StockAllocationRow[];
    accounts?: AccountOption[];
    can: { adjust: boolean; allocate: boolean };
};

/**
 * One SKU in one warehouse, and every movement that made its figures (§19,
 * P3-23).
 *
 * The history is the server's record, newest first, and nothing on this screen
 * can change it: a movement is never edited or removed, so there is no control
 * here that would suggest otherwise. Each row names the buckets that changed and
 * what they held on either side, so a figure can be traced back to its cause.
 */
export default function AdminStockItem({
    item,
    movements,
    reservations,
    adjustment_kinds,
    allocations,
    accounts,
    can,
}: Props) {
    const { t, locale } = useTranslation();
    const [adjusting, setAdjusting] = useState(false);
    const [allocating, setAllocating] = useState(false);
    const [releasing, setReleasing] = useState<StockAllocationRow | null>(null);

    const bucketLabel = (bucket: StockBucket | null) =>
        bucket === null ? '' : t(`inventory.buckets.${bucket}`);

    const describe = (row: StockMovementRow) => {
        if (row.from === null) {
            return t('inventory.item.arrived', {
                quantity: row.quantity,
                to: bucketLabel(row.to),
            });
        }

        if (row.to === null) {
            return t('inventory.item.left', {
                quantity: row.quantity,
                from: bucketLabel(row.from),
            });
        }

        return t('inventory.item.moved', {
            quantity: row.quantity,
            from: bucketLabel(row.from),
            to: bucketLabel(row.to),
        });
    };

    const changes = (row: StockMovementRow) => (
        <ul className="space-y-0.5 text-sm tabular-nums">
            {STOCK_BUCKETS.filter(
                (bucket) => row.before[bucket] !== row.after[bucket],
            ).map((bucket) => (
                <li key={bucket}>
                    <span className="text-muted-foreground">
                        {bucketLabel(bucket)}
                    </span>{' '}
                    {row.before[bucket]} → {row.after[bucket]}
                </li>
            ))}
        </ul>
    );

    const when = (row: StockMovementRow) =>
        new Date(row.occurred_at).toLocaleString(locale);

    const columns: Column<StockMovementRow>[] = [
        {
            key: 'occurred_at',
            header: t('inventory.item.columns.when'),
            cell: (row) => (
                <span className="text-sm whitespace-nowrap">{when(row)}</span>
            ),
        },
        {
            key: 'type',
            header: t('inventory.item.columns.type'),
            cell: (row) => <StatusPill tone="neutral" label={row.type_label} />,
        },
        {
            key: 'movement',
            header: t('inventory.item.columns.movement'),
            cell: (row) => <span className="text-sm">{describe(row)}</span>,
        },
        {
            key: 'change',
            header: t('inventory.item.columns.change'),
            cell: changes,
        },
        {
            key: 'reason',
            header: t('inventory.item.columns.reason'),
            priority: 'secondary',
            cell: (row) => (
                <span className="text-muted-foreground text-sm">
                    {row.reason ?? '—'}
                </span>
            ),
        },
        {
            key: 'actor',
            header: t('inventory.item.columns.actor'),
            priority: 'secondary',
            cell: (row) => (
                <span className="text-sm">
                    {row.actor ?? t('inventory.item.system')}
                </span>
            ),
        },
    ];

    return (
        <>
            <Head title={item.sku} />

            <PageContainer>
                <PageHeader
                    title={item.sku}
                    description={t('inventory.item.held_in', {
                        product: item.product.name,
                        warehouse: item.warehouse.name,
                        code: item.warehouse.code,
                    })}
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link href={index()}>
                                    <ArrowLeft
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t('inventory.item.back')}
                                </Link>
                            </Button>
                            {can.adjust && (
                                <Button onClick={() => setAdjusting(true)}>
                                    <SlidersHorizontal
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t('inventory.adjustments.open')}
                                </Button>
                            )}
                        </>
                    }
                />

                <SectionCard
                    title={t('inventory.item.figures')}
                    description={t('inventory.item.figures_description')}
                    actions={<StockStatePill row={item} />}
                >
                    <dl className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                        {STOCK_BUCKETS.map((bucket) => (
                            <div key={bucket}>
                                <dt className="text-muted-foreground text-xs">
                                    {bucketLabel(bucket)}
                                </dt>
                                <dd className="text-lg font-semibold tabular-nums">
                                    {item.buckets[bucket]}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </SectionCard>

                <SectionCard
                    title={t('inventory.thresholds.title')}
                    description={t('inventory.thresholds.description')}
                >
                    {can.adjust ? (
                        <Form
                            {...StockController.threshold.form(item.id)}
                            options={{ preserveScroll: true }}
                            className="grid gap-4 sm:grid-cols-[minmax(0,16rem)_auto] sm:items-start sm:justify-start"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="low-stock-threshold">
                                            {t('inventory.thresholds.label')}
                                        </Label>
                                        <Input
                                            id="low-stock-threshold"
                                            name="low_stock_threshold"
                                            type="number"
                                            inputMode="numeric"
                                            min={0}
                                            step={1}
                                            defaultValue={
                                                item.low_stock_threshold ?? ''
                                            }
                                            aria-invalid={
                                                errors.low_stock_threshold
                                                    ? true
                                                    : undefined
                                            }
                                        />
                                        <InputError
                                            message={errors.low_stock_threshold}
                                        />
                                    </div>

                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                        className="sm:mt-6"
                                    >
                                        {processing ? (
                                            <Spinner />
                                        ) : (
                                            <BellRing
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                        )}
                                        {t('inventory.thresholds.save')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    ) : (
                        <p className="text-sm">
                            {item.low_stock_threshold === null
                                ? t('inventory.thresholds.none')
                                : t('inventory.thresholds.current', {
                                      count: item.low_stock_threshold,
                                  })}
                        </p>
                    )}
                </SectionCard>

                <SectionCard
                    title={t('inventory.allocations.item_title')}
                    description={t('inventory.allocations.item_description')}
                    actions={
                        can.allocate ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setAllocating(true)}
                            >
                                <PackageCheck
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                {t('inventory.allocations.allocate')}
                            </Button>
                        ) : undefined
                    }
                    contentClassName={
                        allocations.length > 0 ? 'p-0' : undefined
                    }
                >
                    {allocations.length === 0 ? (
                        <EmptyState
                            icon={PackageCheck}
                            title={t('inventory.allocations.item_empty')}
                            description={t(
                                'inventory.allocations.item_empty_help',
                            )}
                        />
                    ) : (
                        <ul className="divide-border divide-y">
                            {allocations.map((allocation) => (
                                <li
                                    key={allocation.id}
                                    className="flex flex-wrap items-center justify-between gap-3 px-5 py-3"
                                >
                                    <div className="min-w-0 space-y-1">
                                        <div className="truncate text-sm font-medium">
                                            {allocation.account.name}
                                        </div>
                                        <div className="text-muted-foreground text-xs">
                                            {t(
                                                'inventory.allocations.changed',
                                                {
                                                    time: new Date(
                                                        allocation.updated_at,
                                                    ).toLocaleString(locale),
                                                },
                                            )}
                                        </div>
                                        {!allocation.account.can_trade && (
                                            <StatusPill
                                                tone="warning"
                                                label={t(
                                                    'inventory.allocations.account_cannot_trade',
                                                )}
                                            />
                                        )}
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <span className="text-sm font-semibold tabular-nums">
                                            {t('inventory.allocations.units', {
                                                count: allocation.quantity,
                                            })}
                                        </span>
                                        {can.allocate &&
                                            allocation.quantity > 0 && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        setReleasing(allocation)
                                                    }
                                                >
                                                    <Unlock
                                                        className="size-4"
                                                        aria-hidden="true"
                                                    />
                                                    {t(
                                                        'inventory.allocations.release',
                                                    )}
                                                </Button>
                                            )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>

                <SectionCard
                    title={t('inventory.item.reservations')}
                    description={t('inventory.item.reservations_description')}
                    contentClassName={
                        reservations.length > 0 ? 'p-0' : undefined
                    }
                >
                    {reservations.length === 0 ? (
                        <EmptyState
                            icon={Lock}
                            title={t('inventory.item.reservations_empty')}
                            description={t(
                                'inventory.item.reservations_empty_help',
                            )}
                        />
                    ) : (
                        <ul className="divide-border divide-y">
                            {reservations.map((reservation) => (
                                <li
                                    key={reservation.id}
                                    className="flex flex-wrap items-start justify-between gap-3 px-5 py-3"
                                >
                                    <div className="min-w-0 space-y-1">
                                        <div className="truncate font-mono text-sm">
                                            {reservation.reference}
                                        </div>
                                        <div className="text-muted-foreground text-xs">
                                            {t(
                                                'inventory.item.reservation_units',
                                                {
                                                    count: reservation.quantity,
                                                    kind: reservation.kind_label,
                                                },
                                            )}
                                        </div>
                                        {reservation.release_reason && (
                                            <div className="text-muted-foreground text-xs">
                                                {reservation.release_reason}
                                            </div>
                                        )}
                                    </div>
                                    <div className="space-y-1 text-right">
                                        <StatusPill
                                            tone={reservation.status_tone}
                                            label={reservation.status_label}
                                        />
                                        <div className="text-muted-foreground text-xs">
                                            {reservation.ended_at === null
                                                ? t('inventory.item.expires', {
                                                      time: new Date(
                                                          reservation.expires_at,
                                                      ).toLocaleString(locale),
                                                  })
                                                : t('inventory.item.ended', {
                                                      time: new Date(
                                                          reservation.ended_at,
                                                      ).toLocaleString(locale),
                                                  })}
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>

                <section className="space-y-3">
                    <div className="space-y-0.5">
                        <h2 className="text-sm font-semibold">
                            {t('inventory.item.history')}
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            {t('inventory.item.history_description')}
                        </p>
                    </div>

                    <DataTable
                        columns={columns}
                        paginator={movements}
                        rowKey={(row) => row.id}
                        searchable={false}
                        caption={t('inventory.item.history_caption')}
                        onlyReload={['movements']}
                        renderCard={(row) => (
                            <div className="space-y-2">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0 space-y-1">
                                        <StatusPill
                                            tone="neutral"
                                            label={row.type_label}
                                        />
                                        <p className="text-sm">
                                            {describe(row)}
                                        </p>
                                    </div>
                                    <span className="text-muted-foreground text-xs whitespace-nowrap">
                                        {when(row)}
                                    </span>
                                </div>
                                {changes(row)}
                                {row.reason && (
                                    <p className="text-muted-foreground text-sm">
                                        {row.reason}
                                    </p>
                                )}
                                <p className="text-muted-foreground text-xs">
                                    {row.actor ?? t('inventory.item.system')}
                                </p>
                            </div>
                        )}
                        emptyState={
                            <EmptyState
                                icon={History}
                                title={t('inventory.item.empty')}
                                description={t('inventory.item.empty_help')}
                            />
                        }
                    />
                </section>
            </PageContainer>

            {can.allocate && (
                <>
                    <AllocateStockDialog
                        itemId={item.id}
                        sku={item.sku}
                        available={item.buckets.available}
                        accounts={accounts}
                        open={allocating}
                        onClose={() => setAllocating(false)}
                    />
                    <ReleaseAllocationDialog
                        allocation={releasing}
                        sku={item.sku}
                        onClose={() => setReleasing(null)}
                    />
                </>
            )}

            {can.adjust && (
                <AdjustStockDialog
                    itemId={item.id}
                    buckets={item.buckets}
                    kinds={adjustment_kinds}
                    open={adjusting}
                    onClose={() => setAdjusting(false)}
                />
            )}
        </>
    );
}

AdminStockItem.layout = {
    breadcrumbs: [
        {
            title: 'Stock',
            href: index(),
        },
    ],
};
