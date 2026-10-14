/**
 * What a screen looks like, learned from the screen itself.
 *
 * A skeleton is only worth showing if it is the right shape: one that is a table
 * where the page is a form moves everything when the real page lands. There are
 * hundreds of screens, so none carries a hand-drawn skeleton. Instead, once a
 * page has rendered, its layout is measured (a table of this many columns and
 * rows, this many figures, this many panels) and remembered against the
 * screen's URL pattern. The next visit to any screen of that pattern — another
 * product, another order — is drawn from that memory. A screen never seen
 * before gets a shape guessed from its URL.
 */

export type PageShape = 'list' | 'dashboard' | 'form' | 'detail';

export type PageSpec = {
    shape: PageShape;
    /** Table columns, for a list. */
    columns: number;
    /** Table rows, for a list. */
    rows: number;
    /** Figures in the stat row above the content. */
    stats: number;
    /** Panels (cards) below the header. */
    sections: number;
    narrow: boolean;
    /** Whether the header carried a row of actions. */
    actions: boolean;
};

const STORAGE_KEY = 'page-shapes:v1';

const ID_SEGMENT = /^([0-9A-Za-z]{26}|\d+|[0-9a-f]{8}-[0-9a-f-]{27})$/;

/** "/admin/orders/01J…/edit" → "/admin/orders/:id/edit". */
export function patternOf(pathname: string): string {
    return pathname
        .split('/')
        .map((segment) => (ID_SEGMENT.test(segment) ? ':id' : segment))
        .join('/');
}

/**
 * A shape for a screen nobody has opened yet, from its URL alone.
 */
export function guessSpec(pathname: string): PageSpec {
    const pattern = patternOf(pathname);
    const segments = pattern.split('/').filter(Boolean);
    const last = segments[segments.length - 1] ?? '';

    const base = {
        columns: 5,
        rows: 8,
        stats: 0,
        sections: 2,
        narrow: false,
        actions: true,
    };

    if (last === 'dashboard' || pattern === '/supplier' || pattern === '/') {
        return { ...base, shape: 'dashboard', stats: 4, sections: 2 };
    }

    if (
        ['create', 'edit', 'new'].includes(last) ||
        segments.includes('settings') ||
        ['profile', 'security', 'appearance'].includes(last) ||
        last.endsWith('-settings')
    ) {
        return { ...base, shape: 'form', narrow: true, actions: false };
    }

    if (last === ':id' || segments.includes(':id')) {
        return { ...base, shape: 'detail', sections: 3 };
    }

    return { ...base, shape: 'list' };
}

function readAll(): Record<string, PageSpec> {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);

        return raw ? (JSON.parse(raw) as Record<string, PageSpec>) : {};
    } catch {
        return {};
    }
}

/**
 * The shape to draw while a screen loads: what was last measured for its URL
 * pattern, or a guess.
 */
export function specFor(pathname: string): PageSpec {
    return readAll()[patternOf(pathname)] ?? guessSpec(pathname);
}

/**
 * Measure the screen that just rendered and remember it for its pattern.
 */
export function learnFrom(main: HTMLElement | null, pathname: string): void {
    if (!main) {
        return;
    }

    const table = main.querySelector('table');
    const columns = table?.querySelectorAll('thead th').length ?? 0;
    const rows = table?.querySelectorAll('tbody tr').length ?? 0;
    const stats = main.querySelector('dl.grid')?.children.length ?? 0;
    const sections = main.querySelectorAll('[data-slot="card"]').length;
    const narrow = main.querySelector('.max-w-4xl') !== null;
    const hasForm = main.querySelector('form') !== null;

    const shape: PageShape = table
        ? 'list'
        : stats >= 3
          ? 'dashboard'
          : narrow && hasForm
            ? 'form'
            : 'detail';

    const spec: PageSpec = {
        shape,
        columns: Math.min(Math.max(columns, 3), 9),
        rows: Math.min(Math.max(rows, 4), 12),
        stats: Math.min(stats, 6),
        sections: Math.min(Math.max(sections - stats, 1), 5),
        narrow,
        actions:
            main
                .querySelector('h1')
                ?.closest('div')
                ?.parentElement?.querySelector(
                    ':scope > div:last-child button, :scope > div:last-child a',
                ) !== null,
    };

    try {
        localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify({ ...readAll(), [patternOf(pathname)]: spec }),
        );
    } catch {
        // Nothing to remember it in; the next visit guesses instead.
    }
}
