import { Head, usePage } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * The custom properties the accent and button colours are written into.
 * Mirrors the inline style `app.blade.php` paints before the stylesheet loads.
 */
const ACCENT_PROPERTIES = {
    color: '--brand-base',
    on: '--brand-on',
    lifted_on: '--brand-lifted-on',
} as const;

const BUTTON_PROPERTIES = {
    color: '--button-base',
    on: '--button-on',
    lifted_on: '--button-lifted-on',
} as const;

const NAV_PROPERTIES = {
    color: '--nav-base',
    on: '--nav-on',
    lifted_on: '--nav-lifted-on',
} as const;

type Properties =
    | typeof ACCENT_PROPERTIES
    | typeof BUTTON_PROPERTIES
    | typeof NAV_PROPERTIES;

function syncProperties(
    properties: Properties,
    value: { color: string; on: string; lifted_on: string } | null,
) {
    const root = document.documentElement.style;

    (Object.keys(properties) as (keyof Properties)[]).forEach((key) => {
        if (value) {
            root.setProperty(properties[key], value[key]);
        } else {
            root.removeProperty(properties[key]);
        }
    });
}

/**
 * The browser icon, the accent colour and the button colour, kept in step with
 * the shared branding contract.
 *
 * The icon is keyed as `favicon`, the same key the root template gives its own
 * icon link, so Inertia adopts that element and updates it in place: one icon
 * link on the page however many screens are visited, and a new icon shows the
 * moment it is uploaded.
 *
 * The colours are written onto `<html>`, where the root template already put
 * them on first load. Doing it again here is what makes a newly saved colour
 * take effect without a reload, and what clears it when the default is
 * restored.
 */
export default function BrandingHead() {
    const { branding } = usePage().props;
    const accent = branding?.accent ?? null;
    const button = branding?.button ?? null;

    useEffect(() => {
        syncProperties(ACCENT_PROPERTIES, accent);
    }, [accent]);

    useEffect(() => {
        syncProperties(BUTTON_PROPERTIES, button);
    }, [button]);

    const nav = branding?.nav ?? null;

    useEffect(() => {
        syncProperties(NAV_PROPERTIES, nav);
    }, [nav]);

    const background = branding?.background ?? null;

    useEffect(() => {
        const root = document.documentElement.style;

        if (background) {
            root.setProperty('--canvas-custom', background);
        } else {
            root.removeProperty('--canvas-custom');
        }
    }, [background]);

    return (
        <Head>
            <link
                head-key="favicon"
                rel="icon"
                href={branding?.favicon_url ?? '/favicon.svg'}
                type={branding?.favicon_type ?? 'image/svg+xml'}
            />
        </Head>
    );
}
