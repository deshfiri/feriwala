// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { TooltipProvider } from '@/components/ui/tooltip';
import type { NavGroup } from '@/types';

// `useIsMobile` (behind the shared `Sidebar` primitives `NavMain` renders
// into) reads `window.matchMedia` once at module load time, not per render —
// this has to be in place before `@/components/ui/sidebar` is ever imported.
window.matchMedia = vi.fn().mockReturnValue({
    matches: false,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
});

const { SidebarMenu, SidebarProvider } =
    await import('@/components/ui/sidebar');
const { NavMain } = await import('./nav-main');

/**
 * `NavMain` backs the Admin/Staff, Client/Partner and Supplier sidebars alike
 * (each layout only supplies its own `groups`) — one component, one test, not
 * three copies. The active row must be discoverable four ways at once: by
 * sight (the existing brand-tinted rail), by `data-active` (what
 * `SidebarMenuButton`'s own styling hooks into), by keyboard (a real,
 * focusable `<a>`), and by a screen reader (`aria-current="page"`, not just a
 * visual treatment nothing but the eye can read).
 */

let currentUrl = '/dashboard';

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
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
        usePage: () => ({ url: currentUrl }),
    };
});

function renderNav(groups: NavGroup[]) {
    return render(
        <TooltipProvider>
            <SidebarProvider>
                <SidebarMenu>
                    <NavMain groups={groups} />
                </SidebarMenu>
            </SidebarProvider>
        </TooltipProvider>,
    );
}

const groups: NavGroup[] = [
    {
        label: 'Overview',
        items: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Orders', href: '/orders' },
        ],
    },
];

describe('NavMain active-route accessibility', () => {
    it('marks the current route with aria-current="page" and the others with none', () => {
        currentUrl = '/dashboard';
        renderNav(groups);

        expect(screen.getByRole('link', { name: 'Dashboard' })).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(
            screen.getByRole('link', { name: 'Orders' }),
        ).not.toHaveAttribute('aria-current');
    });

    it('still exposes the visual data-active hook the active row styles from', () => {
        currentUrl = '/orders';
        renderNav(groups);

        expect(screen.getByRole('link', { name: 'Orders' })).toHaveAttribute(
            'data-active',
            'true',
        );
        expect(screen.getByRole('link', { name: 'Dashboard' })).toHaveAttribute(
            'data-active',
            'false',
        );
    });

    it('keeps every item a real, keyboard-focusable link regardless of active state', () => {
        currentUrl = '/dashboard';
        renderNav(groups);

        for (const name of ['Dashboard', 'Orders']) {
            const link = screen.getByRole('link', { name });
            link.focus();
            expect(link).toHaveFocus();
        }
    });

    it('gives the current route an accessible name a screen reader announces as current', () => {
        currentUrl = '/dashboard';
        renderNav(groups);

        // getByRole with a "current" filter is how testing-library surfaces
        // exactly what assistive tech would announce.
        expect(
            screen.getByRole('link', { name: 'Dashboard', current: 'page' }),
        ).toBeInTheDocument();
    });
});
