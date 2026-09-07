import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import {
    useCurrentUrl,
    type IsCurrentOrParentUrlFn,
    type IsCurrentUrlFn,
} from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
import type { NavBadge, NavBadgeTone, NavGroup, NavItem } from '@/types';

/**
 * Badge tones. The label always renders as text beside the colour, so a badge
 * still reads in greyscale and for a colour-blind user (§33.9).
 */
const badgeToneClasses: Record<NavBadgeTone, string> = {
    brand: 'bg-brand-subtle text-brand',
    info: 'bg-info-subtle text-info',
    danger: 'bg-danger-subtle text-danger',
    neutral: 'bg-muted text-muted-foreground',
};

/**
 * The current row is marked twice: a brand-tinted ground and a solid rail down
 * its leading edge. The tint alone would be the only carrier of meaning, and the
 * rail is what lets the eye find the current section while scanning a long
 * sidebar rather than reading every entry.
 */
const activeItemClasses = cn(
    'data-[active=true]:bg-brand-subtle data-[active=true]:text-brand relative data-[active=true]:font-medium',
    'data-[active=true]:before:absolute data-[active=true]:before:inset-y-1.5 data-[active=true]:before:left-0',
    'data-[active=true]:before:bg-brand data-[active=true]:before:w-0.5 data-[active=true]:before:rounded-full',
);

function NavBadgePill({ badge }: { badge: NavBadge }) {
    return (
        <span
            className={cn(
                'ml-auto shrink-0 rounded px-1.5 py-0.5 text-[10px] leading-4 font-semibold tracking-wide uppercase',
                'group-data-[collapsible=icon]:hidden',
                badgeToneClasses[badge.tone ?? 'neutral'],
            )}
        >
            {badge.label}
        </span>
    );
}

/**
 * A navigation entry with no children — one link, one row.
 */
function NavLink({
    item,
    isCurrentUrl,
}: {
    item: NavItem;
    isCurrentUrl: IsCurrentUrlFn;
}) {
    return (
        <SidebarMenuItem>
            <SidebarMenuButton
                asChild
                className={activeItemClasses}
                isActive={item.isActive ?? isCurrentUrl(item.href)}
                tooltip={{ children: item.title }}
            >
                <Link href={item.href} prefetch>
                    {item.icon && <item.icon />}
                    <span>{item.title}</span>
                    {item.badge && <NavBadgePill badge={item.badge} />}
                </Link>
            </SidebarMenuButton>
        </SidebarMenuItem>
    );
}

/**
 * A navigation entry that owns children.
 *
 * The parent's own `href` still navigates — the chevron expands, the label goes
 * somewhere. A group that can only be opened is a dead end for anyone driving
 * the sidebar from the keyboard.
 */
function NavBranch({
    item,
    isCurrentUrl,
    isCurrentOrParentUrl,
}: {
    item: NavItem;
    isCurrentUrl: IsCurrentUrlFn;
    isCurrentOrParentUrl: IsCurrentOrParentUrlFn;
}) {
    const children = item.items ?? [];
    const holdsCurrentPage =
        isCurrentOrParentUrl(item.href) ||
        children.some((child) => isCurrentUrl(child.href));

    return (
        <Collapsible
            asChild
            className="group/collapsible"
            defaultOpen={holdsCurrentPage}
        >
            <SidebarMenuItem>
                <CollapsibleTrigger asChild>
                    <SidebarMenuButton
                        className={activeItemClasses}
                        isActive={isCurrentUrl(item.href)}
                        tooltip={{ children: item.title }}
                    >
                        {item.icon && <item.icon />}
                        <span>{item.title}</span>
                        {item.badge && <NavBadgePill badge={item.badge} />}
                        <ChevronRight
                            aria-hidden="true"
                            className={cn(
                                'ml-auto size-4 shrink-0 transition-transform duration-200',
                                'group-data-[state=open]/collapsible:rotate-90',
                                item.badge && 'ml-1',
                            )}
                        />
                    </SidebarMenuButton>
                </CollapsibleTrigger>

                <CollapsibleContent>
                    <SidebarMenuSub>
                        {children.map((child) => (
                            <SidebarMenuSubItem key={child.title}>
                                <SidebarMenuSubButton
                                    asChild
                                    isActive={
                                        child.isActive ??
                                        isCurrentUrl(child.href)
                                    }
                                    className="data-[active=true]:bg-brand-subtle data-[active=true]:text-brand"
                                >
                                    <Link href={child.href} prefetch>
                                        <span>{child.title}</span>
                                        {child.badge && (
                                            <NavBadgePill badge={child.badge} />
                                        )}
                                    </Link>
                                </SidebarMenuSubButton>
                            </SidebarMenuSubItem>
                        ))}
                    </SidebarMenuSub>
                </CollapsibleContent>
            </SidebarMenuItem>
        </Collapsible>
    );
}

/**
 * Sectioned sidebar navigation (§33.2).
 *
 * Sections are what make a long sidebar scannable: someone hunting for the KYC
 * queue looks for "Administration" and reads four entries, instead of reading
 * twenty. An empty group renders nothing at all rather than a heading with no
 * rows under it — the permission gate upstream decides what a person may see,
 * and a bare label would advertise a section they cannot reach.
 */
export function NavMain({ groups }: { groups: NavGroup[] }) {
    const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <>
            {groups
                .filter((group) => group.items.length > 0)
                .map((group) => (
                    <SidebarGroup key={group.label} className="px-2 py-0">
                        <SidebarGroupLabel className="text-muted-foreground text-[11px] font-semibold tracking-wider uppercase">
                            {group.label}
                        </SidebarGroupLabel>

                        <SidebarMenu>
                            {group.items.map((item) =>
                                item.items && item.items.length > 0 ? (
                                    <NavBranch
                                        key={item.title}
                                        item={item}
                                        isCurrentUrl={isCurrentUrl}
                                        isCurrentOrParentUrl={
                                            isCurrentOrParentUrl
                                        }
                                    />
                                ) : (
                                    <NavLink
                                        key={item.title}
                                        item={item}
                                        isCurrentUrl={isCurrentUrl}
                                    />
                                ),
                            )}
                        </SidebarMenu>
                    </SidebarGroup>
                ))}
        </>
    );
}
