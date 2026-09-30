import { Link, usePage } from '@inertiajs/react';
import { AccountBadge } from '@/components/account-badge';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { SidebarEdgeToggle } from '@/components/sidebar-edge-toggle';
import {
    Sidebar,
    SidebarContent,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useNavigation } from '@/hooks/use-navigation';
import { dashboard } from '@/routes';

/**
 * The ERP rail (§33.2).
 *
 * The account menu lives in the header rather than down here, so there is
 * exactly one place to find it whether the rail is expanded, collapsed to icons,
 * or closed altogether on a phone.
 *
 * The brand row is exactly the header's height, so the logo sits on the same
 * line as the header's controls and the two read as one frame around the page.
 * The account being worked in is
 * pinned beneath it and does not scroll away; only the navigation does, in its
 * own scroll area, so a long administration section never pushes the
 * account's name out of sight.
 */
export function AppSidebar() {
    const { sidebarGroups } = useNavigation();
    const { account } = usePage().props;

    return (
        <Sidebar collapsible="icon" variant="sidebar">
            <SidebarHeader className="gap-0 p-0">
                <div className="flex h-(--header-height) shrink-0 items-center px-4 group-data-[collapsible=icon]:px-2">
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton
                                size="lg"
                                asChild
                                className="h-10 hover:bg-transparent active:bg-transparent"
                            >
                                <Link href={dashboard()} prefetch>
                                    <AppLogo />
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </div>

                {account && (
                    <div className="px-3 pb-3 group-data-[collapsible=icon]:px-2">
                        <SidebarMenu>
                            <SidebarMenuItem>
                                <AccountBadge />
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </div>
                )}
            </SidebarHeader>

            <SidebarContent className="scrollbar-thin gap-6 pt-2 pb-4">
                <NavMain groups={sidebarGroups} />
            </SidebarContent>

            <SidebarEdgeToggle />
        </Sidebar>
    );
}
