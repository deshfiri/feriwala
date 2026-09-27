import { Link } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { SupplierAccountBadge } from '@/components/supplier/supplier-account-badge';
import {
    Sidebar,
    SidebarContent,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuItem,
    SidebarMenuButton,
    SidebarRail,
    SidebarSeparator,
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
                        <SupplierAccountBadge account={account} />
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="gap-4">
                <NavMain groups={groups} />
            </SidebarContent>

            <SidebarRail />
        </Sidebar>
    );
}
