import { Link, router } from '@inertiajs/react';
import { Bell, LogOut, ShieldCheck, UserCog } from 'lucide-react';
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
import { headerActionClasses } from '@/lib/header-action';
import type { StatusTone } from '@/lib/status';
import { cn } from '@/lib/utils';
import { logout } from '@/routes/supplier';
import { index as notifications } from '@/routes/supplier/notifications';
import { edit as profile } from '@/routes/supplier/profile';
import { edit as security } from '@/routes/supplier/security';

type SupplierAccount = {
    business_name: string;
    reference: string;
    status_label: string;
    operational: boolean;
    unread_notifications: number;
};

/**
 * The Supplier portal's command bar — laid out like the ERP header (rail
 * toggle on a phone, account standing on the left, quick actions and the
 * account menu on the right), wired to the Supplier's own guard session
 * rather than the Client/Partner one. Breadcrumbs sit in `PageBreadcrumbBar`
 * above the page, as they do in the ERP.
 */
export function SupplierHeader({
    account,
}: {
    account: SupplierAccount | null;
}) {
    const { t } = useTranslation();
    const unread = account?.unread_notifications ?? 0;

    return (
        <header className="bg-background/80 supports-[backdrop-filter]:bg-background/70 sticky top-0 z-30 flex h-(--header-height) shrink-0 items-center gap-3 px-4 backdrop-blur-md sm:px-6">
            <div className="flex min-w-0 flex-1 items-center gap-3">
                {/* On a desktop the chip on the rail's edge does this. */}
                <SidebarTrigger
                    className={cn(headerActionClasses, 'shrink-0 md:hidden')}
                />
                {account && (
                    <StatusPill
                        tone={
                            account.operational
                                ? 'success'
                                : ('info' as StatusTone)
                        }
                        label={account.status_label}
                        className="hidden md:inline-flex"
                    />
                )}
            </div>

            <div className="flex shrink-0 items-center gap-2">
                <Button
                    variant="ghost"
                    size="icon"
                    className={headerActionClasses}
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
                                className="bg-brand ring-card absolute top-2 right-2 size-2 rounded-full ring-2"
                            />
                        )}
                    </Link>
                </Button>

                <LanguageSwitcher className={headerActionClasses} />
                <AppearanceToggleTab iconOnly />

                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            className={cn(headerActionClasses, 'ml-1')}
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
