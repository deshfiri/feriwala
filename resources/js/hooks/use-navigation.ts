import { usePage } from '@inertiajs/react';
import {
    CreditCard,
    LayoutGrid,
    ListChecks,
    Package as PackageIcon,
    Settings,
    ShieldCheck,
    UserCheck,
    Users,
} from 'lucide-react';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';
import { index as activationQueue } from '@/routes/admin/activations';
import { index as kycQueue } from '@/routes/admin/kyc';
import { index as kycRequirements } from '@/routes/admin/kyc/document-types';
import { index as packageCatalogue } from '@/routes/admin/packages';
import { history as kycHistory } from '@/routes/kyc';
import { edit as profileSettings } from '@/routes/profile';
import { edit as securitySettings } from '@/routes/security';
import { index as staffDirectory } from '@/routes/staff';
import { show as subscription } from '@/routes/subscription';
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
                ...(permissions['account.view']
                    ? [
                          {
                              title: t('nav.activation_approvals'),
                              href: activationQueue(),
                              icon: UserCheck,
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
