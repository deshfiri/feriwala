import { Head, usePage } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * The custom properties the accent colour is written into. Mirrors the
 * inline style `app.blade.php` paints before the stylesheet loads.
 */
const ACCENT_PROPERTIES = {
    color: '--brand-base',
    on: '--brand-on',
    lifted_on: '--brand-lifted-on',
} as const;

/**
 * The browser icon and the accent colour, kept in step with the shared
 * branding contract.
 *
 * The icon is keyed as `favicon`, the same key the root template gives its own
 * icon link, so Inertia adopts that element and updates it in place: one icon
 * link on the page however many screens are visited, and a new icon shows the
 * moment it is uploaded.
 *
 * The accent is written onto `<html>`, where the root template already put it
 * on first load. Doing it again here is what makes a newly saved colour take
 * effect without a reload, and what clears it when the default is restored.
 */
export default function BrandingHead() {
    const { branding } = usePage().props;
    const accent = branding?.accent ?? null;

    useEffect(() => {
        const root = document.documentElement.style;

        (
            Object.keys(ACCENT_PROPERTIES) as (keyof typeof ACCENT_PROPERTIES)[]
        ).forEach((key) => {
            if (accent) {
                root.setProperty(ACCENT_PROPERTIES[key], accent[key]);
            } else {
                root.removeProperty(ACCENT_PROPERTIES[key]);
            }
        });
    }, [accent]);

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
