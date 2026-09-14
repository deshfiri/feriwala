import { usePage } from '@inertiajs/react';
import {
    Boxes,
    CreditCard,
    FolderTree,
    LayoutGrid,
    ListChecks,
    Lock,
    MessageSquare,
    Package as PackageIcon,
    Palette,
    Receipt,
    Scale,
    ScrollText,
    Settings,
    ShoppingBag,
    ShoppingCart,
    SlidersHorizontal,
    Store,
    Tags,
    ShieldCheck,
    UserCheck,
    Users,
    Wallet as WalletIcon,
    Warehouse,
} from 'lucide-react';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';
import { index as activationQueue } from '@/routes/admin/activations';
import { edit as brandingSettings } from '@/routes/admin/branding';
import { index as billingRules } from '@/routes/admin/billing';
import { index as depositRules } from '@/routes/admin/deposit-rules';
import { index as paymentGateways } from '@/routes/admin/gateways';
import { index as stockReservations } from '@/routes/admin/inventory/reservations';
import { index as stockLevels } from '@/routes/admin/inventory/stock';
import { index as warehouses } from '@/routes/admin/inventory/warehouses';
import { index as kycQueue } from '@/routes/admin/kyc';
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
                    // One dashboard at one address (D1): no account segment.
                    href: dashboard(),
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
                      ]
                    : []),
                ...(permissions['account.view']
                    ? [
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
