import { Link } from '@inertiajs/react';
import { ExternalLink, Pencil, Power, UserPlus } from 'lucide-react';
import { useState } from 'react';
import MoneyAmount from '@/components/money-amount';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { show as supplierShow } from '@/routes/admin/suppliers';
import type {
    ProductDetail,
    ProductSuppliersState,
    SupplierOfferRow,
} from '@/types';
import {
    AssignDialog,
    PriceDialog,
    StatusDialog,
} from './supplier-offer-dialogs';

type Props = {
    product: ProductDetail;
    suppliers: ProductSuppliersState;
};

/**
 * Which Suppliers hold this Product, and at what Supplier Base Price (Admin
 * Product workspace).
 *
 * Every row is a Supplier Offer, whether the Supplier listed the Product and
 * staff approved it or staff assigned it. The Supplier Base Price is what is
 * payable to the Supplier per unit; the Product's own platform prices are not
 * touched from here. The price is only present for staff who may see Supplier
 * pricing.
 */
export default function SuppliersSection({ product, suppliers }: Props) {
    const { t, locale } = useTranslation();
    const [assigning, setAssigning] = useState(false);
    const [editing, setEditing] = useState<SupplierOfferRow | null>(null);
    const [toggling, setToggling] = useState<SupplierOfferRow | null>(null);

    return (
        <SectionCard
            title={t('product_suppliers.section.title')}
            description={t('product_suppliers.section.description')}
            actions={
                suppliers.can.assign && (
                    <Button
                        type="button"
                        size="sm"
                        onClick={() => setAssigning(true)}
                    >
                        <UserPlus className="size-4" aria-hidden="true" />
                        {t('product_suppliers.section.assign')}
                    </Button>
                )
            }
        >
            {suppliers.offers.length === 0 ? (
                <p className="text-muted-foreground text-sm">
                    {t('product_suppliers.section.none')}
                </p>
            ) : (
                <ul className="space-y-3" data-test="product-supplier-offers">
                    {suppliers.offers.map((offer) => (
                        <li
                            key={offer.id}
                            className="space-y-3 rounded-lg border p-4"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="font-medium">
                                        {offer.supplier?.name}
                                    </p>
                                    <p className="text-muted-foreground text-xs">
                                        {offer.supplier?.reference}
                                        {offer.variant
                                            ? ` · ${offer.variant.label} (${offer.variant.sku})`
                                            : ''}
                                    </p>
                                </div>
                                <div className="flex flex-wrap items-center gap-1.5">
                                    <StatusPill
                                        tone={offer.status_tone}
                                        label={offer.status_label}
                                    />
                                    <StatusPill
                                        tone="neutral"
                                        label={offer.origin_label}
                                    />
                                </div>
                            </div>

                            <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                {suppliers.can.view_prices && (
                                    <Fact
                                        label={t(
                                            'product_suppliers.fields.base_price',
                                        )}
                                    >
                                        {offer.supplier_base_price && (
                                            <MoneyAmount
                                                amount={
                                                    offer.supplier_base_price
                                                }
                                                className="font-semibold"
                                            />
                                        )}
                                    </Fact>
                                )}
                                <Fact
                                    label={t(
                                        'product_suppliers.fields.supply_mode',
                                    )}
                                >
                                    {offer.supply_mode_label}
                                </Fact>
                                <Fact
                                    label={t(
                                        'product_suppliers.fields.availability',
                                    )}
                                >
                                    {offer.supply_mode === 'ready_stock'
                                        ? (offer.available_quantity ?? 0)
                                        : offer.fulfilment_capacity !== null
                                          ? t(
                                                'product_suppliers.fields.capacity',
                                                {
                                                    count: offer.fulfilment_capacity,
                                                },
                                            )
                                          : '—'}
                                </Fact>
                                <Fact
                                    label={t(
                                        'product_suppliers.fields.lead_time',
                                    )}
                                >
                                    {offer.lead_time_days === null
                                        ? '—'
                                        : t('product_suppliers.fields.days', {
                                              count: offer.lead_time_days,
                                          })}
                                </Fact>
                            </dl>

                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <p className="text-muted-foreground text-xs">
                                    {offer.assigned_by
                                        ? t('product_suppliers.assigned_by', {
                                              name: offer.assigned_by,
                                              date: new Date(
                                                  offer.assigned_at ?? '',
                                              ).toLocaleDateString(locale),
                                          })
                                        : t('product_suppliers.listed')}
                                    {' · '}
                                    {t('product_suppliers.updated', {
                                        date: new Date(
                                            offer.updated_at ?? '',
                                        ).toLocaleDateString(locale),
                                    })}
                                </p>
                                <div className="flex items-center gap-1">
                                    {offer.supplier && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={supplierShow.url(
                                                    offer.supplier.id,
                                                )}
                                            >
                                                <ExternalLink
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                                {t(
                                                    'product_suppliers.section.view',
                                                )}
                                            </Link>
                                        </Button>
                                    )}
                                    {suppliers.can.edit && (
                                        <>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setEditing(offer)
                                                }
                                            >
                                                <Pencil
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                                {t(
                                                    'product_suppliers.section.edit_price',
                                                )}
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setToggling(offer)
                                                }
                                            >
                                                <Power
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                                {offer.status === 'active'
                                                    ? t(
                                                          'product_suppliers.section.suspend',
                                                      )
                                                    : t(
                                                          'product_suppliers.section.reactivate',
                                                      )}
                                            </Button>
                                        </>
                                    )}
                                </div>
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            <AssignDialog
                open={assigning}
                onClose={() => setAssigning(false)}
                product={product}
                suppliers={suppliers}
            />
            <PriceDialog offer={editing} onClose={() => setEditing(null)} />
            <StatusDialog offer={toggling} onClose={() => setToggling(null)} />
        </SectionCard>
    );
}

function Fact({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}
