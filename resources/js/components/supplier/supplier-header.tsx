import { Link, router } from '@inertiajs/react';
import { Bell, LogOut, ShieldCheck, UserCog } from 'lucide-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import AppearanceToggleTab from '@/components/appearance-tabs';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import LanguageSwitcher from '@/components/language-switcher';
import StatusPill from '@/components/status-pill';
import { useTranslation } from '@/hooks/use-translation';
import type { StatusTone } from '@/lib/status';
import { logout } from '@/routes/supplier';
import { index as notifications } from '@/routes/supplier/notifications';
import { edit as profile } from '@/routes/supplier/profile';
import { edit as security } from '@/routes/supplier/security';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

type SupplierAccount = {
    business_name: string;
    reference: string;
    status_label: string;
    operational: boolean;
    unread_notifications: number;
};

/**
 * The Supplier portal's command bar — the same row §33.2 asks every ERP
 * shell for (rail toggle, breadcrumbs, quick actions, account menu), wired
 * to the Supplier's own guard session rather than the Client/Partner one.
 */
export function SupplierHeader({
    breadcrumbs = [],
    account,
}: {
    breadcrumbs?: BreadcrumbItemType[];
    account: SupplierAccount | null;
}) {
    const { t } = useTranslation();
    const unread = account?.unread_notifications ?? 0;

    return (
        <header className="border-sidebar-border/60 bg-background/95 supports-[backdrop-filter]:bg-background/80 sticky top-0 z-30 flex h-16 shrink-0 items-center gap-2 border-b px-4 backdrop-blur transition-[height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-14 md:px-6">
            <div className="flex min-w-0 flex-1 items-center gap-2">
                <SidebarTrigger className="-ml-1 shrink-0" />
                <div className="hidden min-w-0 sm:block">
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>
            </div>

            <div className="flex shrink-0 items-center gap-1">
                {account && (
                    <StatusPill
                        tone={
                            account.operational
                                ? 'success'
                                : ('info' as StatusTone)
                        }
                        label={account.status_label}
                        className="mr-1 hidden md:inline-flex"
                    />
                )}

                <Button
                    variant="ghost"
                    size="icon"
                    className="relative size-9"
                    asChild
                >
                    <Link
                        href={notifications()}
                        aria-label={
                            unread > 0
                                ? t('nav.notifications.unread', {
                                      count: unread,
                                  })
                                : t('nav.notifications.none')
                        }
                    >
                        <Bell aria-hidden="true" className="size-4" />
                        {unread > 0 && (
                            <span
                                aria-hidden="true"
                                className="bg-brand ring-background absolute top-1.5 right-1.5 size-2 rounded-full ring-2"
                            />
                        )}
                    </Link>
                </Button>

                <LanguageSwitcher />
                <AppearanceToggleTab iconOnly />

                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="ml-1 size-9"
                            aria-label={
                                account?.business_name ??
                                t('supplier.portal_name')
                            }
                        >
                            <UserCog aria-hidden="true" className="size-4" />
                        </Button>
                    </DropdownMenuTrigger>

                    <DropdownMenuContent align="end" className="w-56">
                        {account && (
                            <>
                                <DropdownMenuLabel className="truncate font-normal">
                                    <p className="truncate text-sm font-medium">
                                        {account.business_name}
                                    </p>
                                    <p className="text-muted-foreground truncate text-xs">
                                        {account.reference}
                                    </p>
                                </DropdownMenuLabel>
                                <DropdownMenuSeparator />
                            </>
                        )}

                        <DropdownMenuItem asChild>
                            <Link href={profile()}>
                                <UserCog aria-hidden="true" />
                                {t('supplier.nav.profile')}
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild>
                            <Link href={security()}>
                                <ShieldCheck aria-hidden="true" />
                                {t('supplier.nav.security')}
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            onSelect={() => router.post(logout().url)}
                        >
                            <LogOut aria-hidden="true" />
                            {t('supplier.nav.sign_out')}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </header>
    );
}
