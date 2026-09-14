import { Head, usePage } from '@inertiajs/react';

/**
 * The browser icon, kept in step with the shared branding contract.
 *
 * Keyed as `favicon`, the same key the root template gives its own icon link, so
 * Inertia adopts that element and updates it in place: one icon link on the
 * page however many screens are visited, and a new icon shows the moment it is
 * uploaded.
 */
export default function BrandingHead() {
    const { branding } = usePage().props;

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
