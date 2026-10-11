import { Head, Link } from '@inertiajs/react';
import { ImageOff } from 'lucide-react';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import { index as stockIndex } from '@/routes/supplier/stock';
import type { SupplierOwnOffer } from '@/types';

type Offer = SupplierOwnOffer & {
    rate_history: { supplier_rate: Money; effective_from: string }[];
};

/**
 * One of the Supplier's own products. The Supplier Base Price and its history
 * are the Supplier's to see; the platform's prices are not, and cannot be
 * changed from here. Stock changes keep going through the existing stock
 * update workflow.
 */
export default function SupplierOfferShow({ offer }: { offer: Offer }) {
    const { t, locale } = useTranslation();

    const facts: [string, React.ReactNode][] = [
        [t('supplier.offers.bpc'), offer.bpc],
        [
            t('supplier.offers.variant'),
            offer.variant
                ? `${offer.variant.label} (${offer.variant.sku})`
                : '—',
        ],
        [t('supplier.offers.supply_mode'), offer.supply_mode_label],
        [
            t('supplier.offers.availability'),
            offer.supply_mode === 'ready_stock'
                ? (offer.available_quantity ?? 0).toLocaleString(locale)
                : offer.fulfilment_capacity !== null
                  ? t('supplier.offers.capacity', {
                        count: offer.fulfilment_capacity.toLocaleString(locale),
                    })
                  : '—',
        ],
        [
            t('supplier.offers.lead_time'),
            offer.lead_time_days === null
                ? '—'
                : t('supplier.offers.days', { count: offer.lead_time_days }),
        ],
        [t('supplier.offers.source'), offer.origin_label],
        [
            t('supplier.offers.updated'),
            offer.updated_at
                ? new Date(offer.updated_at).toLocaleDateString(locale)
                : '—',
        ],
    ];

    return (
        <>
            <Head title={offer.product_name} />

            <PageContainer width="narrow">
                <PageHeader
                    title={offer.product_name}
                    description={offer.reference}
                    actions={
                        <StatusPill
                            tone={
                                offer.status === 'active' ? 'success' : 'danger'
                            }
                            label={
                                offer.status === 'active'
                                    ? t('supplier.offers.state_active')
                                    : t('supplier.offers.state_suspended')
                            }
                        />
                    }
                />

                <SectionCard title={t('supplier.offers.rate')}>
                    <div className="flex flex-wrap items-start gap-4">
                        <div className="bg-muted flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-md border">
                            {offer.image ? (
                                <img
                                    src={offer.image.url}
                                    alt={offer.image.alt ?? offer.product_name}
                                    className="size-full object-cover"
                                />
                            ) : (
                                <ImageOff
                                    className="text-muted-foreground size-5"
                                    aria-label={t('supplier.offers.no_image')}
                                />
                            )}
                        </div>
                        <div className="space-y-1">
                            <MoneyAmount
                                amount={offer.supplier_rate}
                                size="large"
                            />
                            <p className="text-muted-foreground text-xs">
                                {t('supplier.offers.rate_help')}
                            </p>
                        </div>
                    </div>

                    <dl className="mt-4 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                        {facts.map(([label, value]) => (
                            <div key={label}>
                                <dt className="text-muted-foreground text-xs">
                                    {label}
                                </dt>
                                <dd>{value}</dd>
                            </div>
                        ))}
                    </dl>

                    {offer.supply_mode === 'ready_stock' && (
                        <Button
                            variant="outline"
                            size="sm"
                            className="mt-4"
                            asChild
                        >
                            <Link href={stockIndex()}>
                                {t('supplier.offers.manage_stock')}
                            </Link>
                        </Button>
                    )}
                </SectionCard>

                <SectionCard title={t('supplier.offers.rate_history')}>
                    <ul className="divide-border divide-y text-sm">
                        {offer.rate_history.map((change, position) => (
                            <li
                                key={position}
                                className="flex flex-wrap justify-between gap-2 py-2"
                            >
                                <MoneyAmount amount={change.supplier_rate} />
                                <span className="text-muted-foreground">
                                    {t('supplier.offers.effective_from')}{' '}
                                    {new Date(
                                        change.effective_from,
                                    ).toLocaleDateString(locale)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </SectionCard>
            </PageContainer>
        </>
    );
}
