import { Link } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import MoneyAmount from '@/components/money-amount';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { edit } from '@/routes/admin/catalog/products';
import type { SupplierProductsState } from '@/types';

type Props = {
    assigned: SupplierProductsState;
};

/**
 * The Products this Supplier holds (Admin Supplier page): every Supplier Offer,
 * whether the Supplier listed it or staff assigned it. Assigning is done from
 * the Product, where the Supplier Base Price is set against what it resells at.
 */
export default function AssignedProductsSection({ assigned }: Props) {
    const { t, locale } = useTranslation();

    return (
        <SectionCard
            title={t('product_suppliers.supplier_section.title')}
            description={t('product_suppliers.supplier_section.description')}
        >
            {assigned.offers.length === 0 ? (
                <p className="text-muted-foreground text-sm">
                    {t('product_suppliers.supplier_section.none')}
                </p>
            ) : (
                <ul className="divide-border divide-y rounded-md border">
                    {assigned.offers.map((offer) => (
                        <li
                            key={offer.id}
                            className="flex flex-wrap items-center justify-between gap-3 p-3"
                        >
                            <div className="min-w-0">
                                <p className="text-sm font-medium">
                                    {offer.product?.name}
                                </p>
                                <p className="text-muted-foreground text-xs">
                                    {offer.product?.bpc}
                                    {offer.variant
                                        ? ` · ${offer.variant.label} (${offer.variant.sku})`
                                        : ''}
                                    {' · '}
                                    {offer.origin_label}
                                    {' · '}
                                    {new Date(
                                        offer.updated_at ?? '',
                                    ).toLocaleDateString(locale)}
                                </p>
                            </div>
                            <div className="flex items-center gap-2">
                                {offer.supplier_base_price && (
                                    <MoneyAmount
                                        amount={offer.supplier_base_price}
                                        className="font-semibold"
                                    />
                                )}
                                <StatusPill
                                    tone={offer.status_tone}
                                    label={offer.status_label}
                                />
                                {offer.product && (
                                    <Button variant="ghost" size="sm" asChild>
                                        <Link href={edit.url(offer.product.id)}>
                                            <ExternalLink
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            {t(
                                                'product_suppliers.section.view_product',
                                            )}
                                        </Link>
                                    </Button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </SectionCard>
    );
}
