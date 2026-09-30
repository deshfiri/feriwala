import { Truck } from 'lucide-react';
import { SidebarMenuButton, useSidebar } from '@/components/ui/sidebar';

type SupplierAccount = {
    business_name: string;
    reference: string;
};

/**
 * Names the Supplier account being worked in — a label, not a control,
 * exactly like {@see AccountBadge} does for a Client/Partner business
 * account. There is nowhere to switch to: one Supplier login is one
 * Supplier account (D25).
 */
export function SupplierAccountBadge({
    account,
}: {
    account: SupplierAccount | null;
}) {
    const { state } = useSidebar();

    if (!account) {
        return null;
    }

    return (
        <SidebarMenuButton
            size="lg"
            className="cursor-default hover:bg-transparent active:bg-transparent"
        >
            <div className="bg-sidebar-primary text-sidebar-primary-foreground flex aspect-square size-8 items-center justify-center rounded-lg">
                <Truck className="size-4" />
            </div>
            {state === 'expanded' ? (
                <div className="grid flex-1 text-left text-sm leading-tight">
                    <span className="truncate font-medium">
                        {account.business_name}
                    </span>
                    <span className="text-muted-foreground truncate text-xs">
                        {account.reference}
                    </span>
                </div>
            ) : null}
        </SidebarMenuButton>
    );
}
