import { router } from '@inertiajs/react';
import { CornerDownLeft, Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useNavigation } from '@/hooks/use-navigation';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { NavItem } from '@/types';

type SearchResult = {
    item: NavItem;
    group: string;
};

/**
 * Whether this machine reports a Mac, which decides ⌘ against Ctrl in the hint.
 *
 * Read once at module scope rather than per render, and guarded for SSR where
 * there is no navigator at all.
 */
const isAppleDevice =
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad/.test(navigator.platform);

/**
 * Jump to any page this person can reach, from the keyboard (§33.2).
 *
 * Deliberately a navigator, not a content search. There is no search index
 * behind the ERP yet, and a box that accepts an order number and silently
 * returns nothing is worse than no box — it teaches people the feature is
 * broken. This searches what it can actually deliver: the destinations in
 * `useNavigation`, which is the same list the sidebar renders.
 */
export default function GlobalSearch() {
    const { t } = useTranslation();
    const { searchGroups } = useNavigation();

    const [isOpen, setIsOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [highlighted, setHighlighted] = useState(0);

    const results = useMemo<SearchResult[]>(() => {
        const destinations = searchGroups.flatMap((group) =>
            group.items.flatMap((item) => [
                { item, group: group.label },
                // Children are destinations in their own right; someone
                // searching "document types" should not have to know it sits
                // under a parent entry.
                ...(item.items ?? []).map((child) => ({
                    item: child,
                    group: group.label,
                })),
            ]),
        );

        const needle = query.trim().toLowerCase();

        if (needle === '') {
            return destinations;
        }

        return destinations.filter(
            ({ item, group }) =>
                item.title.toLowerCase().includes(needle) ||
                group.toLowerCase().includes(needle),
        );
    }, [searchGroups, query]);

    // ⌘K / Ctrl+K from anywhere, the shortcut people already expect.
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (
                event.key.toLowerCase() === 'k' &&
                (event.metaKey || event.ctrlKey)
            ) {
                event.preventDefault();
                setIsOpen((open) => !open);
            }
        };

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, []);

    // A stale highlight after the list shrinks would send Enter somewhere the
    // user cannot see.
    useEffect(() => {
        setHighlighted(0);
    }, [query]);

    const visit = (item: NavItem) => {
        setIsOpen(false);
        setQuery('');
        router.visit(item.href);
    };

    const onListKeyDown = (event: React.KeyboardEvent) => {
        if (results.length === 0) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setHighlighted((index) => (index + 1) % results.length);
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            setHighlighted(
                (index) => (index - 1 + results.length) % results.length,
            );
        }

        if (event.key === 'Enter') {
            event.preventDefault();
            visit(results[highlighted].item);
        }
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setIsOpen(true)}
                aria-label={t('nav.search.open')}
                className={cn(
                    'text-muted-foreground border-border bg-background flex h-9 items-center gap-2 rounded-lg border px-3',
                    'hover:border-brand-border hover:text-foreground transition-colors',
                    'md:w-64 lg:w-80',
                )}
            >
                <Search aria-hidden="true" className="size-4 shrink-0" />
                <span className="hidden flex-1 text-left text-sm md:inline">
                    {t('nav.search.placeholder')}
                </span>
                <kbd
                    aria-hidden="true"
                    className="bg-muted text-muted-foreground hidden rounded px-1.5 py-0.5 text-[10px] font-medium md:inline"
                >
                    {isAppleDevice ? '⌘K' : 'Ctrl K'}
                </kbd>
            </button>

            <Dialog open={isOpen} onOpenChange={setIsOpen}>
                <DialogContent
                    className="top-[15%] max-w-xl translate-y-0 gap-0 overflow-hidden p-0 [&>button:last-child]:hidden"
                    onKeyDown={onListKeyDown}
                >
                    <DialogTitle className="sr-only">
                        {t('nav.search.title')}
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        {t('nav.search.description')}
                    </DialogDescription>

                    <div className="border-border flex items-center gap-3 border-b px-4">
                        <Search
                            aria-hidden="true"
                            className="text-muted-foreground size-4 shrink-0"
                        />
                        <input
                            autoFocus
                            type="text"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder={t('nav.search.placeholder')}
                            aria-label={t('nav.search.title')}
                            className="placeholder:text-muted-foreground h-12 flex-1 bg-transparent text-sm outline-hidden"
                        />
                    </div>

                    <div className="max-h-80 overflow-y-auto p-2">
                        {results.length === 0 ? (
                            <p className="text-muted-foreground px-3 py-6 text-center text-sm">
                                {t('nav.search.no_results', { query })}
                            </p>
                        ) : (
                            <ul
                                role="listbox"
                                aria-label={t('nav.search.title')}
                            >
                                {results.map(({ item, group }, index) => (
                                    <li key={`${group}-${item.title}`}>
                                        <button
                                            type="button"
                                            role="option"
                                            aria-selected={
                                                index === highlighted
                                            }
                                            onClick={() => visit(item)}
                                            onMouseEnter={() =>
                                                setHighlighted(index)
                                            }
                                            className={cn(
                                                'flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-sm',
                                                index === highlighted
                                                    ? 'bg-brand-subtle text-brand'
                                                    : 'text-foreground',
                                            )}
                                        >
                                            {item.icon && (
                                                <item.icon
                                                    aria-hidden="true"
                                                    className="size-4 shrink-0"
                                                />
                                            )}
                                            <span className="flex-1 truncate">
                                                {item.title}
                                            </span>
                                            <span className="text-muted-foreground truncate text-xs">
                                                {group}
                                            </span>
                                            {index === highlighted && (
                                                <CornerDownLeft
                                                    aria-hidden="true"
                                                    className="size-3.5 shrink-0"
                                                />
                                            )}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <p className="text-muted-foreground border-border border-t px-4 py-2 text-xs">
                        {t('nav.search.hint')}
                    </p>
                </DialogContent>
            </Dialog>
        </>
    );
}
