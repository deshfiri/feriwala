import { Link, router, usePage } from '@inertiajs/react';
import {
    Banknote,
    Bell,
    Boxes,
    ClipboardList,
    Coins,
    CreditCard,
    LayoutGrid,
    LogOut,
    PackageCheck,
    PackageSearch,
    ShieldCheck,
    Truck,
    UserCog,
    Wallet,
} from 'lucide-react';
import type { ReactNode } from 'react';
import AppearanceToggleTab from '@/components/appearance-tabs';
import BrandingHead from '@/components/branding-head';
import LanguageSwitcher from '@/components/language-switcher';
import StatusPill from '@/components/status-pill';
import { useTranslation } from '@/hooks/use-translation';
import { cn, toUrl } from '@/lib/utils';
import { dashboard, logout } from '@/routes/supplier';
import { index as allocations } from '@/routes/supplier/allocations';
import { create as kyc } from '@/routes/supplier/kyc';
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
import type { NavItem } from '@/types';

type SupplierAccount = {
    business_name: string;
    reference: string;
    status: string;
    status_label: string;
    operational: boolean;
    unread_notifications: number;
};

/**
 * The Supplier portal shell (D25): its own header and navigation, visibly
 * separate from the Client/Partner ERP sidebar.
 *
 * Operational entries (listings, products, rates, stock) only appear once the
 * Supplier is approved. Hiding a link is a convenience — the server refuses
 * the routes regardless (`supplier.operational`).
 */
export default function SupplierLayout({ children }: { children: ReactNode }) {
    const { t } = useTranslation();
    const page = usePage<{ supplierAccount?: SupplierAccount | null }>();
    const account = page.props.supplierAccount ?? null;
    const currentPath =
        typeof window === 'undefined' ? '' : window.location.pathname;

    const unreadNotifications = account?.unread_notifications ?? 0;

    /*
     * Typed against the same `NavItem` the Admin/Staff and Client/Partner
     * sidebars use (§ shared navigation registry) — a flat top nav rather than
     * grouped, so `NavGroup` doesn't apply here, but the item shape (and the
     * badge convention: label + tone, never colour alone, §33.9) is the one
     * canonical shape across every portal.
     */
    const items: NavItem[] = [
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
        ...(account?.operational
            ? [
                  {
                      title: t('supplier.nav.listings'),
                      href: listings(),
                      icon: ClipboardList,
                  },
                  {
                      title: t('supplier.nav.products'),
                      href: offers(),
                      icon: PackageCheck,
                  },
                  {
                      title: t('supplier.nav.rates'),
                      href: offers(),
                      icon: Coins,
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
            : []),
        {
            title: t('supplier.nav.notifications'),
            href: notifications(),
            icon: Bell,
            badge:
                unreadNotifications > 0
                    ? { label: String(unreadNotifications), tone: 'brand' }
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

            <div className="bg-background text-foreground flex min-h-svh flex-col">
                <header className="bg-brand-subtle border-border border-b">
                    <div className="mx-auto flex max-w-6xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 sm:px-6">
                        <div className="flex min-w-0 items-center gap-2">
                            <Truck
                                className="text-brand size-5 shrink-0"
                                aria-hidden="true"
                            />
                            <div className="min-w-0">
                                <p className="text-sm leading-tight font-semibold">
                                    {t('supplier.portal_name')}
                                </p>
                                {account && (
                                    <p className="text-muted-foreground truncate text-xs">
                                        {account.business_name} ·{' '}
                                        {account.reference}
                                    </p>
                                )}
                            </div>
                        </div>

                        <div className="ms-auto flex flex-wrap items-center gap-2">
                            {account && (
                                <StatusPill
                                    tone={
                                        account.operational ? 'success' : 'info'
                                    }
                                    label={account.status_label}
                                />
                            )}
                            <LanguageSwitcher />
                            <AppearanceToggleTab />
                            <button
                                type="button"
                                onClick={() => router.post(logout().url)}
                                className="hover:bg-muted inline-flex items-center gap-1.5 rounded-md px-2 py-1.5 text-sm"
                            >
                                <LogOut className="size-4" aria-hidden="true" />
                                {t('supplier.nav.sign_out')}
                            </button>
                        </div>
                    </div>

                    <nav
                        aria-label={t('supplier.portal_name')}
                        className="mx-auto max-w-6xl overflow-x-auto px-4 sm:px-6"
                    >
                        <ul className="flex min-w-max gap-1 pb-2">
                            {items.map((item, index) => {
                                const href =
                                    typeof item.href === 'string'
                                        ? item.href
                                        : item.href.url;
                                const active = currentPath === href;

                                return (
                                    <li key={`${toUrl(item.href)}-${index}`}>
                                        <Link
                                            href={item.href}
                                            aria-current={
                                                active ? 'page' : undefined
                                            }
                                            className={cn(
                                                'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm whitespace-nowrap',
                                                active
                                                    ? 'bg-background font-medium shadow-xs'
                                                    : 'hover:bg-background/60 text-muted-foreground',
                                            )}
                                        >
                                            {item.icon && (
                                                <item.icon
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                            )}
                                            {item.title}
                                            {item.badge && (
                                                <span className="bg-brand text-brand-foreground rounded-full px-1.5 text-xs">
                                                    {item.badge.label}
                                                </span>
                                            )}
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </nav>
                </header>

                <main
                    id="supplier-main"
                    className="mx-auto w-full max-w-6xl flex-1 px-4 py-6 sm:px-6"
                >
                    {children}
                </main>
            </div>
        </>
    );
}
