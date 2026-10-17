import { Head } from '@inertiajs/react';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import StoreOrderList from '@/components/store-order-list';
import type {
    StoreOrderFilters,
    StoreOrderRow,
} from '@/components/store-order-list';
import { useTranslation } from '@/hooks/use-translation';
import { show } from '@/routes/store-orders';
import type { Paginator } from '@/types';

type Props = {
    orders: Paginator<StoreOrderRow>;
    filters: StoreOrderFilters;
    steps: { value: string; label: string }[];
    storefronts: { value: string; label: string }[];
};

/** A Partner's Store Orders: every order from their own storefronts, nothing else. */
export default function StoreOrders({
    orders,
    filters,
    steps,
    storefronts,
}: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('store_orders.title')} />
            <PageContainer>
                <PageHeader
                    title={t('store_orders.title')}
                    description={t('store_orders.partner_description')}
                />
                <StoreOrderList
                    orders={orders}
                    filters={filters}
                    steps={steps}
                    storefronts={storefronts}
                    forStaff={false}
                    showUrl={(id) => show(id).url}
                />
            </PageContainer>
        </>
    );
}
