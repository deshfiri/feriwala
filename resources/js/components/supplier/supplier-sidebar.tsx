import { Link } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { SidebarEdgeToggle } from '@/components/sidebar-edge-toggle';
import { SupplierAccountBadge } from '@/components/supplier/supplier-account-badge';
import {
    Sidebar,
    SidebarContent,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuItem,
    SidebarMenuButton,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes/supplier';
import type { NavGroup } from '@/types';

type SupplierAccount = {
    business_name: string;
    reference: string;
};

/**
 * The Supplier portal's own rail — same shared `Sidebar` primitives and the
 * same `NavMain` renderer the Admin/Client shell uses (§4, D25), so both
 * portals look and behave like one design system. Never the ERP sidebar
 * itself: this reads from the Supplier's own nav groups, built from the
 * Supplier's own guard session, not from `useNavigation()`'s
 * permission-scoped registry.
 */
export function SupplierSidebar({
    groups,
    account,
}: {
    groups: NavGroup[];
    account: SupplierAccount | null;
}) {
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

                <div className="px-3 pb-3 group-data-[collapsible=icon]:px-2">
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SupplierAccountBadge account={account} />
                        </SidebarMenuItem>
                    </SidebarMenu>
                </div>
            </SidebarHeader>

            <SidebarContent className="scrollbar-thin gap-6 pt-2 pb-4">
                <NavMain groups={groups} />
            </SidebarContent>

            <SidebarEdgeToggle />
        </Sidebar>
    );
}
