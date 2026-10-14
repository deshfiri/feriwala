import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * The path of the screen being navigated to, once the wait is long enough to
 * deserve a skeleton — or null.
 *
 * Only a navigation to a *different* screen counts: a filter change, a page
 * turn, a form post and the realtime reload all keep the screen they are on, and
 * a skeleton over those would be a flash every few seconds. The short delay is
 * the same idea in time: a screen that arrives in a blink is never replaced by a
 * placeholder first.
 */
export function useNavigationPending(delayMs = 150): string | null {
    const [target, setTarget] = useState<string | null>(null);

    useEffect(() => {
        let timer: ReturnType<typeof setTimeout> | undefined;

        const stop = () => {
            clearTimeout(timer);
            setTarget(null);
        };

        const offStart = router.on('start', (event) => {
            const visit = event.detail.visit;

            if (
                visit.method !== 'get' ||
                visit.prefetch ||
                visit.async ||
                visit.url.pathname === window.location.pathname
            ) {
                return;
            }

            clearTimeout(timer);
            timer = setTimeout(() => setTarget(visit.url.pathname), delayMs);
        });

        const offFinish = router.on('finish', stop);

        return () => {
            offStart();
            offFinish();
            clearTimeout(timer);
        };
    }, [delayMs]);

    return target;
}

/**
 * Whether the screen already open is reloading its own data because its query
 * changed — a search, a filter, a sort, another page of a table.
 *
 * A reload to the same URL (the realtime refresh) is deliberately not one: the
 * rows are still right, and replacing them with a skeleton every few seconds
 * would be worse than the staleness it hides.
 */
export function useQueryReloading(delayMs = 120): boolean {
    const [reloading, setReloading] = useState(false);

    useEffect(() => {
        let timer: ReturnType<typeof setTimeout> | undefined;

        const stop = () => {
            clearTimeout(timer);
            setReloading(false);
        };

        const offStart = router.on('start', (event) => {
            const visit = event.detail.visit;

            if (
                visit.method !== 'get' ||
                visit.prefetch ||
                visit.url.pathname !== window.location.pathname ||
                visit.url.search === window.location.search
            ) {
                return;
            }

            clearTimeout(timer);
            timer = setTimeout(() => setReloading(true), delayMs);
        });

        const offFinish = router.on('finish', stop);

        return () => {
            offStart();
            offFinish();
            clearTimeout(timer);
        };
    }, [delayMs]);

    return reloading;
}
