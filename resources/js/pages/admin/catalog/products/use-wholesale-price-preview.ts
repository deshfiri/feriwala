import { useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ProductController from '@/actions/App/Http/Controllers/Admin/ProductController';
import type { Money } from '@/lib/money';

export type WholesalePricePreview = {
    base_cost: Money;
    wholesale_price: Money;
    /** The percentage as the server normalised it, e.g. "12.5". */
    markup_percentage: string;
};

type PreviewRequest = {
    base_cost: string;
    wholesale_markup_percentage: string;
};

const DEBOUNCE_MS = 250;

/**
 * The wholesale price a base cost and markup give, asked of the server.
 *
 * The browser never does this arithmetic (§36.1, D26): this posts the two typed
 * strings to the same calculator the save uses, so what is shown is what will be
 * stored. While `enabled` is false, or an input is blank or refused, the preview
 * is simply null. A late answer to an older request is dropped, so typing
 * quickly cannot leave a stale figure on screen.
 */
export default function useWholesalePricePreview(
    enabled: boolean,
    baseCost: string,
    markup: string,
): { preview: WholesalePricePreview | null; calculating: boolean } {
    const [preview, setPreview] = useState<WholesalePricePreview | null>(null);
    const [calculating, setCalculating] = useState(false);
    const latest = useRef(0);

    const { submit } = useHttp<PreviewRequest, WholesalePricePreview>(
        () => ProductController.pricePreview(),
        () => ({
            base_cost: baseCost.trim(),
            wholesale_markup_percentage: markup.trim(),
        }),
    );

    useEffect(() => {
        const request = ++latest.current;

        if (!enabled || baseCost.trim() === '' || markup.trim() === '') {
            setCalculating(false);
            setPreview(null);

            return;
        }

        setCalculating(true);

        const timer = window.setTimeout(async () => {
            try {
                const answer = await submit();

                if (request === latest.current) {
                    setPreview(answer);
                }
            } catch {
                // A refused value (422) has no price to show; the field's own
                // validation message says why once the form is submitted.
                if (request === latest.current) {
                    setPreview(null);
                }
            } finally {
                if (request === latest.current) {
                    setCalculating(false);
                }
            }
        }, DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
        // `submit` is recreated every render; the typed inputs are the trigger.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [enabled, baseCost, markup]);

    return { preview, calculating };
}
