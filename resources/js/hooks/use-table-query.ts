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

    const [search, setSearch] = useState(params.get('search') ?? '');
    const debounce = useRef<ReturnType<typeof setTimeout> | null>(null);
    const isFirstRender = useRef(true);

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
                only: only.length ? only : undefined,
            });
        },
        [only],
    );

    // Debounce the search box so a fast typist does not fire a request per
    // keystroke against a table of hundreds of thousands of rows.
    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;

            return;
        }

        if (debounce.current) {
            clearTimeout(debounce.current);
        }

        debounce.current = setTimeout(() => {
            // Any change to the result set returns to page one; staying on
            // page 7 of a narrower result set shows an empty table.
            visit({ search: search || undefined, page: undefined });
        }, searchDebounceMs);

        return () => {
            if (debounce.current) {
                clearTimeout(debounce.current);
            }
        };
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
