import StatusPill from '@/components/status-pill';
import { useTranslation } from '@/hooks/use-translation';
import type { StockRow } from '@/types/inventory';

/**
 * Where a SKU in a warehouse stands, in words (§33.9).
 *
 * Out of stock outranks low stock, and "low" is the server's reading of the
 * threshold someone set — this never compares figures itself.
 */
export default function StockStatePill({
    row,
}: {
    row: Pick<StockRow, 'buckets' | 'is_low'>;
}) {
    const { t } = useTranslation();

    if (row.buckets.available === 0) {
        return (
            <StatusPill
                tone="danger"
                label={t('inventory.states.out_of_stock')}
            />
        );
    }

    if (row.is_low) {
        return (
            <StatusPill
                tone="warning"
                label={t('inventory.states.low_stock')}
            />
        );
    }

    return <StatusPill tone="success" label={t('inventory.states.in_stock')} />;
}
