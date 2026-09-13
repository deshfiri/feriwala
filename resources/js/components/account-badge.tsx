import { usePage } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { SidebarMenuButton, useSidebar } from '@/components/ui/sidebar';

/**
 * Names the account being worked in. It is a label, not a control.
 *
 * This replaces the starter kit's team switcher. A person belongs to one
 * business account and cannot switch, so a dropdown here would open onto a list
 * of one and imply a choice that does not exist (D1). What is still worth
 * showing is *which* account — an invited staff member wants to see whose
 * business they are inside, and their own role in it.
 *
 * Collapsed to icons, the name moves into the rail's tooltip rather than
 * disappearing. On a phone the rail is a drawer at full width, so the name
 * always shows there whatever the desktop rail was left as.
 *
 * Nothing renders for platform staff, who have no account at all.
 */
export function AccountBadge({ inHeader = false }: { inHeader?: boolean }) {
    const { account } = usePage().props;
    const { state, isMobile } = useSidebar();

    if (!account) {
        return null;
    }

    if (inHeader) {
        return (
            <div className="flex items-center gap-2 text-sm">
                <Building2 className="text-muted-foreground size-4 shrink-0" />
                <span className="truncate font-medium">{account.name}</span>
                <span className="text-muted-foreground truncate text-xs">
                    {account.roleLabel}
                </span>
            </div>
        );
    }

    return (
        <SidebarMenuButton
            size="lg"
            tooltip={{ children: `${account.name} · ${account.roleLabel}` }}
            // Not a button in behaviour: there is nowhere for it to go.
            className="bg-surface-subtle hover:bg-surface-subtle active:bg-surface-subtle h-12 cursor-default gap-2.5 rounded-lg border px-2 group-data-[collapsible=icon]:border-0 group-data-[collapsible=icon]:bg-transparent"
        >
            <div className="bg-brand-subtle text-brand flex aspect-square size-8 shrink-0 items-center justify-center rounded-md">
                <Building2 aria-hidden="true" className="size-4" />
            </div>
            {state === 'expanded' || isMobile ? (
                <div className="grid flex-1 text-left text-sm leading-tight">
                    <span className="text-foreground truncate font-semibold">
                        {account.name}
                    </span>
                    <span className="text-muted-foreground truncate text-xs">
                        {account.roleLabel}
                    </span>
                </div>
            ) : null}
        </SidebarMenuButton>
    );
}
