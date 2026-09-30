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
            className="bg-surface-subtle hover:bg-surface-subtle active:bg-surface-subtle h-12 cursor-default gap-2.5 rounded-lg border px-2 group-data-[collapsible=icon]:border-0 group-data-[collapsible=icon]:bg-transparent"
        >
            <div className="bg-brand-subtle text-brand flex aspect-square size-8 shrink-0 items-center justify-center rounded-md">
                <Truck className="size-4" />
            </div>
            {state === 'expanded' ? (
                <div className="grid flex-1 text-left text-sm leading-tight">
                    <span className="text-foreground truncate font-semibold">
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
