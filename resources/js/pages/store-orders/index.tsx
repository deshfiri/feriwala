import { Head } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import NewStoreOrderDialog from '@/components/new-store-order-dialog';
import type { NewStoreOrderUrls } from '@/components/new-store-order-dialog';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import StoreOrderList from '@/components/store-order-list';
import type {
    StoreOrderFilters,
    StoreOrderRow,
} from '@/components/store-order-list';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { show } from '@/routes/store-orders';
import type { Paginator } from '@/types';

type Props = {
    orders: Paginator<StoreOrderRow>;
    filters: StoreOrderFilters;
    steps: { value: string; label: string }[];
    storefronts: { value: string; label: string }[];
    /** The storefronts this person may take an order on; empty makes the popup say why there is none. */
    creatable_storefronts: { value: string; label: string }[];
    create_urls: NewStoreOrderUrls;
};

/** A Partner's Store Orders: every order from their own storefronts, nothing else. */
export default function StoreOrders({
    orders,
    filters,
    steps,
    storefronts,
    creatable_storefronts,
    create_urls,
}: Props) {
    const { t } = useTranslation();
    const [creating, setCreating] = useState(false);

    return (
        <>
            <Head title={t('store_orders.title')} />
            <PageContainer>
                <PageHeader
                    title={t('store_orders.title')}
                    description={t('store_orders.partner_description')}
                    actions={
                        <Button
                            size="sm"
                            onClick={() => setCreating(true)}
                            data-test="new-store-order"
                        >
                            <Plus aria-hidden="true" />
                            {t('store_orders.create.button')}
                        </Button>
                    }
                />
                <NewStoreOrderDialog
                    open={creating}
                    onOpenChange={setCreating}
                    storefronts={creatable_storefronts}
                    urls={create_urls}
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
