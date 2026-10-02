import { usePage } from '@inertiajs/react';
import {
    Banknote,
    Boxes,
    Building2,
    ClipboardList,
    CreditCard,
    Globe,
    LayoutGrid,
    MapPin,
    MonitorSmartphone,
    Network,
    PackageCheck,
    Settings,
    Share2,
    ShieldCheck,
    ShoppingBag,
    ShoppingBasket,
    ShoppingCart,
    Store,
    Truck,
    Users,
    Wallet as WalletIcon,
    Weight,
} from 'lucide-react';

import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';
import { index as addresses } from '@/routes/addresses';
import { index as allocatedStock } from '@/routes/allocated-stock';
import {
    dashboard as adminDashboard,
    settings as settingsHub,
} from '@/routes/admin';
import { index as accountDirectory } from '@/routes/admin/accounts';
import { index as activationQueue } from '@/routes/admin/activations';
import { index as accountWithdrawals } from '@/routes/admin/account-withdrawals';
import { edit as brandingSettings } from '@/routes/admin/branding';
import { index as billingRules } from '@/routes/admin/billing';
import { index as productAttributes } from '@/routes/admin/catalog/attributes';
import { index as productBrands } from '@/routes/admin/catalog/brands';
import { index as productCategories } from '@/routes/admin/catalog/categories';
import { index as productCatalogue } from '@/routes/admin/catalog/products';
import { index as deliverySettings } from '@/routes/admin/delivery-settings';
import { index as depositRules } from '@/routes/admin/deposit-rules';
import { index as paymentGateways } from '@/routes/admin/gateways';
import { index as stockAllocations } from '@/routes/admin/inventory/allocations';
import { index as stockAvailability } from '@/routes/admin/inventory/availability';
import { index as stockReservations } from '@/routes/admin/inventory/reservations';
import { index as stockLevels } from '@/routes/admin/inventory/stock';
import { index as warehouses } from '@/routes/admin/inventory/warehouses';
import { index as kycQueue } from '@/routes/admin/kyc';
import { index as kycRequirements } from '@/routes/admin/kyc/document-types';
import { index as adminOrders } from '@/routes/admin/orders';
import { index as packageCatalogue } from '@/routes/admin/packages';
import { index as paymentLog } from '@/routes/admin/payments';
import { index as referralCommissions } from '@/routes/admin/referral-commissions';
import { index as referralSettings } from '@/routes/admin/referral-settings';
import { index as adminReturns } from '@/routes/admin/returns';
import { index as roles } from '@/routes/admin/roles';
import { index as shipments } from '@/routes/admin/shipments';
import { index as smsSettings } from '@/routes/admin/sms';
import { index as platformStaffIndex } from '@/routes/admin/staff';
import { index as supplierAllocations } from '@/routes/admin/supplier-allocations';
import { index as supplierListingLots } from '@/routes/admin/supplier-listing-lots';
import { index as supplierListings } from '@/routes/admin/supplier-listings';
import { index as supplierOffers } from '@/routes/admin/supplier-offers';
import { index as supplierPayables } from '@/routes/admin/supplier-payables';
import { index as supplierStock } from '@/routes/admin/supplier-stock';
import { index as supplierApplications } from '@/routes/admin/suppliers';
import { index as supplierWallets } from '@/routes/admin/supplier-wallets';
import { index as supplierWithdrawals } from '@/routes/admin/supplier-withdrawals';
import { index as accountWallets } from '@/routes/admin/wallets';
import { index as adminWebsites } from '@/routes/admin/websites';
import { index as websitePricing } from '@/routes/admin/website-pricing';
import { index as dropshippingCatalogue } from '@/routes/catalog/dropshipping';
import { index as wholesaleCatalogue } from '@/routes/catalog/wholesale';
import { history as kycHistory } from '@/routes/kyc';
import { index as payoutMethods } from '@/routes/payout-methods';
import { edit as profileSettings } from '@/routes/profile';
import { index as referrals } from '@/routes/referrals';
import { edit as securitySettings } from '@/routes/security';
import { index as staffDirectory } from '@/routes/staff';
import { show as subscription } from '@/routes/subscription';
import { show as wallet } from '@/routes/wallet';
import { index as websites } from '@/routes/websites';
import { show as wholesaleCart } from '@/routes/wholesale/cart';
import { index as wholesaleOrders } from '@/routes/wholesale/orders';
import { index as clientWithdrawals } from '@/routes/withdrawals';
import type { NavGroup, NavItem } from '@/types';

/**
 * Flatten nested sidebar branches for command-palette search.
 * The sidebar can stay grouped while every real destination remains searchable.
 */
function flattenNavItems(items: NavItem[]): NavItem[] {
    return items.flatMap((item) => {
        if (!item.items || item.items.length === 0) {
            return [item];
        }

        return item.items.flatMap((child) =>
            child.items && child.items.length > 0
                ? flattenNavItems([child])
                : [child],
        );
    });
}

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
                    href: account ? dashboard() : adminDashboard(),
                    icon: LayoutGrid,
                },
            ],
        },
        {
            label: t('nav.groups.business'),
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
                                    {
                                        title: t('payout.nav.payout_methods'),
                                        href: payoutMethods(),
                                        icon: CreditCard,
                                    },
                                    {
                                        title: t('withdrawal.erp.title'),
                                        href: clientWithdrawals(),
                                        icon: Banknote,
                                    },
                                ]
                              : []),
                          ...(account?.allowsWholesale
                              ? [
                                    {
                                        title: t('nav.wholesale_catalogue'),
                                        href: wholesaleCatalogue(),
                                        icon: ShoppingCart,
                                    },
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
                          ...(account?.allowsWebsites || account?.hasWebsites
                              ? [
                                    {
                                        title: t('nav.websites'),
                                        href: websites(),
                                        icon: Globe,
                                    },
                                ]
                              : []),
                          ...(account?.viewsReferrals
                              ? [
                                    {
                                        title: t('nav.referrals'),
                                        href: referrals(),
                                        icon: Share2,
                                    },
                                ]
                              : []),
                          ...(account?.holdsAllocatedStock
                              ? [
                                    {
                                        title: t('nav.allocated_stock'),
                                        href: allocatedStock(),
                                        icon: PackageCheck,
                                    },
                                ]
                              : []),
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
                ...(permissions['payment.view'] ||
                permissions['wallet.view'] ||
                permissions['withdrawal.view'] ||
                permissions['website.manage_settings'] ||
                permissions['referral.view_settings'] ||
                permissions['package.view'] ||
                permissions['kyc.manage_settings'] ||
                permissions['sms.view'] ||
                permissions['system.manage_settings']
                    ? [
                          {
                              title: t('nav.settings_hub'),
                              href: settingsHub(),
                              icon: Settings,
                          },
                      ]
                    : []),

                ...(permissions['access.view'] ||
                permissions['kyc.view'] ||
                permissions['kyc.manage_settings'] ||
                permissions['package.view']
                    ? [
                          {
                              title: 'Access & KYC',
                              href: permissions['access.view']
                                  ? roles()
                                  : permissions['kyc.view']
                                    ? kycQueue()
                                    : permissions['kyc.manage_settings']
                                      ? kycRequirements()
                                      : packageCatalogue(),
                              icon: ShieldCheck,
                              items: [
                                  ...(permissions['access.view']
                                      ? [
                                            {
                                                title: t('nav.roles'),
                                                href: roles(),
                                            },
                                            {
                                                title: t('nav.platform_staff'),
                                                href: platformStaffIndex(),
                                            },
                                        ]
                                      : []),
                                  ...(permissions['kyc.view']
                                      ? [
                                            {
                                                title: t('nav.kyc_review'),
                                                href: kycQueue(),
                                            },
                                        ]
                                      : []),
                                  ...(permissions['kyc.manage_settings']
                                      ? [
                                            {
                                                title: t(
                                                    'nav.kyc_requirements',
                                                ),
                                                href: kycRequirements(),
                                            },
                                        ]
                                      : []),
                                  ...(permissions['package.view']
                                      ? [
                                            {
                                                title: t('nav.packages'),
                                                href: packageCatalogue(),
                                            },
                                        ]
                                      : []),
                              ],
                          },
                      ]
                    : []),

                ...(permissions['catalog.view']
                    ? [
                          {
                              title: 'Catalogue',
                              href: productCatalogue(),
                              icon: ShoppingBag,
                              items: [
                                  {
                                      title: t('nav.products'),
                                      href: productCatalogue(),
                                  },
                                  {
                                      title: t('nav.product_categories'),
                                      href: productCategories(),
                                  },
                                  {
                                      title: t('nav.brands'),
                                      href: productBrands(),
                                  },
                                  {
                                      title: t('nav.attributes'),
                                      href: productAttributes(),
                                  },
                              ],
                          },
                      ]
                    : []),

                ...(permissions['inventory.view']
                    ? [
                          {
                              title: 'Inventory',
                              href: stockLevels(),
                              icon: Boxes,
                              items: [
                                  {
                                      title: t('nav.stock'),
                                      href: stockLevels(),
                                  },
                                  {
                                      title: t('nav.warehouses'),
                                      href: warehouses(),
                                  },
                                  {
                                      title: t('nav.reservations'),
                                      href: stockReservations(),
                                  },
                                  {
                                      title: t('nav.availability'),
                                      href: stockAvailability(),
                                  },
                                  {
                                      title: t('nav.allocations'),
                                      href: stockAllocations(),
                                  },
                              ],
                          },
                      ]
                    : []),

                ...(permissions['order.view'] ||
                permissions['payment.approve'] ||
                permissions['payment.reverse_transaction']
                    ? [
                          {
                              title: 'Orders',
                              href: permissions['order.view']
                                  ? adminOrders()
                                  : adminReturns(),
                              icon: ClipboardList,
                              items: [
                                  ...(permissions['order.view']
                                      ? [
                                            {
                                                title: t('nav.orders'),
                                                href: adminOrders(),
                                            },
                                        ]
                                      : []),
                                  ...(permissions['order.view'] ||
                                  permissions['payment.approve'] ||
                                  permissions['payment.reverse_transaction']
                                      ? [
                                            {
                                                title: t('nav.returns'),
                                                href: adminReturns(),
                                            },
                                        ]
                                      : []),
                              ],
                          },
                      ]
                    : []),

                ...(permissions['website.view'] ||
                permissions['website.manage_settings']
                    ? [
                          {
                              title: 'Websites',
                              href: permissions['website.view']
                                  ? adminWebsites()
                                  : websitePricing(),
                              icon: MonitorSmartphone,
                              items: [
                                  ...(permissions['website.view']
                                      ? [
                                            {
                                                title: t(
                                                    'nav.partner_websites',
                                                ),
                                                href: adminWebsites(),
                                            },
                                        ]
                                      : []),
                                  ...(permissions['website.manage_settings']
                                      ? [
                                            {
                                                title: t('nav.website_pricing'),
                                                href: websitePricing(),
                                            },
                                        ]
                                      : []),
                              ],
                          },
                      ]
                    : []),

                ...(permissions['referral.view'] ||
                permissions['referral.view_settings']
                    ? [
                          {
                              title: 'Referrals',
                              href: permissions['referral.view']
                                  ? referralCommissions()
                                  : referralSettings(),
                              icon: Network,
                              items: [
                                  ...(permissions['referral.view']
                                      ? [
                                            {
                                                title: t(
                                                    'nav.referral_commissions',
                                                ),
                                                href: referralCommissions(),
                                            },
                                        ]
                                      : []),
                                  ...(permissions['referral.view_settings']
                                      ? [
                                            {
                                                title: t(
                                                    'nav.referral_settings',
                                                ),
                                                href: referralSettings(),
                                            },
                                        ]
                                      : []),
                              ],
                          },
                      ]
                    : []),

                ...(permissions['account.view']
                    ? [
                          {
                              title: 'Accounts',
                              href: accountDirectory(),
                              icon: Building2,
                              items: [
                                  {
                                      title: t('nav.accounts'),
                                      href: accountDirectory(),
                                  },
                                  {
                                      title: t('nav.activation_approvals'),
                                      href: activationQueue(),
                                  },
                              ],
                          },
                      ]
                    : []),

                ...(permissions['payment.view'] ||
                permissions['wallet.view'] ||
                permissions['withdrawal.view']
                    ? [
                          {
                              title: 'Finance',
                              href: permissions['payment.view']
                                  ? paymentLog()
                                  : permissions['wallet.view']
                                    ? accountWallets()
                                    : accountWithdrawals(),
                              icon: Banknote,
                              items: [
                                  ...(permissions['payment.view']
                                      ? [
                                            {
                                                title: t('nav.billing_rules'),
                                                href: billingRules(),
                                            },
                                            {
                                                title: t(
                                                    'nav.payment_gateways',
                                                ),
                                                href: paymentGateways(),
                                            },
                                            {
                                                title: t('nav.payments'),
                                                href: paymentLog(),
                                            },
                                        ]
                                      : []),
                                  ...(permissions['wallet.view']
                                      ? [
                                            {
                                                title: t('nav.wallets'),
                                                href: accountWallets(),
                                            },
                                            {
                                                title: t('nav.deposit_rules'),
                                                href: depositRules(),
                                            },
                                        ]
                                      : []),
                                  ...(permissions['withdrawal.view']
                                      ? [
                                            {
                                                title: t(
                                                    'nav.account_withdrawals',
                                                ),
                                                href: accountWithdrawals(),
                                            },
                                        ]
                                      : []),
                              ],
                          },
                      ]
                    : []),

                ...(permissions['supplier.view'] ||
                permissions['supplier_listing.view'] ||
                permissions['supplier_pricing.view'] ||
                permissions['supplier_stock.view'] ||
                permissions['supplier_payable.view'] ||
                permissions['withdrawal.view']
                    ? [
                          {
                              title: 'Suppliers',
                              href: permissions['supplier.view']
                                  ? supplierApplications()
                                  : permissions['supplier_listing.view']
                                    ? supplierListings()
                                    : permissions['supplier_pricing.view']
                                      ? supplierOffers()
                                      : permissions['supplier_stock.view']
                                        ? supplierStock()
                                        : permissions['supplier_payable.view']
                                          ? supplierPayables()
                                          : supplierWithdrawals(),
                              icon: Truck,
                              items: [
                                  ...(permissions['supplier.view']
                                      ? [
                                            {
                                                title: t('nav.suppliers'),
                                                href: supplierApplications(),
                                            },
                                        ]
                                      : []),
                                  ...(permissions['supplier_listing.view']
                                      ? [
                                            {
                                                title: t(
                                                    'nav.supplier_listings',
                                                ),
                                                href: supplierListings(),
                                            },
                                            {
                                                title: t(
                                                    'nav.supplier_listing_lots',
                                                ),
                                                href: supplierListingLots(),
                                            },
                                        ]
                                      : []),
                                  ...(permissions['supplier_pricing.view']
                                      ? [
                                            {
                                                title: t('nav.supplier_offers'),
                                                href: supplierOffers(),
                                            },
                                            {
                                                title: t(
                                                    'nav.supplier_allocations',
                                                ),
                                                href: supplierAllocations(),
                                            },
                                        ]
                                      : []),
                                  ...(permissions['supplier_stock.view']
                                      ? [
                                            {
                                                title: t('nav.supplier_stock'),
                                                href: supplierStock(),
                                            },
                                        ]
                                      : []),
                                  ...(permissions['supplier_payable.view']
                                      ? [
                                            {
                                                title: t(
                                                    'nav.supplier_payables',
                                                ),
                                                href: supplierPayables(),
                                            },
                                            {
                                                title: t(
                                                    'nav.supplier_wallets',
                                                ),
                                                href: supplierWallets(),
                                            },
                                        ]
                                      : []),
                                  ...(permissions['withdrawal.view']
                                      ? [
                                            {
                                                title: t(
                                                    'nav.supplier_withdrawals',
                                                ),
                                                href: supplierWithdrawals(),
                                            },
                                        ]
                                      : []),
                              ],
                          },
                      ]
                    : []),

                ...(permissions['courier.view'] ||
                permissions['delivery_settings.view']
                    ? [
                          {
                              title: 'Shipments & Delivery',
                              href: permissions['courier.view']
                                  ? shipments()
                                  : deliverySettings(),
                              icon: PackageCheck,
                              items: [
                                  ...(permissions['courier.view']
                                      ? [
                                            {
                                                title: t('nav.shipments'),
                                                href: shipments(),
                                            },
                                        ]
                                      : []),
                                  ...(permissions['delivery_settings.view']
                                      ? [
                                            {
                                                title: t(
                                                    'nav.delivery_settings',
                                                ),
                                                href: deliverySettings(),
                                                icon: Weight,
                                            },
                                        ]
                                      : []),
                              ],
                          },
                      ]
                    : []),

                ...(permissions['sms.view'] ||
                permissions['system.manage_settings']
                    ? [
                          {
                              title: 'System',
                              href: permissions['sms.view']
                                  ? smsSettings()
                                  : brandingSettings(),
                              icon: Settings,
                              items: [
                                  ...(permissions['sms.view']
                                      ? [
                                            {
                                                title: t('nav.sms'),
                                                href: smsSettings(),
                                            },
                                        ]
                                      : []),
                                  ...(permissions['system.manage_settings']
                                      ? [
                                            {
                                                title: t('nav.branding'),
                                                href: brandingSettings(),
                                            },
                                        ]
                                      : []),
                              ],
                          },
                      ]
                    : []),
            ],
        },
    ];

    const visibleSidebarGroups = sidebarGroups.filter(
        (group) => group.items.length > 0,
    );

    const searchGroups: NavGroup[] = [
        ...visibleSidebarGroups.map((group) => ({
            ...group,
            items: flattenNavItems(group.items),
        })),
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
        sidebarGroups: visibleSidebarGroups,
        searchGroups: searchGroups.filter((group) => group.items.length > 0),
    };
}
