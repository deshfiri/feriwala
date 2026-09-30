// @vitest-environment jsdom

import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// `useIsMobile` reads `window.matchMedia` at module load, so this has to be in
// place before `@/components/ui/sidebar` is imported.
window.matchMedia = vi.fn().mockReturnValue({
    matches: false,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
});

vi.mock('@/hooks/use-translation', () => ({
    useTranslation: () => ({
        t: (key: string) => key,
        locale: 'en',
        direction: 'ltr',
    }),
}));

const { SidebarProvider } = await import('@/components/ui/sidebar');
const { SidebarEdgeToggle } = await import('./sidebar-edge-toggle');

function renderEdgeToggle(defaultOpen: boolean) {
    return render(
        <SidebarProvider defaultOpen={defaultOpen}>
            <SidebarEdgeToggle />
        </SidebarProvider>,
    );
}

/**
 * The chip is the desktop's rail toggle now that the header trigger is
 * phone-only, so its name and state must say which way it will go.
 */
describe('SidebarEdgeToggle', () => {
    it('collapses an expanded rail and then offers to expand it', () => {
        renderEdgeToggle(true);

        const toggle = screen.getByRole('button', {
            name: 'common.nav.collapse_sidebar',
        });
        expect(toggle).toHaveAttribute('aria-expanded', 'true');

        fireEvent.click(toggle);

        expect(
            screen.getByRole('button', { name: 'common.nav.expand_sidebar' }),
        ).toHaveAttribute('aria-expanded', 'false');
    });

    it('offers to expand a rail that starts collapsed', () => {
        renderEdgeToggle(false);

        expect(
            screen.getByRole('button', { name: 'common.nav.expand_sidebar' }),
        ).toHaveAttribute('aria-expanded', 'false');
    });
});
