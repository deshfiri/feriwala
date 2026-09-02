import type { ReactNode } from 'react';

/**
 * Laravel's length-aware paginator, as it arrives over Inertia.
 *
 * Pagination is always server-side (§39). The client never holds a full result
 * set — a partner with 40,000 orders would otherwise ship 40,000 rows to a phone.
 */
export type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    from: number | null;
    to: number | null;
    total: number;
};

export type SortDirection = 'asc' | 'desc';

export type TableSort = {
    column: string;
    direction: SortDirection;
};

/**
 * How important a column is when space runs out.
 *
 * `primary` columns survive on the narrowest screen; `secondary` ones are the
 * first to go. Marking every column primary defeats the purpose — choose the
 * two or three that let someone decide what to do with the row.
 */
export type ColumnPriority = 'primary' | 'secondary';

export type Column<T> = {
    /** Stable key, also used as the sort parameter sent to the server. */
    key: string;
    header: string;
    cell: (row: T) => ReactNode;
    align?: 'start' | 'end';
    sortable?: boolean;
    priority?: ColumnPriority;
    /** Hidden by default but offered in the column menu. */
    hiddenByDefault?: boolean;
    /** Excluded from the column menu — for a row's actions cell. */
    alwaysVisible?: boolean;
    width?: string;
};

/**
 * What the table is currently showing.
 *
 * Every table can be in any of these; §33.10 requires all of them to exist
 * rather than only the happy path.
 */
export type TableState = 'ready' | 'loading' | 'error' | 'forbidden';
