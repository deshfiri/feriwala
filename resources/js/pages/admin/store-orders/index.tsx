import { Head } from '@inertiajs/react';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import StoreOrderList from '@/components/store-order-list';
import type {
    StoreOrderFilters,
    StoreOrderRow,
} from '@/components/store-order-list';
import { useTranslation } from '@/hooks/use-translation';
import { show } from '@/routes/admin/store-orders';
import type { Paginator } from '@/types';

type Option = { value: string; label: string };

type Props = {
    orders: Paginator<StoreOrderRow>;
    filters: StoreOrderFilters;
    steps: Option[];
    payments: string[];
    partners: Option[];
    storefronts: Option[];
};

/** Every Partner's storefront orders, with the Partner and storefront named and four separate status badges. */
export default function AdminStoreOrders({
    orders,
    filters,
    steps,
    payments,
    partners,
    storefronts,
}: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('store_orders.title')} />
            <PageContainer>
                <PageHeader
                    title={t('store_orders.title')}
                    description={t('store_orders.admin_description')}
                />
                <StoreOrderList
                    orders={orders}
                    filters={filters}
                    steps={steps}
                    storefronts={storefronts}
                    partners={partners}
                    payments={payments}
                    forStaff
                    showUrl={(id) => show(id).url}
                />
            </PageContainer>
        </>
    );
}
