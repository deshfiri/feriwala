import { useEffect, useRef, useState } from 'react';

/**
 * The rendered width of an element, tracked as it changes.
 *
 * An SVG chart needs real pixels to place a label or a tick: scaling a viewBox
 * instead would stretch the type and the stroke along with the plot. Measuring
 * is what lets the drawing stay in the same units the rest of the page is in.
 *
 * Zero until the first measurement, which is the caller's cue to render nothing
 * rather than a chart laid out against a width that does not exist yet.
 */
export function useMeasuredWidth<T extends HTMLElement>(): {
    ref: React.RefObject<T | null>;
    width: number;
} {
    const ref = useRef<T>(null);
    const [width, setWidth] = useState(0);

    useEffect(() => {
        const element = ref.current;

        if (element === null) {
            return;
        }

        // Set once up front: an element that never resizes would otherwise wait
        // for an observation that never comes.
        setWidth(element.clientWidth);

        if (typeof ResizeObserver === 'undefined') {
            return;
        }

        const observer = new ResizeObserver((entries) => {
            for (const entry of entries) {
                setWidth(entry.contentRect.width);
            }
        });

        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    return { ref, width };
}
