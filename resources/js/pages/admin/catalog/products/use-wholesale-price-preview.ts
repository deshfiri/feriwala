import { useEffect, useRef, useState } from 'react';
import ProductController from '@/actions/App/Http/Controllers/Admin/ProductController';
import type { Money } from '@/lib/money';

export type WholesalePricePreview = {
    base_cost: Money;
    wholesale_price: Money;
    /** The percentage as the server normalised it, e.g. "12.5". */
    markup_percentage: string;
};

const DEBOUNCE_MS = 250;

const xsrfToken = (): string => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
};

/**
 * The wholesale price a base cost and markup give, asked of the server.
 *
 * The browser never does this arithmetic (§36.1, D26): this posts the two typed
 * strings to the same calculator the save uses, so what is shown is what will be
 * stored. A plain request rather than a form hook, so the values sent are always
 * exactly the ones passed in. While `enabled` is false, or an input is blank,
 * the preview is null; a refused or failed request sets `failed` so the screen
 * can say so instead of showing nothing. A late answer to an older request is
 * dropped, so typing quickly cannot leave a stale figure on screen.
 */
export default function useWholesalePricePreview(
    enabled: boolean,
    baseCost: string,
    markup: string,
): {
    preview: WholesalePricePreview | null;
    calculating: boolean;
    failed: boolean;
} {
    const [preview, setPreview] = useState<WholesalePricePreview | null>(null);
    const [calculating, setCalculating] = useState(false);
    const [failed, setFailed] = useState(false);
    const latest = useRef(0);

    useEffect(() => {
        const request = ++latest.current;

        setFailed(false);

        if (!enabled || baseCost.trim() === '' || markup.trim() === '') {
            setCalculating(false);
            setPreview(null);

            return;
        }

        setCalculating(true);

        const timer = window.setTimeout(async () => {
            try {
                const response = await fetch(
                    ProductController.pricePreview.url(),
                    {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-XSRF-TOKEN': xsrfToken(),
                        },
                        body: JSON.stringify({
                            base_cost: baseCost.trim(),
                            wholesale_markup_percentage: markup.trim(),
                        }),
                    },
                );

                if (request !== latest.current) {
                    return;
                }

                if (!response.ok) {
                    setPreview(null);
                    setFailed(true);

                    return;
                }

                setPreview((await response.json()) as WholesalePricePreview);
            } catch {
                if (request === latest.current) {
                    setPreview(null);
                    setFailed(true);
                }
            } finally {
                if (request === latest.current) {
                    setCalculating(false);
                }
            }
        }, DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [enabled, baseCost, markup]);

    return { preview, calculating, failed };
}
