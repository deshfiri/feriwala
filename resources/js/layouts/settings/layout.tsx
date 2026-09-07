import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useTranslation } from '@/hooks/use-translation';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as staff } from '@/routes/staff';
import { show as subscription } from '@/routes/subscription';
import type { NavItem } from '@/types';

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { account } = usePage().props;
    const { t } = useTranslation();

    /*
     * Staff appears only when the account has staff to manage and this person
     * may manage them. A solo package, or a staff member who is not a manager,
     * sees no entry at all — not a greyed-out one, which reads as something
     * withheld rather than something the plan never included.
     */
    const sidebarNavItems: NavItem[] = [
        {
            title: t('common.settings.nav.profile'),
            href: edit(),
            icon: null,
        },
        {
            title: t('common.settings.nav.security'),
            href: editSecurity(),
            icon: null,
        },

        /*
         * Shown to anyone with an account, activated or not. "What did I choose
         * and what will it cost" is a question an applicant asks before paying,
         * and an entry that appears only afterwards answers it too late.
         */
        ...(account
            ? [
                  {
                      title: t('common.settings.nav.package'),
                      href: subscription(),
                      icon: null,
                  },
              ]
            : []),
        ...(account?.allowsStaff && account.managesStaff
            ? [
                  {
                      title: t('common.settings.nav.staff'),
                      href: staff(),
                      icon: null,
                  },
              ]
            : []),
        {
            title: t('common.settings.nav.appearance'),
            href: editAppearance(),
            icon: null,
        },
    ];

    return (
        <PageContainer>
            <PageHeader
                title={t('common.settings.title')}
                description={t('common.settings.description')}
            />

            <div className="flex flex-col gap-6 lg:flex-row lg:gap-10">
                <aside className="w-full lg:w-52 lg:shrink-0">
                    <nav
                        className="flex flex-col space-y-1"
                        aria-label={t('common.settings.nav.label')}
                    >
                        {sidebarNavItems.map((item, index) => {
                            const current = isCurrentOrParentUrl(item.href);

                            return (
                                <Button
                                    key={`${toUrl(item.href)}-${index}`}
                                    size="sm"
                                    variant="ghost"
                                    asChild
                                    className={cn(
                                        'w-full justify-start font-normal',
                                        current &&
                                            'bg-sidebar-accent text-sidebar-accent-foreground font-medium',
                                    )}
                                >
                                    <Link
                                        href={item.href}
                                        // Marks the page you are on for a screen
                                        // reader, which a background colour
                                        // alone does not (§33.9).
                                        aria-current={
                                            current ? 'page' : undefined
                                        }
                                    >
                                        {item.icon && (
                                            <item.icon className="size-4" />
                                        )}
                                        {item.title}
                                    </Link>
                                </Button>
                            );
                        })}
                    </nav>
                </aside>

                <Separator className="lg:hidden" />

                {/*
                 * Capped rather than full-width: a settings form is mostly text
                 * inputs, and an input stretched across a wide monitor is harder
                 * to fill in than a narrow one, not easier.
                 */}
                <div className="min-w-0 flex-1 space-y-6 lg:max-w-3xl">
                    {children}
                </div>
            </div>
        </PageContainer>
    );
}
