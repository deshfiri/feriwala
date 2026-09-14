// @vitest-environment jsdom

import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const { page } = vi.hoisted(() => ({
    page: { props: {} as Record<string, unknown> },
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
}));

const { default: AppLogoIcon } = await import('./app-logo-icon');
const { default: AppLogo } = await import('./app-logo');

declare global {
    /** React reads this to decide whether `act` is being used correctly. */
    var IS_REACT_ACT_ENVIRONMENT: boolean | undefined;
}

const uploaded = {
    logo_url: 'https://erp.example/storage/branding/logo-abc.png',
    favicon_url: 'https://erp.example/storage/branding/favicon-abc.png',
    favicon_type: 'image/png',
};

/**
 * The logo is whatever an administrator uploaded, from the shared branding
 * contract — an image with image attributes, never an inline SVG, falling back
 * to the shipped `/logo.png`.
 */
describe('the platform logo', () => {
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

    it('renders the uploaded logo as an image', () => {
        page.props = { name: 'Feriwala', branding: uploaded };
        act(() => root.render(<AppLogoIcon className="h-10" />));

        const image = container.querySelector('img');

        expect(image?.getAttribute('src')).toBe(uploaded.logo_url);
        expect(image?.getAttribute('alt')).toBe('Feriwala');
        expect(image?.className).toContain('h-10');
        expect(container.querySelector('svg')).toBeNull();
    });

    it('falls back to the shipped logo when the contract is absent', () => {
        page.props = { name: 'Feriwala' };
        act(() => root.render(<AppLogoIcon />));

        expect(container.querySelector('img')?.getAttribute('src')).toBe(
            '/logo.png',
        );
    });

    it('uses the same image in both sidebar states, keeping the name for screen readers', () => {
        page.props = { name: 'Feriwala', branding: uploaded };
        act(() => root.render(<AppLogo />));

        const image = container.querySelector('img');

        expect(image?.getAttribute('src')).toBe(uploaded.logo_url);
        expect(image?.getAttribute('alt')).toBe('');
        expect(image?.className).toContain(
            'group-data-[collapsible=icon]:size-8',
        );
        expect(container.querySelector('.sr-only')?.textContent).toBe(
            'Feriwala',
        );
    });
});
