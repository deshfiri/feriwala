import { usePage } from '@inertiajs/react';
import AppearanceMenu from '@/components/appearance-menu';
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
 * breadcrumbs saying where you are, global search, language, appearance,
 * notifications, and the account menu.
 *
 * It stays put while the page scrolls. In a data-dense ERP the alternative is
 * losing your place in a long table and having to scroll back up to reach
 * search — the header is the one thing that must always be within reach. It is
 * a fixed height, the same as the brand row in the rail, so the two rules line
 * up and nothing shifts when the rail collapses.
 */
export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    return (
        <header className="bg-background/85 supports-[backdrop-filter]:bg-background/70 sticky top-0 z-30 flex h-(--header-height) shrink-0 items-center gap-3 border-b px-4 backdrop-blur-md sm:px-6">
            <div className="flex min-w-0 flex-1 items-center gap-2">
                <SidebarTrigger className="text-muted-foreground hover:text-foreground -ml-1.5 size-8 shrink-0" />

                {/* Breadcrumbs give way to the search field first on a phone. */}
                <span
                    aria-hidden="true"
                    className="bg-border hidden h-5 w-px shrink-0 sm:block"
                />
                <div className="hidden min-w-0 pl-1 sm:block">
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>
            </div>

            <div className="flex shrink-0 items-center gap-1">
                <GlobalSearch />

                <span
                    aria-hidden="true"
                    className="bg-border mx-1.5 hidden h-5 w-px md:block"
                />

                <LanguageSwitcher className="size-9" />
                <AppearanceMenu />
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
                    className="hover:ring-border focus-visible:ring-ring ring-offset-background ml-1.5 flex items-center rounded-full ring-offset-2 transition-shadow hover:ring-2 focus-visible:ring-2 focus-visible:outline-hidden"
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

            <DropdownMenuContent
                align="end"
                sideOffset={8}
                className="w-64 rounded-xl p-1.5"
            >
                <UserMenuContent user={auth.user} />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
