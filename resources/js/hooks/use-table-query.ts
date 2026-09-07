import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { SortDirection, TableSort } from '@/types';

type QueryValue = string | number | undefined;

/**
 * Keeps search, sort, filters, and page in the URL, and asks the server for the
 * result.
 *
 * State lives in the URL rather than in component state so a filtered view can
 * be bookmarked, shared with a colleague, and survives a refresh or a back
 * button. Filtering that vanishes on reload is one of the fastest ways to make
 * an operations tool frustrating.
 *
 * Every change is a server round trip because filtering and sorting must happen
 * in the database (§39) — the browser only ever holds one page of rows.
 *
 * Nothing here polls. Every request is the direct result of a user action, so
 * an idle table must sit at zero requests.
 */
export function useTableQuery({
    only = [],
    searchDebounceMs = 300,
}: {
    /** Inertia partial-reload keys, so a filter change re-fetches the table and nothing else. */
    only?: string[];
    searchDebounceMs?: number;
} = {}) {
    const params = new URLSearchParams(
        typeof window === 'undefined' ? '' : window.location.search,
    );

    const urlSearch = params.get('search') ?? '';

    const [search, setSearch] = useState(urlSearch);

    /**
     * The search value the URL is already known to carry.
     *
     * Tracked separately from `search` so the two directions stay
     * distinguishable: the user editing the box, and the URL moving underneath
     * us because of a back button or another instance of this hook.
     */
    const syncedSearch = useRef(urlSearch);

    /**
     * `only` arrives as a fresh array on every render — an inline prop at the
     * call site, or the default above. Keying the callback on its contents
     * instead of its identity is what keeps `visit` stable; rebuilt every
     * render, it retriggers the debounce effect below, whose own request
     * re-renders the page and starts the whole cycle again.
     */
    const onlyKey = only.join(',');

    const currentSort: TableSort | null = params.get('sort')
        ? {
              column: params.get('sort') as string,
              direction: (params.get('direction') as SortDirection) ?? 'asc',
          }
        : null;

    const visit = useCallback(
        (next: Record<string, QueryValue>) => {
            const merged = new URLSearchParams(window.location.search);

            Object.entries(next).forEach(([key, value]) => {
                if (value === undefined || value === '') {
                    merged.delete(key);
                } else {
                    merged.set(key, String(value));
                }
            });

            router.get(window.location.pathname, Object.fromEntries(merged), {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: onlyKey ? onlyKey.split(',') : undefined,
            });
        },
        [onlyKey],
    );

    // Adopt the URL when it changes underneath us — a back button, or the one
    // instance of this hook that owns the search box on a page holding two.
    // Without this the effect below reads the difference as an unsent edit and
    // pushes the stale value straight back.
    useEffect(() => {
        if (urlSearch !== syncedSearch.current) {
            syncedSearch.current = urlSearch;
            setSearch(urlSearch);
        }
    }, [urlSearch]);

    // Debounce the search box so a fast typist does not fire a request per
    // keystroke against a table of hundreds of thousands of rows.
    //
    // The equality guard is what keeps this from running away: once the URL
    // carries what the box holds there is nothing left to send, so a re-render
    // — including the one caused by this visit's own response — schedules no
    // further request. The timer is local to the effect, so React's development
    // double-invoke cannot leave a second one running, and unmounting clears it.
    useEffect(() => {
        if (search === syncedSearch.current) {
            return;
        }

        const timer = setTimeout(() => {
            syncedSearch.current = search;

            // Any change to the result set returns to page one; staying on
            // page 7 of a narrower result set shows an empty table.
            visit({ search: search || undefined, page: undefined });
        }, searchDebounceMs);

        return () => clearTimeout(timer);
    }, [search, searchDebounceMs, visit]);

    /**
     * Cycle a column: ascending, then descending, then unsorted.
     *
     * The third state matters — without it there is no way back to the server's
     * default ordering once a column has been clicked.
     */
    const toggleSort = useCallback(
        (column: string) => {
            if (currentSort?.column !== column) {
                visit({ sort: column, direction: 'asc', page: undefined });

                return;
            }

            if (currentSort.direction === 'asc') {
                visit({ sort: column, direction: 'desc', page: undefined });

                return;
            }

            visit({ sort: undefined, direction: undefined, page: undefined });
        },
        [currentSort, visit],
    );

    const setFilter = useCallback(
        (key: string, value: QueryValue) =>
            visit({ [key]: value, page: undefined }),
        [visit],
    );

    const goToPage = useCallback((page: number) => visit({ page }), [visit]);

    const clearAll = useCallback(() => {
        setSearch('');
        syncedSearch.current = '';
        router.get(
            window.location.pathname,
            {},
            {
                preserveScroll: true,
                replace: true,
            },
        );
    }, []);

    return {
        search,
        setSearch,
        sort: currentSort,
        toggleSort,
        setFilter,
        goToPage,
        clearAll,
        getFilter: (key: string) => params.get(key) ?? undefined,
    };
}
