import { usePage } from '@inertiajs/react';
import {
    Banknote,
    Boxes,
    Building2,
    ClipboardList,
    CreditCard,
    FolderTree,
    Globe,
    LayoutGrid,
    ListChecks,
    Lock,
    MapPin,
    MessageSquare,
    MonitorSmartphone,
    Network,
    Share2,
    Coins,
    Package as PackageIcon,
    PackageCheck,
    PackageSearch,
    Palette,
    Receipt,
    Scale,
    ScrollText,
    Settings,
    ShoppingBag,
    ShoppingBasket,
    ShoppingCart,
    SlidersHorizontal,
    Store,
    Tags,
    ShieldCheck,
    Truck,
    Undo2,
    UserCheck,
    Users,
    Wallet as WalletIcon,
    Warehouse,
} from 'lucide-react';
import { useTranslation } from '@/hooks/use-translation';
import { index as addresses } from '@/routes/addresses';
import { dashboard } from '@/routes';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as accountDirectory } from '@/routes/admin/accounts';
import { index as activationQueue } from '@/routes/admin/activations';
import { edit as brandingSettings } from '@/routes/admin/branding';
import { index as billingRules } from '@/routes/admin/billing';
import { index as depositRules } from '@/routes/admin/deposit-rules';
import { index as paymentGateways } from '@/routes/admin/gateways';
import { index as allocatedStock } from '@/routes/allocated-stock';
import { index as stockAllocations } from '@/routes/admin/inventory/allocations';
import { index as stockAvailability } from '@/routes/admin/inventory/availability';
import { index as stockReservations } from '@/routes/admin/inventory/reservations';
import { index as stockLevels } from '@/routes/admin/inventory/stock';
import { index as warehouses } from '@/routes/admin/inventory/warehouses';
import { index as kycQueue } from '@/routes/admin/kyc';
import { index as adminOrders } from '@/routes/admin/orders';
import { index as adminReturns } from '@/routes/admin/returns';
import { index as supplierAllocations } from '@/routes/admin/supplier-allocations';
import { index as supplierPayables } from '@/routes/admin/supplier-payables';
import { index as supplierApplications } from '@/routes/admin/suppliers';
import { index as supplierListings } from '@/routes/admin/supplier-listings';
import { index as supplierOffers } from '@/routes/admin/supplier-offers';
import { index as supplierStock } from '@/routes/admin/supplier-stock';
import { index as supplierWallets } from '@/routes/admin/supplier-wallets';
import { index as supplierWithdrawals } from '@/routes/admin/supplier-withdrawals';
import { index as paymentLog } from '@/routes/admin/payments';
import { index as smsSettings } from '@/routes/admin/sms';
import { index as accountWallets } from '@/routes/admin/wallets';
import { index as productAttributes } from '@/routes/admin/catalog/attributes';
import { index as dropshippingCatalogue } from '@/routes/catalog/dropshipping';
import { index as wholesaleCatalogue } from '@/routes/catalog/wholesale';
import { index as productBrands } from '@/routes/admin/catalog/brands';
import { index as productCatalogue } from '@/routes/admin/catalog/products';
import { index as productCategories } from '@/routes/admin/catalog/categories';
import { index as kycRequirements } from '@/routes/admin/kyc/document-types';
import { index as packageCatalogue } from '@/routes/admin/packages';
import { history as kycHistory } from '@/routes/kyc';
import { edit as profileSettings } from '@/routes/profile';
import { edit as securitySettings } from '@/routes/security';
import { index as staffDirectory } from '@/routes/staff';
import { show as subscription } from '@/routes/subscription';
import { show as wallet } from '@/routes/wallet';
import { show as wholesaleCart } from '@/routes/wholesale/cart';
import { index as wholesaleOrders } from '@/routes/wholesale/orders';
import { index as websites } from '@/routes/websites';
import { index as adminWebsites } from '@/routes/admin/websites';
import { index as websitePricing } from '@/routes/admin/website-pricing';
import { index as referralSettings } from '@/routes/admin/referral-settings';
import { index as referralCommissions } from '@/routes/admin/referral-commissions';
import { index as referrals } from '@/routes/referrals';
import type { NavGroup } from '@/types';

/**
 * The destinations this person can actually reach, grouped into sections.
 *
 * One source for both the sidebar and the command palette. Two hand-maintained
 * lists would drift, and the drift shows up as a page reachable by search but
 * missing from the navigation — or worse, the reverse.
 *
 * Gating here only hides a door; the policy on the route is what refuses. The
 * `sidebar` flag marks the groups that also belong in the rail, so settings
 * pages stay searchable without adding a fourth section nobody asked for.
 */
export function useNavigation(): {
    sidebarGroups: NavGroup[];
    searchGroups: NavGroup[];
} {
    const page = usePage();
    const { t } = useTranslation();

    const permissions = page.props.permissions ?? {};
    const account = page.props.account;

    const sidebarGroups: NavGroup[] = [
        {
            label: t('nav.groups.overview'),
            items: [
                {
                    title: t('nav.dashboard'),
                    /*
                     * A business identity gets the ERP dashboard; platform
                     * staff have no account (D23) and get their own overview
                     * instead — otherwise this link would 403 for them.
                     */
                    href: account ? dashboard() : adminDashboard(),
                    icon: LayoutGrid,
                },
            ],
        },
        {
            label: t('nav.groups.business'),
            /*
             * Only for someone who has a business. Every entry here is scoped to
             * the reader's own account, and platform staff have none — the links
             * would lead to a refusal, which is worse than their absence. The
             * group drops out entirely once it is empty.
             */
            items:
                account === null || account === undefined
                    ? []
                    : [
                          {
                              title: t('nav.verification'),
                              href: kycHistory(),
                              icon: ShieldCheck,
                          },
                          {
                              title: t('nav.subscription'),
                              href: subscription(),
                              icon: CreditCard,
                          },
                          /*
                           * The account's own money (§23, §33.7). Shown once
                           * the business is active, because that is when the
                           * wallet is opened — before it there is nothing to
                           * look at and the funnel gate would turn them back.
                           */
                          ...(account?.status === 'active'
                              ? [
                                    {
                                        title: t('nav.wallet'),
                                        href: wallet(),
                                        icon: WalletIcon,
                                    },
                                    {
                                        title: t('nav.addresses'),
                                        href: addresses(),
                                        icon: MapPin,
                                    },
                                ]
                              : []),
                          /*
                           * The central catalogue, one door per business method
                           * (§10). Separate entries because they are separate
                           * acts, and each appears only when the account's
                           * package includes it — the server refuses the rest
                           * regardless.
                           */
                          ...(account?.allowsWholesale
                              ? [
                                    {
                                        title: t('nav.wholesale_catalogue'),
                                        href: wholesaleCatalogue(),
                                        icon: ShoppingCart,
                                    },
                                    /*
                                     * The person's own wholesale cart (§14),
                                     * with how many products are in it, said in
                                     * the title rather than by a badge alone.
                                     */
                                    {
                                        title:
                                            (account?.wholesaleCartLines ?? 0) >
                                            0
                                                ? `${t('nav.wholesale_cart')} (${account?.wholesaleCartLines})`
                                                : t('nav.wholesale_cart'),
                                        href: wholesaleCart(),
                                        icon: ShoppingBasket,
                                    },
                                ]
                              : []),
                          /*
                           * The account's own wholesale orders (§10.2, P4-12).
                           * Still here after the package stops including
                           * wholesale, while there are orders to follow.
                           */
                          ...(account?.allowsWholesale ||
                          account?.hasWholesaleOrders
                              ? [
                                    {
                                        title: t('nav.wholesale_orders'),
                                        href: wholesaleOrders(),
                                        icon: ClipboardList,
                                    },
                                ]
                              : []),
                          ...(account?.allowsDropshipping
                              ? [
                                    {
                                        title: t('nav.dropshipping_catalogue'),
                                        href: dropshippingCatalogue(),
                                        icon: Store,
                                    },
                                ]
                              : []),
                          /*
                           * The account's own storefronts (§16, P5-8). Still
                           * here once the package stops including one, while a
                           * website is open: its owner has a status to act on
                           * and charges that may still be due.
                           */
                          ...(account?.allowsWebsites || account?.hasWebsites
                              ? [
                                    {
                                        title: t('nav.websites'),
                                        href: websites(),
                                        icon: Globe,
                                    },
                                ]
                              : []),
                          /*
                           * The business's own referral code, direct
                           * referrals and earnings (D24). Its owner's by
                           * default; nothing below the direct referrals.
                           */
                          ...(account?.viewsReferrals
                              ? [
                                    {
                                        title: t('nav.referrals'),
                                        href: referrals(),
                                        icon: Share2,
                                    },
                                ]
                              : []),
                          /*
                           * Central stock set aside for this business (§19,
                           * P3-30). Only while it holds some, rather than a
                           * door onto an empty list for every account.
                           */
                          ...(account?.holdsAllocatedStock
                              ? [
                                    {
                                        title: t('nav.allocated_stock'),
                                        href: allocatedStock(),
                                        icon: PackageCheck,
                                    },
                                ]
                              : []),
                          // A package with no staff facility shows no Staff door at all,
                          // rather than one that opens onto a refusal.
                          ...(account?.managesStaff
                              ? [
                                    {
                                        title: t('nav.staff'),
                                        href: staffDirectory(),
                                        icon: Users,
                                    },
                                ]
                              : []),
                      ],
        },
        {
            label: t('nav.groups.administration'),
            items: [
                ...(permissions['kyc.view']
                    ? [
                          {
                              title: t('nav.kyc_review'),
                              href: kycQueue(),
                              icon: ShieldCheck,
                          },
                      ]
                    : []),
                ...(permissions['kyc.manage_settings']
                    ? [
                          {
                              title: t('nav.kyc_requirements'),
                              href: kycRequirements(),
                              icon: ListChecks,
                          },
                      ]
                    : []),
                ...(permissions['package.view']
                    ? [
                          {
                              title: t('nav.packages'),
                              href: packageCatalogue(),
                              icon: PackageIcon,
                          },
                      ]
                    : []),
                /*
                 * The central catalogue (§11, §12). Behind `catalog.view`
                 * rather than a broader admin check: writing the catalogue is
                 * a platform privilege of its own, and a business account
                 * holds none of it.
                 */
                ...(permissions['catalog.view']
                    ? [
                          {
                              title: t('nav.products'),
                              href: productCatalogue(),
                              icon: ShoppingBag,
                          },
                          {
                              title: t('nav.product_categories'),
                              href: productCategories(),
                              icon: FolderTree,
                          },
                          {
                              title: t('nav.brands'),
                              href: productBrands(),
                              icon: Tags,
                          },
                          {
                              title: t('nav.attributes'),
                              href: productAttributes(),
                              icon: SlidersHorizontal,
                          },
                      ]
                    : []),
                /*
                 * Central stock and where it is held (§19). Its own
                 * permission: counting stock is not writing the catalogue,
                 * and a partner holds neither.
                 */
                ...(permissions['inventory.view']
                    ? [
                          {
                              title: t('nav.stock'),
                              href: stockLevels(),
                              icon: Boxes,
                          },
                          {
                              title: t('nav.warehouses'),
                              href: warehouses(),
                              icon: Warehouse,
                          },
                          {
                              title: t('nav.reservations'),
                              href: stockReservations(),
                              icon: Lock,
                          },
                          {
                              title: t('nav.availability'),
                              href: stockAvailability(),
                              icon: Globe,
                          },
                          {
                              title: t('nav.allocations'),
                              href: stockAllocations(),
                              icon: PackageCheck,
                          },
                      ]
                    : []),
                /*
                 * Every order, for the staff who review them (§18.4). Not the
                 * partner's own orders door above: that one needs an account,
                 * this one needs `order.view`, and neither implies the other.
                 */
                ...(permissions['order.view']
                    ? [
                          {
                              title: t('nav.orders'),
                              href: adminOrders(),
                              icon: ClipboardList,
                          },
                      ]
                    : []),
                /*
                 * The returns desk (P6-12): order staff who decide and count
                 * goods back in, and finance staff who decide, send or settle
                 * the money — each of whom works one of its steps.
                 */
                ...(permissions['order.view'] ||
                permissions['payment.approve'] ||
                permissions['payment.reverse_transaction']
                    ? [
                          {
                              title: t('nav.returns'),
                              href: adminReturns(),
                              icon: Undo2,
                          },
                      ]
                    : []),
                /*
                 * Every partner storefront (§16.3). Not the partner's own
                 * websites door above: that one needs an account, this one
                 * needs `website.view`, and neither implies the other.
                 */
                ...(permissions['website.view']
                    ? [
                          {
                              title: t('nav.partner_websites'),
                              href: adminWebsites(),
                              icon: MonitorSmartphone,
                          },
                      ]
                    : []),
                /*
                 * What partners may charge for what they sell (§15.1). Behind
                 * its own permission: reading a storefront and pricing the
                 * whole platform's selling bounds are different jobs.
                 */
                ...(permissions['website.manage_settings']
                    ? [
                          {
                              title: t('nav.website_pricing'),
                              href: websitePricing(),
                              icon: Scale,
                          },
                      ]
                    : []),
                /*
                 * Every referral commission on the platform, and the chains
                 * behind them (D24). A partner sees only their own, on their
                 * own page, and holds none of this.
                 */
                ...(permissions['referral.view']
                    ? [
                          {
                              title: t('nav.referral_commissions'),
                              href: referralCommissions(),
                              icon: Coins,
                          },
                      ]
                    : []),
                /*
                 * The multi-level referral configuration (D24). Reached by
                 * seeing it; changing it is a second permission the screen
                 * checks for itself.
                 */
                ...(permissions['referral.view_settings']
                    ? [
                          {
                              title: t('nav.referral_settings'),
                              href: referralSettings(),
                              icon: Network,
                          },
                      ]
                    : []),
                ...(permissions['account.view']
                    ? [
                          {
                              title: t('nav.accounts'),
                              href: accountDirectory(),
                              icon: Building2,
                          },
                          {
                              title: t('nav.activation_approvals'),
                              href: activationQueue(),
                              icon: UserCheck,
                          },
                      ]
                    : []),
                // Fee rules, coupons and tax: one destination, because they are
                // one decision about what an invoice says (§9).
                ...(permissions['payment.view']
                    ? [
                          {
                              title: t('nav.billing_rules'),
                              href: billingRules(),
                              icon: Receipt,
                          },
                          /*
                           * Separate from billing rules, because they are
                           * separate decisions: a fee rule changes an invoice,
                           * a gateway credential decides where the money lands
                           * (§26.4).
                           */
                          {
                              title: t('nav.payment_gateways'),
                              href: paymentGateways(),
                              icon: CreditCard,
                          },
                          /*
                           * Where the money and the records are compared —
                           * including the payments that arrived after their
                           * checkout closed and are waiting on a person (§42).
                           */
                          {
                              title: t('nav.payments'),
                              href: paymentLog(),
                              icon: ScrollText,
                          },
                      ]
                    : []),
                /*
                 * Account wallets and their ledgers (§23, §33.7). Its own
                 * permission rather than `payment.view`: reconciling what a
                 * gateway sent and reading what a business holds are different
                 * jobs, and the second is somebody else's money at rest.
                 */
                ...(permissions['wallet.view']
                    ? [
                          {
                              title: t('nav.wallets'),
                              href: accountWallets(),
                              icon: WalletIcon,
                          },
                          /*
                           * Separate from the wallets themselves: reading what
                           * one business holds and deciding what every business
                           * must hold are different jobs (§24.1).
                           */
                          {
                              title: t('nav.deposit_rules'),
                              href: depositRules(),
                              icon: Scale,
                          },
                      ]
                    : []),
                // Its own permission: whether customers hear about their own
                // payments is not the same decision as pricing them (§30).
                ...(permissions['sms.view']
                    ? [
                          {
                              title: t('nav.sms'),
                              href: smsSettings(),
                              icon: MessageSquare,
                          },
                      ]
                    : []),
                /*
                 * The Supplier account domain's staff screens (D25). Each is
                 * its own permission: seeing the listing queue never implies
                 * seeing a Supplier Rate, and the server refuses regardless.
                 */
                ...(permissions['supplier.view']
                    ? [
                          {
                              title: t('nav.suppliers'),
                              href: supplierApplications(),
                              icon: Truck,
                          },
                      ]
                    : []),
                ...(permissions['supplier_listing.view']
                    ? [
                          {
                              title: t('nav.supplier_listings'),
                              href: supplierListings(),
                              icon: ClipboardList,
                          },
                      ]
                    : []),
                ...(permissions['supplier_pricing.view']
                    ? [
                          {
                              title: t('nav.supplier_offers'),
                              href: supplierOffers(),
                              icon: Coins,
                          },
                      ]
                    : []),
                ...(permissions['supplier_stock.view']
                    ? [
                          {
                              title: t('nav.supplier_stock'),
                              href: supplierStock(),
                              icon: Boxes,
                          },
                      ]
                    : []),
                /*
                 * Every row here carries a Supplier Rate — gated the same as
                 * the offers screen, never `supplier_stock.view` alone (D25).
                 */
                ...(permissions['supplier_pricing.view']
                    ? [
                          {
                              title: t('nav.supplier_allocations'),
                              href: supplierAllocations(),
                              icon: PackageSearch,
                          },
                      ]
                    : []),
                ...(permissions['supplier_payable.view']
                    ? [
                          {
                              title: t('nav.supplier_payables'),
                              href: supplierPayables(),
                              icon: Coins,
                          },
                          {
                              title: t('nav.supplier_wallets'),
                              href: supplierWallets(),
                              icon: WalletIcon,
                          },
                      ]
                    : []),
                ...(permissions['withdrawal.view']
                    ? [
                          {
                              title: t('nav.supplier_withdrawals'),
                              href: supplierWithdrawals(),
                              icon: Banknote,
                          },
                      ]
                    : []),
                // The platform's logo and browser icon.
                ...(permissions['system.manage_settings']
                    ? [
                          {
                              title: t('nav.branding'),
                              href: brandingSettings(),
                              icon: Palette,
                          },
                      ]
                    : []),
            ],
        },
    ];

    const searchGroups: NavGroup[] = [
        ...sidebarGroups,
        {
            label: t('nav.groups.settings'),
            items: [
                {
                    title: t('nav.profile'),
                    href: profileSettings(),
                    icon: Settings,
                },
                {
                    title: t('nav.security'),
                    href: securitySettings(),
                    icon: ShieldCheck,
                },
            ],
        },
    ];

    return {
        sidebarGroups: sidebarGroups.filter((group) => group.items.length > 0),
        searchGroups: searchGroups.filter((group) => group.items.length > 0),
    };
}
