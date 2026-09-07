import { usePage } from '@inertiajs/react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import GlobalSearch from '@/components/global-search';
import LanguageSwitcher from '@/components/language-switcher';
import NotificationMenu from '@/components/notification-menu';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { UserInfo } from '@/components/user-info';
import { UserMenuContent } from '@/components/user-menu-content';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

/**
 * The ERP command bar (§33.2).
 *
 * Everything §33.2 asks a header to carry, in one row: the rail toggle,
 * breadcrumbs saying where you are, global search, language, notifications, and
 * the account menu.
 *
 * It stays put while the page scrolls. In a data-dense ERP the alternative is
 * losing your place in a long table and having to scroll back up to reach
 * search — the header is the one thing that must always be within reach.
 */
export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    return (
        <header className="border-sidebar-border/60 bg-background/95 supports-[backdrop-filter]:bg-background/80 sticky top-0 z-30 flex h-16 shrink-0 items-center gap-2 border-b px-4 backdrop-blur transition-[height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-14 md:px-6">
            <div className="flex min-w-0 flex-1 items-center gap-2">
                <SidebarTrigger className="-ml-1 shrink-0" />
                {/* Breadcrumbs give way to the search field first on a phone. */}
                <div className="hidden min-w-0 sm:block">
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>
            </div>

            <div className="flex shrink-0 items-center gap-1">
                <GlobalSearch />
                <LanguageSwitcher className="size-9" />
                <NotificationMenu />
                <HeaderUserMenu />
            </div>
        </header>
    );
}

/**
 * The account menu (§33.2).
 *
 * It lives here rather than in the sidebar footer so there is exactly one place
 * to find it, whether the rail is expanded, collapsed to icons, or closed
 * altogether on a phone.
 */
function HeaderUserMenu() {
    const { auth } = usePage().props;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className="focus-visible:ring-ring ml-1 flex items-center rounded-full focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden"
                    aria-label={auth.user.name}
                    data-test="header-user-menu"
                >
                    {/*
                     * The avatar alone — the name and email are one click away
                     * in the menu, and a header that repeats them is clutter
                     * (§33.1).
                     */}
                    <UserInfo user={auth.user} showName={false} />
                </button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" className="min-w-56 rounded-lg">
                <UserMenuContent user={auth.user} />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
