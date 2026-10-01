import {
    Banknote,
    Bell,
    Boxes,
    ClipboardList,
    Coins,
    CreditCard,
    LayoutGrid,
    Layers,
    MapPin,
    PackageCheck,
    PackageSearch,
    ShieldCheck,
    UserCog,
    Wallet,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import BrandingHead from '@/components/branding-head';
import { SupplierHeader } from '@/components/supplier/supplier-header';
import { SupplierSidebar } from '@/components/supplier/supplier-sidebar';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes/supplier';
import { index as addresses } from '@/routes/supplier/addresses';
import { index as allocations } from '@/routes/supplier/allocations';
import { create as kyc } from '@/routes/supplier/kyc';
import { index as listingLots } from '@/routes/supplier/listing-lots';
import { index as listings } from '@/routes/supplier/listings';
import { index as notifications } from '@/routes/supplier/notifications';
import { index as offers } from '@/routes/supplier/offers';
import { index as payables } from '@/routes/supplier/payables';
import { index as payoutMethods } from '@/routes/supplier/payout-methods';
import { edit as profile } from '@/routes/supplier/profile';
import { edit as security } from '@/routes/supplier/security';
import { index as stock } from '@/routes/supplier/stock';
import { show as wallet } from '@/routes/supplier/wallet';
import { index as withdrawals } from '@/routes/supplier/withdrawals';
import type { BreadcrumbItem as BreadcrumbItemType, NavGroup } from '@/types';

type SupplierAccount = {
    business_name: string;
    reference: string;
    status: string;
    status_label: string;
    operational: boolean;
    unread_notifications: number;
};

/**
 * The Supplier portal shell (D25). Same shared shell primitives
 * (`Sidebar`/`AppShell`/`AppContent`) and the same `NavMain` renderer the
 * Admin/Client ERP uses — one design system, not two — but every piece of
 * *data* behind it comes from the Supplier's own guard session
 * (`supplierAccount`, shared only for a `supplier` request) and its own
 * route list, never `useNavigation()`'s permission-scoped registry. A
 * Client/Partner account and a Supplier hold no navigation or session in
 * common by construction, not by convention this file could drift from.
 *
 * Operational entries (listings, products, rates, stock, payables, wallet,
 * payout methods, withdrawals) only appear once the Supplier is approved.
 * Hiding a link is a convenience — the server refuses the routes
 * regardless (`supplier.operational`).
 */
export default function SupplierLayout({
    children,
    breadcrumbs = [],
}: {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { t } = useTranslation();
    const page = usePage<{ supplierAccount?: SupplierAccount | null }>();
    const account = page.props.supplierAccount ?? null;

    const unreadNotifications = account?.unread_notifications ?? 0;

    const groups: NavGroup[] = [
        {
            label: t('supplier.nav_groups.overview'),
            items: [
                {
                    title: t('supplier.nav.dashboard'),
                    href: dashboard(),
                    icon: LayoutGrid,
                },
                {
                    title: t('supplier.nav.application'),
                    href: kyc(),
                    icon: ShieldCheck,
                },
            ],
        },
        {
            label: t('supplier.nav_groups.operations'),
            items: account?.operational
                ? [
                      {
                          title: t('supplier.nav.listings'),
                          href: listings(),
                          icon: ClipboardList,
                      },
                      {
                          title: t('supplier.nav.listing_lots'),
                          href: listingLots(),
                          icon: Layers,
                      },
                      /*
                       * One screen, not two: `supplier/offers` shows each
                       * approved product together with its own Supplier
                       * Rate (its page description says so), so "Approved
                       * products" and "Rates" used to sit here as separate
                       * rows pointed at the identical href -- two doors
                       * onto the same room. One row, kept under the more
                       * discoverable label.
                       */
                      {
                          title: t('supplier.nav.products'),
                          href: offers(),
                          icon: PackageCheck,
                      },
                      {
                          title: t('supplier.nav.stock'),
                          href: stock(),
                          icon: Boxes,
                      },
                      {
                          title: t('supplier.nav.allocations'),
                          href: allocations(),
                          icon: PackageSearch,
                      },
                      {
                          title: t('supplier.nav.addresses'),
                          href: addresses(),
                          icon: MapPin,
                      },
                  ]
                : [],
        },
        {
            label: t('supplier.nav_groups.finance'),
            items: account?.operational
                ? [
                      {
                          title: t('supplier.nav.payables'),
                          href: payables(),
                          icon: Coins,
                      },
                      {
                          title: t('supplier.nav.wallet'),
                          href: wallet(),
                          icon: Wallet,
                      },
                      {
                          title: t('supplier.nav.payout_methods'),
                          href: payoutMethods(),
                          icon: CreditCard,
                      },
                      {
                          title: t('supplier.nav.withdrawals'),
                          href: withdrawals(),
                          icon: Banknote,
                      },
                  ]
                : [],
        },
        {
            label: t('supplier.nav_groups.account'),
            items: [
                {
                    title: t('supplier.nav.notifications'),
                    href: notifications(),
                    icon: Bell,
                    badge:
                        unreadNotifications > 0
                            ? {
                                  label: String(unreadNotifications),
                                  tone: 'brand',
                              }
                            : undefined,
                },
                {
                    title: t('supplier.nav.profile'),
                    href: profile(),
                    icon: UserCog,
                },
                {
                    title: t('supplier.nav.security'),
                    href: security(),
                    icon: ShieldCheck,
                },
            ],
        },
    ];

    return (
        <>
            <BrandingHead />
            <a
                href="#supplier-main"
                className="bg-background focus:ring-ring sr-only rounded-md px-3 py-2 focus:not-sr-only focus:absolute focus:z-50 focus:ring-2"
            >
                {t('supplier.nav.skip')}
            </a>

            <AppShell>
                <SupplierSidebar groups={groups} account={account} />
                <AppContent
                    id="supplier-main"
                    variant="sidebar"
                    className="min-w-0"
                >
                    <SupplierHeader
                        breadcrumbs={breadcrumbs}
                        account={account}
                    />
                    {children}
                </AppContent>
            </AppShell>
        </>
    );
}
