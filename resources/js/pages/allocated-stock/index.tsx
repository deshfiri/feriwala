import { Head } from '@inertiajs/react';
import { PackageCheck } from 'lucide-react';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import EmptyState from '@/components/states/empty-state';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/allocated-stock';
import type { OwnAllocationRow } from '@/types/inventory';

type Props = {
    allocations: OwnAllocationRow[];
    total: number;
    can_trade: boolean;
};

/**
 * The central stock set aside for the reader's own business (§19, P3-30).
 *
 * Self-scoped through the membership — no identifier on this screen or in its
 * URL (§31.3) — and read-only: only Feriwala allocates or releases stock. Which
 * warehouse holds it is not shown, as it is not to any storefront.
 */
export default function AllocatedStockIndex({
    allocations,
    total,
    can_trade,
}: Props) {
    const { t, locale } = useTranslation();

    return (
        <>
            <Head title={t('inventory.allocated_stock.title')} />

            <PageContainer>
                <PageHeader
                    title={t('inventory.allocated_stock.title')}
                    description={t('inventory.allocated_stock.description')}
                />

                {!can_trade && allocations.length > 0 && (
                    <div
                        role="status"
                        className="border-warning bg-warning-subtle rounded-xl border p-4 text-sm"
                    >
                        {t('inventory.allocated_stock.cannot_trade')}
                    </div>
                )}

                <SectionCard
                    title={t('inventory.allocated_stock.caption')}
                    description={
                        allocations.length > 0
                            ? t('inventory.allocated_stock.total', {
                                  count: total,
                              })
                            : undefined
                    }
                    contentClassName={
                        allocations.length > 0 ? 'p-0' : undefined
                    }
                >
                    {allocations.length === 0 ? (
                        <EmptyState
                            icon={PackageCheck}
                            title={t('inventory.allocated_stock.empty')}
                            description={t(
                                'inventory.allocated_stock.empty_help',
                            )}
                        />
                    ) : (
                        <ul className="divide-border divide-y">
                            {allocations.map((row) => (
                                <li
                                    key={row.sku}
                                    className="flex flex-wrap items-start justify-between gap-3 px-5 py-3"
                                >
                                    <div className="min-w-0 space-y-0.5">
                                        <div className="truncate font-mono text-sm font-medium">
                                            {row.sku}
                                        </div>
                                        <div className="text-muted-foreground truncate text-xs">
                                            {row.product}
                                        </div>
                                    </div>
                                    <div className="space-y-0.5 text-right">
                                        <div className="text-sm font-semibold tabular-nums">
                                            {row.quantity}
                                            <span className="sr-only">
                                                {' '}
                                                {t(
                                                    'inventory.allocated_stock.columns.quantity',
                                                )}
                                            </span>
                                        </div>
                                        <div className="text-muted-foreground text-xs">
                                            {t(
                                                'inventory.allocated_stock.columns.updated',
                                            )}
                                            :{' '}
                                            {new Date(
                                                row.updated_at,
                                            ).toLocaleDateString(locale)}
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </PageContainer>
        </>
    );
}

AllocatedStockIndex.layout = {
    breadcrumbs: [
        {
            title: 'nav.allocated_stock',
            href: index(),
        },
    ],
};
