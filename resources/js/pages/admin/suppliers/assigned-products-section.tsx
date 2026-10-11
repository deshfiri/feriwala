import { Link, router } from '@inertiajs/react';
import { ExternalLink, PackagePlus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import ProductLinkController from '@/actions/App/Http/Controllers/Admin/ProductLinkController';
import ProductSupplierController from '@/actions/App/Http/Controllers/Admin/ProductSupplierController';
import ProductPickerSheet from '@/components/content-library/product-picker-sheet';
import SectionCard from '@/components/section-card';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { edit } from '@/routes/admin/catalog/products';
import type { ProductLinkSummary, SupplierProductsState } from '@/types';

type Props = {
    supplierId: string;
    assigned: SupplierProductsState;
};

/**
 * The Products this Supplier actually has (Admin Supplier page). Assigning
 * here is the same record as assigning from a Product's Suppliers tab.
 */
export default function AssignedProductsSection({
    supplierId,
    assigned,
}: Props) {
    const { t, locale } = useTranslation();
    const [picking, setPicking] = useState(false);
    const [selected, setSelected] = useState<ProductLinkSummary[]>([]);

    const assign = (products: ProductLinkSummary[]) => {
        setSelected(products);
    };

    const confirm = (open: boolean) => {
        setPicking(open);

        if (open || selected.length === 0) {
            return;
        }

        router.post(
            ProductSupplierController.store.url(),
            {
                product_ids: selected.map((product) => product.id),
                supplier_ids: [supplierId],
            },
            {
                preserveScroll: true,
                onFinish: () => setSelected([]),
            },
        );
    };

    return (
        <SectionCard
            title={t('product_suppliers.supplier_section.title')}
            description={t('product_suppliers.supplier_section.description')}
            actions={
                assigned.can.assign && (
                    <Button
                        type="button"
                        size="sm"
                        onClick={() => setPicking(true)}
                    >
                        <PackagePlus className="size-4" aria-hidden="true" />
                        {t('product_suppliers.supplier_section.assign')}
                    </Button>
                )
            }
        >
            {assigned.assigned.length === 0 ? (
                <p className="text-muted-foreground text-sm">
                    {t('product_suppliers.supplier_section.none')}
                </p>
            ) : (
                <ul className="divide-border divide-y rounded-md border">
                    {assigned.assigned.map((row) => (
                        <li
                            key={row.id}
                            className="flex flex-wrap items-center justify-between gap-3 p-3"
                        >
                            <div className="min-w-0">
                                <p className="text-sm font-medium">
                                    {row.product.name}
                                </p>
                                <p className="text-muted-foreground text-xs">
                                    {row.product.bpc} ·{' '}
                                    {new Date(
                                        row.assigned_at,
                                    ).toLocaleDateString(locale)}
                                </p>
                            </div>
                            <div className="flex items-center gap-1">
                                <Button variant="ghost" size="sm" asChild>
                                    <Link href={edit.url(row.product.id)}>
                                        <ExternalLink
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        {t('product_links.section.view')}
                                    </Link>
                                </Button>
                                {assigned.can.remove && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            router.delete(
                                                ProductSupplierController.destroy.url(
                                                    row.id,
                                                ),
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Trash2
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        {t('product_suppliers.section.remove')}
                                    </Button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            <ProductPickerSheet
                open={picking}
                onOpenChange={confirm}
                selected={selected}
                onChange={assign}
                searchUrl={ProductLinkController.browse.url()}
                excludeIds={assigned.assigned.map((row) => row.product.id)}
                title={t('product_suppliers.supplier_section.assign_title')}
                description={t(
                    'product_suppliers.supplier_section.assign_description',
                )}
            />
        </SectionCard>
    );
}
