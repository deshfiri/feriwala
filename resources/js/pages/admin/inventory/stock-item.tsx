import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, History, SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';
import DataTable from '@/components/data-table/data-table';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/admin/inventory/stock';
import type { Column, Paginator } from '@/types';
import {
    STOCK_BUCKETS,
    type AdjustmentKindOption,
    type StockBucket,
    type StockMovementRow,
    type StockRow,
} from '@/types/inventory';
import AdjustStockDialog from './adjust-stock-dialog';

type Props = {
    item: StockRow;
    movements: Paginator<StockMovementRow>;
    adjustment_kinds: AdjustmentKindOption[];
    can: { adjust: boolean };
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
    adjustment_kinds,
    can,
}: Props) {
    const { t, locale } = useTranslation();
    const [adjusting, setAdjusting] = useState(false);

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
                    actions={
                        item.buckets.available > 0 ? (
                            <StatusPill
                                tone="success"
                                label={t('inventory.states.in_stock')}
                            />
                        ) : (
                            <StatusPill
                                tone="warning"
                                label={t('inventory.states.out_of_stock')}
                            />
                        )
                    }
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
