// @vitest-environment jsdom

import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const { page } = vi.hoisted(() => ({
    page: { props: {} as Record<string, unknown> },
}));

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
        usePage: () => page,
        // Stands in for Inertia's head manager: renders what it is given.
        Head: ({ children }: { children?: unknown }) =>
            createElement('div', { 'data-head': '' }, children as never),
    };
});

const { default: BrandingHead } = await import('./branding-head');

declare global {
    /** React reads this to decide whether `act` is being used correctly. */
    var IS_REACT_ACT_ENVIRONMENT: boolean | undefined;
}

/**
 * The browser icon is one keyed link: the root template renders it with
 * `data-inertia="favicon"`, and this keeps it in step, so navigating replaces
 * it rather than stacking another beside it.
 *
 * Read from `document.head`: React hoists a rendered `<link rel="icon">` there,
 * so the head is where a duplicate would actually appear.
 */
describe('the browser icon', () => {
    let container: HTMLDivElement;
    let root: Root;

    beforeEach(() => {
        globalThis.IS_REACT_ACT_ENVIRONMENT = true;
        container = document.createElement('div');
        root = createRoot(container);
    });

    afterEach(() => {
        act(() => root.unmount());
    });

    it('renders one icon link keyed as the favicon', () => {
        page.props = {
            branding: {
                logo_url: '/logo.png',
                favicon_url: '/favicon.svg',
                favicon_type: 'image/svg+xml',
            },
        };
        act(() => root.render(<BrandingHead />));

        const links = document.head.querySelectorAll('link[rel="icon"]');

        expect(links).toHaveLength(1);
        expect(links[0].getAttribute('head-key')).toBe('favicon');
        expect(links[0].getAttribute('href')).toBe('/favicon.svg');
        expect(links[0].getAttribute('type')).toBe('image/svg+xml');
    });

    it('follows a newly uploaded icon without adding a second link', () => {
        page.props = {
            branding: {
                logo_url: '/logo.png',
                favicon_url: '/favicon.svg',
                favicon_type: 'image/svg+xml',
            },
        };
        act(() => root.render(<BrandingHead />));

        page.props = {
            branding: {
                logo_url: '/logo.png',
                favicon_url:
                    'https://erp.example/storage/branding/favicon-abc.png',
                favicon_type: 'image/png',
            },
        };
        act(() => root.render(<BrandingHead />));

        const links = document.head.querySelectorAll('link[rel="icon"]');

        expect(links).toHaveLength(1);
        expect(links[0].getAttribute('href')).toBe(
            'https://erp.example/storage/branding/favicon-abc.png',
        );
        expect(links[0].getAttribute('type')).toBe('image/png');
    });

    it('falls back to the shipped icon when the contract is absent', () => {
        page.props = {};
        act(() => root.render(<BrandingHead />));

        expect(
            document.head
                .querySelector('link[rel="icon"]')
                ?.getAttribute('href'),
        ).toBe('/favicon.svg');
    });
});
