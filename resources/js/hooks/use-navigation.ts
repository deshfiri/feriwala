import { usePage } from '@inertiajs/react';
import {
    Banknote,
    Boxes,
    Building2,
    ClipboardList,
    CreditCard,
    Globe,
    HardDrive,
    LayoutGrid,
    Library,
    MapPin,
    MonitorSmartphone,
    Network,
    Package,
    PackageCheck,
    Palette,
    Settings,
    Receipt,
    ReceiptText,
    Share2,
    ShieldCheck,
    ShoppingBag,
    Truck,
    UserRound,
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
import {
    create as createAccount,
    index as accountDirectory,
} from '@/routes/admin/accounts';
import { index as activationQueue } from '@/routes/admin/activations';
import { index as accountVerificationSettings } from '@/routes/admin/account-verification-settings';
import { index as contentLibrary } from '@/routes/admin/content-library';
import { index as productDeletionSettings } from '@/routes/admin/product-deletion-settings';
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
import { index as activityLog } from '@/routes/admin/activity-log';
import { index as stockAllocations } from '@/routes/admin/inventory/allocations';
import { index as stockAvailability } from '@/routes/admin/inventory/availability';
import { index as stockReservations } from '@/routes/admin/inventory/reservations';
import { index as stockLevels } from '@/routes/admin/inventory/stock';
import { index as warehouses } from '@/routes/admin/inventory/warehouses';
import { index as kycQueue } from '@/routes/admin/kyc';
import { index as deletionRequests } from '@/routes/admin/deletions';
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
import { index as storageSettings } from '@/routes/admin/storage-settings';
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
import { index as productsIndex, mine as myProducts } from '@/routes/catalog';
import { edit as appearanceSettings } from '@/routes/appearance';
import { index as invoices } from '@/routes/subscription/invoices';
import { index as receipts } from '@/routes/subscription/receipts';
import { history as kycHistory } from '@/routes/kyc';
import { index as payoutMethods } from '@/routes/payout-methods';
import { edit as profileSettings } from '@/routes/profile';
import { index as referrals } from '@/routes/referrals';
import { edit as securitySettings } from '@/routes/security';
import { index as staffDirectory } from '@/routes/staff';
import { show as subscription } from '@/routes/subscription';
import { show as wallet } from '@/routes/wallet';
import { index as websites } from '@/routes/websites';
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

    const active = account?.status === 'active';
    const buys = Boolean(
        account?.allowsWholesale || account?.allowsDropshipping,
    );

    /*
     * The Partner sidebar: one entry per real, authorised destination, grouped
     * by what the person is doing. The cart is not here — it sits in the top
     * bar beside the wallet quick view.
     */
    const partnerGroups: NavGroup[] =
        account === null || account === undefined
            ? []
            : [
                  {
                      label: t('nav.groups.products'),
                      items: [
                          ...(buys
                              ? [
                                    {
                                        title: t('nav.products'),
                                        href: productsIndex(),
                                        icon: ShoppingBag,
                                    },
                                    {
                                        title: t('nav.my_products'),
                                        href: myProducts(),
                                        icon: Package,
                                    },
                                ]
                              : []),
                          ...(account.holdsAllocatedStock
                              ? [
                                    {
                                        title: t('nav.allocated_stock'),
                                        href: allocatedStock(),
                                        icon: PackageCheck,
                                    },
                                ]
                              : []),
                      ],
                  },
                  {
                      label: t('nav.groups.sales'),
                      items: [
                          ...(account.allowsWholesale ||
                          account.hasWholesaleOrders
                              ? [
                                    {
                                        title: t('nav.orders'),
                                        href: wholesaleOrders(),
                                        icon: ClipboardList,
                                    },
                                ]
                              : []),
                          ...(account.allowsWebsites || account.hasWebsites
                              ? [
                                    {
                                        title: t('nav.websites'),
                                        href: websites(),
                                        icon: Globe,
                                    },
                                ]
                              : []),
                      ],
                  },
                  {
                      label: t('nav.groups.finance'),
                      items: [
                          ...(active
                              ? [
                                    {
                                        title: t('nav.wallet'),
                                        href: wallet(),
                                        icon: WalletIcon,
                                    },
                                    {
                                        title: t('withdrawal.erp.title'),
                                        href: clientWithdrawals(),
                                        icon: Banknote,
                                    },
                                    {
                                        title: t('payout.nav.payout_methods'),
                                        href: payoutMethods(),
                                        icon: CreditCard,
                                    },
                                ]
                              : []),
                          {
                              title: t('common.settings.nav.invoices'),
                              href: invoices(),
                              icon: Receipt,
                          },
                          {
                              title: t('common.settings.nav.receipts'),
                              href: receipts(),
                              icon: ReceiptText,
                          },
                      ],
                  },
                  {
                      label: t('nav.groups.business'),
                      items: [
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
                          ...(account.viewsReferrals
                              ? [
                                    {
                                        title: t('nav.referrals'),
                                        href: referrals(),
                                        icon: Share2,
                                    },
                                ]
                              : []),
                          ...(account.managesStaff
                              ? [
                                    {
                                        title: t('nav.staff'),
                                        href: staffDirectory(),
                                        icon: Users,
                                    },
                                ]
                              : []),
                          ...(active
                              ? [
                                    {
                                        title: t('nav.addresses'),
                                        href: addresses(),
                                        icon: MapPin,
                                    },
                                ]
                              : []),
                      ],
                  },
                  {
                      label: t('nav.groups.account'),
                      items: [
                          {
                              title: t('nav.profile'),
                              href: profileSettings(),
                              icon: UserRound,
                          },
                          {
                              title: t('nav.security'),
                              href: securitySettings(),
                              icon: ShieldCheck,
                          },
                          {
                              title: t('nav.appearance'),
                              href: appearanceSettings(),
                              icon: Palette,
                          },
                      ],
                  },
              ];

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
        ...partnerGroups,
        {
            label: t('nav.groups.administration'),
            items: [
                // Every configuration screen lives behind this one entry (and is
                // searchable from the command palette), so none of them is
                // repeated in a group below. Gated on every permission the hub
                // itself filters its cards by.
                ...(permissions['payment.view'] ||
                permissions['wallet.view'] ||
                permissions['withdrawal.view'] ||
                permissions['website.manage_settings'] ||
                permissions['referral.view_settings'] ||
                permissions['package.view'] ||
                permissions['kyc.manage_settings'] ||
                permissions['account.manage_settings'] ||
                permissions['catalog.view'] ||
                permissions['sms.view'] ||
                permissions['system.manage_settings'] ||
                permissions['integration.view'] ||
                permissions['audit.view_audit_logs']
                    ? [
                          {
                              title: t('nav.settings_hub'),
                              href: settingsHub(),
                              icon: Settings,
                          },
                      ]
                    : []),

                // Who can do what on the platform. KYC review is an account
                // queue and lives under Accounts; packages and KYC
                // requirements are settings and live in the Settings hub.
                // The activity log (§36.2) is read with its own permission, so
                // it can be granted without the right to manage roles.
                ...(permissions['access.view'] ||
                permissions['audit.view_audit_logs']
                    ? [
                          {
                              title: 'Access',
                              href: permissions['access.view']
                                  ? roles()
                                  : activityLog(),
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
                                  ...(permissions['audit.view_audit_logs']
                                      ? [
                                            {
                                                title: t('nav.activity_log'),
                                                href: activityLog(),
                                            },
                                        ]
                                      : []),
                              ],
                          },
                      ]
                    : []),

                // Subscription packages are sold and assigned day to day, so
                // they keep a door of their own beside the Settings hub.
                ...(permissions['package.view']
                    ? [
                          {
                              title: t('nav.packages'),
                              href: packageCatalogue(),
                              icon: Boxes,
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

                // Its own entry: content is released to Products from here, but
                // it is a library of its own, not part of the catalogue.
                ...(permissions['content_library.view']
                    ? [
                          {
                              title: t('nav.content_library'),
                              href: contentLibrary(),
                              icon: Library,
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

                ...(permissions['website.view']
                    ? [
                          {
                              title: 'Websites',
                              href: adminWebsites(),
                              icon: MonitorSmartphone,
                              items: [
                                  {
                                      title: t('nav.partner_websites'),
                                      href: adminWebsites(),
                                  },
                              ],
                          },
                      ]
                    : []),

                ...(permissions['referral.view']
                    ? [
                          {
                              title: 'Partner Network',
                              href: referralCommissions(),
                              icon: Network,
                              items: [
                                  {
                                      title: t('nav.referral_commissions'),
                                      href: referralCommissions(),
                                  },
                              ],
                          },
                      ]
                    : []),

                // The account queues: the directory, new accounts, activation
                // approvals and KYC review. Verification rules are settings.
                ...(permissions['account.view'] ||
                permissions['kyc.view'] ||
                permissions['account.delete'] ||
                permissions['supplier.delete']
                    ? [
                          {
                              title: 'Accounts',
                              href: permissions['account.view']
                                  ? accountDirectory()
                                  : permissions['kyc.view']
                                    ? kycQueue()
                                    : deletionRequests(),
                              icon: Building2,
                              items: [
                                  ...(permissions['account.view']
                                      ? [
                                            {
                                                title: t('nav.accounts'),
                                                href: accountDirectory(),
                                            },
                                        ]
                                      : []),
                                  ...(permissions['account.create']
                                      ? [
                                            {
                                                title: t('nav.add_account'),
                                                href: createAccount(),
                                            },
                                        ]
                                      : []),
                                  ...(permissions['account.view']
                                      ? [
                                            {
                                                title: t(
                                                    'nav.activation_approvals',
                                                ),
                                                href: activationQueue(),
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
                                  ...(permissions['account.delete'] ||
                                  permissions['supplier.delete']
                                      ? [
                                            {
                                                title: t(
                                                    'nav.deletion_requests',
                                                ),
                                                href: deletionRequests(),
                                            },
                                        ]
                                      : []),
                              ],
                          },
                      ]
                    : []),

                // Money in motion. Billing rules, gateways and deposit rules
                // are settings and live in the Settings hub.
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
                permissions['supplier_listing.view']
                    ? [
                          {
                              title: 'Supplier Management',
                              href: permissions['supplier.view']
                                  ? supplierApplications()
                                  : supplierListings(),
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
                              ],
                          },
                      ]
                    : []),

                ...(permissions['supplier_pricing.view'] ||
                permissions['supplier_stock.view']
                    ? [
                          {
                              title: 'Supplier Operations',
                              href: permissions['supplier_pricing.view']
                                  ? supplierAllocations()
                                  : supplierStock(),
                              icon: Truck,
                              items: [
                                  ...(permissions['supplier_pricing.view']
                                      ? [
                                            {
                                                title: t(
                                                    'nav.supplier_allocations',
                                                ),
                                                href: supplierAllocations(),
                                            },
                                            {
                                                title: t('nav.supplier_offers'),
                                                href: supplierOffers(),
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
                              ],
                          },
                      ]
                    : []),

                ...(permissions['supplier_payable.view'] ||
                permissions['withdrawal.view']
                    ? [
                          {
                              title: 'Supplier Finance',
                              href: permissions['supplier_payable.view']
                                  ? supplierPayables()
                                  : supplierWithdrawals(),
                              icon: Truck,
                              items: [
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
            // The configuration screens: reached from the sidebar through the
            // Settings hub, and found here by name.
            label: t('nav.groups.platform_settings'),
            items: [
                ...(permissions['payment.view']
                    ? [
                          {
                              title: t('nav.billing_rules'),
                              href: billingRules(),
                              icon: Settings,
                          },
                          {
                              title: t('nav.payment_gateways'),
                              href: paymentGateways(),
                              icon: CreditCard,
                          },
                      ]
                    : []),
                ...(permissions['wallet.view']
                    ? [
                          {
                              title: t('nav.deposit_rules'),
                              href: depositRules(),
                              icon: WalletIcon,
                          },
                      ]
                    : []),
                ...(permissions['website.manage_settings']
                    ? [
                          {
                              title: t('nav.website_pricing'),
                              href: websitePricing(),
                              icon: MonitorSmartphone,
                          },
                      ]
                    : []),
                ...(permissions['referral.view_settings']
                    ? [
                          {
                              title: t('nav.referral_settings'),
                              href: referralSettings(),
                              icon: Network,
                          },
                      ]
                    : []),
                ...(permissions['kyc.manage_settings']
                    ? [
                          {
                              title: t('nav.kyc_requirements'),
                              href: kycRequirements(),
                              icon: ShieldCheck,
                          },
                      ]
                    : []),
                ...(permissions['account.manage_settings']
                    ? [
                          {
                              title: t('nav.account_verification_settings'),
                              href: accountVerificationSettings(),
                              icon: ShieldCheck,
                          },
                      ]
                    : []),
                ...(permissions['catalog.view']
                    ? [
                          {
                              title: t('nav.product_deletion_settings'),
                              href: productDeletionSettings(),
                              icon: ShoppingBag,
                          },
                      ]
                    : []),
                ...(permissions['sms.view']
                    ? [
                          {
                              title: t('nav.sms'),
                              href: smsSettings(),
                              icon: Settings,
                          },
                      ]
                    : []),
                ...(permissions['system.manage_settings']
                    ? [
                          {
                              title: t('nav.branding'),
                              href: brandingSettings(),
                              icon: Settings,
                          },
                      ]
                    : []),
                ...(permissions['integration.view']
                    ? [
                          {
                              title: t('nav.storage_settings'),
                              href: storageSettings(),
                              icon: HardDrive,
                          },
                      ]
                    : []),
            ],
        },
        {
            label: t('nav.groups.settings'),
            items: account
                ? []
                : [
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
