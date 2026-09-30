import { usePage } from '@inertiajs/react';
import AppearanceMenu from '@/components/appearance-menu';
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
import { headerActionClasses } from '@/lib/header-action';
import { cn } from '@/lib/utils';

/**
 * The ERP command bar (§33.2).
 *
 * Search leads on the left, after both Able Pro and Isomorphic; language,
 * appearance, notifications and the account menu sit on the right. The rail
 * toggle shows here only on a phone — on a desktop it is the chip on the
 * rail's edge. Breadcrumbs, the rest of what §33.2 asks for, sit in
 * `PageBreadcrumbBar` above the page instead.
 *
 * It stays put while the page scrolls. In a data-dense ERP the alternative is
 * losing your place in a long table and having to scroll back up to reach
 * search — the header is the one thing that must always be within reach. It is
 * a fixed height, the same as the brand row in the rail, so the logo and the
 * controls share a line and nothing shifts when the rail collapses. It has no
 * rule of its own: it is frosted canvas, so the page scrolls away beneath it.
 */
export function AppSidebarHeader() {
    return (
        <header className="bg-background/80 supports-[backdrop-filter]:bg-background/70 sticky top-0 z-30 flex h-(--header-height) shrink-0 items-center gap-3 px-4 backdrop-blur-md sm:px-6">
            <div className="flex min-w-0 flex-1 items-center gap-3">
                {/* On a desktop the chip on the rail's edge does this. */}
                <SidebarTrigger
                    className={cn(headerActionClasses, 'shrink-0 md:hidden')}
                />

                <GlobalSearch />
            </div>

            <div className="flex shrink-0 items-center gap-2">
                <LanguageSwitcher className={headerActionClasses} />
                <AppearanceMenu className={headerActionClasses} />
                <NotificationMenu className={headerActionClasses} />
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
                    className="hover:ring-border focus-visible:ring-ring ring-offset-background ml-1 flex items-center rounded-full ring-offset-2 transition-shadow hover:ring-2 focus-visible:ring-2 focus-visible:outline-hidden"
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
