import type { ReactNode } from 'react';
import DetailList, { DetailItem } from '@/components/detail-list';
import SectionCard from '@/components/section-card';
import { useTranslation } from '@/hooks/use-translation';

/** {@see \App\Domain\Order\Queries\OrderCustomerDetails::of()} — the server's one reading of an order's customer. */
export type OrderCustomerDetails = {
    name: string | null;
    phone: string | null;
    email: string | null;
    business_name: string | null;
    is_guest: boolean | null;
    /** The delivery address as printable lines, already in order. */
    address: string[];
};

/**
 * Who an order is for and where it goes: the same card on every order page.
 *
 * The phone is a `tel:` link, so whoever is arranging the delivery can call
 * from a phone in one tap. A missing value says so rather than leaving a gap.
 */
export default function OrderCustomerCard({
    customer,
    actions,
}: {
    customer: OrderCustomerDetails;
    /** Shown in the card's header, such as staff's one Edit button. */
    actions?: ReactNode;
}) {
    const { t } = useTranslation();
    const missing = (
        <span className="text-muted-foreground">
            {t('orders.customer_card.not_given')}
        </span>
    );

    return (
        <SectionCard title={t('orders.customer_card.title')} actions={actions}>
            <DetailList columns={1}>
                {customer.business_name && (
                    <DetailItem label={t('orders.customer_card.business')}>
                        {customer.business_name}
                    </DetailItem>
                )}
                <DetailItem label={t('orders.customer_card.name')}>
                    {customer.name ?? missing}
                    {customer.is_guest && (
                        <span className="text-muted-foreground ms-2 text-xs">
                            {t('orders.customer_card.guest')}
                        </span>
                    )}
                </DetailItem>
                <DetailItem label={t('orders.customer_card.phone')}>
                    {customer.phone ? (
                        <a
                            href={`tel:${customer.phone}`}
                            className="font-mono underline-offset-4 hover:underline"
                        >
                            {customer.phone}
                        </a>
                    ) : (
                        missing
                    )}
                </DetailItem>
                {customer.email && (
                    <DetailItem label={t('orders.customer_card.email')}>
                        <a
                            href={`mailto:${customer.email}`}
                            className="break-all underline-offset-4 hover:underline"
                        >
                            {customer.email}
                        </a>
                    </DetailItem>
                )}
                <DetailItem label={t('orders.customer_card.address')}>
                    {customer.address.length > 0 ? (
                        <address className="not-italic">
                            {customer.address.map((line, index) => (
                                <span key={index} className="block">
                                    {line}
                                </span>
                            ))}
                        </address>
                    ) : (
                        missing
                    )}
                </DetailItem>
            </DetailList>
        </SectionCard>
    );
}
