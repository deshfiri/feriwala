import { ArrowLeft } from 'lucide-react';
import { useSidebar } from '@/components/ui/sidebar';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

/**
 * The round chip riding the rail's edge that collapses and expands it on a
 * desktop, after Isomorphic.
 *
 * The same `toggleSidebar()` the phone header trigger and Ctrl/Cmd+B call, so
 * the collapsed state is still one cookie read by every shell. On a phone the
 * rail is a drawer and the header trigger opens it; this chip is not shown.
 */
export function SidebarEdgeToggle() {
    const { t } = useTranslation();
    const { state, toggleSidebar } = useSidebar();
    const isExpanded = state === 'expanded';

    return (
        <button
            type="button"
            onClick={toggleSidebar}
            aria-label={
                isExpanded
                    ? t('common.nav.collapse_sidebar')
                    : t('common.nav.expand_sidebar')
            }
            aria-expanded={isExpanded}
            data-test="sidebar-edge-toggle"
            className={cn(
                'bg-brand text-brand-foreground ring-sidebar absolute top-[calc(var(--header-height)+0.5rem)] -right-3 z-20 hidden size-6 items-center justify-center rounded-full shadow-md ring-4 md:flex',
                'hover:bg-brand/90 transition-colors',
            )}
        >
            <ArrowLeft
                aria-hidden="true"
                className={cn(
                    'size-3.5 transition-transform duration-200',
                    !isExpanded && 'rotate-180',
                )}
            />
        </button>
    );
}
