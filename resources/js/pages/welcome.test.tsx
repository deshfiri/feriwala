// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
        Head: () => null,
        Link: ({
            href,
            children,
            ...rest
        }: {
            href: string | { url: string };
            children?: unknown;
        }) =>
            createElement(
                'a',
                { href: typeof href === 'string' ? href : href.url, ...rest },
                children as never,
            ),
        usePage: () => ({
            props: {
                auth: { user: null },
                branding: {
                    logo_url: '/logo.png',
                    favicon_url: '/favicon.svg',
                    favicon_type: 'image/svg+xml',
                    accent: null,
                },
            },
        }),
    };
});

const { default: Welcome } = await import('./welcome');

/**
 * The public home page keeps its own neutral palette, but its accent — the
 * "Business Portal" dot and the quotation mark — must come from the brand
 * token, so the colour an administrator picks under Admin → Branding reaches
 * it like every other page.
 */
describe('the public home page', () => {
    it('paints its accent marks in the brand colour', () => {
        const { container } = render(<Welcome />);

        expect(screen.getByText('Business Portal')).toBeInTheDocument();
        expect(container.querySelector('.bg-brand')).not.toBeNull();
        expect(container.querySelector('.text-brand\\/30')).not.toBeNull();
    });

    it('carries no fixed accent colour of its own', () => {
        const { container } = render(<Welcome />);

        expect(container.innerHTML).not.toMatch(
            /(bg|text|border|ring)-(orange|amber|red|rose)-\d{2,3}/,
        );
    });
});
