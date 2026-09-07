// @vitest-environment jsdom

import { act, StrictMode } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const { routerGet } = vi.hoisted(() => ({ routerGet: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router: { get: routerGet },
}));

const { useTableQuery } = await import('./use-table-query');

declare global {
    /** React reads this to decide whether `act` is being used correctly. */
    var IS_REACT_ACT_ENVIRONMENT: boolean | undefined;
}

type QueryApi = ReturnType<typeof useTableQuery>;

let api: QueryApi | null = null;

function Harness({ only }: { only?: string[] }) {
    api = useTableQuery(only === undefined ? undefined : { only });

    return null;
}

/**
 * These cover the runaway-request regression on /admin/kyc: an idle table sent
 * `GET /admin/kyc` two to three times a second because the debounce effect
 * depended on a callback that was rebuilt on every render, and its own request
 * caused the next render.
 *
 * StrictMode is deliberate — it is how the application mounts (`app.tsx`), and
 * its double effect invocation is what kicked the old loop off with no user
 * input at all.
 */
describe('useTableQuery', () => {
    let container: HTMLDivElement;
    let root: Root;
    let unmounted = false;

    const render = (only?: string[]) => {
        act(() => {
            root.render(
                <StrictMode>
                    <Harness only={only} />
                </StrictMode>,
            );
        });
    };

    beforeEach(() => {
        globalThis.IS_REACT_ACT_ENVIRONMENT = true;
        vi.useFakeTimers();
        routerGet.mockClear();
        api = null;
        unmounted = false;
        window.history.replaceState({}, '', '/admin/kyc');
        container = document.createElement('div');
        document.body.appendChild(container);
        root = createRoot(container);
    });

    afterEach(() => {
        if (!unmounted) {
            act(() => root.unmount());
        }

        container.remove();
        vi.useRealTimers();
    });

    it('sends nothing while the table sits idle', () => {
        render();

        act(() => {
            vi.advanceTimersByTime(5000);
        });

        expect(routerGet).not.toHaveBeenCalled();
    });

    it('sends nothing when the page re-renders under it', () => {
        render();

        // Every re-render used to rebuild `visit`, retrigger the debounce
        // effect, and schedule another request.
        for (let i = 0; i < 5; i++) {
            render();
            act(() => {
                vi.advanceTimersByTime(1000);
            });
        }

        expect(routerGet).not.toHaveBeenCalled();
    });

    it('sends one request once the search debounce elapses', () => {
        render();

        act(() => api?.setSearch('rahim'));

        act(() => {
            vi.advanceTimersByTime(299);
        });
        expect(routerGet).not.toHaveBeenCalled();

        act(() => {
            vi.advanceTimersByTime(1);
        });
        expect(routerGet).toHaveBeenCalledTimes(1);
    });

    it('does not request again when the response re-renders the page', () => {
        render();

        act(() => api?.setSearch('rahim'));
        act(() => {
            vi.advanceTimersByTime(300);
        });
        expect(routerGet).toHaveBeenCalledTimes(1);

        // Inertia replaces the URL, then re-renders with the new props. That
        // render is what used to feed the next request.
        act(() => {
            window.history.replaceState({}, '', '/admin/kyc?search=rahim');
        });
        render();

        act(() => {
            vi.advanceTimersByTime(10000);
        });

        expect(routerGet).toHaveBeenCalledTimes(1);
    });

    it('collapses a burst of keystrokes into a single request', () => {
        render();

        for (const value of ['r', 'ra', 'rah', 'rahi', 'rahim']) {
            act(() => api?.setSearch(value));
            act(() => {
                vi.advanceTimersByTime(50);
            });
        }

        act(() => {
            vi.advanceTimersByTime(300);
        });

        expect(routerGet).toHaveBeenCalledTimes(1);
        expect(routerGet).toHaveBeenCalledWith(
            '/admin/kyc',
            { search: 'rahim' },
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('cancels a pending search when the page unmounts', () => {
        render();

        act(() => api?.setSearch('rahim'));

        act(() => root.unmount());
        unmounted = true;

        act(() => {
            vi.advanceTimersByTime(5000);
        });

        expect(routerGet).not.toHaveBeenCalled();
    });

    it('adopts the URL instead of pushing a stale value back', () => {
        render();

        act(() => api?.setSearch('rahim'));
        act(() => {
            vi.advanceTimersByTime(300);
        });
        expect(routerGet).toHaveBeenCalledTimes(1);

        act(() => {
            window.history.replaceState({}, '', '/admin/kyc?search=rahim');
        });
        render();

        // A back button drops the search from the URL. The hook must follow it,
        // not re-send the value the box still holds.
        act(() => {
            window.history.replaceState({}, '', '/admin/kyc');
        });
        render();

        act(() => {
            vi.advanceTimersByTime(10000);
        });

        expect(routerGet).toHaveBeenCalledTimes(1);
        expect(api?.search).toBe('');
    });

    it('scopes the request to the props the caller asked for', () => {
        render(['submissions']);

        act(() => api?.setSearch('rahim'));
        act(() => {
            vi.advanceTimersByTime(300);
        });

        expect(routerGet).toHaveBeenCalledWith(
            '/admin/kyc',
            { search: 'rahim' },
            expect.objectContaining({ only: ['submissions'] }),
        );
    });

    it('asks for the whole page when the caller scopes nothing', () => {
        render();

        act(() => api?.setSearch('rahim'));
        act(() => {
            vi.advanceTimersByTime(300);
        });

        expect(routerGet).toHaveBeenCalledWith(
            '/admin/kyc',
            { search: 'rahim' },
            expect.objectContaining({ only: undefined }),
        );
    });
});
