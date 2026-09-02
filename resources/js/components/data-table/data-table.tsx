import { ArrowDown, ArrowUp, ArrowUpDown, Search } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import ColumnVisibilityMenu from '@/components/data-table/column-visibility-menu';
import TablePagination from '@/components/data-table/table-pagination';
import EmptyState from '@/components/states/empty-state';
import ErrorState from '@/components/states/error-state';
import PermissionDeniedState from '@/components/states/permission-denied-state';
import TableSkeleton from '@/components/states/table-skeleton';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { Column, Paginator, TableState } from '@/types';

/**
 * The table behind roughly fifty ERP screens.
 *
 * Covers §33.6 in one place: search, filters, sorting, server-side pagination,
 * column visibility, bulk actions, export, and the loading, empty, error, and
 * permission-denied states. Building it once is what stops fifty screens from
 * each inventing their own half of it.
 *
 * Sorting, searching, and paging are all server round trips (§39). The component
 * holds one page of rows and never filters in the browser, because a browser
 * cannot filter rows it was never sent.
 */
export default function DataTable<T>({
    columns,
    paginator,
    rowKey,
    state = 'ready',
    searchable = true,
    searchPlaceholder,
    filters,
    actions,
    selection,
    bulkActions,
    renderCard,
    emptyState,
    onRetry,
    onlyReload,
    caption,
}: {
    columns: Column<T>[];
    paginator: Paginator<T>;
    rowKey: (row: T) => string | number;
    state?: TableState;
    searchable?: boolean;
    searchPlaceholder?: string;
    /** Filter controls rendered in the toolbar. */
    filters?: ReactNode;
    /** Buttons on the right of the toolbar — export, create, and so on. */
    actions?: ReactNode;
    selection?: {
        selected: Set<string | number>;
        onChange: (selected: Set<string | number>) => void;
    };
    /** Rendered in place of the toolbar while rows are selected. */
    bulkActions?: (selected: Set<string | number>) => ReactNode;
    /**
     * Mobile presentation. When given, rows become cards below `md` instead of
     * scrolling sideways — the difference between a usable phone screen and a
     * table nobody can read (§33.6, §33.8).
     */
    renderCard?: (row: T) => ReactNode;
    emptyState?: ReactNode;
    onRetry?: () => void;
    /** Inertia partial-reload keys, so a filter change re-fetches only the table. */
    onlyReload?: string[];
    caption?: string;
}) {
    const { t } = useTranslation();
    const { search, setSearch, sort, toggleSort, goToPage } = useTableQuery({
        only: onlyReload,
    });

    const [hidden, setHidden] = useState<Set<string>>(
        () =>
            new Set(
                columns
                    .filter((column) => column.hiddenByDefault)
                    .map((column) => column.key),
            ),
    );

    const visibleColumns = columns.filter((column) => !hidden.has(column.key));

    const rows = paginator.data;
    const selectedCount = selection?.selected.size ?? 0;
    const allOnPageSelected =
        rows.length > 0 &&
        rows.every((row) => selection?.selected.has(rowKey(row)));

    const toggleAllOnPage = () => {
        if (!selection) return;

        const next = new Set(selection.selected);

        if (allOnPageSelected) {
            rows.forEach((row) => next.delete(rowKey(row)));
        } else {
            rows.forEach((row) => next.add(rowKey(row)));
        }

        selection.onChange(next);
    };

    const toggleRow = (row: T) => {
        if (!selection) return;

        const key = rowKey(row);
        const next = new Set(selection.selected);

        if (next.has(key)) {
            next.delete(key);
        } else {
            next.add(key);
        }

        selection.onChange(next);
    };

    const body = () => {
        if (state === 'forbidden') return <PermissionDeniedState />;
        if (state === 'error') return <ErrorState onRetry={onRetry} />;
        if (state === 'loading') {
            return (
                <TableSkeleton
                    columns={visibleColumns.length}
                    rows={paginator.per_page > 10 ? 8 : paginator.per_page}
                />
            );
        }
        if (rows.length === 0) {
            return (
                emptyState ?? (
                    <EmptyState
                        description={
                            search ? t('common.table.no_results') : undefined
                        }
                    />
                )
            );
        }

        return (
            <>
                {/* Cards below md when the caller supplies a mobile shape. */}
                {renderCard && (
                    <div className="divide-border divide-y md:hidden">
                        {rows.map((row) => (
                            <div key={rowKey(row)} className="p-3">
                                {renderCard(row)}
                            </div>
                        ))}
                    </div>
                )}

                <div
                    className={cn(
                        'overflow-x-auto',
                        renderCard && 'hidden md:block',
                    )}
                >
                    <table className="w-full border-collapse text-sm">
                        {caption && (
                            <caption className="sr-only">{caption}</caption>
                        )}

                        <thead>
                            <tr className="bg-muted/60 border-border border-b">
                                {selection && (
                                    <th scope="col" className="w-9 px-4 py-2">
                                        <Checkbox
                                            checked={allOnPageSelected}
                                            onCheckedChange={toggleAllOnPage}
                                            aria-label="Select all rows on this page"
                                        />
                                    </th>
                                )}

                                {visibleColumns.map((column) => (
                                    <th
                                        key={column.key}
                                        scope="col"
                                        style={{ width: column.width }}
                                        className={cn(
                                            'text-muted-foreground px-4 py-2 text-[11px] font-semibold tracking-wide whitespace-nowrap uppercase',
                                            column.align === 'end'
                                                ? 'text-right'
                                                : 'text-left',
                                            column.priority === 'secondary' &&
                                                'hidden lg:table-cell',
                                        )}
                                        aria-sort={
                                            sort?.column === column.key
                                                ? sort.direction === 'asc'
                                                    ? 'ascending'
                                                    : 'descending'
                                                : undefined
                                        }
                                    >
                                        {column.sortable ? (
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    toggleSort(column.key)
                                                }
                                                className={cn(
                                                    'hover:text-foreground inline-flex items-center gap-1 uppercase',
                                                    column.align === 'end' &&
                                                        'flex-row-reverse',
                                                )}
                                            >
                                                {column.header}
                                                {sort?.column !== column.key ? (
                                                    <ArrowUpDown
                                                        className="size-3 opacity-50"
                                                        aria-hidden="true"
                                                    />
                                                ) : sort.direction === 'asc' ? (
                                                    <ArrowUp
                                                        className="text-brand size-3"
                                                        aria-hidden="true"
                                                    />
                                                ) : (
                                                    <ArrowDown
                                                        className="text-brand size-3"
                                                        aria-hidden="true"
                                                    />
                                                )}
                                            </button>
                                        ) : (
                                            column.header
                                        )}
                                    </th>
                                ))}
                            </tr>
                        </thead>

                        <tbody>
                            {rows.map((row) => {
                                const key = rowKey(row);
                                const isSelected = selection?.selected.has(key);

                                return (
                                    <tr
                                        key={key}
                                        data-selected={isSelected || undefined}
                                        className="border-border hover:bg-muted/50 data-[selected]:bg-brand-subtle border-b last:border-b-0"
                                    >
                                        {selection && (
                                            <td className="px-4 py-2">
                                                <Checkbox
                                                    checked={isSelected}
                                                    onCheckedChange={() =>
                                                        toggleRow(row)
                                                    }
                                                    aria-label="Select row"
                                                />
                                            </td>
                                        )}

                                        {visibleColumns.map((column) => (
                                            <td
                                                key={column.key}
                                                className={cn(
                                                    'px-4 py-2 align-middle',
                                                    column.align === 'end' &&
                                                        'text-right tabular-nums',
                                                    column.priority ===
                                                        'secondary' &&
                                                        'hidden lg:table-cell',
                                                )}
                                            >
                                                {column.cell(row)}
                                            </td>
                                        ))}
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </>
        );
    };

    return (
        <div className="bg-card border-border overflow-hidden rounded-lg border shadow-sm">
            <div className="border-border flex flex-wrap items-center gap-2 border-b px-3 py-2.5">
                {selectedCount > 0 && bulkActions ? (
                    <>
                        <span className="text-sm font-medium tabular-nums">
                            {t('common.table.rows_selected', {
                                count: selectedCount,
                            })}
                        </span>
                        <div className="ml-auto flex items-center gap-2">
                            {bulkActions(selection!.selected)}
                        </div>
                    </>
                ) : (
                    <>
                        {searchable && (
                            <div className="relative max-w-xs flex-1">
                                <Search
                                    className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2"
                                    aria-hidden="true"
                                />
                                <Input
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    placeholder={
                                        searchPlaceholder ??
                                        t('common.table.search_placeholder')
                                    }
                                    className="h-8 pl-8 text-sm"
                                    type="search"
                                />
                            </div>
                        )}

                        {filters}

                        <div className="ml-auto flex items-center gap-2">
                            {actions}
                            <ColumnVisibilityMenu
                                columns={columns}
                                hidden={hidden}
                                onToggle={(key) =>
                                    setHidden((current) => {
                                        const next = new Set(current);

                                        if (next.has(key)) {
                                            next.delete(key);
                                        } else {
                                            next.add(key);
                                        }

                                        return next;
                                    })
                                }
                            />
                        </div>
                    </>
                )}
            </div>

            {body()}

            {state === 'ready' && rows.length > 0 && (
                <TablePagination
                    paginator={paginator}
                    onPageChange={goToPage}
                />
            )}
        </div>
    );
}
