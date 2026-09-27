// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            translations: {
                common: {
                    appearance: {
                        label: 'Appearance',
                        light: 'Light',
                        dark: 'Dark',
                        system: 'System',
                    },
                },
            },
        },
    }),
}));

const { default: AppearanceTabs } = await import('./appearance-tabs');

/**
 * The appearance control (§33.1), exercised the way a person uses it.
 *
 * Exactly Light, Dark and System — a radio group, so a keyboard user gets arrow
 * keys and a screen reader hears which one is chosen — and choosing one is
 * remembered for the next page load and applied to the page at once.
 */
describe('appearance tabs', () => {
    beforeEach(() => {
        localStorage.clear();
        document.documentElement.className = '';
        // jsdom has no media queries; "System" follows a light device here.
        window.matchMedia = vi.fn().mockReturnValue({
            matches: false,
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
        });
    });

    it('offers exactly Light, Dark and System as one choice', () => {
        render(<AppearanceTabs />);

        const group = screen.getByRole('radiogroup', { name: 'Appearance' });
        const options = screen.getAllByRole('radio');

        expect(group).toBeInTheDocument();
        expect(options.map((option) => option.textContent)).toEqual([
            'Light',
            'Dark',
            'System',
        ]);
    });

    it('selects, remembers and applies the theme somebody chooses', async () => {
        const user = userEvent.setup();

        render(<AppearanceTabs />);

        await user.click(screen.getByRole('radio', { name: 'Dark' }));

        expect(screen.getByRole('radio', { name: 'Dark' })).toBeChecked();
        expect(screen.getByRole('radio', { name: 'Light' })).not.toBeChecked();
        expect(localStorage.getItem('appearance')).toBe('dark');
        expect(document.documentElement).toHaveClass('dark');

        await user.click(screen.getByRole('radio', { name: 'Light' }));

        expect(screen.getByRole('radio', { name: 'Light' })).toBeChecked();
        expect(document.documentElement).not.toHaveClass('dark');
    });

    it('keeps the same accessible names in iconOnly mode, just visually hidden', () => {
        render(<AppearanceTabs iconOnly />);

        const light = screen.getByRole('radio', { name: 'Light' });

        expect(light).toBeInTheDocument();
        expect(light.querySelector('span')).toHaveClass('sr-only');
    });

    it('can be operated from the keyboard', async () => {
        const user = userEvent.setup();

        render(<AppearanceTabs />);

        await user.tab();
        expect(screen.getByRole('radio', { name: 'Light' })).toHaveFocus();

        await user.keyboard('{Tab}{Enter}');

        expect(screen.getByRole('radio', { name: 'Dark' })).toBeChecked();
    });
});
