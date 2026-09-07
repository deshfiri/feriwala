import { Link } from '@inertiajs/react';
import { AccountBadge } from '@/components/account-badge';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import {
    Sidebar,
    SidebarContent,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarRail,
    SidebarSeparator,
} from '@/components/ui/sidebar';
import { useNavigation } from '@/hooks/use-navigation';
import { dashboard } from '@/routes';

/**
 * The ERP rail (§33.2).
 *
 * The account menu lives in the header rather than down here, so there is
 * exactly one place to find it whether the rail is expanded, collapsed to icons,
 * or closed altogether on a phone.
 */
export function AppSidebar() {
    const { sidebarGroups } = useNavigation();

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader className="gap-2">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>

                <SidebarSeparator className="mx-0 group-data-[collapsible=icon]:hidden" />

                <SidebarMenu>
                    <SidebarMenuItem>
                        <AccountBadge />
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="gap-4">
                <NavMain groups={sidebarGroups} />
            </SidebarContent>

            {/* Drag or click the edge to collapse, for people who never find the header trigger. */}
            <SidebarRail />
        </Sidebar>
    );
}
