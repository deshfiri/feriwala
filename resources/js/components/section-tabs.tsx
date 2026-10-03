import { AlertCircle, type LucideIcon } from 'lucide-react';
import { useRef, type ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type SectionTabItem = {
    key: string;
    label: string;
    icon?: LucideIcon;
    /** Shows a small marker without relying on colour alone (§33.9). */
    hasError?: boolean;
};

/**
 * A horizontal tab strip for organizing a long form or detail screen into
 * named sections, built from plain buttons rather than a new dependency —
 * this application vendors shadcn's Radix-based primitives but does not
 * carry `@radix-ui/react-tabs`, and a single extra package for one widget
 * is not worth the dependency-approval round trip. Keyboard behavior
 * (roving tabindex, arrow-key navigation) follows the WAI-ARIA tabs pattern
 * by hand instead.
 *
 * Pair with {@see SectionTabPanel} for the matching `tabpanel` role.
 */
export default function SectionTabs({
    items,
    active,
    onChange,
    className,
}: {
    items: SectionTabItem[];
    active: string;
    onChange: (key: string) => void;
    className?: string;
}) {
    const listRef = useRef<HTMLDivElement>(null);

    const focusTab = (key: string) => {
        requestAnimationFrame(() => {
            listRef.current
                ?.querySelector<HTMLButtonElement>(`[data-tab-key="${key}"]`)
                ?.focus();
        });
    };

    const move = (delta: 1 | -1) => {
        const index = items.findIndex((item) => item.key === active);
        const next =
            items[(index + delta + items.length) % items.length] ?? items[0];

        onChange(next.key);
        focusTab(next.key);
    };

    return (
        <div
            ref={listRef}
            role="tablist"
            aria-orientation="horizontal"
            className={cn(
                '-mx-4 flex gap-1 overflow-x-auto border-b px-4 sm:mx-0 sm:px-0',
                className,
            )}
        >
            {items.map((item) => {
                const isActive = item.key === active;
                const Icon = item.icon;

                return (
                    <button
                        key={item.key}
                        type="button"
                        role="tab"
                        id={`tab-${item.key}`}
                        data-tab-key={item.key}
                        aria-selected={isActive}
                        aria-controls={`panel-${item.key}`}
                        tabIndex={isActive ? 0 : -1}
                        onClick={() => onChange(item.key)}
                        onKeyDown={(event) => {
                            if (event.key === 'ArrowRight') {
                                event.preventDefault();
                                move(1);
                            } else if (event.key === 'ArrowLeft') {
                                event.preventDefault();
                                move(-1);
                            } else if (event.key === 'Home') {
                                event.preventDefault();
                                onChange(items[0].key);
                                focusTab(items[0].key);
                            } else if (event.key === 'End') {
                                event.preventDefault();
                                onChange(items[items.length - 1].key);
                                focusTab(items[items.length - 1].key);
                            }
                        }}
                        className={cn(
                            'relative flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium whitespace-nowrap transition-colors focus-visible:outline-none',
                            isActive
                                ? 'border-primary text-foreground'
                                : 'text-muted-foreground hover:text-foreground border-transparent',
                        )}
                    >
                        {Icon && <Icon className="size-4" aria-hidden="true" />}
                        {item.label}
                        {item.hasError && (
                            <AlertCircle
                                className="text-danger size-3.5"
                                aria-hidden="true"
                            />
                        )}
                    </button>
                );
            })}
        </div>
    );
}

/**
 * One tab's content.
 *
 * `keepMounted` is for a panel whose fields belong to a form shared with
 * other tabs — hiding it with the `hidden` attribute keeps every field in
 * the DOM (and so in the submission) while only one tab's fields are shown
 * at a time. Omit it for a panel with its own independent form or state,
 * where unmounting on tab-away is cheaper and has no side effect.
 */
export function SectionTabPanel({
    tab,
    active,
    keepMounted = false,
    className,
    children,
}: {
    tab: string;
    active: string;
    keepMounted?: boolean;
    className?: string;
    children: ReactNode;
}) {
    const isActive = tab === active;

    if (!keepMounted && !isActive) {
        return null;
    }

    return (
        <div
            role="tabpanel"
            id={`panel-${tab}`}
            aria-labelledby={`tab-${tab}`}
            hidden={!isActive}
            className={cn('space-y-6', className)}
        >
            {children}
        </div>
    );
}
